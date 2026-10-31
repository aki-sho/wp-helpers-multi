<?php
if (!defined('ABSPATH')) exit;

function wphm_su_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'wphm_site_urls';
}

function wphm_su_schema(): bool {
    if (get_option('wphm_site_urls_db_version') === '1.1.0') return true;
    global $wpdb;
    $table = wphm_su_table();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        url_hash char(64) NOT NULL,
        url text NOT NULL,
        source_url text NOT NULL,
        source_label varchar(100) NOT NULL,
        is_article tinyint(1) NOT NULL DEFAULT 0,
        crawl_state varchar(16) NOT NULL DEFAULT 'queued',
        http_status smallint unsigned NOT NULL DEFAULT 0,
        note varchar(255) NOT NULL DEFAULT '',
        access_level varchar(24) NOT NULL DEFAULT 'unknown',
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash),
        KEY crawl_state (crawl_state,id),
        KEY is_article (is_article,id)
    ) {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false;
    update_option('wphm_site_urls_db_version', '1.1.0', false);
    return true;
}

function wphm_su_lock(): bool {
    global $wpdb;
    $key = 'wphm_su_' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . wphm_su_table()), 0, 40);
    return (string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)) === '1';
}

function wphm_su_unlock(): void {
    global $wpdb;
    $key = 'wphm_su_' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . wphm_su_table()), 0, 40);
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
}

function wphm_su_clear_run(string $run): bool {
    global $wpdb;
    wp_cache_delete('wphm_site_urls_state', 'options');
    wp_cache_delete('notoptions', 'options');
    $state = get_option('wphm_site_urls_state', []);
    if (!is_array($state) || empty($state['run']) || !hash_equals($state['run'], $run)) return false;
    if ($wpdb->query('DELETE FROM ' . wphm_su_table()) === false) throw new RuntimeException('収集結果を削除できませんでした。');
    delete_option('wphm_site_urls_state');
    delete_transient('wphm_su_clear_' . $run);
    return true;
}

function wphm_su_clear_requested(string $run): bool {
    if (!wphm_su_has_clear_request($run)) return false;
    $cleared = wphm_su_clear_run($run);
    if (!$cleared) delete_transient('wphm_su_clear_' . $run);
    return $cleared;
}

function wphm_su_has_clear_request(string $run): bool {
    $key = 'wphm_su_clear_' . $run;
    if (wp_using_ext_object_cache()) return (bool)wp_cache_get($key, 'transient', true);
    // A different PHP request can create the cancellation while this batch is running.
    wp_cache_delete('_transient_' . $key, 'options');
    wp_cache_delete('_transient_timeout_' . $key, 'options');
    wp_cache_delete('notoptions', 'options');
    return (bool)get_transient($key);
}

function wphm_su_columns(): array {
    return ['url' => 'URL', 'source_url' => '発見元', 'source_label' => '発見方法', 'http_status' => 'HTTP', 'confirmation' => '確認状態', 'access_level' => 'アクセス権限'];
}

function wphm_su_confirmation(array $row): string {
    return $row['note'] ?: ($row['crawl_state'] === 'queued' ? '巡回待ち' : '取得済み');
}

function wphm_su_rows(string $search, int $page = 1, int $per_page = 100, string $orderby = 'url', string $order = 'ASC'): array {
    global $wpdb;
    $table = wphm_su_table();
    $where = 'is_article = 0'; $params = [];
    if ($search !== '') { $where .= ' AND (url LIKE %s OR source_url LIKE %s)'; $like = '%' . $wpdb->esc_like(substr($search, 0, 200)) . '%'; $params = [$like, $like]; }
    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $total = (int)$wpdb->get_var($params ? $wpdb->prepare($sql, $params) : $sql);
    $per_page = max(1, min(WPHM_SU_MAX_URLS, $per_page));
    $pages = max(1, (int)ceil($total / $per_page)); $page = max(1, min($pages, $page));
    $args = array_merge($params, [$per_page, ($page - 1) * $per_page]);
    $sorts = ['url' => 'url', 'source_url' => 'source_url', 'source_label' => 'source_label', 'http_status' => 'http_status', 'confirmation' => "CASE WHEN note <> '' THEN note WHEN crawl_state = 'queued' THEN '巡回待ち' ELSE '取得済み' END"];
    $access_sort = 'CASE access_level ';
    foreach (wphm_su_access_labels() as $key => $label) $access_sort .= $wpdb->prepare('WHEN %s THEN %s ', $key, $label);
    $sorts['access_level'] = $access_sort . "ELSE '未確認' END";
    if (!isset($sorts[$orderby])) $orderby = 'url';
    $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
    $rows = (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY {$sorts[$orderby]} {$order}, id ASC LIMIT %d OFFSET %d", $args), ARRAY_A);
    foreach ($rows as &$row) { $row['access_label'] = wphm_su_access_labels()[$row['access_level'] ?? 'unknown'] ?? '未確認'; $row['confirmation_label'] = wphm_su_confirmation($row); }
    unset($row);
    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'orderby' => $orderby, 'order' => $order];
}
