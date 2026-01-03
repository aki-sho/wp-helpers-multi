<?php
if (!defined('ABSPATH')) exit;

/**
 * Access Log module entry
 * - front: record access
 * - admin: render page / export / delete
 */

define('WPHM_ACCESS_LOG_DB_VERSION', '1.0.0');

require_once __DIR__ . '/schema/table.php';
require_once __DIR__ . '/app/repository.php';
require_once __DIR__ . '/app/service.php';
require_once __DIR__ . '/app/ajax.php';
require_once __DIR__ . '/admin/admin.php';

/**
 * DB schema (adminでもfrontでも安全に回るように、軽いチェックだけ実行)
 * - option を見て必要なときだけ dbDelta
 */
add_action('init', 'wphm_access_log_maybe_update_schema', 1);
function wphm_access_log_maybe_update_schema(): void {
    // なるべく軽く：version一致なら即return
    $cur = get_option('wphm_access_log_db_version');
    if ($cur === WPHM_ACCESS_LOG_DB_VERSION) return;

    // dbDeltaは管理画面以外でも動くが、負荷を避けたいなら admin のみでもOK
    wphm_access_log_schema_ensure();
    update_option('wphm_access_log_db_version', WPHM_ACCESS_LOG_DB_VERSION, false);
}

/**
 * Record access (front only)
 */
add_action('template_redirect', 'wphm_access_log_record_front', 1);
function wphm_access_log_record_front(): void {
    if (is_admin()) return;
    if (wp_doing_ajax()) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;

    // 記録するか判定（ボット除外など）
    if (!wphm_access_log_should_log()) return;

    $row = wphm_access_log_build_row();
    if (!$row) return;

    wphm_access_log_repo_insert($row);
}