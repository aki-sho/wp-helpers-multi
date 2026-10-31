<?php
if (!defined('ABSPATH')) exit;

/** Same-site HTTP URLs only. Fragments do not identify separate resources. */
function wphm_su_normalize(string $value, string $base): string {
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x20\\\\]/', $value)) return '';
    $url = WP_Http::make_absolute_url($value, $base);
    $p = wp_parse_url($url);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return '';
    $host = strtolower($p['host']);
    $hosts = [strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)), strtolower((string)wp_parse_url(site_url('/'), PHP_URL_HOST))];
    if (!in_array($host, $hosts, true) || (isset($p['port']) && ((int)$p['port'] < 1 || (int)$p['port'] > 65535))) return '';
    $scheme = strtolower($p['scheme']);
    $port = isset($p['port']) && (int)$p['port'] !== ($scheme === 'https' ? 443 : 80) ? ':' . (int)$p['port'] : '';
    $query = '';
    if (!empty($p['query'])) {
        $parts = explode('&', $p['query']);
        foreach ($parts as &$part) {
            $pair = explode('=', $part, 2); $name = rawurldecode($pair[0]);
            if (preg_match('/nonce|token|secret|pass(word|wd)?|signature|auth|session|credential|api.?key|^key$/i', $name)) $part = $pair[0] . '=%5Bredacted%5D';
        }
        unset($part); $query = '?' . implode('&', $parts);
    }
    $out = $scheme . '://' . $host . $port . ($p['path'] ?? '/') . $query;
    return strlen($out) <= 2048 ? $out : '';
}

function wphm_su_is_article(string $url): bool {
    $id = url_to_postid($url);
    if (!$id) {
        $query = wp_parse_url($url, PHP_URL_QUERY); $vars = [];
        if (is_string($query)) parse_str($query, $vars);
        foreach (['p', 'page_id', 'preview_id'] as $key) if (isset($vars[$key]) && is_scalar($vars[$key])) {
            $candidate = absint($vars[$key]);
            if (in_array(get_post_type($candidate), ['post', 'page'], true)) return true;
        }
    }
    return $id && in_array(get_post_type($id), ['post', 'page'], true);
}

function wphm_su_requestable(string $url): bool {
    $p = wp_parse_url($url);
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return false;
    if (!empty($p['query'])) {
        // Only this read-only WordPress route is eligible; never crawl arbitrary query actions.
        parse_str($p['query'], $vars);
        if (count($vars) !== 1 || !isset($vars['attachment_id']) || !is_string($vars['attachment_id']) || !preg_match('/^[1-9][0-9]*$/D', $vars['attachment_id'])) return false;
    }
    $path = $p['path'] ?? '/';
    if (preg_match('~/(?:wp-admin|wp-login\.php|wp-cron\.php|xmlrpc\.php|wp-comments-post\.php)(?:/|$)~i', $path)) return false;
    return true;
}

function wphm_su_should_fetch(string $url): bool {
    if (!wphm_su_requestable($url)) return false;
    $path = (string)wp_parse_url($url, PHP_URL_PATH);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $extension === '' || in_array($extension, ['html', 'htm', 'php', 'xml', 'txt', 'css', 'json'], true);
}

/** Canonical directory boundaries for the upload-folder exclusion. */
function wphm_su_directory_path(string $path): string {
    $segments = [];
    foreach (explode('/', str_replace('\\', '/', rawurldecode($path))) as $segment) {
        if ($segment === '' || $segment === '.') continue;
        if ($segment === '..') array_pop($segments);
        else $segments[] = $segment;
    }
    return '/' . implode('/', $segments);
}

function wphm_su_upload_directories(): array {
    $uploads = wp_get_upload_dir();
    $urls = [(string)($uploads['baseurl'] ?? ''), content_url('/uploads/')];
    $paths = [];
    foreach ($urls as $url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) continue;
        $path = wphm_su_directory_path($path);
        if ($path !== '/') $paths[] = $path;
    }
    return array_values(array_unique($paths));
}

