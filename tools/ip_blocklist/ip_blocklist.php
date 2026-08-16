<?php
if (!defined('ABSPATH')) exit;

/**
 * コメント管理とアクセスログで共有するIP拒否リスト。
 */
define('WPHM_IP_BLOCKLIST_DB_VERSION', '1.0.0');

function wphm_ip_blocklist_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'wphm_ip_blocklist';
}

function wphm_ip_blocklist_schema_ensure(): void {
    global $wpdb;

    $table = wphm_ip_blocklist_table_name();
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip VARCHAR(45) NOT NULL,
        source VARCHAR(32) NOT NULL DEFAULT '',
        source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY ip (ip),
        KEY source (source),
        KEY source_id (source_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

add_action('init', 'wphm_ip_blocklist_maybe_update_schema', 0);
function wphm_ip_blocklist_maybe_update_schema(): void {
    $current = get_option('wphm_ip_blocklist_db_version');
    if ($current === WPHM_IP_BLOCKLIST_DB_VERSION) return;

    wphm_ip_blocklist_schema_ensure();
    update_option('wphm_ip_blocklist_db_version', WPHM_IP_BLOCKLIST_DB_VERSION, false);
}

function wphm_ip_blocklist_normalize_ip(string $ip): string {
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) return '';

    // IPv6の省略表記などを統一し、同じIPが別表記で重複しないようにする。
    $packed = @inet_pton($ip);
    if ($packed === false) return $ip;

    $normalized = @inet_ntop($packed);
    return $normalized !== false ? $normalized : $ip;
}

function wphm_ip_blocklist_block(string $ip, string $source = 'manual', int $source_id = 0): bool {
    global $wpdb;

    $ip = wphm_ip_blocklist_normalize_ip($ip);
    if ($ip === '') return false;

    $source = substr(sanitize_key($source), 0, 32);
    if ($source === '') $source = 'manual';

    $table = wphm_ip_blocklist_table_name();
    $now = current_time('mysql', true);
    $created_by = get_current_user_id();

    $sql = "INSERT INTO {$table}
            (ip, source, source_id, created_by, created_at, updated_at)
            VALUES (%s, %s, %d, %d, %s, %s)
            ON DUPLICATE KEY UPDATE
                source = VALUES(source),
                source_id = VALUES(source_id),
                created_by = VALUES(created_by),
                updated_at = VALUES(updated_at)";

    $result = $wpdb->query($wpdb->prepare(
        $sql,
        $ip,
        $source,
        max(0, $source_id),
        max(0, (int)$created_by),
        $now,
        $now
    ));

    if ($result !== false) {
        $GLOBALS['wphm_ip_blocklist_runtime_cache'][$ip] = true;
        return true;
    }

    return false;
}

function wphm_ip_blocklist_unblock(string $ip): bool {
    global $wpdb;

    $ip = wphm_ip_blocklist_normalize_ip($ip);
    if ($ip === '') return false;

    $deleted = $wpdb->delete(
        wphm_ip_blocklist_table_name(),
        ['ip' => $ip],
        ['%s']
    );

    if ($deleted !== false) {
        $GLOBALS['wphm_ip_blocklist_runtime_cache'][$ip] = false;
        return true;
    }

    return false;
}

/**
 * IPをキーに拒否情報を返す。拒否されていないIPは配列に含めない。
 */
function wphm_ip_blocklist_get_entries(array $ips): array {
    global $wpdb;

    $normalized = [];
    foreach ($ips as $ip) {
        $ip = wphm_ip_blocklist_normalize_ip((string)$ip);
        if ($ip !== '') $normalized[$ip] = true;
    }

    if (!$normalized) return [];

    $entries = [];
    $table = wphm_ip_blocklist_table_name();

    foreach (array_chunk(array_keys($normalized), 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
        $sql = "SELECT ip, source, source_id, created_by, created_at, updated_at
                FROM {$table}
                WHERE ip IN ({$placeholders})";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);

        foreach ((array)$rows as $row) {
            $ip = wphm_ip_blocklist_normalize_ip((string)($row['ip'] ?? ''));
            if ($ip !== '') {
                $entries[$ip] = $row;
                $GLOBALS['wphm_ip_blocklist_runtime_cache'][$ip] = true;
            }
        }
    }

    return $entries;
}

function wphm_ip_blocklist_is_blocked(string $ip): bool {
    $ip = wphm_ip_blocklist_normalize_ip($ip);
    if ($ip === '') return false;

    $cache = $GLOBALS['wphm_ip_blocklist_runtime_cache'] ?? [];
    if (array_key_exists($ip, $cache)) {
        return (bool)$cache[$ip];
    }

    $entries = wphm_ip_blocklist_get_entries([$ip]);
    $blocked = isset($entries[$ip]);
    $GLOBALS['wphm_ip_blocklist_runtime_cache'][$ip] = $blocked;
    return $blocked;
}

/**
 * IPごとのスパムコメント件数。アクセスログ側の相互表示に使用する。
 */
function wphm_ip_blocklist_get_spam_comment_counts(array $ips): array {
    global $wpdb;

    $normalized = [];
    foreach ($ips as $ip) {
        $ip = wphm_ip_blocklist_normalize_ip((string)$ip);
        if ($ip !== '') $normalized[$ip] = true;
    }

    $counts = array_fill_keys(array_keys($normalized), 0);
    if (!$normalized) return $counts;

    foreach (array_chunk(array_keys($normalized), 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
        $sql = "SELECT comment_author_IP AS ip, COUNT(*) AS spam_count
                FROM {$wpdb->comments}
                WHERE comment_approved = 'spam'
                  AND comment_author_IP IN ({$placeholders})
                GROUP BY comment_author_IP";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $chunk), ARRAY_A);

        foreach ((array)$rows as $row) {
            $ip = wphm_ip_blocklist_normalize_ip((string)($row['ip'] ?? ''));
            if ($ip !== '') $counts[$ip] = (int)($row['spam_count'] ?? 0);
        }
    }

    return $counts;
}

function wphm_ip_blocklist_get_current_ip(): string {
    if (function_exists('wphm_access_log_get_client_ip')) {
        return wphm_ip_blocklist_normalize_ip(wphm_access_log_get_client_ip());
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return wphm_ip_blocklist_normalize_ip((string)apply_filters('wphm_access_log_ip', $ip));
}

function wphm_ip_blocklist_reject_current_request(string $title): void {
    $message = (string)apply_filters(
        'wphm_ip_blocklist_rejection_message',
        'このIPアドレスからのアクセスは拒否されています。'
    );

    wp_die(
        esc_html($message),
        esc_html($title),
        ['response' => 403]
    );
}

// アクセスログを1件記録した後に、拒否IPのフロント閲覧を403で終了する。
add_action('template_redirect', 'wphm_ip_blocklist_reject_front_access', 2);
function wphm_ip_blocklist_reject_front_access(): void {
    if (is_admin()) return;
    if (wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;

    $ip = wphm_ip_blocklist_get_current_ip();
    if ($ip !== '' && wphm_ip_blocklist_is_blocked($ip)) {
        wphm_ip_blocklist_reject_current_request('アクセスを拒否しました');
    }
}

// wp-comments-post.php などtemplate_redirectを通らないコメント投稿も拒否する。
add_filter('preprocess_comment', 'wphm_ip_blocklist_reject_comment_submission', 5);
function wphm_ip_blocklist_reject_comment_submission(array $comment_data): array {
    $ip = wphm_ip_blocklist_get_current_ip();
    if ($ip !== '' && wphm_ip_blocklist_is_blocked($ip)) {
        wphm_ip_blocklist_reject_current_request('コメント投稿を拒否しました');
    }

    return $comment_data;
}
