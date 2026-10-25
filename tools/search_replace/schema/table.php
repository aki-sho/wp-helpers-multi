<?php
if (!defined('ABSPATH')) exit;

function wphm_sr_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'wphm_search_replace_history';
}

function wphm_sr_ensure_schema(): bool {
    if (get_option('wphm_sr_db_version') === '1.0.0') return true;
    global $wpdb;
    $table = wphm_sr_table();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        run_id varchar(32) NOT NULL,
        post_id bigint(20) unsigned NOT NULL,
        user_id bigint(20) unsigned NOT NULL,
        created_at datetime NOT NULL,
        restored_at datetime NULL,
        replacements int unsigned NOT NULL DEFAULT 0,
        status varchar(20) NOT NULL DEFAULT 'pending',
        before_content longtext NOT NULL,
        after_content longtext NOT NULL,
        before_hash char(64) NOT NULL,
        after_hash char(64) NOT NULL,
        note text NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY run_post (run_id,post_id),
        KEY post_id (post_id),
        KEY created_at (created_at)
    ) {$charset};");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return false;
    update_option('wphm_sr_db_version', '1.0.0', false);
    return true;
}