function wphm_su_access_labels(): array {
    return ['public' => '未ログインでアクセス可', 'login' => 'ログインが必要', 'password' => 'パスワードが必要', 'auth' => '認証が必要（HTTP 401）', 'denied' => 'アクセス拒否（HTTP 403）', 'unknown' => '未確認'];
}

/** Anonymous response evidence; never infer administrator/editor roles from a URL. */
function wphm_su_access_level(string $url, int $code = 0, string $body = '', string $location = ''): string {
    if ($code === 403) return 'denied';
    if ($code === 401) return 'auth';
    $path = wphm_su_directory_path((string)wp_parse_url($url, PHP_URL_PATH));
    $admin = wphm_su_directory_path((string)wp_parse_url(admin_url('/'), PHP_URL_PATH));
    if ($admin !== '/' && ($path === $admin || strpos($path, $admin . '/') === 0) && !in_array(basename($path), ['admin-ajax.php', 'admin-post.php'], true)) return 'login';
    if (in_array($code, [301, 302, 303, 307, 308], true) && $location !== '') {
        $target = WP_Http::make_absolute_url($location, $url); $login = wp_login_url();
        if (strtolower((string)wp_parse_url($target, PHP_URL_HOST)) === strtolower((string)wp_parse_url($login, PHP_URL_HOST)) && wp_parse_url($target, PHP_URL_PATH) === wp_parse_url($login, PHP_URL_PATH)) return 'login';
    }
    if (in_array($code, [200, 206], true)) {
        if (preg_match('~<form\b[^>]*class\s*=\s*["\'][^"\']*\bpost-password-form\b~i', $body)) return 'password';
        return 'public';
    }
    return 'unknown';
}

function wphm_su_add(string $value, string $base, string $label, array &$state): void {
    global $wpdb;
    $url = wphm_su_normalize($value, $base);
    if ($url === '') return;
    $hash = hash('sha256', $url); $table = wphm_su_table();
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE url_hash=%s", $hash))) return;
    if ($state['total'] >= WPHM_SU_MAX_URLS) { $state['limited'] = true; return; }
    $fetch = wphm_su_should_fetch($url);
    $head = !$fetch && wphm_su_requestable($url);
    $ok = $wpdb->insert($table, array_merge(['url_hash' => $hash, 'url' => $url, 'source_url' => wphm_su_normalize($base, home_url('/')), 'source_label' => substr($label, 0, 100), 'is_article' => wphm_su_is_article($url) ? 1 : 0, 'crawl_state' => $fetch ? 'queued' : ($head ? 'head' : 'listed'), 'note' => $fetch ? '' : ($head ? 'ヘッダー確認待ち' : 'URLを記録（自動巡回なし）'), 'access_level' => wphm_su_access_level($url), 'redirect_url' => '', 'redirect_state' => $fetch || $head ? '' : 'unverified'], wphm_su_classify_url($url)));
    if ($ok === false) throw new RuntimeException('URLの保存に失敗しました。');
    $state['total']++;
}

