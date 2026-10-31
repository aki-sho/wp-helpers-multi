<?php
if (!defined('ABSPATH')) exit;

function wphm_su_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'wphm_site_urls';
}

function wphm_su_schema(): bool {
    if (get_option('wphm_site_urls_db_version') === '1.0.0') return true;
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
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash),
        KEY crawl_state (crawl_state,id),
        KEY is_article (is_article,id)
    ) {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false;
    update_option('wphm_site_urls_db_version', '1.0.0', false);
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

function wphm_su_rows(string $search, int $page = 1, int $per_page = 100): array {
    global $wpdb;
    $table = wphm_su_table();
    $where = 'is_article = 0'; $params = [];
    if ($search !== '') { $where .= ' AND (url LIKE %s OR source_url LIKE %s)'; $like = '%' . $wpdb->esc_like(substr($search, 0, 200)) . '%'; $params = [$like, $like]; }
    $sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $total = (int)$wpdb->get_var($params ? $wpdb->prepare($sql, $params) : $sql);
    $per_page = max(1, min(WPHM_SU_MAX_URLS, $per_page));
    $pages = max(1, (int)ceil($total / $per_page)); $page = max(1, min($pages, $page));
    $args = array_merge($params, [$per_page, ($page - 1) * $per_page]);
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} ORDER BY id LIMIT %d OFFSET %d", $args), ARRAY_A);
    return ['rows' => (array)$rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}
