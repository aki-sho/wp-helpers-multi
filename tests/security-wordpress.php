<?php
/** Native WordPress/MariaDB regressions in a newly created, disposable database. */
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$core = $root . '/.wphm-test/wordpress/';
$password = trim(file_get_contents($root . '/.wphm-test/db-password.txt'));
$database = 'wphm_security_test_' . bin2hex(random_bytes(6));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli('127.0.0.1', 'root', $password, '', 33077);
$connection->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$checks = 0;
function security_same($expected, $actual, string $label): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true));
}
try {
    define('ABSPATH', $core);
    foreach (['DB_NAME' => $database, 'DB_USER' => 'root', 'DB_PASSWORD' => $password, 'DB_HOST' => '127.0.0.1:33077', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_CONTENT_DIR' => $root . '/.wphm-test/security-content', 'WP_CONTENT_URL' => 'http://127.0.0.1:8767/wp-content', 'WP_HOME' => 'http://127.0.0.1:8767', 'WP_SITEURL' => 'http://127.0.0.1:8767', 'WP_ADMIN' => true, 'WP_INSTALLING' => true, 'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false, 'WP_DEBUG_LOG' => $root . '/.wphm-test/security-debug.log', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true] as $name => $value) define($name, $value);
    foreach (['plugins', 'themes', 'mu-plugins', 'uploads'] as $directory) if (!is_dir(WP_CONTENT_DIR . '/' . $directory)) mkdir(WP_CONTENT_DIR . '/' . $directory, 0777, true);
    $table_prefix = 'wphmsec_';
    $_SERVER = ['HTTP_HOST' => '127.0.0.1:8767', 'REQUEST_URI' => '/wp-admin/', 'PHP_SELF' => '/wp-admin/admin.php', 'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '192.0.2.1'];
    require ABSPATH . 'wp-settings.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    require ABSPATH . 'wp-admin/includes/admin.php';
    add_filter('pre_wp_mail', '__return_true');
    add_filter('flush_rewrite_rules_hard', '__return_false');
    $http = function ($pre, $args, $url) {
        $GLOBALS['security_http_calls'][] = ['url' => $url, 'args' => $args];
        return ($GLOBALS['security_http_response'])($url);
    };
    $GLOBALS['security_http_response'] = function () { return new WP_Error('isolated', 'No external traffic'); };
    add_filter('pre_http_request', $http, 10, 3);
    $installed = wp_install('Security fixture', 'securityadmin', 'fixture@example.invalid', false, '', bin2hex(random_bytes(12)));
    wp_installing(false);
    wp_set_current_user((int)$installed['user_id']);
    require $root . '/wp-helpers-multi.php';
    require $root . '/tools/bcrypt.php';
    require $root . '/tools/link_inspector.php';
    wphm_access_log_maybe_update_schema();
    wphm_ip_blocklist_maybe_update_schema();
    security_same('1.1.0', get_option('wphm_ip_blocklist_db_version'), 'IP schema upgraded');
    security_same(true, (bool)$wpdb->get_var("SHOW COLUMNS FROM " . wphm_ip_blocklist_table_name() . " LIKE 'expires_at'"), 'expiry column exists');

    $row = ['created_at' => time(), 'method' => 'GET', 'status' => 200, 'url' => 'https://user:pass@example.org/a?token=private#secret', 'path' => '/a', 'referrer' => 'https://example.org/from?unknown_secret=private', 'user_agent' => str_repeat('あ', 300), 'ip' => '192.0.2.50', 'user_id' => 0];
    wphm_access_log_repo_insert($row);
    $stored = $wpdb->get_row('SELECT * FROM ' . wphm_access_log_table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A);
    security_same('https://example.org/a', $stored['url'], 'URL strips userinfo/query/fragment before storage');
    security_same('https://example.org/from', $stored['referrer'], 'unknown secret keys removed');
    security_same(true, strlen($stored['user_agent']) <= 512 && preg_match('//u', $stored['user_agent']) === 1, 'UTF-8 byte bounds');
    $legacy = $row; $legacy['url'] = 'https://example.org/legacy?secret=old';
    $wpdb->insert(wphm_access_log_table_name(), $legacy);
    $legacy_id = $wpdb->insert_id;
    $visible = wphm_access_log_repo_get(['q' => 'legacy']);
    security_same('https://example.org/legacy', $visible['items'][0]['url'], 'legacy rows masked before rendering/export');
    wphm_access_log_maintenance();
    security_same('https://example.org/legacy', $wpdb->get_var($wpdb->prepare('SELECT url FROM ' . wphm_access_log_table_name() . ' WHERE id=%d', $legacy_id)), 'legacy stored secrets scrubbed');
    $wpdb->insert(wphm_access_log_table_name(), array_merge($row, ['created_at' => time() - 40 * DAY_IN_SECONDS]));
    $expired_id = $wpdb->insert_id;
    wphm_access_log_maintenance();
    security_same(null, $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wphm_access_log_table_name() . ' WHERE id=%d', $expired_id)), 'old logs purged');
    update_option('wphm_access_log_limits', ['rows' => 1000, 'per_minute' => 10, 'per_ip_minute' => 2]);
    security_same(true, wphm_access_log_take_budget('192.0.2.60'), 'first request admitted');
    security_same(true, wphm_access_log_take_budget('192.0.2.60'), 'second request admitted');
    security_same(false, wphm_access_log_take_budget('192.0.2.60'), 'per-IP rate denied');
    for ($i = 0; $i < 30; $i++) wphm_access_log_take_budget('192.0.2.' . ($i + 70));
    security_same(false, wphm_access_log_take_budget('198.51.100.2'), 'global rate denied');
    security_same(true, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'wphm_access_log_budget_%'") <= 65, 'counter storage bounded');
    $table = wphm_access_log_table_name();
    $wpdb->query("TRUNCATE TABLE {$table}");
    $seeds = [];
    for ($i = 0; $i < 1000; $i++) $seeds[] = $wpdb->prepare('(%d,%s,%d,%s,%s,%s,%s,%d,%s)', time(), 'GET', 200, 'https://example.org/seed', '/seed', '', '192.0.2.50', 0, 'fixture');
    $wpdb->query("INSERT INTO {$table} (created_at,method,status,url,path,referrer,ip,user_id,user_agent) VALUES " . implode(',', $seeds));
    for ($i = 0; $i < 10; $i++) wphm_access_log_repo_insert($row);
    security_same(1000, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}"), 'strict row cap across sequential inserts');
    security_same(true, $wpdb->last_error === '', 'no logging SQL errors');
    $lock = wphm_access_log_lock_name();
    $connection->query("SELECT GET_LOCK('{$lock}', 0)");
    $last = (int)$wpdb->get_var("SELECT MAX(id) FROM {$table}");
    wphm_access_log_repo_insert($row);
    security_same($last, (int)$wpdb->get_var("SELECT MAX(id) FROM {$table}"), 'concurrent writer skips instead of bypassing cap');
    $connection->query("SELECT RELEASE_LOCK('{$lock}')");

    security_same('', wphm_bcrypt_password_error(str_repeat('A', 72)), '72-byte input accepted');
    security_same(true, wphm_bcrypt_password_error(str_repeat('A', 73)) !== '', '73-byte input rejected');
    security_same(true, wphm_bcrypt_password_error(str_repeat('あ', 25)) !== '', 'multibyte byte limit');
    security_same(true, wphm_bcrypt_password_error('a' . chr(0) . 'b') !== '', 'NUL rejected');
    $fixture_hash = password_hash('fixture', PASSWORD_BCRYPT, ['cost' => 4]);
    security_same('', wphm_bcrypt_hash_error($fixture_hash), 'low-cost existing bcrypt can be verified');
    security_same(true, wphm_bcrypt_hash_error(substr_replace($fixture_hash, '31', 4, 2)) !== '', 'expensive hash rejected before verification');
    security_same(true, wphm_bcrypt_hash_error('$argon2id$v=19$m=999999,t=99999,p=1$fixture') !== '', 'other algorithms rejected');
    foreach ([['10', false, '10'], ['4', false, '10'], ['4', true, '04'], ['31', true, '12']] as $case) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['wphm_bcrypt_nonce' => wp_create_nonce('wphm_bcrypt'), 'mode' => 'hash', 'password' => 'fixture', 'cost' => $case[0], 'test_mode' => $case[1] ? '1' : '0'];
        ob_start(); wphm_render_bcrypt_tool_page(); $html = ob_get_clean();
        preg_match('/id="wphm_bcrypt_hash_out"[^>]*>([^<]*)</', $html, $m);
        security_same($case[2], substr($m[1], 4, 2), 'generation cost enforcement');
    }
    $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = [];

    foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '224.0.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '2001:db8::1', '2002:7f00:1::1'] as $ip) security_same(false, wphm_li_public_ip($ip), 'private/reserved destination denied: ' . $ip);
    security_same(true, wphm_li_public_ip('93.184.216.34'), 'public destination accepted');
    add_filter('home_url', function () { return 'https://93.184.216.34/'; });
    add_filter('site_url', function () { return 'https://93.184.216.34/'; });
    foreach (['https://93.184.216.35/a', 'https://user@93.184.216.34/a', 'file:///etc/passwd', 'https://93.184.216.34:8080/a', 'https://127.0.0.1/a'] as $url) security_same(false, wphm_li_allowed_url($url), 'unsafe URL denied');
    $reply = function ($body, $code = 200, $headers = []) { return ['response' => ['code' => $code], 'body' => $body, 'headers' => $headers]; };
    $GLOBALS['security_http_response'] = function () use ($reply) { return $reply('<urlset><url><loc>https://93.184.216.34/a</loc></url></urlset>'); };
    security_same(1, count(wphm_li_build_queue_from_sitemap('https://93.184.216.34/sitemap.xml')), 'safe sitemap accepted');
    $args = end($GLOBALS['security_http_calls'])['args'];
    security_same(0, $args['redirection'], 'automatic redirects disabled');
    security_same(WPHM_LI_MAX_BODY + 1, $args['limit_response_size'], 'HTTP body bounded');
    $before = count($GLOBALS['security_http_calls']);
    $GLOBALS['security_http_response'] = function () use ($reply) { return $reply('', 302, ['location' => 'http://127.0.0.1/private']); };
    security_same('', wphm_li_fetch_body('https://93.184.216.34/a', 3), 'private redirect denied');
    security_same($before + 1, count($GLOBALS['security_http_calls']), 'private redirect never fetched');
    $GLOBALS['security_http_response'] = function () use ($reply) { return $reply(str_repeat('A', WPHM_LI_MAX_BODY + 1)); };
    security_same('', wphm_li_fetch_body('https://93.184.216.34/a', 3), 'oversized response denied');
    $GLOBALS['security_http_response'] = function () use ($reply) { return $reply('compressed', 200, ['content-encoding' => 'gzip']); };
    security_same('', wphm_li_fetch_body('https://93.184.216.34/a', 3), 'compressed response denied');
    security_same([], wphm_li_parse_sitemap_xml('<!DOCTYPE x [<!ENTITY x "secret">]><urlset><loc>&x;</loc></urlset>'), 'DTD denied');
    security_same([], wphm_li_parse_sitemap_xml(''), 'empty XML rejected without exception');
    security_same(20000, count(wphm_li_parse_sitemap_xml('<urlset>' . str_repeat('<loc>x</loc>', 20001) . '</urlset>')), 'XML entry count bounded');
    $budget = ['started' => microtime(true) - 21, 'requests' => 0];
    security_same('', wphm_li_fetch_body('https://93.184.216.34/a', 3, $budget), 'elapsed deadline stops fetch');
    $budget = ['started' => microtime(true), 'requests' => 20];
    security_same('', wphm_li_fetch_body('https://93.184.216.34/a', 3, $budget), 'request limit stops fetch');
    $GLOBALS['security_http_response'] = function ($url) use ($reply) {
        if (strpos($url, '/index') !== false) { $xml = '<sitemapindex>'; for ($i = 0; $i < 50; $i++) $xml .= '<sitemap><loc>https://93.184.216.34/child' . $i . '</loc></sitemap>'; return $reply($xml . '</sitemapindex>'); }
        return $reply('<urlset><loc>https://93.184.216.34/article</loc></urlset>');
    };
    $before = count($GLOBALS['security_http_calls']); wphm_li_build_queue_from_sitemap('https://93.184.216.34/index');
    security_same(20, count($GLOBALS['security_http_calls']) - $before, 'nested sitemap fetch count bounded');

    $post = wp_insert_post(['post_title' => 'Security fixture', 'post_status' => 'publish']);
    $comment = wp_insert_comment(['comment_post_ID' => $post, 'comment_content' => 'fixture', 'comment_author_IP' => '192.0.2.99', 'comment_approved' => '0']);
    wphm_comment_manager_apply_bulk_action('spam', [$comment]);
    security_same(false, wphm_ip_blocklist_is_blocked('192.0.2.99'), 'spam alone never blocks IP');
    wp_unspam_comment($comment);
    wphm_comment_manager_prepare_block([$comment]);
    $review = $GLOBALS['wphm_comment_block_review'];
    security_same('0', (string)get_comment($comment)->comment_approved, 'preview makes no comment change');
    security_same(false, wphm_ip_blocklist_is_blocked('192.0.2.99'), 'preview does not block');
    ob_start(); wphm_comment_manager_render_block_review($review); $review_html = ob_get_clean();
    security_same(true, strpos($review_html, '192.0.2.99') !== false && strpos($review_html, 'review_token') !== false, 'review shows targets and confirmation token');
    file_put_contents($root . '/.wphm-test/security-review.html', $review_html);
    $_POST = ['review_token' => $review['token']];
    $confirmed = wphm_comment_manager_confirm_block();
    wphm_comment_manager_apply_bulk_action('spam_block', array_keys($confirmed['targets']), $confirmed['duration']);
    security_same(true, wphm_ip_blocklist_is_blocked('192.0.2.99'), 'confirmed spam operation blocks IP');
    $entry = wphm_ip_blocklist_get_entries(['192.0.2.99'])['192.0.2.99'];
    security_same(true, abs(strtotime($entry['expires_at'] . ' UTC') - time() - DAY_IN_SECONDS) < 5, 'new blocks expire after 24h');
    $wpdb->update(wphm_ip_blocklist_table_name(), ['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)], ['ip' => '192.0.2.99']);
    unset($GLOBALS['wphm_ip_blocklist_runtime_cache']);
    security_same(false, wphm_ip_blocklist_is_blocked('192.0.2.99'), 'expired blocks no longer reject');
    update_option('wphm_ip_allowlist', ['192.0.2.99']);
    security_same(false, wphm_ip_blocklist_block('192.0.2.99'), 'allowlist blocks registration');
    security_same(false, wphm_ip_blocklist_block('192.0.2.1'), 'own admin IP protected');
    wphm_ip_blocklist_block('192.0.2.100');
    update_option('wphm_ip_allowlist', ['192.0.2.99', '192.0.2.100']);
    security_same(false, wphm_ip_blocklist_is_blocked('192.0.2.100'), 'allowlist overrides an existing block/cache');
    add_filter('wp_die_handler', function () { return function ($message) { throw new RuntimeException('denied'); }; });
    $_POST = ['review_token' => $review['token']];
    try { wphm_comment_manager_confirm_block(); $denied = false; } catch (RuntimeException $error) { $denied = true; }
    security_same(true, $denied, 'confirmation token is single-use');
    $_POST = ['_wpnonce' => 'invalid', 'allowlist' => '192.0.2.200'];
    try { wphm_ip_blocklist_save_settings(); $denied = false; } catch (RuntimeException $error) { $denied = true; }
    security_same(true, $denied, 'new settings endpoint rejects invalid nonce');
    $subscriber = wp_create_user('securitysubscriber', bin2hex(random_bytes(12)), 'subscriber@example.invalid');
    wp_set_current_user($subscriber);
    security_same(false, current_user_can('manage_options'), 'subscriber has no management authority');
    try { wphm_ip_blocklist_save_settings(); $denied = false; } catch (RuntimeException $error) { $denied = true; }
    security_same(true, $denied, 'new settings endpoint rejects subscriber');
    ob_start(); wphm_render_comment_manager_tool_page(); security_same('', ob_get_clean(), 'subscriber cannot render protected tool');
    echo "PASS: {$checks} native WordPress security assertions.\n";
} finally {
    $connection->query("DROP DATABASE `{$database}`");
    $connection->close();
}