/** Do not execute scripts, blocks or shortcodes while extracting saved markup. */
function wphm_su_extract(string $text, string $base, string $label, array &$state, bool $blocks = false): void {
    $urls = [];
    $previous = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if ($text !== '') $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $text . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $document_base = $base;
    $bases = $dom->getElementsByTagName('base');
    if ($bases->length && $bases->item(0)->hasAttribute('href')) $document_base = WP_Http::make_absolute_url($bases->item(0)->getAttribute('href'), $base);
    foreach ($dom->getElementsByTagName('*') as $node) {
        if (!$node->hasAttributes()) continue;
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->name); $value = $attribute->value;
            if (preg_match('/(?:^|[-_:])(?:href|src|url|poster|action)$/', $name)) $urls[] = $value;
            if (in_array($name, ['srcset', 'data-srcset'], true) && stripos($value, 'data:') === false) {
                foreach (explode(',', $value) as $candidate) $urls[] = preg_split('/\s+/', trim($candidate))[0];
            }
            if ($name === 'content' && preg_match('/^https?:\/\//i', $value)) $urls[] = $value;
            if ($name === 'content' && preg_match('/(?:^|;)\s*url\s*=\s*(.+)$/i', $value, $match)) $urls[] = trim($match[1], "\"' ");
        }
        if ($node->nodeName === 'script' && strtolower($node->getAttribute('type')) === 'application/ld+json') {
            $json = json_decode($node->textContent, true);
            if (is_array($json)) wphm_su_json_urls($json, $urls);
        }
    }
    if (preg_match_all('~url\(\s*["\']?([^\)"\'\s]+)["\']?\s*\)|@import\s+["\']([^"\']+)["\']~i', $text, $matches, PREG_SET_ORDER)) foreach ($matches as $match) $urls[] = !empty($match[1]) ? $match[1] : $match[2];
    if ($blocks) foreach (parse_blocks($text) as $block) wphm_su_block_urls($block, $urls);
    $urls = array_values(array_unique($urls));
    if (count($urls) > 2000) $state['limited'] = true;
    foreach (array_slice($urls, 0, 2000) as $url) { wphm_su_add(WP_Http::make_absolute_url((string)$url, $document_base), $base, $label, $state); if ($state['total'] >= WPHM_SU_MAX_URLS) break; }
}

function wphm_su_json_urls(array $data, array &$urls, int $depth = 0): void {
    if ($depth > 20 || count($urls) > 2000) return;
    foreach ($data as $key => $value) {
        if (is_array($value)) wphm_su_json_urls($value, $urls, $depth + 1);
        elseif (is_string($value) && (preg_match('~^https?://~i', $value) || (is_string($key) && preg_match('/url|href|src|poster/i', $key)))) $urls[] = $value;
        if (count($urls) > 2000) return;
    }
}

function wphm_su_block_urls(array $block, array &$urls, int $depth = 0): void {
    if ($depth > 30 || count($urls) > 2000) return;
    wphm_su_json_urls($block['attrs'] ?? [], $urls);
    foreach ($block['innerBlocks'] ?? [] as $child) wphm_su_block_urls($child, $urls, $depth + 1);
}

function wphm_su_start(?array $filters = null): array {
    global $wpdb;
    if (!$wpdb->query('DELETE FROM ' . wphm_su_table()) && $wpdb->last_error) throw new RuntimeException('前回の収集結果を初期化できませんでした。');
    $state = ['run' => str_replace('-', '', wp_generate_uuid4()), 'phase' => 'posts', 'cursor' => 0, 'total' => 0, 'fetched' => 0, 'limited' => false, 'started_at' => time(), 'updated_at' => time()];
    if ($filters !== null) wphm_su_save_filters($filters);
    $roots = [home_url('/'), site_url('/'), home_url('/robots.txt'), wp_login_url(), rest_url(), get_feed_link(), get_bloginfo('comments_rss2_url')];
    if (function_exists('get_sitemap_url')) $roots[] = get_sitemap_url('index');
    foreach (get_post_types(['public' => true]) as $type) { $archive = get_post_type_archive_link($type); if ($archive) $roots[] = $archive; }
    foreach ($roots as $url) if (is_string($url)) wphm_su_add($url, home_url('/'), 'WordPress', $state);
    update_option('wphm_site_urls_state', $state, false);
    return $state;
}

