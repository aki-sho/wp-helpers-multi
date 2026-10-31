<?php
if (!defined('ABSPATH')) exit;

/** Fixed bounds keep logging work and storage finite, including on busy public sites. */
function wphm_access_log_limits(): array {
    $saved = get_option('wphm_access_log_limits', []);
    if (!is_array($saved)) $saved = [];
    $bounds = ['days' => [30, 1, 365], 'rows' => [100000, 1000, 500000], 'per_minute' => [120, 10, 1000], 'per_ip_minute' => [10, 1, 60]];
    $out = [];
    foreach ($bounds as $key => $bound) $out[$key] = max($bound[1], min($bound[2], (int)($saved[$key] ?? $bound[0])));
    return $out;
}

function wphm_access_log_lock_name(): string {
    return 'wphm_al_' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . wphm_access_log_table_name()), 0, 40);
}

function wphm_access_log_clip(string $text, int $bytes): string {
    $text = str_replace("\0", '', $text);
    if (!preg_match('//u', $text)) $text = function_exists('wp_check_invalid_utf8') ? wp_check_invalid_utf8($text, true) : '';
    if (strlen($text) <= $bytes) return $text;
    $text = substr($text, 0, $bytes);
    while ($text !== '' && !preg_match('//u', $text)) $text = substr($text, 0, -1);
    return $text;
}

/** Keep the origin/path only. Allowlisting query keys cannot anticipate every secret name. */
function wphm_access_log_private_url(string $url): string {
    $url = preg_split('/[?#]/', $url, 2)[0];
    $url = preg_replace('~^(https?://)[^/]*@~i', '$1', $url);
    return wphm_access_log_clip((string)$url, 2048);
}

function wphm_access_log_protect_row(array $row): array {
    foreach (['url', 'referrer'] as $field) $row[$field] = wphm_access_log_private_url((string)($row[$field] ?? ''));
    foreach (['path' => 1024, 'user_agent' => 512, 'method' => 10, 'ip' => 45] as $field => $limit) {
        $row[$field] = wphm_access_log_clip((string)($row[$field] ?? ''), $limit);
    }
    return $row;
}

/** Atomic, bounded counters: one global row and 64 shared IP slots, never a row per visitor. */
function wphm_access_log_take_budget(string $ip): bool {
    global $wpdb;
    $limits = wphm_access_log_limits();
    $slot = hexdec(substr(hash('sha256', $ip), 0, 6)) % 64;
    foreach (['ip_' . $slot => $limits['per_ip_minute'], 'global' => $limits['per_minute']] as $key => $limit) {
        $epoch = (string)intdiv(time(), 60);
        $name = 'wphm_access_log_budget_' . $key;
        $value = $epoch . ':1';
        $sql = "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
            ON DUPLICATE KEY UPDATE option_value = IF(SUBSTRING_INDEX(option_value, ':', 1) <> %s, %s,
                IF(CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) < %d,
                    CONCAT(%s, ':', CAST(SUBSTRING_INDEX(option_value, ':', -1) AS UNSIGNED) + 1), option_value))";
        if (!$wpdb->query($wpdb->prepare($sql, $name, $value, $epoch, $value, $limit, $epoch))) return false;
    }
    return true;
}

function wphm_access_log_schedule_maintenance(): void {
    if (!wp_next_scheduled('wphm_access_log_maintenance')) wp_schedule_single_event(time() + 60, 'wphm_access_log_maintenance');
}

/** Incremental cleanup avoids a large migration/DELETE during an upgrade request. */
function wphm_access_log_maintenance(): void {
    global $wpdb;
    $table = wphm_access_log_table_name();
    $limits = wphm_access_log_limits();
    $threshold = time() - $limits['days'] * DAY_IN_SECONDS;
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %d LIMIT 1000", $threshold));
    $more = $wpdb->rows_affected >= 1000;
    $newest = (int)$wpdb->get_var("SELECT id FROM {$table} ORDER BY id DESC LIMIT 1");
    $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE id <= %d LIMIT 1000", max(0, $newest - $limits['rows'])));
    $more = $more || $wpdb->rows_affected >= 1000;
    $state = get_option('wphm_access_log_privacy_v1', []);
    if (!is_array($state) || !isset($state['end'])) $state = ['cursor' => 0, 'end' => $newest];
    if ($state['cursor'] < $state['end']) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE id > %d AND id <= %d ORDER BY id LIMIT 200", $state['cursor'], $state['end']), ARRAY_A);
        foreach ((array)$rows as $row) {
            $safe = wphm_access_log_protect_row($row);
            $fields = array_intersect_key($safe, array_flip(['url', 'referrer', 'path', 'user_agent', 'method', 'ip']));
            if ($wpdb->update($table, $fields, ['id' => (int)$row['id']]) === false) break;
            $state['cursor'] = (int)$row['id'];
        }
        if (!$rows) $state['cursor'] = $state['end'];
        update_option('wphm_access_log_privacy_v1', $state, false);
        $more = $more || $state['cursor'] < $state['end'];
    }
    if (!wp_next_scheduled('wphm_access_log_maintenance')) wp_schedule_single_event(time() + ($more ? 60 : HOUR_IN_SECONDS), 'wphm_access_log_maintenance');
}

function wphm_access_log_render_limits(): void {
    $limits = wphm_access_log_limits();
    echo '<details style="margin:16px 0"><summary>ログの保存・記録制限</summary><form method="post">';
    wp_nonce_field('wphm_access_log_action');
    foreach (['days' => ['保持日数', 1, 365], 'rows' => ['最大保存件数', 1000, 500000], 'per_minute' => ['全体の記録上限（件/分）', 10, 1000], 'per_ip_minute' => ['IPごとの記録上限（件/分）', 1, 60]] as $key => $field) {
        echo '<p><label>' . esc_html($field[0]) . ' <input type="number" name="limits[' . esc_attr($key) . ']" min="' . $field[1] . '" max="' . $field[2] . '" value="' . (int)$limits[$key] . '"></label></p>';
    }
    echo '<p>URL・参照元のクエリとフラグメントは保存しません。上限到達時は一部アクセスの記録を省略します。既存ログは少量ずつ整理します。</p><button class="button" name="wphm_access_log_action" value="save_limits">保存</button></form></details>';
}
