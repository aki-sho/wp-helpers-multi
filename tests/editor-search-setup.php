<?php
/** Disposable browser fixture: copy a clean core to .wphm-test/wordpress and start MariaDB on 33077 first. */
if (PHP_SAPI !== 'cli') exit;
$root = getenv('WPHM_EDITOR_FIXTURE') ?: dirname(__DIR__) . '/.wphm-test';
$base = getenv('WPHM_EDITOR_URL') ?: 'http://127.0.0.1:8767';
$core = $root . '/wordpress/';
if (!is_file($core . 'wp-settings.php')) throw new RuntimeException('Missing disposable WordPress core.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('127.0.0.1', 'root', '', '', 33077);
$name = 'wphm_editor_' . bin2hex(random_bytes(6));
$db->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
file_put_contents($root . '/db-password.txt', '');
$config = "<?php\n";
foreach (['DB_NAME' => $name, 'DB_USER' => 'root', 'DB_PASSWORD' => '', 'DB_HOST' => '127.0.0.1:33077', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_HOME' => $base, 'WP_SITEURL' => $base, 'WP_ENVIRONMENT_TYPE' => 'local', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true, 'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false, 'WP_DEBUG_LOG' => $root . '/editor-debug.log'] as $key => $value) $config .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $key) $config .= "define('$key', '" . bin2hex(random_bytes(32)) . "');\n";
$config .= "\$table_prefix = 'editor_';\nif (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n";
file_put_contents($core . 'wp-config.php', $config);
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = parse_url($base, PHP_URL_HOST) . ':' . parse_url($base, PHP_URL_PORT); $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $core . 'wp-config.php';
require ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter('pre_wp_mail', '__return_true'); add_filter('flush_rewrite_rules_hard', '__return_false');
$installed = wp_install('Editor search local fixture', 'fixtureadmin', 'test@example.invalid', false, '', bin2hex(random_bytes(16)));
wp_installing(false); wp_set_current_user($installed['user_id']);
update_option('active_plugins', ['wp-helpers-multi/wp-helpers-multi.php']);
$tools = ['search_replace' => 1];
foreach (['site_links', 'qr', 'calc', 'bcrypt', 'password', 'timer', 'link_inspector', 'access_log', 'post_data', 'comment_manager'] as $tool) $tools[$tool] = 0;
update_option('wphm_tool_states', $tools);
update_user_meta($installed['user_id'], 'show_welcome_panel', 0);
update_user_meta($installed['user_id'], 'persistent_preferences', ['core' => ['isComplementaryAreaVisible' => false], 'core/edit-post' => ['welcomeGuide' => false], 'core/editor' => ['welcomeGuide' => false]]);
$content = '<!-- wp:paragraph --><p>ワードプレスと <strong>ワードプレス</strong>。<a href="https://example.invalid/ワードプレス">ワードプレス</a> &amp; 😀</p><!-- /wp:paragraph -->' . "\n" . '<!-- wp:heading --><h2 class="wp-block-heading">見出しのワードプレス</h2><!-- /wp:heading -->' . "\n" . '<!-- wp:code --><pre class="wp-block-code"><code>ワードプレスはコード内</code></pre><!-- /wp:code -->';
$post = wp_insert_post(wp_slash(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => '編集画面の投稿テスト', 'post_content' => $content]));
$page = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => '固定ページの編集テスト', 'post_content' => '<!-- wp:paragraph --><p>固定ページのワードプレス</p><!-- /wp:paragraph -->']);
$classic = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'クラシック編集テスト', 'post_content' => '<p>ワードプレスと <strong>ワードプレス</strong>。<a href="https://example.invalid/ワードプレス">リンク</a></p>']);
$other = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => '別記事', 'post_content' => '別記事のワードプレス']);
$author = wp_insert_user(['user_login' => 'fixtureauthor', 'user_pass' => bin2hex(random_bytes(16)), 'user_email' => 'author@example.invalid', 'role' => 'author']);
$own = wp_insert_post(['post_type' => 'post', 'post_author' => $author, 'post_status' => 'draft', 'post_title' => '投稿者の本文', 'post_content' => '<!-- wp:paragraph --><p>投稿者のワードプレス</p><!-- /wp:paragraph -->']);
$runtime = ['base' => $base, 'key' => bin2hex(random_bytes(24)), 'database' => $name, 'admin' => $installed['user_id'], 'author' => $author, 'post' => $post, 'page' => $page, 'classic' => $classic, 'other' => $other, 'own' => $own];
foreach (['admin' => $runtime['admin'], 'author' => $author] as $role => $user_id) {
    $expiration = time() + 7200; $token = WP_Session_Tokens::get_instance($user_id)->create($expiration);
    // Pre-install COOKIEHASH differs from the installed site's hash on older cores.
    $cookie_hash = md5(get_option('siteurl'));
    $runtime['cookies'][$role] = ['wordpress_' . $cookie_hash => wp_generate_auth_cookie($user_id, $expiration, 'auth', $token), 'wordpress_logged_in_' . $cookie_hash => wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token)];
}
file_put_contents($root . '/editor-runtime.json', json_encode($runtime));
$mu = <<<'PHP'
<?php
$fixture = json_decode(file_get_contents(dirname(ABSPATH) . '/editor-runtime.json'), true);
if (($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1' && hash_equals($fixture['key'], $_SERVER['HTTP_X_WPHM_TEST'] ?? '')) {
    foreach ($fixture['cookies'][($_SERVER['HTTP_X_WPHM_ROLE'] ?? '') === 'author' ? 'author' : 'admin'] as $cookie => $value) $_COOKIE[$cookie] = $value;
    add_filter('determine_current_user', function () use ($fixture) { return ($_SERVER['HTTP_X_WPHM_ROLE'] ?? '') === 'author' ? $fixture['author'] : $fixture['admin']; }, 99);
}
add_filter('use_block_editor_for_post', function ($use) { return isset($_GET['wphm-classic']) ? false : $use; });
add_filter('pre_http_request', function () { return new WP_Error('local_fixture', 'External HTTP disabled.'); });
add_filter('pre_wp_mail', '__return_true');
PHP;
file_put_contents($core . 'wp-content/mu-plugins/editor-fixture.php', $mu);
echo "PASS: disposable editor fixture ready; post=$post page=$page classic=$classic own=$own.\n";