function wphm_su_seed(array &$state, float $deadline): void {
    global $wpdb;
    if ($state['phase'] === 'posts') {
        $types = array_values(array_unique(array_merge(array_values(get_post_types(['public' => true])), ['nav_menu_item', 'wp_navigation'])));
        $marks = implode(',', array_fill(0, count($types), '%s'));
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_status IN ('publish','inherit') AND post_type IN ({$marks}) ORDER BY ID LIMIT 20", array_merge([(int)$state['cursor']], $types)));
        foreach ($ids as $id) {
            $post = get_post((int)$id);
            if (!$post) { $state['cursor'] = (int)$id; continue; }
            $base = is_post_type_viewable($post->post_type) ? (get_permalink($post) ?: home_url('/')) : home_url('/');
            if (is_post_type_viewable($post->post_type)) wphm_su_add($base, home_url('/'), 'WordPress', $state);
            if ($post->post_type === 'attachment') { $file = wp_get_attachment_url($post->ID); if ($file) wphm_su_add($file, $base, 'WordPress', $state); }
            if ($post->post_type === 'nav_menu_item') wphm_su_add((string)get_post_meta($post->ID, '_menu_item_url', true), home_url('/'), 'メニュー', $state);
            wphm_su_extract(substr($post->post_content, 0, WPHM_SU_MAX_BODY), $base, '保存本文', $state, true);
            if (strlen($post->post_content) > WPHM_SU_MAX_BODY) $state['limited'] = true;
            if ($post->post_type === 'post' && $post->post_status === 'publish') {
                wphm_su_add(get_author_posts_url((int)$post->post_author), $base, 'WordPress', $state);
                $y = (int)substr($post->post_date, 0, 4); $m = (int)substr($post->post_date, 5, 2); $d = (int)substr($post->post_date, 8, 2);
                foreach ([get_year_link($y), get_month_link($y, $m), get_day_link($y, $m, $d)] as $url) wphm_su_add($url, $base, 'WordPress', $state);
            }
            $state['cursor'] = (int)$id;
            if (microtime(true) >= $deadline || $state['total'] >= WPHM_SU_MAX_URLS) return;
        }
        if (count($ids) < 20) { $state['phase'] = 'terms'; $state['cursor'] = 0; }
    } elseif ($state['phase'] === 'terms') {
        $taxonomies = array_values(get_taxonomies(['public' => true]));
        if (!$taxonomies) { $state['phase'] = 'crawl'; return; }
        $marks = implode(',', array_fill(0, count($taxonomies), '%s'));
        $terms = $wpdb->get_results($wpdb->prepare("SELECT term_taxonomy_id,term_id,taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id > %d AND taxonomy IN ({$marks}) ORDER BY term_taxonomy_id LIMIT 100", array_merge([(int)$state['cursor']], $taxonomies)), ARRAY_A);
        foreach ($terms as $term) {
            $url = get_term_link((int)$term['term_id'], $term['taxonomy']);
            if (!is_wp_error($url)) wphm_su_add($url, home_url('/'), 'WordPress', $state);
            $state['cursor'] = (int)$term['term_taxonomy_id'];
            if (microtime(true) >= $deadline || $state['total'] >= WPHM_SU_MAX_URLS) return;
        }
        if (count($terms) < 100) $state['phase'] = 'crawl';
    }
}

