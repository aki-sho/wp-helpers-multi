<?php
/** Passive checker integration against disposable WordPress/MariaDB; all HTTP is fixture data. */
if (PHP_SAPI !== 'cli') exit;
set_exception_handler(function (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1); });
$root = dirname(__DIR__); $mode = $argv[1] ?? 'main';
$password = trim(file_get_contents($root . '/.wphm-test/db-password.txt'));
$database = $mode==='network-setup' ? ($argv[2] ?? '') : 'wphm_sc_test_' . bin2hex(random_bytes(6));
if (!preg_match('/^wphm_sc_test_[a-f0-9]{12}$/D',$database)) throw new RuntimeException('Invalid disposable database');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli('127.0.0.1', 'root', $password, '', 33077);
if ($mode!=='network-setup') $connection->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$checks = 0;
function sc_same($expected, $actual, string $label): void {
    global $checks; $checks++;
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true));
}
function sc_limiter_evidence(array &$facts, ?array $limiter): void {
    $facts['identity']['limiter']=$limiter;
    $limits=$limiter===null?[]:[$limiter+['source'=>'wordpress','enabled'=>true,'applied'=>true,'period'=>300,'routes'=>['wp_login']]];
    $facts['identity']['limiters']=$limits;
    if(isset($facts['acquisition'])) $facts['acquisition']['sources']['wordpress']['login_limits']=$limits;
}
class SecurityCheckExit extends Exception {}
try {
    if ($mode==='multisite') {
        $command=escapeshellarg(PHP_BINARY).' -n -d extension_dir='.escapeshellarg(ini_get('extension_dir')).' -d extension=mysqli -d extension=mbstring -d extension=openssl -d extension=curl '.escapeshellarg(__FILE__).' network-setup '.escapeshellarg($database);
        passthru($command,$status); sc_same(0,$status,'native network database initialized');
        define('MULTISITE',true); define('SUBDOMAIN_INSTALL',false);
        define('DOMAIN_CURRENT_SITE','example.invalid'); define('PATH_CURRENT_SITE','/'); define('SITE_ID_CURRENT_SITE',1); define('BLOG_ID_CURRENT_SITE',1);
    }
    define('ABSPATH', getenv('WPHM_TEST_WORDPRESS') ?: $root . '/.wphm-test/wordpress/');
    foreach (['DB_NAME' => $database, 'DB_USER' => 'root', 'DB_PASSWORD' => $password, 'DB_HOST' => '127.0.0.1:33077', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_CONTENT_DIR' => $root . '/.wphm-test/security-check-content-' . substr($database,-12), 'WP_CONTENT_URL' => 'https://example.invalid/wp-content', 'WP_HOME' => 'https://example.invalid', 'WP_SITEURL' => 'https://example.invalid', 'WP_ADMIN' => $mode !== 'front', 'WP_INSTALLING' => true, 'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false, 'WP_DEBUG_LOG' => $root . '/.wphm-test/security-check-debug.log', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true] as $key => $value) define($key, $value);
    foreach (['plugins', 'themes', 'mu-plugins', 'uploads'] as $directory) if (!is_dir(WP_CONTENT_DIR . '/' . $directory)) mkdir(WP_CONTENT_DIR . '/' . $directory, 0777, true);
    file_put_contents(WP_CONTENT_DIR . '/plugins/sc-fixture.php', "<?php\n/* Plugin Name: Security Check Fixture\nVersion: 1.0\nPlugin URI: https://example.invalid/plugin/\n*/\n");
    file_put_contents(WP_CONTENT_DIR . '/plugins/private-fixture.php', "<?php\n/* Plugin Name: Private Fixture\nVersion: 1.0\nUpdate URI: https://private.invalid/\n*/\n");
    if (!is_dir(WP_CONTENT_DIR . '/themes/sc-fixture')) mkdir(WP_CONTENT_DIR . '/themes/sc-fixture', 0777, true);
    file_put_contents(WP_CONTENT_DIR . '/themes/sc-fixture/style.css', "/* Theme Name: Security Check Theme\nVersion: 1.0\n*/\n");
    file_put_contents(WP_CONTENT_DIR . '/themes/sc-fixture/index.php', "<?php // Inert fixture.\n");
    $table_prefix = 'wphmsc_';
    $_SERVER = ['HTTP_HOST' => 'example.invalid', 'HTTPS' => 'on', 'REQUEST_URI' => '/wp-admin/', 'PHP_SELF' => '/wp-admin/admin.php', 'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '192.0.2.1'];
    require ABSPATH . 'wp-settings.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    require ABSPATH . 'wp-admin/includes/admin.php';
    add_filter('pre_wp_mail', '__return_true'); add_filter('flush_rewrite_rules_hard', '__return_false');
    $calls = []; $api_mode = 'current';
    add_filter('pre_http_request', function ($pre, $args, $url) use (&$calls, &$api_mode, &$wp_version) {
        $calls[] = ['url' => $url, 'args' => $args];
        if ($api_mode === 'failure') return new WP_Error('fixture_tls', 'Certificate verification failed');
        if ($api_mode === 'invalid') return ['response' => ['code' => 200], 'headers' => [], 'body' => 'invalid JSON'];
        if ($api_mode === 'oversize') return ['response' => ['code' => 200], 'headers' => [], 'body' => str_repeat('a', 1048577)];
        if ($api_mode === 'redirect') return ['response' => ['code' => 302], 'headers' => ['location' => 'http://127.0.0.1/private'], 'body' => ''];
        $data = [];
        if (strpos($url,'www.wpvulnerability.net/') !== false) {
            $parts=explode('/',trim((string)wp_parse_url($url,PHP_URL_PATH),'/'));
            $data=['error'=>0,'data'=>[$parts[0]=>$parts[1],'vulnerability'=>[]]];
        }
        if (strpos($url, '/stable-check/') !== false) $data = [$wp_version => $api_mode === 'insecure' ? 'insecure' : ($api_mode === 'updates' ? 'outdated' : 'latest')];
        if (strpos($url, '/serve-happy/') !== false) $data = ['is_secure' => $api_mode !== 'old_php', 'is_supported' => true];
        if (strpos($url, '/plugins/update-check/') !== false) $data = ['plugins' => $api_mode === 'updates' ? ['sc-fixture.php' => ['new_version' => '2.0']] : [], 'no_update' => ['sc-fixture.php' => ['new_version' => '1.0']]];
        if (strpos($url, '/themes/update-check/') !== false) $data = ['themes' => [], 'no_update' => ['sc-fixture' => ['new_version' => '1.0']]];
        return ['response' => ['code' => 200], 'headers' => ['content-type' => 'application/json'], 'body' => wp_json_encode($data)];
    }, 10, 3);
    $installed = $mode==='multisite' ? ['user_id'=>1] : wp_install('Passive checker fixture', 'fixtureadmin', 'fixture@example.invalid', false, '', bin2hex(random_bytes(12)));
    wp_installing(false); wp_set_current_user((int)$installed['user_id']);
    if ($mode==='network-setup') {
        foreach($wpdb->tables('ms_global') as $name=>$table) $wpdb->$name=$table;
        install_network();
        populate_network(1,'example.invalid','fixture@example.invalid','Passive checker network','/',false);
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->sitemeta)))!==$wpdb->sitemeta) throw new RuntimeException('Network tables missing');
        echo "Native WordPress network installed.\n";
    } else {
    update_option('timezone_string', 'Asia/Tokyo');
    update_option('stylesheet', 'sc-fixture'); update_option('template', 'sc-fixture');
    if ($mode === 'disabled') update_option('wphm_tool_states', ['security_check' => false]);
    require $root . '/wp-helpers-multi.php';
    if ($mode === 'multisite') {
        require __DIR__ . '/security-check-multisite.php';
        echo "PASS: {$checks} native multisite security-check assertions.\n";
    } elseif ($mode === 'twenty') {
        require __DIR__ . '/security-check-twenty.php';
        require __DIR__ . '/security-check-guidance.php';
        echo "PASS: {$checks} native twenty-item security assertions.\n";
    } elseif ($mode !== 'main') {
        sc_same(false, function_exists('wphm_sc_collect'), $mode . ': checker module not loaded');
        sc_same(false, has_action('wp_ajax_wphm_security_check'), $mode . ': no AJAX hook');
        sc_same(true, function_exists('wphm_access_log_build_row'), $mode . ': existing log module still loaded');
        if ($mode==='front') {
            sc_same(true,function_exists('wphm_sc_observe_request'),'enabled lightweight observation module available on front');
            sc_same(false,wphm_sc_telemetry_schema(),'front request cannot create counter schema');
            sc_same(null,$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'wphm_security_counts'))),'front request leaves counter schema absent');
        } else {
            sc_same(false,function_exists('wphm_sc_observe_request'),'disabled checker has no observation module');
            sc_same(false,has_action('wp_login_failed','wphm_sc_login_failed'),'disabled checker registers no authentication observer');
        }
        echo "PASS: {$checks} {$mode} isolation assertions.\n";
    } else {
        sc_same(true, wphm_is_tool_enabled('security_check'), 'enabled by default');
        sc_same('wphm-security-check', wphm_get_tool_definitions()['security_check']['slug'], 'dashboard and menu definition');
        $calls = [];
        $empty = wphm_sc_log_facts();
        sc_same(false, $empty['available'], 'missing log table is unknown');
        sc_same(null, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'wphm_access_log'))), 'checker never creates a log table');
        wphm_access_log_maybe_update_schema();
        $table = wphm_access_log_table_name();
        $paths = ['/normal/', '/union%20select/', '/%3Cscript%3E/', '/%252e%252e%252fprivate'];
        foreach ($paths as $i => $path) $wpdb->insert($table, ['created_at' => time() - $i, 'method' => 'GET', 'status' => $i === 0 ? 503 : 200, 'url' => 'https://example.invalid/x?private_secret=secret', 'path' => $path, 'referrer' => 'https://secret.invalid/', 'ip' => '198.51.100.50', 'user_id' => 0, 'user_agent' => '<script>untrusted</script>']);
        $log_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $logs = wphm_sc_log_facts();
        foreach (['sql_paths' => 1, 'html_paths' => 1, 'traversal_paths' => 1, 'errors' => 1, 'sampled' => 4] as $key => $expected) sc_same($expected, $logs[$key], 'bounded log observation: ' . $key);
        sc_same(0, count($calls), 'local log check never sends HTTP');
        $local = wphm_sc_collect();
        sc_same(0, count($calls), 'initial check uses saved update data without HTTP');
        sc_same(20, count($local['items']), 'all twenty checks represented');
        $items = array_column($local['items'], null, 'id');
        foreach (['brute_force', 'credential_stuffing', 'ddos'] as $key) sc_same('unknown', $items[$key]['level'], 'insufficient evidence never guessed: ' . $key);
        foreach (['sql_injection','xss','csrf','directory_traversal'] as $key) sc_same('low',$items[$key]['level'],'low is scoped to the exact reviewed plugin files: '.$key);
        sc_same('medium', $items['rce']['level'], 'current admin code-edit permission is a scoped hardening concern');
        sc_same('low', $items['file_upload']['level'], 'confirmed standard type and size restrictions have explicitly limited low scope');
        sc_same('unknown', $items['vulnerability_exploit']['level'], 'missing update data never considered safe');
        $serialized = wp_json_encode($local);
        foreach (['private_secret', 'secret.invalid', '198.51.100.50', '<script>untrusted'] as $secret) sc_same(false, strpos($serialized, $secret) !== false, 'report contains aggregates only');
        sc_same($log_count, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"), 'scan does not change existing logs');
        $cached = (object)['last_checked' => time(), 'checked' => ['sc-fixture.php' => '1.0'], 'response' => ['sc-fixture.php' => (object)['new_version' => '2.0']]];
        set_site_transient('update_plugins', $cached);
        set_site_transient('update_core', (object)['last_checked' => time(), 'version_checked' => $wp_version, 'updates' => [(object)['response' => 'latest', 'current' => $wp_version]]]);
        set_site_transient('update_themes', (object)['last_checked' => time(), 'checked' => ['sc-fixture' => '1.0'], 'no_update' => ['sc-fixture' => (object)['new_version' => '1.0']]]);
        $core_before = serialize(wphm_sc_saved_site_transient('update_core')); $themes_before = serialize(wphm_sc_saved_site_transient('update_themes'));
        $before_updates = serialize(get_site_transient('update_plugins'));
        $calls = [];
        wphm_sc_collect();
        sc_same(0, count($calls), 'reading populated update data bypasses updater filters and HTTP');
        $fresh = wphm_sc_collect(true); $fresh_items = array_column($fresh['items'], null, 'id');
        sc_same('unknown', $fresh_items['vulnerability_exploit']['level'], 'unsupported private products keep the complete checklist unconfirmed');
        sc_same(0, $fresh['facts']['updates']['plugins']['pending'], 'fresh API result used instead of previous update flag');
        sc_same(1, $fresh['facts']['updates']['plugins']['unknown'], 'private update provider remains unverified');
        sc_same($before_updates, serialize(get_site_transient('update_plugins')), 'standard WordPress update notifications untouched');
        sc_same($core_before, serialize(wphm_sc_saved_site_transient('update_core')), 'core update notification untouched');
        sc_same($themes_before, serialize(wphm_sc_saved_site_transient('update_themes')), 'theme update notification untouched');
        $wp_calls=array_values(array_filter($calls,function($call){return wp_parse_url($call['url'],PHP_URL_HOST)==='api.wordpress.org';}));
        sc_same(4, count($wp_calls), 'recheck uses four fixed WordPress metadata endpoints');
        foreach ($wp_calls as $call) {
            sc_same('api.wordpress.org', wp_parse_url($call['url'], PHP_URL_HOST), 'no site scanning or arbitrary target');
            sc_same(0, $call['args']['redirection'], 'metadata redirects never followed');
            sc_same(3, $call['args']['timeout'], 'metadata request timeout bounded');
            sc_same(1048577, $call['args']['limit_response_size'], 'metadata response bounded');
            sc_same(true, $call['args']['reject_unsafe_urls'], 'safe WordPress HTTP API');
            sc_same([], $call['args']['cookies'], 'no logged-in cookies sent');
            sc_same(false, strpos(wp_json_encode($call['args']), 'private_secret') !== false, 'no access log content sent');
            sc_same(false, strpos(wp_json_encode($call['args']), 'private-fixture') !== false, 'custom-provider plugin is not sent to WordPress.org');
            sc_same(false, strpos($call['args']['user-agent'], 'example.invalid') !== false, 'site URL not put in API user agent');
            sc_same(false, strpos(wp_json_encode($call['args']), 'example.invalid') !== false, 'site-hosted metadata URI not sent');
        }
        $inventory = wphm_sc_inventory();
        $stale_group = wphm_sc_update_group($inventory['plugins'], ['checked' => ['sc-fixture.php' => '0.1'], 'no_update' => ['sc-fixture.php' => ['new_version' => '1.0']]], 'response', time(), 'cached');
        sc_same(2, $stale_group['unknown'], 'installed-version mismatch invalidates saved status');
        sc_same(2, wphm_sc_update_group($inventory['plugins'], ['no_update' => ['sc-fixture.php' => ['new_version' => '1.0']]], 'response', time() - 2 * DAY_IN_SECONDS, 'cached')['unknown'], 'old metadata unverified');
        sc_same(2, wphm_sc_update_group($inventory['plugins'], ['checked' => (object)['x' => 1], 'no_update' => ['sc-fixture.php' => ['new_version' => '1.0']]], 'response', time(), 'cached')['unknown'], 'malformed cache shape does not crash or claim safety');
        $api_mode = 'updates'; $updated = wphm_sc_collect(true);
        sc_same('medium', array_column($updated['items'], 'level', 'id')['vulnerability_exploit'], 'confirmed update waiting is medium, not a claimed CVE');
        $api_mode = 'insecure';
        sc_same('high', array_column(wphm_sc_collect(true)['items'], 'level', 'id')['vulnerability_exploit'], 'official insecure core status raises high');
        $api_mode = 'old_php';
        sc_same('high', array_column(wphm_sc_collect(true)['items'], 'level', 'id')['rce'], 'upstream PHP support warning raises high');
        foreach (['failure', 'invalid', 'oversize', 'redirect'] as $failure) {
            delete_option('wphm_sc_vulnerability_metadata');
            $api_mode = $failure; $failed = wphm_sc_collect(true);
            sc_same('unknown', array_column($failed['items'], 'level', 'id')['vulnerability_exploit'], 'failed remote check is unknown: ' . $failure);
            sc_same(false, $failed['facts']['updates']['php']['known'], 'PHP support unverified on API failure: ' . $failure);
            sc_same(2, $failed['facts']['updates']['plugins']['unknown'], 'all plugin metadata unverified on failure: ' . $failure);
        }
        $api_mode = 'current'; $facts = $fresh['facts'];
        $facts['file_uploads'] = false;
        sc_same('low', array_column(wphm_sc_assess($facts), 'level', 'id')['file_upload'], 'low only for explicitly disabled PHP standard receive route');
        $facts['file_uploads'] = true; $facts['unfiltered_uploads'] = true;
        sc_same('medium', array_column(wphm_sc_assess($facts), 'level', 'id')['file_upload'], 'unfiltered uploads enabled is a scoped concern');
        $facts['registration'] = true; $facts['raw_upload'] = true; $facts['raw_html'] = true;
        $dangerous = array_column(wphm_sc_assess($facts), null, 'id');
        sc_same('high', $dangerous['file_upload']['level'], 'public registration with unfiltered upload role is high');
        sc_same('high', $dangerous['xss']['level'], 'public registration with raw HTML edit role is high');
        update_option('users_can_register', 1); update_option('default_role', 'administrator');
        sc_same(true, wphm_sc_collect()['facts']['raw_html'], 'role facts come from actual WordPress capabilities');
        sc_same('high', array_column(wphm_sc_collect()['items'], 'level', 'id')['xss'], 'actual public privileged registration detected');
        update_option('users_can_register', 0); update_option('default_role', 'subscriber');
        $facts['file_uploads'] = false;
        $fixture = ['checked_at' => time(), 'refreshed' => true, 'facts' => $facts, 'items' => wphm_sc_assess($facts)];
        $escape = $fixture; $escape['items'][0]['checks'][0]['status']='ok'; $escape['items'][0]['checks'][0]['value'] = '<img src=x onerror=alert(1)>';
        ob_start(); wphm_sc_report_html($escape); $html = ob_get_clean();
        sc_same(false, strpos($html, '<img src=x') !== false, 'fact text HTML escaped');
        sc_same(true, strpos($html, '&lt;img') !== false, 'escaped fact displayed as text');
        $_GET = ['page' => 'wphm-security-check'];
        $GLOBALS['wphm_sc_page_report'] = $fixture;
        ob_start(); wphm_sc_render(); $page = ob_get_clean();
        file_put_contents($root . '/.wphm-test/security-check-page.html', $page);
        ob_start(); wphm_render_dashboard(); $dashboard = ob_get_clean();
        sc_same(true, strpos($dashboard, 'wphm_tools[security_check]') !== false, 'dashboard toggle rendered');
        sc_same(20, substr_count($page, 'data-check='), 'twenty rows rendered');
        foreach (array_keys(wphm_sc_levels()) as $level) sc_same(true, strpos($page, 'wphm-sc-' . $level) !== false, 'four states supported by UI');
        $calls = []; delete_transient('wphm_security_check_' . WPHM_VERSION . '_' . get_current_user_id());
        wphm_sc_report(); wphm_sc_report();
        sc_same(0, count($calls), 'viewing cached report performs no HTTP');
        $observer = wp_create_user('scobserver', bin2hex(random_bytes(12)), 'observer@example.invalid');
        $observer_user = new WP_User($observer); $observer_user->set_role('administrator'); $observer_user->add_cap('edit_plugins', false); $observer_user->add_cap('edit_themes', false);
        wp_set_current_user($observer);
        sc_same(false, wphm_sc_report()['facts']['file_edit_allowed'], 'cached current-user permission facts are isolated per administrator');
        wp_set_current_user((int)$installed['user_id']);
        sc_same('medium', array_column(wphm_sc_report()['items'], 'level', 'id')['rce'], 'original administrator retains own permission result');
        define('DOING_AJAX', true);
        add_filter('wp_die_ajax_handler', function () { return function () { throw new SecurityCheckExit(); }; });
        $ajax = function ($nonce, $method = 'POST') { $_SERVER['REQUEST_METHOD'] = $method; $_POST = $_REQUEST = ['nonce' => $nonce]; ob_start(); try { wphm_sc_ajax(); } catch (SecurityCheckExit $error) {} return json_decode(ob_get_clean(), true); };
        $nonce = wp_create_nonce('wphm_security_check');
        $reply = $ajax($nonce);
        sc_same(true, $reply['success'], 'valid AJAX recheck');
        sc_same(20, substr_count($reply['data']['html'], 'data-check='), 'AJAX response contains all results');
        file_put_contents($root . '/.wphm-test/security-check-ajax.json', wp_json_encode($reply));
        $before = count($calls); sc_same(null, $ajax('invalid'), 'invalid nonce denied');
        sc_same($before, count($calls), 'invalid nonce cannot initiate metadata requests');
        sc_same(false, $ajax($nonce, 'GET')['success'], 'GET cannot trigger a recheck');
        sc_same($before, count($calls), 'rejected GET does not send metadata requests');
        delete_option('wphm_sc_last_recheck');
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = $_REQUEST = ['nonce' => $nonce, 'wphm_sc_action' => 'recheck'];
        wphm_sc_handle_request();
        sc_same(true, $GLOBALS['wphm_sc_page_report']['refreshed'], 'native POST fallback rechecks without JavaScript');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $held_lease=wphm_sc_acquire_lease();
        sc_same(false, $ajax($nonce)['success'], 'concurrent scan rejected without modifying other feature locks');
        wphm_sc_release_lease($held_lease);
        $subscriber = wp_create_user('scsubscriber', bin2hex(random_bytes(12)), 'subscriber@example.invalid');
        wp_set_current_user($subscriber); $before = count($calls);
        sc_same(false, $ajax($nonce)['success'], 'subscriber denied');
        sc_same($before, count($calls), 'denied user cannot start check');
        wp_set_current_user((int)$installed['user_id']);
        $states = wphm_get_tool_states(); $states['security_check'] = false; update_option('wphm_tool_states', $states);
        sc_same(false, $ajax($nonce)['success'], 'disabled feature denies AJAX even when already loaded');
        sc_same($log_count, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"), 'all checks leave original log rows untouched');
        $states['security_check'] = true; update_option('wphm_tool_states', $states);
        $wpdb->query("UPDATE {$table} SET created_at=" . (time() - 2 * DAY_IN_SECONDS));
        sc_same(0, wphm_sc_log_facts()['sampled'], 'old log rows outside observation window');
        $seeds = [];
        for ($i = 0; $i < 2002; $i++) $seeds[] = $wpdb->prepare('(%d,%s,%s,%s,%s)', time(), 'GET', '', '/sample', '');
        $wpdb->query("INSERT INTO {$table} (created_at,method,url,path,referrer) VALUES " . implode(',', $seeds));
        $bounded = wphm_sc_log_facts();
        sc_same(2000, $bounded['sampled'], 'log observation hard limit'); sc_same(true, $bounded['limited'], 'partial observation disclosed');
        sc_same($before_updates, serialize(get_site_transient('update_plugins')), 'update cache remains unchanged after all checks');
        sc_same('', $wpdb->last_error, 'no database errors');
        require __DIR__ . '/security-check-extended.php';
        require __DIR__ . '/security-check-acquisition.php';
        require __DIR__ . '/security-check-followups.php';
        require __DIR__ . '/security-check-twenty.php';
        require __DIR__ . '/security-check-guidance.php';
        echo "PASS: {$checks} native WordPress security-check assertions.\n";
    }
    }
} finally { if ($mode!=='network-setup') $connection->query("DROP DATABASE `{$database}`"); $connection->close(); }
