<?php
// Isolated fixture setup/check/cleanup. Requires copied WordPress core and a disposable MariaDB on 33077.
if (PHP_SAPI !== 'cli') exit;
$workspace = dirname(__DIR__);
$temporary = $workspace . '/.wphm-test';
$core = $temporary . '/wordpress/';
$password = trim(file_get_contents($temporary . '/db-password.txt'));
$mode = $argv[1] ?? 'check';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
if ($mode === 'cleanup') {
    $runtime = json_decode(file_get_contents($temporary . '/maintenance-runtime.json'), true);
    if (!preg_match('/^wphm_maint_test_[a-f0-9]{12}$/D', $runtime['database'])) throw new RuntimeException('Unexpected test database.');
    $db = new mysqli('127.0.0.1', 'root', $password, '', 33077);
    $db->query('DROP DATABASE `' . $runtime['database'] . '`');
    $db->close(); echo "Disposable test database removed.\n"; exit;
}
if ($mode === 'setup') {
    $database = 'wphm_maint_test_' . bin2hex(random_bytes(6));
    $db = new mysqli('127.0.0.1', 'root', $password, '', 33077);
    $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $db->close();
    $config = "<?php\n";
    $constants = [
        'DB_NAME' => $database, 'DB_USER' => 'root', 'DB_PASSWORD' => $password, 'DB_HOST' => '127.0.0.1:33077', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '',
        'WP_CONTENT_DIR' => $core . 'wp-content', 'WP_CONTENT_URL' => 'http://127.0.0.1:8766/wp-content',
        'WP_HOME' => 'http://127.0.0.1:8766', 'WP_SITEURL' => 'http://127.0.0.1:8766', 'WP_ENVIRONMENT_TYPE' => 'local',
        'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false, 'WP_DEBUG_LOG' => $temporary . '/maintenance-debug.log',
        'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true,
    ];
    foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $name) $constants[$name] = bin2hex(random_bytes(24));
    foreach ($constants as $name => $value) $config .= 'define(' . var_export($name, true) . ', ' . var_export($value, true) . ");\n";
    $config .= "if (!defined('WP_ADMIN')) define('WP_ADMIN', true);\n\$table_prefix = 'wphmmaint_';\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
    file_put_contents($core . 'wp-config.php', $config);
    foreach (['plugins', 'themes', 'mu-plugins', 'uploads'] as $directory) if (!is_dir($core . 'wp-content/' . $directory)) mkdir($core . 'wp-content/' . $directory, 0777, true);
    file_put_contents($temporary . '/update-fixture.json', json_encode(['version' => '0.9.2', 'download_url' => 'https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip']));
    $fixture_code = <<<'PHP'
