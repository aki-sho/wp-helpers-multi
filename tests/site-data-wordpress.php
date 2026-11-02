<?php
/** Native WordPress/MariaDB regressions in a newly created, disposable database. */
if (PHP_SAPI !== 'cli') exit;
set_exception_handler(function (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL); exit(1); });
$root = dirname(__DIR__);
$core = $root . '/.wphm-test/wordpress/';
$password = trim(file_get_contents($root . '/.wphm-test/db-password.txt'));
$database = 'wphm_site_data_test_' . bin2hex(random_bytes(6));
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$connection = new mysqli('127.0.0.1', 'root', $password, '', 33077);
$connection->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$checks = 0;
function inventory_same($expected, $actual, string $label): void {
    global $checks;
    $checks++;
    if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true));
}
try {
    define('ABSPATH', $core);
    foreach (['DB_NAME' => $database, 'DB_USER' => 'root', 'DB_PASSWORD' => $password, 'DB_HOST' => '127.0.0.1:33077', 'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'WP_CONTENT_DIR' => $root . '/.wphm-test/site-data-content', 'WP_CONTENT_URL' => 'https://93.184.216.34/wp-content', 'WP_HOME' => 'https://93.184.216.34', 'WP_SITEURL' => 'https://93.184.216.34', 'WP_ADMIN' => true, 'WP_INSTALLING' => true, 'WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => false, 'WP_DEBUG_LOG' => $root . '/.wphm-test/site-data-debug.log', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true, 'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true] as $name => $value) define($name, $value);
    foreach (['plugins', 'themes', 'mu-plugins', 'uploads'] as $directory) if (!is_dir(WP_CONTENT_DIR . '/' . $directory)) mkdir(WP_CONTENT_DIR . '/' . $directory, 0777, true);
    $table_prefix = 'wphmsec_';
    $_SERVER = ['HTTP_HOST' => '93.184.216.34', 'HTTPS' => 'on', 'REQUEST_URI' => '/wp-admin/', 'PHP_SELF' => '/wp-admin/admin.php', 'REQUEST_METHOD' => 'GET', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '192.0.2.1'];
    require ABSPATH . 'wp-settings.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    require ABSPATH . 'wp-admin/includes/admin.php';
    add_filter('pre_wp_mail', '__return_true');
    add_filter('flush_rewrite_rules_hard', '__return_false');
    $http = function ($pre, $args, $url) {
        $GLOBALS['inventory_http_calls'][] = ['url' => $url, 'args' => $args];
        return ($GLOBALS['inventory_http_response'])($url, $args);
    };
    $GLOBALS['inventory_http_response'] = function () { return new WP_Error('isolated', 'No external traffic'); };
    add_filter('pre_http_request', $http, 10, 3);
    $installed = wp_install('Security fixture', 'securityadmin', 'fixture@example.invalid', false, '', bin2hex(random_bytes(12)));
    wp_installing(false);
    wp_set_current_user((int)$installed['user_id']);
    require $root . '/wp-helpers-multi.php';
    wphm_access_log_maybe_update_schema();
    wphm_ip_blocklist_maybe_update_schema();
    inventory_same(true, wphm_su_schema(), 'URL inventory schema created');
    $wpdb->query('ALTER TABLE ' . wphm_su_table() . ' DROP COLUMN access_level');
    update_option('wphm_site_urls_db_version', '1.0.0');
    inventory_same(true, wphm_su_schema(), 'existing URL inventory schema upgrades');
    inventory_same(true, (bool)$wpdb->get_var("SHOW COLUMNS FROM " . wphm_su_table() . " LIKE 'access_level'"), 'access column added by migration');
    $wp_rewrite->set_permalink_structure('/%postname%/');
    register_post_type('fixture_item', ['public' => true, 'has_archive' => true, 'rewrite' => ['slug' => 'catalog']]);
    $wp_rewrite->flush_rules(false);
    $body = '<!-- wp:paragraph --><p><img src="/media/a.jpg"><picture><source srcset="/media/b-small.jpg 1x, /media/b-large.jpg 2x"><img src="/media/b.jpg"></picture><a href="/unusual/path/">Internal</a><a href="https://external.invalid/">External</a><a href="mailto:fixture@example.invalid">Mail</a><a>No href</a></p><!-- /wp:paragraph -->';
    inventory_same(['images' => 2, 'links' => 3], fv_export_list_body_counts($body), 'counts body tags, not picture source variants');
    inventory_same(['images' => 0, 'links' => 0], fv_export_list_body_counts('<pre>&lt;img src="x"&gt;</pre><!-- <img src="x"><a href="x"> -->'), 'escaped examples/comments ignored');
    inventory_same(['images' => 0, 'links' => 0], fv_export_list_body_counts('<script>const x="<img src=x><a href=x>";</script>'), 'script text ignored');
    inventory_same(['images' => 0, 'links' => 0], fv_export_list_body_counts(''), 'empty body has zero counts');
    $post = wp_insert_post(wp_slash(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Inventory article', 'post_name' => 'inventory-article', 'post_content' => $body]));
    $other = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Empty article', 'post_content' => '']);
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Fixed', 'post_name' => 'fixed', 'post_content' => '<a href="/page-only-file.pdf">File</a>']);
    $custom = wp_insert_post(['post_type' => 'fixture_item', 'post_status' => 'publish', 'post_title' => 'Widget', 'post_name' => 'widget', 'post_content' => '<a href="/cpt-only.svg">SVG</a>']);
    $media = wp_insert_attachment(['post_title' => 'Photo', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], false, $post);
    update_post_meta($media, '_wp_attached_file', '2026/11/fixture.jpg');
    update_post_meta($post, '_thumbnail_id', $media);
    $cat = wp_insert_term('Inventory category', 'category')['term_id'];
    wp_set_post_terms($post, [(int)$cat], 'category');
    $columns = array_keys(fv_export_list_available_columns()); $position = array_search('has_thumb', $columns, true);
    inventory_same(['has_thumb', 'image_count', 'link_count'], array_slice($columns, $position, 3), 'columns immediately follow thumbnail');
    $rows = fv_export_list_get_rows(); $indexed = [];
    foreach ($rows as $row) $indexed[(int)$row['id']] = $row;
    inventory_same(2, $indexed[$post]['image_count'], 'row image count excludes featured image');
    inventory_same(3, $indexed[$post]['link_count'], 'row link count');
    inventory_same(0, $indexed[$other]['image_count'], 'draft zero image count');
    inventory_same('あり', $indexed[$post]['has_thumb'], 'featured image column retained');
    $ordered = fv_export_list_sort_rows([['id' => 1, 'image_count' => 12], ['id' => 2, 'image_count' => 2]], 'image_count', 'ASC');
    inventory_same(2, $ordered[0]['id'], 'image count sorts numerically');
    $ordered = fv_export_list_sort_rows([['id' => 1, 'link_count' => 12], ['id' => 2, 'link_count' => 2]], 'link_count', 'ASC');
    inventory_same(2, $ordered[0]['id'], 'link count sorts numerically');
    $_GET = ['cols' => ['has_thumb', 'image_count', 'link_count']];
    inventory_same(['has_thumb', 'image_count', 'link_count'], fv_export_list_get_selected_columns(), 'CSV/display column selection');
    $_GET = [];
    ob_start(); fv_export_list_render_page(); $post_html = ob_get_clean();
    file_put_contents($root . '/.wphm-test/site-data-post.html', $post_html);
    $_GET = [];

    $base = home_url('/');
    $menu = wp_create_nav_menu('Inventory menu');
    wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Menu target', 'menu-item-url' => $base . 'menu-only-target/', 'menu-item-status' => 'publish', 'menu-item-type' => 'custom']);
    wp_insert_post(wp_slash(['post_type' => 'wp_navigation', 'post_status' => 'publish', 'post_title' => 'Block navigation', 'post_content' => '<!-- wp:navigation-link {"label":"Block target","url":"' . $base . 'block-menu-only/"} /-->']));
    inventory_same('https://93.184.216.34/a/file.png', wphm_su_normalize('../file.png#top', $base . 'a/b/'), 'relative URLs resolve and fragments removed');
    foreach (['https://external.invalid/file', 'mailto:fixture@example.invalid', 'javascript:alert(1)', 'https://user:password@93.184.216.34/file', '/bad path'] as $url) inventory_same('', wphm_su_normalize($url, $base), 'invalid/external URL excluded');
    inventory_same('https://93.184.216.34:8080/file', wphm_su_normalize('https://93.184.216.34:8080/file', $base), 'same-host nonstandard port URL retained');
    inventory_same(false, wphm_su_should_fetch('https://93.184.216.34:8080/file'), 'nonstandard port never crawled');
    inventory_same($base . 'offer?filter=blue&token=%5Bredacted%5D', wphm_su_normalize('/offer?filter=blue&amp;token=secret', $base), 'useful query retained, secret masked');
    inventory_same('unknown', wphm_su_access_level($base . 'media/a.jpg'), 'unfetched URL access is unknown');
    inventory_same('public', wphm_su_access_level($base, 200, '<p>Public</p>'), 'anonymous 200 recorded as public response');
    inventory_same('auth', wphm_su_access_level($base, 401), 'HTTP authentication required');
    inventory_same('denied', wphm_su_access_level($base, 403), 'forbidden response recorded');
    inventory_same('unknown', wphm_su_access_level($base, 404), '404 does not invent a required role');
    inventory_same('password', wphm_su_access_level($base, 200, '<form class="post-password-form"><input name="post_password"></form>'), 'post-password form detected');
    inventory_same('login', wphm_su_access_level(admin_url('/')), 'WordPress admin requires login');
    inventory_same('unknown', wphm_su_access_level(admin_url('admin-ajax.php')), 'public AJAX route not mislabeled login-only');
    inventory_same('login', wphm_su_access_level($base . 'member/', 302, '', wp_login_url()), 'redirect to login detected');
    inventory_same(true, wphm_su_is_article(get_permalink($post)), 'post URL excluded');
    inventory_same(true, wphm_su_is_article(get_permalink($page)), 'page URL excluded');
    inventory_same(true, wphm_su_is_article(get_permalink($post) . '?tracking=test'), 'post query variant excluded');
    inventory_same(true, wphm_su_is_article($base . '?p=' . $post), 'plain post permalink excluded');
    inventory_same(false, wphm_su_is_article(get_permalink($custom)), 'custom post type URL retained');
    inventory_same(false, wphm_su_is_article($base . 'unusual/path/'), 'unknown path retained');
    foreach (['/wp-login.php', '/wp-admin/', '/wp-cron.php', '/offer?filter=blue', '/media/a.jpg'] as $url) inventory_same(false, wphm_su_should_fetch($base . ltrim($url, '/')), 'non-crawled URL still eligible for listing');
    $probe = ['total' => 0, 'limited' => false];
    wphm_su_extract('<base href="/based/"><img src="file.png"><a href="../elsewhere/">Link</a>', $base . 'original/page/', 'fixture', $probe);
    inventory_same(1, wphm_su_rows('based/file.png')['total'], 'HTML base href respected');
    inventory_same($base . 'original/page/', wphm_su_rows('based/file.png')['rows'][0]['source_url'], 'actual discovery source retained with base href');

    $GLOBALS['inventory_http_calls'] = [];
    $GLOBALS['inventory_http_response'] = function ($url, $args) use ($base, $media) {
        $path = rawurldecode(wp_parse_url($url, PHP_URL_PATH));
        $code = 200; $headers = ['content-type' => 'text/html'];
        $bodies = [
            '/' => '<link href="/style.css" rel="stylesheet"><a href="/mystery/custom-endpoint/">Mystery</a><script src="/script.js"></script><a href="/jump/">Jump</a><a href="/スクリーンショット-451/">Image redirect</a><a href="/chain-start/">Chain</a><a href="/not-image-redirect/">HTML</a><a href="/loop/">Loop</a><a href="/failed-image/">Failure</a><a href="/head-fallback/">Fallback</a><a href="/alias.png">Static alias</a><a href="/broken/">Missing page</a><a href="/?attachment_id=' . $media . '">Attachment</a><img src="/media/root.webp"><img src="/wp-content/uploads/2026/11/crawled.jpg"><a href="https://external.invalid/out">External</a>',
            '/mystery/custom-endpoint/' => '<a href="/only-by-crawling/resource.bin">Unknown file</a><a href="/unknown-kind/">Unknown kind</a><script type="application/ld+json">{"url":"/json-only/"}</script>',
            '/style.css' => 'body{background:url("/fonts/background.webp")} @import "/imported.css";',
            '/imported.css' => '@font-face{src:url(/fonts/site.woff2)}',
            '/robots.txt' => "User-agent: *\nSitemap: " . $base . "fixture-index.xml\n",
            '/fixture-index.xml' => '<sitemapindex><sitemap><loc>' . $base . 'fixture-child.xml</loc></sitemap></sitemapindex>',
            '/fixture-child.xml' => '<urlset><url><loc>' . $base . 'sitemap-only/opaque</loc></url></urlset>',
        ];
        if ($path === '/jump/') { $code = 302; $headers['location'] = 'http://127.0.0.1/private'; }
        if ($path === '/upload-redirect/') { $code = 302; $headers['location'] = '/wp-content/uploads/2026/11/redirected.xml'; }
        $redirects = ['/alias.png' => '/image-endpoint', '/スクリーンショット-451/' => '/wp-content/uploads/2024/10/1a02489a6c88a10f9bb5d4cb256f8b26.png', '/chain-start/' => '/chain-mid/', '/chain-mid/' => '/image-endpoint', '/not-image-redirect/' => '/fake.png', '/loop/' => '/loop/', '/failed-image/' => '/fail.png', '/head-fallback/' => '/no-head.png'];
        if (isset($redirects[$path])) { $code = 301; $headers['location'] = $redirects[$path]; }
        if (strpos($url, '?attachment_id=') !== false) { $code = 302; $headers['location'] = '/wp-content/uploads/2026/11/fixture.jpg'; }
        if ($path === '/broken/') $code = 404;
        if ($path === '/fail.png') return new WP_Error('fixture_failure', 'No response');
        if (in_array($path, ['/image-endpoint', '/no-head.png', '/wp-content/uploads/2024/10/1a02489a6c88a10f9bb5d4cb256f8b26.png'], true)) $headers['content-type'] = 'image/png';
        if ($path === '/wp-content/uploads/2026/11/fixture.jpg') $headers['content-type'] = 'image/jpeg';
        if ($path === '/no-head.png') $code = ($args['method'] ?? '') === 'HEAD' ? 405 : 206;
        if (substr($path, -4) === '.xml') $headers['content-type'] = 'application/xml';
        if (substr($path, -4) === '.css') $headers['content-type'] = 'text/css';
        return ['response' => ['code' => $code], 'headers' => $headers, 'body' => $bodies[$path] ?? '<html><body></body></html>'];
    };
    update_option('wphm_site_urls_exclude_uploads', true, false);
    inventory_same(true, wphm_su_filters()['uploads'], 'legacy upload preference migrated');
    $excluded = wphm_su_start(['mode' => 'exclude', 'uploads' => true]);
    inventory_same(true, wphm_su_filters()['uploads'], 'selection saved before collection');
    foreach (['/wp-content/uploads', '/wp-content/uploads/', '/wp-content/uploads/2026/11/a.jpg', '/wp-content/uploads/2026/file.pdf?version=2', '/wp-content/%75ploads/a.jpg', '/wp-content/uploads%2F2026/a.jpg', '/wp-content/other/../uploads/a.xml'] as $path) inventory_same(true, wphm_su_is_upload($base . ltrim($path, '/')), 'upload descendant classified');
    foreach (['/wp-content/uploads-old/a.jpg', '/another/wp-content/uploads/a.jpg', '/wp-content/uploads/../themes/a.css', '/media/a.jpg', get_permalink($media)] as $path) inventory_same(false, wphm_su_is_upload(WP_Http::make_absolute_url($path, $base)), 'other directories and attachment pages are separate groups');
    inventory_same(true, wphm_su_is_attachment($base . '?attachment_id=' . $media), 'attachment_id group recognized');
    inventory_same(true, wphm_su_is_attachment(get_permalink($media)), 'pretty attachment permalink recognized');
    inventory_same(false, wphm_su_is_attachment($base . 'unknown/'), 'ordinary unknown URL not invented as attachment');
    inventory_same(true, wphm_su_should_fetch($base . '?attachment_id=' . $media), 'read-only attachment query can be checked');
    inventory_same(false, wphm_su_should_fetch($base . '?attachment_id=' . $media . '&action=delete'), 'attachment query cannot authorize an extra action');
    $before = $excluded['total'];
    wphm_su_add('/wp-content/uploads/2026/11/direct.pdf', $base, 'fixture', $excluded);
    inventory_same($before + 1, $excluded['total'], 'candidate retained to permit instant reversible filtering');
    inventory_same(0, wphm_su_rows('direct.pdf')['total'], 'upload excluded from results');
    $state = wphm_su_start(['mode' => 'exclude']); $run = $state['run'];
    for ($i = 0; $i < 100 && $state['phase'] !== 'done'; $i++) $state = wphm_su_step($run);
    inventory_same('done', $state['phase'], 'batched collection and redirect checks complete');
    inventory_same(1, wphm_su_rows('crawled.jpg')['total'], 'uploads included without selection');
    $row_for = function ($path) use ($wpdb, $base) { return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wphm_su_table() . ' WHERE url=%s', $base . ltrim($path, '/')), ARRAY_A); };
    inventory_same('image', $row_for('/スクリーンショット-451/')['redirect_state'], 'reported Japanese-slug to PNG pattern identified by response headers');
    inventory_same('image', $row_for('/chain-start/')['redirect_state'], 'multi-hop extensionless image identified');
    inventory_same('image', $row_for('/alias.png')['redirect_state'], 'static file URL redirects also checked using HEAD');
    inventory_same('other', $row_for('/not-image-redirect/')['redirect_state'], 'PNG filename with HTML response is not an image redirect');
    inventory_same('unknown', $row_for('/loop/')['redirect_state'], 'redirect loop bounded and not guessed');
    inventory_same('unknown', $row_for('/failed-image/')['redirect_state'], 'failed image target remains unverified');
    inventory_same('image', $row_for('/head-fallback/')['redirect_state'], 'bounded GET fallback when HEAD unsupported');
    $examples = ['uploads' => $base . 'wp-content/uploads/2026/11/crawled.jpg', 'attachments' => get_permalink($media), 'image_redirects' => $base . 'スクリーンショット-451/', 'ordinary' => $base . 'unusual/path/'];
    foreach (['exclude', 'only'] as $mode) for ($mask = 0; $mask < 8; $mask++) {
        $filters = ['mode' => $mode, 'uploads' => (bool)($mask & 1), 'attachments' => (bool)($mask & 2), 'image_redirects' => (bool)($mask & 4)];
        $filtered = array_column(wphm_su_rows('', 1, 10000, 'url', 'ASC', $filters)['rows'], 'url');
        foreach ($examples as $kind => $url) {
            $matches = !empty($filters[$kind]);
            inventory_same($mode === 'only' ? $matches : !$matches, in_array($url, $filtered, true), "{$mode} combination {$mask}: {$kind}");
        }
    }
    $kept_count = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table());
    wphm_su_save_filters(['mode' => 'only', 'uploads' => true]);
    inventory_same(1, wphm_su_rows('crawled.jpg')['total'], 'only upload group visible without a rescan');
    inventory_same(0, wphm_su_rows('unusual/path')['total'], 'only mode hides other groups');
    wphm_su_save_filters(['mode' => 'exclude', 'uploads' => true, 'attachments' => true, 'image_redirects' => true]);
    foreach (['crawled.jpg', 'スクリーンショット-451/'] as $term) inventory_same(0, wphm_su_rows($term)['total'], 'selected group excluded immediately');
    inventory_same(false, in_array($base . 'failed-image/', array_column(wphm_su_rows('', 1, 10000)['rows'], 'url'), true), 'unknown image redirect held rather than misclassified');
    inventory_same(1, wphm_su_rows('unusual/path')['total'], 'ordinary confirmed URL retained with all groups excluded');
    inventory_same(1, wphm_su_rows('/broken/')['total'], 'non-redirecting HTTP 404 remains visible when image redirects excluded');
    inventory_same($kept_count, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table()), 'filter changes do not erase candidates');
    inventory_same($run, get_option('wphm_site_urls_state')['run'], 'filter changes do not create another scan');
    wphm_su_save_filters(['mode' => 'exclude']);
    $result = wphm_su_rows('', 1, 10000); $urls = array_column($result['rows'], 'url');
    foreach (['unusual/path/', 'media/a.jpg', 'media/b-small.jpg', 'page-only-file.pdf', 'cpt-only.svg', 'mystery/custom-endpoint/', 'only-by-crawling/resource.bin', 'unknown-kind/', 'fonts/background.webp', 'fonts/site.woff2', 'json-only/', 'sitemap-only/opaque', 'script.js', 'wp-login.php', 'wp-admin/', 'menu-only-target/', 'block-menu-only/'] as $path) {
        if ($path === 'wp-admin/') { $copy = $state; wphm_su_add('/wp-admin/', $base, 'fixture', $copy); $urls = array_column(wphm_su_rows('', 1, 10000)['rows'], 'url'); }
        inventory_same(true, in_array($base . $path, $urls, true), 'retains URL regardless of kind: ' . $path);
    }
    inventory_same(true, in_array(get_permalink($custom), $urls, true), 'custom single content remains visible');
    inventory_same(true, in_array(get_term_link((int)$cat), $urls, true), 'taxonomy URL visible');
    inventory_same(false, in_array(get_permalink($post), $urls, true), 'post absent from result');
    inventory_same(false, in_array(get_permalink($page), $urls, true), 'page absent from result');
    $calls = array_column($GLOBALS['inventory_http_calls'], 'url');
    inventory_same(true, in_array(get_permalink($post), $calls, true), 'excluded post still crawled for discovery');
    inventory_same(false, in_array('http://127.0.0.1/private', $calls, true), 'redirect to private IP never requested');
    $script_gets = array_filter($GLOBALS['inventory_http_calls'], function ($call) use ($base) { return $call['url'] === $base . 'script.js' && $call['args']['method'] !== 'HEAD'; });
    inventory_same(0, count($script_gets), 'scripts checked by headers without downloading or executing');
    inventory_same(false, in_array($base . 'wp-login.php', $calls, true), 'login recorded without fetching');
    $count = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table());
    $copy = $state; wphm_su_add('/unusual/path/#another', $base, 'duplicate', $copy);
    inventory_same($count, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table()), 'duplicate URL not inserted');
    $copy['total'] = WPHM_SU_MAX_URLS; wphm_su_add('/over-cap/', $base, 'limit', $copy);
    inventory_same(true, $copy['limited'], 'maximum discovery count enforced');
    inventory_same(1, wphm_su_rows('only-by-crawling')['total'], 'URL search');
    inventory_same(1, wphm_su_rows('sitemap-only', 9999)['page'], 'pagination clamped safely');
    foreach (['ASC', 'DESC'] as $direction) {
        $sorted = wphm_su_rows('', 1, 10000, 'http_status', $direction);
        $values = array_map('intval', array_column($sorted['rows'], 'http_status')); $expected = $values;
        if ($direction === 'ASC') sort($expected, SORT_NUMERIC); else rsort($expected, SORT_NUMERIC);
        inventory_same($expected, $values, 'HTTP status uses numeric sorting');
        $sorted = wphm_su_rows('', 1, 10000, 'url', $direction);
        $values = array_column($sorted['rows'], 'url'); $expected = $values;
        if ($direction === 'ASC') sort($expected, SORT_STRING); else rsort($expected, SORT_STRING);
        inventory_same($expected, $values, 'URL sorting direction');
    }
    foreach (array_keys(wphm_su_columns()) as $key) {
        $sorted = wphm_su_rows('', 1, 100, $key, 'DESC');
        inventory_same($key, $sorted['orderby'], 'each displayed column is sortable');
        inventory_same('', $wpdb->last_error, 'sort expression is valid SQL');
    }
    inventory_same(['url', 'ASC'], wphm_su_sort_input(['orderby' => 'url; DROP TABLE x', 'order' => 'DESC; SELECT 1']), 'sort whitelist rejects SQL syntax');
    inventory_same('access_level', array_keys(wphm_su_columns())[5], 'access rights follow confirmation status');
    $wpdb->query('UPDATE ' . wphm_su_table() . " SET classified=0,is_upload=0,is_attachment=0,redirect_state=''");
    update_option('wphm_site_urls_db_version', '1.1.0', false);
    inventory_same(true, wphm_su_schema(), 'v0.9.7 rows upgrade without deleting results');
    inventory_same(true, wphm_su_response()['state']['needs_classification'], 'legacy rows request automatic classification');
    $legacy_count = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table() . ' WHERE classified=0');
    $state = wphm_su_step($run);
    inventory_same(max(0, $legacy_count - 20), (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table() . ' WHERE classified=0'), 'legacy classification processes at most 20 rows per batch');
    for ($i = 0; $i < 100 && $state['phase'] !== 'done'; $i++) $state = wphm_su_step($run);
    inventory_same(0, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table() . ' WHERE classified=0'), 'all legacy rows classified in bounded batches');
    inventory_same('image', $row_for('/スクリーンショット-451/')['redirect_state'], 'legacy image redirects automatically rechecked');
    inventory_same('1', $row_for('/wp-content/uploads/2026/11/crawled.jpg')['is_upload'], 'legacy upload group restored');
    $heads = array_filter($GLOBALS['inventory_http_calls'], function ($call) { return ($call['args']['method'] ?? '') === 'HEAD'; });
    inventory_same(true, count($heads) > 0, 'image classification uses HEAD requests');
    foreach ($heads as $call) {
        inventory_same(0, $call['args']['redirection'], 'each redirect hop validated separately');
        inventory_same(512, $call['args']['limit_response_size'], 'header probe has a response size cap');
        inventory_same(true, !empty($call['args']['reject_unsafe_urls']), 'safe WordPress API used for HEAD');
    }
    ob_start(); wphm_su_render_page(); $url_html = ob_get_clean();
    file_put_contents($root . '/.wphm-test/site-data-urls.html', $url_html);
    file_put_contents($root . '/.wphm-test/site-data-result.json', wp_json_encode(wphm_su_response()));
    $old_state = get_option('wphm_site_urls_state');
    try { wphm_su_step(str_repeat('f', 32)); $stale = false; } catch (RuntimeException $error) { $stale = true; }
    inventory_same(true, $stale, 'stale scan token rejected');
    inventory_same($old_state, get_option('wphm_site_urls_state'), 'stale request does not mutate scan');
    $limited = $old_state; $limited['phase'] = 'crawl'; $limited['fetched'] = WPHM_SU_MAX_FETCHES;
    update_option('wphm_site_urls_state', $limited); $before = count($GLOBALS['inventory_http_calls']);
    $limited = wphm_su_step($run);
    inventory_same('done', $limited['phase'], 'fetch limit completes collection');
    inventory_same(true, $limited['limited'], 'fetch limit reported');
    inventory_same($before, count($GLOBALS['inventory_http_calls']), 'no HTTP after fetch limit');
    update_option('wphm_site_urls_state', $old_state);

    define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', function () { return function () { throw new RuntimeException('ajax_exit'); }; });
    $ajax = function (array $input) { $_SERVER['REQUEST_METHOD']='POST'; $_POST = $_REQUEST = $input; ob_start(); try { wphm_su_ajax(); } catch (RuntimeException $error) {} return json_decode(ob_get_clean(), true); };
    $nonce = wp_create_nonce('wphm_site_urls');
    inventory_same(true, $ajax(['nonce' => $nonce, 'op' => 'list'])['success'], 'authenticated AJAX list');
    $filter_request = ['nonce' => $nonce, 'op' => 'filters', 'mode' => 'only', 'uploads' => '1', 'attachments' => '0', 'image_redirects' => '0'];
    $filtered_reply = $ajax($filter_request);
    inventory_same(true, $filtered_reply['success'], 'authenticated automatic filter update');
    inventory_same('only', $filtered_reply['data']['filters']['mode'], 'mode reflected in response');
    inventory_same($run, get_option('wphm_site_urls_state')['run'], 'automatic update keeps current run');
    foreach ($filtered_reply['data']['result']['rows'] as $row) inventory_same('1', $row['is_upload'], 'AJAX only-upload results contain uploads exclusively');
    $invalid_filter = $filter_request; $invalid_filter['attachments'] = ['1'];
    inventory_same(false, $ajax($invalid_filter)['success'], 'array filter value rejected');
    $invalid_filter = $filter_request; $invalid_filter['mode'] = 'only OR 1=1';
    inventory_same(false, $ajax($invalid_filter)['success'], 'invalid filter mode rejected');
    inventory_same('only', wphm_su_filters()['mode'], 'invalid filter request cannot change saved selection');
    wphm_su_save_filters(['mode' => 'exclude']);
    inventory_same(false, $ajax(['nonce' => $nonce, 'op' => 'start', 'mode' => 'exclude', 'uploads' => ['1'], 'attachments' => '0', 'image_redirects' => '0'])['success'], 'malformed exclusion rejected');
    inventory_same($old_state, get_option('wphm_site_urls_state'), 'invalid exclusion does not delete prior results');
    inventory_same(false, $ajax(['nonce' => $nonce, 'op' => 'invalid'])['success'], 'invalid action rejected');
    inventory_same(false, $ajax(['nonce' => 'invalid', 'op' => 'start']) !== null, 'invalid nonce terminates before JSON result');
    $tools = wphm_get_tool_states(); $tools['site_urls'] = false; update_option('wphm_tool_states', $tools);
    inventory_same(false, $ajax(['nonce' => $nonce, 'op' => 'list'])['success'], 'disabled feature denies API');
    $tools['site_urls'] = true; update_option('wphm_tool_states', $tools);
    $subscriber = wp_create_user('inventorysubscriber', bin2hex(random_bytes(12)), 'inventory@example.invalid');
    wp_set_current_user($subscriber);
    inventory_same(false, $ajax($filter_request)['success'], 'subscriber cannot alter filters');
    inventory_same(false, $ajax(['nonce' => $nonce, 'op' => 'start'])['success'], 'subscriber denied before mutation');
    inventory_same($old_state, get_option('wphm_site_urls_state'), 'denied actions do not reset results');
    wp_set_current_user((int)$installed['user_id']);
    $to_clear = wphm_su_start(['mode' => 'exclude', 'uploads' => true]);
    $reply = $ajax(['nonce' => $nonce, 'op' => 'clear', 'run' => $to_clear['run']]);
    inventory_same(true, $reply['data']['cleared'], 'leaving deletes current results');
    inventory_same(0, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table()), 'URL table empty after departure');
    inventory_same(false, get_option('wphm_site_urls_state'), 'run state removed on departure');
    inventory_same(true, wphm_su_filters()['uploads'], 'departure retains filter preference');
    $new_run = wphm_su_start(['mode' => 'exclude']);
    inventory_same(false, $ajax(['nonce' => $nonce, 'op' => 'clear', 'run' => $to_clear['run']])['data']['cleared'], 'old tab cannot clear a new run');
    inventory_same($new_run['run'], get_option('wphm_site_urls_state')['run'], 'newer scan protected');
    $lock = 'wphm_su_' . substr(hash('sha256', DB_NAME . ':' . wphm_su_table()), 0, 40);
    $connection->query("SELECT GET_LOCK('{$lock}', 0)");
    inventory_same(true, $ajax($filter_request)['success'], 'filter selection can save while a batch holds the scan lock');
    inventory_same($new_run['run'], get_option('wphm_site_urls_state')['run'], 'live filter update cannot reset an active batch');
    $pending = $ajax(['nonce' => $nonce, 'op' => 'clear', 'run' => $new_run['run']]);
    inventory_same(true, $pending['data']['pending'], 'busy scan accepts deferred deletion');
    $connection->query("SELECT RELEASE_LOCK('{$lock}')");
    inventory_same([], wphm_su_step($new_run['run']), 'next batch honors deferred deletion');
    inventory_same(0, (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table()), 'deferred deletion removes all URLs');
    $cross_request = wphm_su_start(['mode' => 'exclude']);
    inventory_same(false, wphm_su_has_clear_request($cross_request['run']), 'missing clear request initially cached');
    $connection->select_db($database);
    $key = '_transient_wphm_su_clear_' . $cross_request['run'];
    $connection->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,'1','no')", $key));
    inventory_same(true, wphm_su_has_clear_request($cross_request['run']), 'clear signal from another request bypasses stale option cache');
    inventory_same([], wphm_su_step($cross_request['run']), 'cross-request clear honored before new HTTP');
    echo "PASS: {$checks} native WordPress post-data and URL-inventory assertions.\n";
} finally {
    $connection->query("DROP DATABASE `{$database}`");
    $connection->close();
}
