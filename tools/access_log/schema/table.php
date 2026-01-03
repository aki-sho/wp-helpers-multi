<?php
if (!defined('ABSPATH')) exit;

function wphm_access_log_table_name(): string {
    global $wpdb;
    return $wpdb->prefix . 'wphm_access_log';
}

function wphm_access_log_schema_ensure(): void {
    global $wpdb;

    $table = wphm_access_log_table_name();
    $charset = $wpdb->get_charset_collate();

    // created_at は UNIX秒（検索/範囲絞り込みが簡単）
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at INT UNSIGNED NOT NULL,
        method VARCHAR(10) NOT NULL DEFAULT '',
        status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        url TEXT NOT NULL,
        path TEXT NOT NULL,
        referrer TEXT NOT NULL,
        ip VARCHAR(45) NOT NULL DEFAULT '',
        user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_agent TEXT NOT NULL,
        PRIMARY KEY  (id),
        KEY created_at (created_at),
        KEY ip (ip),
        KEY user_id (user_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}