<?php
add_filter('pre_wp_mail', '__return_true');
add_filter('flush_rewrite_rules_hard', '__return_false');
add_filter('pre_http_request', function ($response, $args, $url) {
    if ($url === 'https://plugin.pretty-cute.info/wp-helpers-multi/update.json') {
        $GLOBALS['maintenance_update_requests'] = ($GLOBALS['maintenance_update_requests'] ?? 0) + 1;
        $fixture = json_decode(file_get_contents(dirname(ABSPATH) . '/update-fixture.json'), true);
        if (isset($fixture['error'])) return new WP_Error('fixture_timeout', 'Simulated unavailable update server');
        return ['response' => ['code' => 200], 'body' => json_encode($fixture), 'headers' => []];
    }
    if (substr($url, -12) === '/sitemap.xml') return ['response' => ['code' => 200], 'body' => '<?xml version="1.0"?><urlset><url><loc>http://127.0.0.1:8766/?p=1</loc></url></urlset>', 'headers' => []];
    return new WP_Error('isolated_test', 'External requests disabled in tests');
}, 10, 3);
PHP;
    file_put_contents($core . 'wp-content/mu-plugins/isolated-fixture.php', $fixture_code);
    define('WP_INSTALLING', true);
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8766'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $core . 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
if ($mode === 'setup') {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $installed = wp_install('WP Helpers maintenance test', 'testadmin', 'test@example.invalid', false, '', bin2hex(random_bytes(12)));
    wp_installing(false); wp_set_current_user((int) $installed['user_id']);
    require WP_CONTENT_DIR . '/plugins/wp-helpers-multi/includes/settings.php';
    $states = array_fill_keys(array_keys(wphm_get_tool_definitions()), 0);
    foreach (['access_log', 'comment_manager', 'link_inspector', 'post_data', 'bcrypt'] as $tool) $states[$tool] = 1;
    update_option('wphm_tool_states', $states);
    update_option('active_plugins', ['wp-helpers-multi/wp-helpers-multi.php']);
    update_option('timezone_string', 'Asia/Tokyo');
    require WP_CONTENT_DIR . '/plugins/wp-helpers-multi/wp-helpers-multi.php';
    wphm_access_log_maybe_update_schema(); wphm_ip_blocklist_maybe_update_schema();
    $post = wp_insert_post(['post_title' => '=1+1', 'post_content' => 'maintenance fixture', 'post_type' => 'post', 'post_status' => 'publish']);
    $comment = wp_insert_comment(['comment_post_ID' => $post, 'comment_author' => 'Fixture', 'comment_content' => 'Test comment', 'comment_approved' => '0', 'comment_author_IP' => '192.0.2.5']);
    wphm_access_log_repo_insert(['created_at' => strtotime('2026-10-25 00:00:00 UTC'), 'method' => 'GET', 'status' => 200, 'url' => 'https://example.test/article/', 'path' => '/fixture/', 'referrer' => '=1+1', 'ip' => '192.0.2.10', 'user_id' => 0, 'user_agent' => '@fixture']);
    $delete_log = (int) $wpdb->insert_id;
    wphm_access_log_repo_insert(['created_at' => strtotime('2026-10-25 00:00:00 UTC'), 'method' => 'GET', 'status' => 404, 'url' => 'https://example.test/retained/', 'path' => '/retained/', 'referrer' => '=1+1', 'ip' => '192.0.2.11', 'user_id' => 0, 'user_agent' => '@fixture']);
    $nonces = [];
    foreach (['wphm_access_log_action', 'wphm_comment_manager_action', 'wphm_link_scan', 'fv_export_list_download', 'wphm_bcrypt'] as $action) $nonces[$action] = wp_create_nonce($action);
    $runtime = ['database' => $database, 'key' => bin2hex(random_bytes(24)), 'user' => (int) $installed['user_id'], 'post' => $post, 'comment' => $comment, 'delete_log' => $delete_log, 'nonces' => $nonces];
    file_put_contents($temporary . '/maintenance-runtime.json', json_encode($runtime));
    echo "READY: isolated WordPress database and HTTP fixtures.\n"; exit;
}
$runtime = json_decode(file_get_contents($temporary . '/maintenance-runtime.json'), true); wp_set_current_user($runtime['user']);
$checks = 0;
function same($expected, $actual, $label) { global $checks; $checks++; if ($expected !== $actual) throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
same('0.9.1', WPHM_VERSION, 'patch version');
same('2026-10-25 12:34', fv_export_list_format_datetime('2026-10-25 12:34:56'), 'real WP local post date');
same('2026-10-25 09:00:00', wphm_format_timestamp(strtotime('2026-10-25 00:00:00 UTC')), 'real WP epoch display');
same(strtotime('2026-10-24 15:00:00 UTC'), wphm_access_log_date_to_ts('2026-10-25'), 'real WP local date filter');
update_option('timezone_string', 'America/New_York');
same(23 * 3600, wphm_access_log_date_to_ts('2026-03-08', true) - wphm_access_log_date_to_ts('2026-03-08') + 1, 'real WP DST start');
same(25 * 3600, wphm_access_log_date_to_ts('2026-11-01', true) - wphm_access_log_date_to_ts('2026-11-01') + 1, 'real WP DST end');
update_option('timezone_string', 'Asia/Tokyo');
wphm_register_admin_menu();
foreach (['access_log' => 'wphm_access_log_handle_request', 'comment_manager' => 'wphm_comment_manager_handle_request', 'link_inspector' => 'wphm_load_link_inspector'] as $tool => $callback) {
    $definition = wphm_get_tool_definitions()[$tool];
    $hook = get_plugin_page_hookname($definition['slug'], 'wp-helpers-multi');
    same(10, has_action('load-' . $hook, $callback), 'real pre-render hook: ' . $tool);
}
delete_site_transient('wphm_update_metadata_v1'); delete_site_transient('wphm_update_last_good_v1'); $GLOBALS['maintenance_update_requests'] = 0;
$meta = ['version' => '0.9.2', 'download_url' => 'https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip', 'sections' => ['changelog' => '<script>bad</script><p>更新</p>']];
file_put_contents($temporary . '/update-fixture.json', json_encode($meta));
same('0.9.2', wphm_get_update_metadata()['version'], 'metadata fetched via WP HTTP API');
same(false, strpos(wphm_get_update_metadata()['sections']['changelog'], '<script>') !== false, 'server HTML sanitized by WordPress');
same('0.9.2', wphm_filter_plugin_information(false, 'plugin_information', (object) ['slug' => 'wp-helpers-multi'])->version, 'real plugin details');
same(1, $GLOBALS['maintenance_update_requests'], 'real site transient prevents repeated HTTP');
$plugin = plugin_basename(WPHM_PLUGIN_FILE);
$list = wphm_filter_plugin_updates((object) ['response' => [], 'no_update' => [$plugin => (object) []]]);
same('0.9.2', $list->response[$plugin]->new_version, 'real update entry');
same(false, isset($list->no_update[$plugin]), 'opposite cached entry removed');
same('0.9.2', apply_filters('update_plugins_plugin.pretty-cute.info', false, [], $plugin, [])['version'], 'core Update URI filter');
delete_site_transient('wphm_update_metadata_v1'); file_put_contents($temporary . '/update-fixture.json', json_encode(['error' => true]));
same('0.9.2', wphm_get_update_metadata()['version'], 'real HTTP failure uses last good metadata');
same('0.9.2', wphm_get_update_metadata()['version'], 'real HTTP failure cached');
same(2, $GLOBALS['maintenance_update_requests'], 'real failure retry throttled');
delete_site_transient('wphm_update_metadata_v1'); $meta['version'] = '0.9.1'; file_put_contents($temporary . '/update-fixture.json', json_encode($meta));
$list = wphm_filter_plugin_updates($list);
same(false, isset($list->response[$plugin]), 'real stale notice cleared');
same('0.9.1', $list->no_update[$plugin]->new_version, 'real current version retained');
$status_map = [];
foreach ($wpdb->get_results('SELECT path,status FROM ' . wphm_access_log_table_name(), ARRAY_A) as $row) if (strpos($row['path'], '/front/') === 0) $status_map[$row['path']][] = (int) $row['status'];
foreach (['ok' => 200, 'redirect' => 301, 'temporary' => 302, 'missing' => 404, 'blocked' => 403, 'error' => 500, 'fatal' => 500, 'double' => 200] as $path => $code) same([$code], $status_map['/front/' . $path] ?? [], 'recorded real HTTP status: ' . $path);
same('1', get_comment($runtime['comment'])->comment_approved, 'comment action executed');
same(null, $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wphm_access_log_table_name() . ' WHERE id=%d', $runtime['delete_log'])), 'selected log deleted');
same(false, get_option('wphm_link_inspector_state'), 'link reset executed');
echo "PASS: {$checks} native WordPress maintenance assertions.\n";
