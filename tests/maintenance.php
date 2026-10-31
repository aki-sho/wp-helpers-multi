<?php
// php -n tests/maintenance.php
if (PHP_SAPI !== 'cli') exit;
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', __DIR__ . '/');
define('WPHM_PLUGIN_FILE', dirname(__DIR__) . '/wp-helpers-multi.php');
define('WPHM_VERSION', '0.9.1');
define('HOUR_IN_SECONDS', 3600); define('MINUTE_IN_SECONDS', 60); define('DAY_IN_SECONDS', 86400);
class WP_Error { public function __construct($code, $message = '') {} }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone($GLOBALS['zone']); }
function wp_date($format, $timestamp = null, $timezone = null) { return (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone($timezone ?? wp_timezone())->format($format); }
function wp_unslash($value) { return stripslashes($value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
function sanitize_text_field($value) { return strip_tags($value); }
function wp_kses_post($value) { return strip_tags($value, '<p><h4><ul><li><a>'); }
function esc_url_raw($value, $protocols = ['http', 'https']) { return in_array(parse_url($value, PHP_URL_SCHEME), $protocols, true) ? $value : ''; }
function wp_parse_url($url) { return parse_url($url); }
function plugin_basename($file) { return 'wp-helpers-multi/wp-helpers-multi.php'; }
function add_filter($hook, $callback, $priority = 10, $arguments = 1) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_action($hook, $callback, $priority = 10, $arguments = 1) { $GLOBALS['hooks'][$hook][] = $callback; }
function apply_filters($hook, $value) { return $value; }
function get_option($key, $default = false) { return $default; }
function get_site_transient($key) { return $GLOBALS['cache'][$key]['value'] ?? false; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = ['value' => $value, 'ttl' => $ttl]; return true; }
function delete_site_transient($key) { unset($GLOBALS['cache'][$key]); }
function wp_safe_remote_get($url, $args) { $GLOBALS['requests']++; $GLOBALS['request_args'] = $args; return $GLOBALS['response']; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function is_admin() { return $GLOBALS['is_admin']; }
function wp_doing_ajax() { return false; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return $GLOBALS['user_id'] ?? 1; }
function current_user_can($capability) { return $capability === 'manage_network_plugins' ? ($GLOBALS['can_network'] ?? true) : ($GLOBALS['can_update'] ?? true); }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function self_admin_url($path) { return admin_url($path); }
function is_network_admin() { return $GLOBALS['network_admin'] ?? false; }
function is_multisite() { return $GLOBALS['multisite'] ?? false; }
function network_admin_url($path) { return 'https://example.test/wp-admin/network/' . $path; }
function esc_url($url) { return htmlspecialchars($url, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=test-' . $action; }
function add_query_arg($key, $value, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . urlencode($key) . '=' . urlencode($value); }
function set_transient($key, $value, $ttl) { $GLOBALS['user_cache'][$key] = ['value' => $value, 'ttl' => $ttl]; }
function get_transient($key) { return $GLOBALS['user_cache'][$key]['value'] ?? false; }
function delete_transient($key) { unset($GLOBALS['user_cache'][$key]); }
class MaintenanceDenied extends Exception {}
class MaintenanceRedirect extends Exception {}
function wp_die($message, $title = '', $args = []) { throw new MaintenanceDenied($message, $args['response'] ?? 500); }
function check_admin_referer($action) { if (!($GLOBALS['nonce_valid'] ?? true)) throw new MaintenanceDenied('Invalid nonce', 403); $GLOBALS['checked_nonce'] = $action; }
function wp_safe_redirect($url) { throw new MaintenanceRedirect($url); }
function add_menu_page(...$args) { return 'wphm-main'; }
function add_submenu_page(...$args) { return 'wphm-page-' . $args[4]; }
class MaintenanceDatabase {
    public $prefix = 'test_'; public $options = 'test_options'; public $rows = []; public $rows_affected = 0;
    public function prepare($query, ...$args) { return $query; }
    public function query($query) { return 1; }
    public function get_var($query) { if (strpos($query, 'GET_LOCK') !== false) return '1'; return null; }
    public function insert($table, $data, $formats) { $this->rows[] = $data; return 1; }
}
$GLOBALS['zone'] = 'Asia/Tokyo'; $GLOBALS['hooks'] = []; $GLOBALS['cache'] = []; $GLOBALS['requests'] = 0; $GLOBALS['is_admin'] = false;
$GLOBALS['wpdb'] = new MaintenanceDatabase();
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/settings.php';
require __DIR__ . '/../includes/admin.php';
require __DIR__ . '/../includes/updater.php';
require __DIR__ . '/../tools/bcrypt.php';
require __DIR__ . '/../tools/post_data/post_data.php';
require __DIR__ . '/../tools/access_log/access_log.php';
require __DIR__ . '/../tools/comment_manager/admin/admin.php';
require __DIR__ . '/../tools/link_inspector.php';
$checks = 0;
function same($expected, $actual, $label) {
    global $checks; $checks++;
    if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
foreach (['=1+1', '+1', '-text', '@value', "\t=1", " \r\n+1", "\u{FEFF}=1", '＝1', '＋1', '－1', '＠name'] as $text) same("'" . $text, wphm_csv_safe_cell($text), 'formula-like text protected');
foreach (['日本語', 'https://example.test/?x=1', 'name@example.test', '', '123', "line\none"] as $text) same($text, wphm_csv_safe_cell($text), 'ordinary text retained');
same(-25, wphm_csv_safe_cell(-25), 'real negative number remains numeric');
same(0.5, wphm_csv_safe_cell(0.5), 'real decimal remains numeric');
$stream = fopen('php://temp', 'r+');
$row = ["=1", "C:\\path\\\"quoted\"", "複数\n行", 'a,b'];
same(true, wphm_csv_write_row($stream, $row), 'CSV written'); rewind($stream);
same(array_map('wphm_csv_safe_cell', $row), fgetcsv($stream, 0, ',', '"', ''), 'CSV round trip preserves quotes and backslashes'); fclose($stream);
same('2026-10-25 12:34', fv_export_list_format_datetime('2026-10-25 12:34:56'), 'local post time not converted twice');
same('2026-10-25 09:00:00', wphm_format_timestamp(strtotime('2026-10-25 00:00:00 UTC')), 'epoch uses site timezone');
same(strtotime('2026-10-24 15:00:00 UTC'), wphm_access_log_date_to_ts('2026-10-25'), 'Tokyo filter starts at local midnight');
same(strtotime('2026-10-25 14:59:59 UTC'), wphm_access_log_date_to_ts('2026-10-25', true), 'Tokyo filter ends at local midnight');
same(0, wphm_access_log_date_to_ts('2026-02-30'), 'invalid dates rejected');
same('', fv_export_list_format_datetime('0000-00-00 00:00:00'), 'invalid stored dates rejected');
$GLOBALS['zone'] = 'America/New_York';
same(23 * 3600, wphm_access_log_date_to_ts('2026-03-08', true) - wphm_access_log_date_to_ts('2026-03-08') + 1, 'spring DST day length');
same(25 * 3600, wphm_access_log_date_to_ts('2026-11-01', true) - wphm_access_log_date_to_ts('2026-11-01') + 1, 'autumn DST day length');
$GLOBALS['zone'] = 'Asia/Tokyo';
$plain = "引用'と\"二重引用\" C:\\path\\終端\\";
$_POST = ['password' => addslashes($plain), 'plain' => addslashes($plain)];
same($plain, wphm_bcrypt_request_text('password'), 'bcrypt generation restores original input');
$hash = password_hash(wphm_bcrypt_request_text('password'), PASSWORD_BCRYPT, ['cost' => 4]);
same(true, password_verify($plain, $hash), 'generated hash verifies outside WordPress');
same(true, password_verify(wphm_bcrypt_request_text('plain'), password_hash($plain, PASSWORD_BCRYPT, ['cost' => 4])), 'external hash verifies in WordPress');
$_POST['password'] = ['bad']; same('', wphm_bcrypt_request_text('password'), 'non-text password rejected');
wphm_register_admin_menu();
same(['wphm_access_log_handle_request'], $GLOBALS['hooks']['load-wphm-page-wphm-access-log'], 'access actions run before headers');
same(['wphm_comment_manager_handle_request'], $GLOBALS['hooks']['load-wphm-page-wphm-comment-manager'], 'comment actions run before headers');
same(['wphm_load_link_inspector'], $GLOBALS['hooks']['load-wphm-page-wphm-link-inspector'], 'link actions run before headers');
foreach (['wphm_render_access_log_tool_page', 'wphm_render_comment_manager_tool_page', 'wphm_render_link_inspector_tool_page'] as $name) {
    $function = new ReflectionFunction($name);
    $body = implode('', array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1));
    same(false, strpos($body, 'wp_safe_redirect(') !== false || strpos($body, '_handle_request(') !== false || strpos($body, '_export_csv(') !== false, $name . ' only renders');
}
$_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'REQUEST_URI' => '/article/', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'test browser'];
foreach ([200, 301, 302, 403, 404, 500] as $code) {
    $before = count($GLOBALS['wpdb']->rows); http_response_code(200); wphm_access_log_record_front();
    same($before, count($GLOBALS['wpdb']->rows), 'log deferred until response finished');
    http_response_code($code); wphm_access_log_flush_pending();
    same($code, $GLOBALS['wpdb']->rows[$before]['status'], 'final HTTP status captured');
    wphm_access_log_flush_pending(); same($before + 1, count($GLOBALS['wpdb']->rows), 'log only written once');
}
http_response_code(200);
$metadata = ['version' => '0.9.2', 'download_url' => 'https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip', 'sections' => ['changelog' => '<p>更新</p>']];
$GLOBALS['response'] = ['response' => ['code' => 200], 'body' => json_encode($metadata)];
same('0.9.2', wphm_get_update_metadata()['version'], 'update fetched');
same('0.9.2', wphm_get_update_metadata()['version'], 'update cache read');
same(1, $GLOBALS['requests'], 'cache prevents repeat HTTP requests');
same(6 * HOUR_IN_SECONDS, $GLOBALS['cache']['wphm_update_metadata_v1']['ttl'], 'successful check cached for six hours');
$plugin = plugin_basename(WPHM_PLUGIN_FILE);
$list = (object) ['response' => [], 'no_update' => [$plugin => (object) ['new_version' => '0.9.1']]];
$list = wphm_filter_plugin_updates($list);
same('0.9.2', $list->response[$plugin]->new_version, 'new version advertised');
same(false, isset($list->no_update[$plugin]), 'opposite old no-update flag removed');
same('0.9.2', wphm_filter_plugin_information(false, 'plugin_information', (object) ['slug' => 'wp-helpers-multi'])->version, 'details share metadata cache');
same(1, $GLOBALS['requests'], 'list and details share same HTTP request');
same(false, wphm_update_for_hostname(false, [], 'another/plugin.php', []), 'other plugins unaffected');
same('0.9.2', wphm_update_for_hostname(false, [], $plugin, [])['version'], 'Update URI hook supported');
delete_site_transient('wphm_update_metadata_v1'); $GLOBALS['response'] = new WP_Error('timeout');
same('0.9.2', wphm_get_update_metadata()['version'], 'failure falls back to last good metadata');
same('0.9.2', wphm_get_update_metadata()['version'], 'failed request also cached');
same(2, $GLOBALS['requests'], 'failure does not repeatedly contact server');
same(10 * MINUTE_IN_SECONDS, $GLOBALS['cache']['wphm_update_metadata_v1']['ttl'], 'failure retry limited to ten minutes');
$GLOBALS['cache'] = []; $GLOBALS['response'] = ['response' => ['code' => 200], 'body' => '{broken'];
same(null, wphm_get_update_metadata(), 'invalid JSON rejected without fallback');
same(null, wphm_get_update_metadata(), 'missing metadata failure cached');
same(3, $GLOBALS['requests'], 'invalid JSON not requested repeatedly');
$list = wphm_filter_plugin_updates((object) ['response' => [$plugin => (object) ['new_version' => '9.0']]]);
same(false, isset($list->response[$plugin]), 'obsolete notice removed when metadata unavailable');
$GLOBALS['cache'] = []; $metadata['version'] = '0.9.1'; $GLOBALS['response'] = ['response' => ['code' => 200], 'body' => json_encode($metadata)];
$list = wphm_filter_plugin_updates((object) ['response' => [$plugin => (object) ['new_version' => '0.9.2']]]);
same(false, isset($list->response[$plugin]), 'obsolete notice removed at current version');
same('0.9.1', $list->no_update[$plugin]->new_version, 'current version represented correctly');
same('', $list->no_update[$plugin]->package, 'current version cannot install an older package');
same(null, wphm_validate_update_metadata(['version' => [], 'download_url' => 'https://example.test/a.zip']), 'non-string version rejected');
same(null, wphm_validate_update_metadata(['version' => 'bad', 'download_url' => 'https://example.test/a.zip']), 'invalid version rejected');
same(null, wphm_validate_update_metadata(['version' => '1.0.0', 'download_url' => 'javascript:bad']), 'non-HTTPS download rejected');
same(null, wphm_validate_update_metadata(['version' => '1.0.0', 'download_url' => 'https://user:pass@example.test/a.zip']), 'embedded credentials rejected');
wphm_clear_update_cache_after_upgrade(null, ['type' => 'plugin', 'action' => 'update', 'plugins' => [$plugin]]);
same([], $GLOBALS['cache'], 'own upgrade clears both update caches');

// Regression: an installed 0.9.1 cache must not suppress an explicit update check.
$metadata['version'] = '0.9.2';
$GLOBALS['response'] = ['response' => ['code' => 200], 'body' => json_encode($metadata)];
$old = $metadata; $old['version'] = '0.9.1';
set_site_transient('wphm_update_metadata_v1', ['data' => $old], 21600);
set_site_transient('wphm_update_last_good_v1', $old, 604800);
set_site_transient('another_plugin_cache', ['keep' => true], 3600);
$baseline = $GLOBALS['requests'];
same('0.9.1', wphm_get_update_metadata()['version'], 'normal checks retain six-hour cache');
same($baseline, $GLOBALS['requests'], 'normal cached check does not request HTTP');
$_GET = []; wphm_recheck_updates_on_core_screen();
same($baseline, $GLOBALS['requests'], 'opening update screen normally keeps cache');
$_GET = ['force-check' => ['1']]; wphm_recheck_updates_on_core_screen();
same($baseline, $GLOBALS['requests'], 'array force-check is ignored');
$_GET = ['force-check' => '1']; $GLOBALS['can_update'] = false; wphm_recheck_updates_on_core_screen();
same($baseline, $GLOBALS['requests'], 'unauthorized force check makes no external request');
$GLOBALS['can_update'] = true; wphm_recheck_updates_on_core_screen();
same($baseline + 1, $GLOBALS['requests'], 'WordPress Check again bypasses custom cache');
same('0.9.2', wphm_get_update_metadata()['version'], 'fresh version replaces old 0.9.1 cache');
same('no-cache', $GLOBALS['request_args']['headers']['Cache-Control'], 'explicit check requests fresh HTTP response');
same(['keep' => true], get_site_transient('another_plugin_cache'), 'other plugins caches are untouched');
same(['wphm_recheck_updates_on_core_screen'], $GLOBALS['hooks']['load-update-core.php'], 'standard update screen hook registered');
$links = wphm_update_check_action_link(['deactivate' => '<a>無効化</a>']);
same(true, strpos($links['wphm_check_updates'], '更新を確認') !== false, 'plugin row offers manual check');
same(true, strpos($links['wphm_check_updates'], '_wpnonce=') !== false, 'manual check link includes nonce');
same('<a>無効化</a>', $links['deactivate'], 'existing plugin actions retained');
$GLOBALS['can_update'] = false;
same([], wphm_update_check_action_link([]), 'manual link hidden without update permission');
try { wphm_handle_update_check(); throw new RuntimeException('Unauthorized check unexpectedly allowed.'); }
catch (MaintenanceDenied $error) { same(403, $error->getCode(), 'manual check enforces permission'); }
$GLOBALS['can_update'] = true; $GLOBALS['nonce_valid'] = false;
try { wphm_handle_update_check(); throw new RuntimeException('Invalid nonce unexpectedly allowed.'); }
catch (MaintenanceDenied $error) { same(403, $error->getCode(), 'manual check enforces nonce'); }
$GLOBALS['nonce_valid'] = true;
try { wphm_handle_update_check(); throw new RuntimeException('Manual check failed to redirect.'); }
catch (MaintenanceRedirect $redirect) { same('https://example.test/wp-admin/plugins.php?wphm-update-checked=1', $redirect->getMessage(), 'manual check redirects before HTML output'); }
same('wphm_check_updates', $GLOBALS['checked_nonce'], 'nonce action matches row link');
same(['fresh' => true, 'version' => '0.9.2'], get_transient('wphm_update_check_1'), 'manual result belongs to current user');
same(300, $GLOBALS['user_cache']['wphm_update_check_1']['ttl'], 'result expires after five minutes');
same(true, strpos(wphm_update_check_message(['fresh' => true, 'version' => '0.9.2']), 'v0.9.2への更新があります') !== false, 'fresh newer version is announced');
same(true, strpos(wphm_update_check_message(['fresh' => true, 'version' => '0.9.0']), '利用可能な更新はありません') !== false, 'older server version cannot request downgrade');
$GLOBALS['response'] = new WP_Error('timeout');
same(['fresh' => false, 'version' => '0.9.2'], wphm_check_updates_now(), 'failed manual check is distinguishable from last good data');
same(true, strpos(wphm_update_check_message(['fresh' => false, 'version' => '0.9.2']), '以前取得した情報') !== false, 'fallback is not described as a fresh success');
$GLOBALS['cache'] = [];
same(['fresh' => false, 'version' => ''], wphm_check_updates_now(), 'failed check without prior data reports failure');
same(true, strpos(wphm_update_check_message(['fresh' => false, 'version' => '']), '取得できません') !== false, 'failure without fallback gives retry guidance');
$_GET = ['wphm-update-checked' => '1']; $pagenow = 'plugins.php'; $GLOBALS['user_id'] = 2;
ob_start(); wphm_update_check_notice(); $notice = ob_get_clean();
same('', $notice, 'another user cannot consume the result');
$GLOBALS['user_id'] = 1;
ob_start(); wphm_update_check_notice(); $notice = ob_get_clean();
same(true, strpos($notice, 'notice-success') !== false, 'fresh result shown on plugin page');
same(false, get_transient('wphm_update_check_1'), 'notice consumed once');
ob_start(); wphm_update_check_notice(); $notice = ob_get_clean();
same('', $notice, 'notice is not replayed after consumption');
$GLOBALS['network_admin'] = true; $GLOBALS['multisite'] = true;
same(true, strpos(wphm_update_check_action_link([])['wphm_check_updates'], 'wphm-network=1') !== false, 'network plugin list preserves its return location');
$_GET = ['wphm-network' => '1'];
try { wphm_handle_update_check(); throw new RuntimeException('Network check failed to redirect.'); }
catch (MaintenanceRedirect $redirect) { same('https://example.test/wp-admin/network/plugins.php?wphm-update-checked=1', $redirect->getMessage(), 'network administrator returns to network plugin list'); }
$GLOBALS['can_network'] = false;
try { wphm_handle_update_check(); throw new RuntimeException('Site check failed to redirect.'); }
catch (MaintenanceRedirect $redirect) { same('https://example.test/wp-admin/plugins.php?wphm-update-checked=1', $redirect->getMessage(), 'network flag cannot redirect an unauthorized user into network administration'); }
$GLOBALS['network_admin'] = false; $GLOBALS['multisite'] = false;
$_GET = [];
echo "PASS: {$checks} maintenance assertions (CSV, dates/DST, bcrypt, pre-render hooks, final status, updater).\n";