function wphm_su_crawl(array $row, array &$state): void {
    global $wpdb;
    $url = $row['url']; $note = ''; $code = 0; $body = ''; $location = '';
    $headers_only = in_array($row['crawl_state'], ['head', 'head_get'], true) || !wphm_su_should_fetch($url);
    if (!wphm_su_requestable($url)) $note = '自動巡回の対象外';
    elseif (!wphm_li_allowed_url($url)) $note = '内部IP等への通信を拒否';
    else {
        $args = ['timeout' => 3, 'redirection' => 0, 'limit_response_size' => $headers_only ? 512 : WPHM_SU_MAX_BODY + 1, 'decompress' => false, 'headers' => ['Accept-Encoding' => 'identity']];
        if ($row['crawl_state'] === 'head_get') $args['headers']['Range'] = 'bytes=0-0';
        $response = $headers_only && $row['crawl_state'] !== 'head_get' ? wp_safe_remote_head($url, $args) : wp_safe_remote_get($url, $args);
        $state['fetched']++;
        if (is_wp_error($response)) $note = '取得失敗';
        else {
            $code = (int)wp_remote_retrieve_response_code($response);
            $body = (string)wp_remote_retrieve_body($response);
            $encoding = strtolower(trim((string)wp_remote_retrieve_header($response, 'content-encoding')));
            $type = strtolower((string)wp_remote_retrieve_header($response, 'content-type'));
            if (in_array($code, [301, 302, 303, 307, 308], true)) {
                $target = wp_remote_retrieve_header($response, 'location');
                $location = is_string($target) ? $target : '';
                if (is_string($target)) wphm_su_add($target, $url, '転送先', $state);
                $note = '転送（転送先を別途検証）';
            } elseif (strlen($body) > WPHM_SU_MAX_BODY || !in_array($encoding, ['', 'identity'], true)) { $note = 'サイズ・圧縮上限により解析省略'; $state['limited'] = true; }
            elseif ($code === 200 && !$headers_only) {
                if (strpos($type, 'xml') !== false || preg_match('/^\s*(?:<\?xml|<urlset\b|<sitemapindex\b)/i', $body)) {
                    if (!preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $body)) {
                        $prev = libxml_use_internal_errors(true); $dom = new DOMDocument();
                        if ($body !== '' && $dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) foreach ($dom->getElementsByTagName('loc') as $loc) { wphm_su_add($loc->textContent, $url, 'サイトマップ', $state); if ($state['total'] >= WPHM_SU_MAX_URLS) break; }
                        libxml_clear_errors(); libxml_use_internal_errors($prev);
                        wphm_su_extract($body, $url, '公開XML', $state);
                    } else $note = 'DTDを含むXMLの解析を拒否';
                } elseif (strpos($type, 'json') !== false) {
                    $data = json_decode($body, true); $urls = [];
                    if (is_array($data)) wphm_su_json_urls($data, $urls);
                    foreach ($urls as $candidate) wphm_su_add($candidate, $url, '公開JSON', $state);
                } else {
                    if (strpos($type, 'html') !== false || strpos($type, 'css') !== false || $type === '' || preg_match('/\.css(?:$|\?)/i', $url)) wphm_su_extract($body, $url, '巡回', $state);
                    if (preg_match_all('/^Sitemap:\s*(\S+)/mi', $body, $matches)) foreach ($matches[1] as $candidate) wphm_su_add($candidate, $url, 'robots.txt', $state);
                }
            }
        }
    }
    $redirect = in_array($code, [301, 302, 303, 307, 308], true);
    $target = $redirect ? wphm_su_normalize($location, $url) : '';
    $fallback = $headers_only && $row['crawl_state'] !== 'head_get' && in_array($code, [405, 501], true);
    $classification = $redirect ? ($target !== '' ? 'pending' : 'unknown') : ($code >= 100 ? '' : 'unverified');
    if ($wpdb->update(wphm_su_table(), ['crawl_state' => $fallback ? 'head_get' : 'done', 'http_status' => $code, 'note' => $fallback ? 'ヘッダー確認を再試行中' : $note, 'access_level' => wphm_su_access_level($url, $code, $body, $location), 'redirect_state' => $classification, 'redirect_url' => $target, 'redirect_hops' => 0, 'redirect_method' => 'HEAD'], ['id' => (int)$row['id']]) === false) throw new RuntimeException('収集状態の保存に失敗しました。');
}

