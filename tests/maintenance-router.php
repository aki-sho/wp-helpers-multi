<?php
// Run only with PHP's loopback development server and the disposable test runtime.
if (PHP_SAPI !== 'cli-server') exit;
$temporary = dirname(__DIR__) . '/.wphm-test';
$runtime = is_file($temporary . '/maintenance-runtime.json') ? json_decode(file_get_contents($temporary . '/maintenance-runtime.json'), true) : null;
if (!$runtime || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) || !hash_equals($runtime['key'], $_SERVER['HTTP_X_WPHM_TEST'] ?? '')) { http_response_code(403); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
define('WP_ADMIN', strpos($path, '/front/') !== 0);
require $temporary . '/wordpress/wp-load.php';
wp_set_current_user(WP_ADMIN ? $runtime['user'] : 0);
if (!WP_ADMIN) {
    if ($path === '/front/blocked') wphm_ip_blocklist_block('127.0.0.1', 'manual');
    wphm_access_log_record_front();
    if ($path === '/front/redirect' || $path === '/front/temporary') { wp_redirect('/target', $path === '/front/redirect' ? 301 : 302); exit; }
    if ($path === '/front/missing') status_header(404);
    if ($path === '/front/error') status_header(500);
    if ($path === '/front/blocked') wphm_ip_blocklist_reject_front_access();
    if ($path === '/front/fatal') trigger_error('Intentional isolated test failure', E_USER_ERROR);
    if ($path === '/front/double') wphm_access_log_record_front();
    echo 'FRONT_FIXTURE'; exit;
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
if ($path === '/admin/bcrypt') { require_once WP_CONTENT_DIR . '/plugins/wp-helpers-multi/tools/bcrypt.php'; wphm_render_bcrypt_tool_page(); exit; }
if ($path === '/admin/post-csv') { do_action('admin_post_fv_export_list_download'); exit; }
if ($path === '/records') { wp_send_json($wpdb->get_results('SELECT path,status FROM ' . wphm_access_log_table_name(), ARRAY_A)); }
$pages = ['/admin/access' => 'wphm-access-log', '/admin/comments' => 'wphm-comment-manager', '/admin/links' => 'wphm-link-inspector'];
if (!isset($pages[$path])) { status_header(404); exit; }
$_GET['page'] = $pages[$path];
wphm_register_admin_menu();
$hook = get_plugin_page_hookname($pages[$path], 'wp-helpers-multi');
do_action('load-' . $hook);
echo 'ADMIN_HTML_MARKER';
do_action($hook);
