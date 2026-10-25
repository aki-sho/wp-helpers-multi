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
function get_current_user_id() { return 1; }
function current_user_can($capability) { return true; }
function add_menu_page(...$args) { return 'wphm-main'; }
function add_submenu_page(...$args) { return 'wphm-page-' . $args[4]; }
class MaintenanceDatabase {
    public $prefix = 'test_'; public $rows = [];
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
echo "PASS: {$checks} maintenance assertions (CSV, dates/DST, bcrypt, pre-render hooks, final status, updater).\n";