/** One bounded request per batch iteration. Images are inspected by headers, not downloaded. */
function wphm_su_probe_redirect(array $row, array &$state): void {
    global $wpdb;
    $url = $row['redirect_url']; $result = 'unknown'; $next = $url;
    $hops = (int)$row['redirect_hops']; $method = $row['redirect_method'] === 'GET' ? 'GET' : 'HEAD';
    if ($url !== '' && $hops < 5 && wphm_su_requestable($url) && wphm_su_normalize($url, home_url('/')) === $url && wphm_li_allowed_url($url)) {
        $args = ['timeout' => 3, 'redirection' => 0, 'limit_response_size' => 512, 'decompress' => false, 'headers' => ['Accept-Encoding' => 'identity']];
        if ($method === 'GET') $args['headers']['Range'] = 'bytes=0-0';
        $response = $method === 'HEAD' ? wp_safe_remote_head($url, $args) : wp_safe_remote_get($url, $args);
        $state['fetched']++;
        if (!is_wp_error($response)) {
            $code = (int)wp_remote_retrieve_response_code($response);
            $type = strtolower(trim(explode(';', (string)wp_remote_retrieve_header($response, 'content-type'))[0]));
            if ($method === 'HEAD' && in_array($code, [405, 501], true)) { $result = 'pending'; $method = 'GET'; }
            elseif (in_array($code, [301, 302, 303, 307, 308], true)) {
                $location = wp_remote_retrieve_header($response, 'location');
                $next = is_string($location) ? wphm_su_normalize($location, $url) : '';
                $hops++; $method = 'HEAD';
                if ($next !== '' && $next !== $url && $hops < 5) { $result = 'pending'; wphm_su_add($next, $row['url'], '転送先', $state); }
            } elseif (in_array($code, [200, 206], true) && $type !== '') $result = strpos($type, 'image/') === 0 ? 'image' : 'other';
        }
    }
    $notes = ['pending' => '転送先の種類を確認中', 'image' => '画像ファイルへ自動転送', 'other' => '画像以外へ自動転送', 'unknown' => '転送先の種類を確認できません'];
    if ($wpdb->update(wphm_su_table(), ['redirect_state' => $result, 'redirect_url' => $next, 'redirect_hops' => $hops, 'redirect_method' => $method, 'note' => $notes[$result]], ['id' => (int)$row['id']]) === false) throw new RuntimeException('転送先の判定を保存できませんでした。');
}

function wphm_su_step(string $run): array {
    global $wpdb;
    $state = get_option('wphm_site_urls_state', []);
    if (!is_array($state) || empty($state['run']) || !hash_equals($state['run'], $run)) throw new RuntimeException('収集が更新されました。画面を再読み込みしてください。');
    if (wphm_su_clear_requested($run)) return [];
    $legacy = $wpdb->get_results('SELECT * FROM ' . wphm_su_table() . ' WHERE classified = 0 ORDER BY id LIMIT 20', ARRAY_A);
    foreach ($legacy as $row) {
        $values = wphm_su_classify_url($row['url']);
        if ($row['crawl_state'] === 'listed') {
            if (wphm_su_requestable($row['url'])) $values['crawl_state'] = 'head';
            else $values['redirect_state'] = 'unverified';
        }
        if ($wpdb->update(wphm_su_table(), $values, ['id' => (int)$row['id']]) === false) throw new RuntimeException('URLの種類を保存できませんでした。');
    }
    if ($state['phase'] === 'done') {
        if (!$wpdb->get_var("SELECT id FROM " . wphm_su_table() . " WHERE classified = 0 OR crawl_state IN ('queued','head','head_get') OR redirect_state = 'pending' LIMIT 1")) return $state;
        $state['phase'] = 'crawl';
    }
    $state['total'] = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . wphm_su_table());
    $deadline = microtime(true) + 9;
    if ($state['phase'] !== 'crawl') wphm_su_seed($state, $deadline);
    else {
        for ($i = 0; $i < 3 && microtime(true) < $deadline; $i++) {
            if ($state['fetched'] >= WPHM_SU_MAX_FETCHES) { $state['limited'] = true; $state['phase'] = 'done'; break; }
            $row = $wpdb->get_row("SELECT * FROM " . wphm_su_table() . " WHERE redirect_state='pending' OR crawl_state IN ('queued','head','head_get') ORDER BY (redirect_state='pending') DESC, id LIMIT 1", ARRAY_A);
            if (!$row) { $state['phase'] = 'done'; break; }
            if ($row['redirect_state'] === 'pending') wphm_su_probe_redirect($row, $state);
            else wphm_su_crawl($row, $state);
        }
    }
    if ($state['total'] >= WPHM_SU_MAX_URLS) { $state['limited'] = true; $state['phase'] = 'done'; }
    if ($legacy && $wpdb->get_var('SELECT id FROM ' . wphm_su_table() . ' WHERE classified = 0 LIMIT 1')) $state['phase'] = 'crawl';
    $state['updated_at'] = time();
    if (wphm_su_clear_requested($run)) return [];
    update_option('wphm_site_urls_state', $state, false);
    return $state;
}
