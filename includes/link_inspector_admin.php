<?php
if (!defined('ABSPATH')) exit;

/**
 * SAFE Link Inspector (v0)
 * - Avoid fatal by guarding missing ext/functions and by separating DOM usage.
 */

if (defined('WPHM_LINK_INSPECTOR_LOADED')) return;
define('WPHM_LINK_INSPECTOR_LOADED', 1);

const WPHM_LI_OPT_SETTINGS = 'wphm_link_inspector_settings';
const WPHM_LI_OPT_STATE    = 'wphm_link_inspector_state';

add_action('admin_post_wphm_link_scan_start', 'wphm_handle_link_scan_start');
add_action('admin_post_wphm_link_scan_step',  'wphm_handle_link_scan_step');
add_action('admin_post_wphm_link_scan_reset', 'wphm_handle_link_scan_reset');

function wphm_li_defaults_settings(): array {
    return [
        'sitemap_url'  => home_url('/sitemap.xml'),
        'bad_patterns' => "https://v2.pretty-cute.info/\nhttps://pretty-cut.info/\nhttps://pretty-cutee.info/",
        'typo_level'   => 'weak',
        'target_host'  => 'pretty-cute.info',
        'types'        => ['a'=>1,'img'=>1,'video'=>0,'source'=>0],
        'batch_size'   => 20,
        'timeout'      => 8,
        'max_results'  => 2000,
    ];
}

function wphm_li_get_settings(): array {
    $d = wphm_li_defaults_settings();
    $s = get_option(WPHM_LI_OPT_SETTINGS, []);
    if (!is_array($s)) $s = [];
    $s = array_merge($d, $s);
    if (!isset($s['types']) || !is_array($s['types'])) $s['types'] = $d['types'];
    $s['types'] = array_merge($d['types'], $s['types']);
    return $s;
}

function wphm_li_update_settings(array $s): void {
    update_option(WPHM_LI_OPT_SETTINGS, $s, false);
}

function wphm_li_get_state(): array {
    $st = get_option(WPHM_LI_OPT_STATE, []);
    return is_array($st) ? $st : [];
}

function wphm_li_update_state(array $st): void {
    update_option(WPHM_LI_OPT_STATE, $st, false);
}

function wphm_li_clear_state(): void {
    delete_option(WPHM_LI_OPT_STATE);
}

function wphm_li_admin_url(array $q = []): string {
    $base = admin_url('admin.php?page=wphm-link-inspector');
    return $q ? add_query_arg($q, $base) : $base;
}

function wphm_li_require_cap_or_die(): void {
    if (!current_user_can('manage_options')) wp_die('権限がありません');
}

function wphm_li_verify_nonce_or_die(): void {
    if (!isset($_POST['wphm_link_scan_nonce']) || !wp_verify_nonce($_POST['wphm_link_scan_nonce'], 'wphm_link_scan')) {
        wp_die('Nonceが不正です。');
    }
}

/**
 * UI
 */
function wphm_render_link_inspector_tool_page() {
    wphm_li_require_cap_or_die();
    $settings = wphm_li_get_settings();
    $state    = wphm_li_get_state();

    echo '<div class="wrap wphm-app">';
    if (function_exists('wphm_render_header')) {
        wphm_render_header('リンク点検');
    } else {
        echo '<h1>リンク点検</h1>';
    }

    // env checks (show but do not fatal)
    $warnings = [];
    if (!function_exists('url_to_postid')) $warnings[] = 'url_to_postid() が利用できません（WPコア関数のはずなので通常は出ません）';
    if (!function_exists('levenshtein')) $warnings[] = 'levenshtein() が無効です（typo検出は無効になります）';
    if (!class_exists('DOMDocument')) $warnings[] = 'PHP拡張 DOMDocument が無いので、HTMLリンク抽出は簡易モードになります（落ちはしません）';

    if ($warnings) {
        echo '<div class="notice notice-warning"><p><strong>環境チェック：</strong><br>' . implode('<br>', array_map('esc_html', $warnings)) . '</p></div>';
    }

    if (!empty($_GET['started'])) echo '<div class="notice notice-success"><p>スキャンを開始しました。</p></div>';
    if (!empty($_GET['stepped'])) echo '<div class="notice notice-info"><p>バッチ処理を実行しました。</p></div>';
    if (!empty($_GET['reset']))   echo '<div class="notice notice-warning"><p>状態をリセットしました。</p></div>';

    echo '<h2 style="margin-top:12px;">設定 & 開始</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="wphm_link_scan_start">';
    wp_nonce_field('wphm_link_scan', 'wphm_link_scan_nonce');

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row">サイトマップURL</th><td>';
    echo '<input type="url" name="sitemap_url" style="width:100%;max-width:720px;" value="' . esc_attr($settings['sitemap_url']) . '">';
    echo '</td></tr>';

    echo '<tr><th scope="row">一致パターン（部分一致）</th><td>';
    echo '<textarea name="bad_patterns" rows="5" style="width:100%;max-width:720px;">' . esc_textarea($settings['bad_patterns']) . '</textarea>';
    echo '</td></tr>';

    echo '<tr><th scope="row">typo検出</th><td>';
    $levels = ['weak'=>'弱','medium'=>'中','high'=>'高'];
    echo '<select name="typo_level">';
    foreach ($levels as $k=>$label) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($settings['typo_level'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>　正しいホスト：<input type="text" name="target_host" value="' . esc_attr($settings['target_host']) . '" style="width:220px;">';
    echo '</td></tr>';

    echo '<tr><th scope="row">対象種別</th><td>';
    foreach (['a'=>'HTML(a[href])','img'=>'画像(img[src])','video'=>'動画(video[src])','source'=>'source[src]'] as $k=>$label) {
        $checked = !empty($settings['types'][$k]) ? 'checked' : '';
        echo '<label style="margin-right:12px;"><input type="checkbox" name="types[' . esc_attr($k) . ']" value="1" ' . $checked . '> ' . esc_html($label) . '</label>';
    }
    echo '</td></tr>';

    echo '<tr><th scope="row">バッチサイズ</th><td>';
    echo '<input type="number" name="batch_size" min="5" max="100" value="' . (int)$settings['batch_size'] . '" style="width:90px;">';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p><button class="button button-primary" type="submit">スキャン開始（キュー作成）</button></p>';
    echo '</form>';

    echo '<hr style="margin:18px 0;">';
    echo '<h2>進捗</h2>';

    $queue_total = isset($state['queue']) && is_array($state['queue']) ? count($state['queue']) : 0;
    $cursor      = isset($state['cursor']) ? (int)$state['cursor'] : 0;
    $done        = !empty($state['done']);
    $results_cnt = isset($state['results']) && is_array($state['results']) ? count($state['results']) : 0;

    if ($queue_total === 0) {
        echo '<p>まだ開始していません。</p>';
    } else {
        echo '<p><strong>' . esc_html(min($cursor, $queue_total)) . '</strong> / <strong>' . esc_html($queue_total) . '</strong> URL 処理済み';
        echo '　/　検出：<strong>' . esc_html($results_cnt) . '</strong> 件';
        if ($done) echo '　/　<strong style="color:#0a7;">完了</strong>';
        echo '</p>';

        if (!$done) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:8px;">';
            echo '<input type="hidden" name="action" value="wphm_link_scan_step">';
            wp_nonce_field('wphm_link_scan', 'wphm_link_scan_nonce');
            echo '<button class="button button-secondary" type="submit">次のバッチを処理</button>';
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;">';
        echo '<input type="hidden" name="action" value="wphm_link_scan_reset">';
        wp_nonce_field('wphm_link_scan', 'wphm_link_scan_nonce');
        echo '<button class="button" type="submit" onclick="return confirm(\'状態をリセットします。よろしいですか？\');">リセット</button>';
        echo '</form>';
    }

    echo '<hr style="margin:18px 0;">';
    echo '<h2>検出結果</h2>';

    $results = isset($state['results']) && is_array($state['results']) ? $state['results'] : [];
    if (!$results) {
        echo '<p>まだ結果はありません。</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>投稿</th><th>投稿URL</th><th>検出リンク</th><th>種別</th><th>理由</th></tr></thead><tbody>';
        $show = array_reverse($results);
        $limit = min(200, count($show));
        for ($i=0; $i<$limit; $i++) {
            $r = $show[$i];
            echo '<tr>';
            echo '<td>' . esc_html($r['post_title'] ?? '') . ' (#' . (int)($r['post_id'] ?? 0) . ')</td>';
            echo '<td><a href="' . esc_url($r['post_url'] ?? '') . '" target="_blank" rel="noopener">' . esc_html($r['post_url'] ?? '') . '</a></td>';
            echo '<td><a href="' . esc_url($r['found_url'] ?? '') . '" target="_blank" rel="noopener">' . esc_html($r['found_url'] ?? '') . '</a></td>';
            echo '<td>' . esc_html($r['type'] ?? '') . '</td>';
            echo '<td>' . esc_html($r['reason'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        if (count($results) > 200) echo '<p class="description">表示は最新200件のみ</p>';
    }

    echo '</div>';
}

/**
 * START: save settings, build queue
 */
function wphm_handle_link_scan_start() {
    wphm_li_require_cap_or_die();
    wphm_li_verify_nonce_or_die();

    $d = wphm_li_defaults_settings();

    $sitemap_url  = isset($_POST['sitemap_url']) ? esc_url_raw(trim((string)$_POST['sitemap_url'])) : $d['sitemap_url'];
    $bad_patterns = isset($_POST['bad_patterns']) ? (string)$_POST['bad_patterns'] : $d['bad_patterns'];
    $typo_level   = isset($_POST['typo_level']) ? sanitize_text_field((string)$_POST['typo_level']) : $d['typo_level'];
    $target_host  = isset($_POST['target_host']) ? strtolower(trim(sanitize_text_field((string)$_POST['target_host']))) : $d['target_host'];
    $batch_size   = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : (int)$d['batch_size'];

    if (!in_array($typo_level, ['weak','medium','high'], true)) $typo_level = $d['typo_level'];
    $batch_size = max(5, min(100, $batch_size));

    $types = $d['types'];
    if (isset($_POST['types']) && is_array($_POST['types'])) {
        foreach ($types as $k => $_) $types[$k] = !empty($_POST['types'][$k]) ? 1 : 0;
    }

    $settings = wphm_li_get_settings();
    $settings['sitemap_url']  = $sitemap_url ?: $d['sitemap_url'];
    $settings['bad_patterns'] = $bad_patterns;
    $settings['typo_level']   = $typo_level;
    $settings['target_host']  = $target_host ?: $d['target_host'];
    $settings['types']        = $types;
    $settings['batch_size']   = $batch_size;

    wphm_li_update_settings($settings);

    $queue = wphm_li_build_queue_from_sitemap($settings['sitemap_url'], (int)$settings['timeout']);

    wphm_li_update_state([
        'queue' => $queue,
        'cursor'=> 0,
        'results'=> [],
        'done' => empty($queue),
        'started_at'=> time(),
    ]);

    wp_safe_redirect(wphm_li_admin_url(['started'=>1]));
    exit;
}

/**
 * STEP: process N URLs
 */
function wphm_handle_link_scan_step() {
    wphm_li_require_cap_or_die();
    wphm_li_verify_nonce_or_die();

    $settings = wphm_li_get_settings();
    $state    = wphm_li_get_state();

    $queue   = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : [];
    $cursor  = isset($state['cursor']) ? (int)$state['cursor'] : 0;
    $results = isset($state['results']) && is_array($state['results']) ? $state['results'] : [];

    if (!$queue || !empty($state['done'])) {
        wp_safe_redirect(wphm_li_admin_url(['stepped'=>1]));
        exit;
    }

    $batch = (int)$settings['batch_size'];
    $batch = ($batch < 5) ? 20 : min(100, $batch);

    $total = count($queue);
    $end   = min($cursor + $batch, $total);

    $patterns = wphm_li_patterns_from_text($settings['bad_patterns']);
    $target_host = strtolower($settings['target_host']);
    $typo_threshold = wphm_li_typo_threshold($settings['typo_level']);
    $types = $settings['types'];

    for ($i=$cursor; $i<$end; $i++) {
        $url = $queue[$i];

        // URL -> post
        $post_id = function_exists('url_to_postid') ? url_to_postid($url) : 0;
        if (!$post_id) continue;

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') continue;

        $found = wphm_li_scan_post_for_bad_links($post, $types, $patterns, $target_host, $typo_threshold);

        foreach ($found as $row) {
            $results[] = $row;
            $max = (int)$settings['max_results'];
            if ($max > 0 && count($results) > $max) $results = array_slice($results, -$max);
        }
    }

    $cursor = $end;
    $state['cursor'] = $cursor;
    $state['results']= $results;
    $state['done']   = ($cursor >= $total);

    wphm_li_update_state($state);

    wp_safe_redirect(wphm_li_admin_url(['stepped'=>1]));
    exit;
}

function wphm_handle_link_scan_reset() {
    wphm_li_require_cap_or_die();
    wphm_li_verify_nonce_or_die();
    wphm_li_clear_state();
    wp_safe_redirect(wphm_li_admin_url(['reset'=>1]));
    exit;
}

/**
 * sitemap helpers (DOMDocument optional)
 */
function wphm_li_build_queue_from_sitemap(string $sitemap_url, int $timeout = 8): array {
    $sitemap_url = esc_url_raw($sitemap_url);
    if (!$sitemap_url) return [];

    $xml = wphm_li_fetch_body($sitemap_url, $timeout);
    if ($xml === '') return [];

    // If DOMDocument not available, fallback to regex loc extraction
    $urls = class_exists('DOMDocument') ? wphm_li_parse_sitemap_dom($xml) : wphm_li_parse_sitemap_regex($xml);
    if (!$urls) return [];

    // detect sitemapindex
    $is_index = (stripos($xml, '<sitemapindex') !== false);
    if ($is_index) {
        $all = [];
        foreach ($urls as $child) {
            $child_xml = wphm_li_fetch_body($child, $timeout);
            if ($child_xml === '') continue;
            $child_urls = class_exists('DOMDocument') ? wphm_li_parse_sitemap_dom($child_xml) : wphm_li_parse_sitemap_regex($child_xml);
            foreach ($child_urls as $u) $all[] = $u;
        }
        $urls = $all;
    }

    $urls = array_values(array_unique(array_filter($urls, function($u){
        return is_string($u) && $u !== '';
    })));

    return $urls;
}

function wphm_li_fetch_body(string $url, int $timeout): string {
    $res = wp_remote_get($url, ['timeout'=>$timeout]);
    if (is_wp_error($res)) return '';
    if (wp_remote_retrieve_response_code($res) !== 200) return '';
    return (string)wp_remote_retrieve_body($res);
}

function wphm_li_parse_sitemap_dom(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_use_internal_errors($prev);
    if (!$ok) return [];
    $out = [];
    foreach ($dom->getElementsByTagName('loc') as $loc) {
        $u = trim((string)$loc->textContent);
        if ($u !== '') $out[] = $u;
    }
    return $out;
}

function wphm_li_parse_sitemap_regex(string $xml): array {
    // crude but safe fallback
    if (!preg_match_all('~<loc>\s*([^<]+)\s*</loc>~i', $xml, $m)) return [];
    $out = [];
    foreach ($m[1] as $u) {
        $u = trim($u);
        if ($u !== '') $out[] = $u;
    }
    return $out;
}

/**
 * scanning
 */
function wphm_li_scan_post_for_bad_links(WP_Post $post, array $types, array $patterns, string $target_host, int $typo_threshold): array {
    $html = (string)$post->post_content;
    if ($html === '') return [];

    $links = wphm_li_extract_links_safe($html, $types);
    if (!$links) return [];

    $post_url = get_permalink($post->ID);
    $post_title = get_the_title($post->ID);

    $found = [];

    foreach ($links as $item) {
        $u = trim((string)($item['url'] ?? ''));
        $type = (string)($item['type'] ?? '');
        if ($u === '') continue;
        if (preg_match('~^(#|mailto:|tel:|javascript:)~i', $u)) continue;

        $abs = wphm_li_to_absolute_url($u, $post_url);

        $reason = '';
        foreach ($patterns as $p) {
            if ($p !== '' && stripos($abs, $p) !== false) { $reason = '一致: ' . $p; break; }
        }

        if ($reason === '' && $typo_threshold > 0 && function_exists('levenshtein')) {
            $host = wphm_li_get_host($abs);
            if ($host !== '') {
                $dist = levenshtein(wphm_li_host_norm($host), wphm_li_host_norm($target_host));
                if ($dist > 0 && $dist <= $typo_threshold) {
                    $reason = 'typo疑い: host距離=' . $dist . ' (正:' . $target_host . ' / 検出:' . $host . ')';
                }
            }
        }

        if ($reason !== '') {
            $found[] = [
                'post_id'=>$post->ID,
                'post_title'=>$post_title,
                'post_url'=>$post_url,
                'found_url'=>$abs,
                'type'=>$type,
                'reason'=>$reason,
            ];
        }
    }

    return $found;
}

function wphm_li_extract_links_safe(string $html, array $types): array {
    // If DOM available, use it. Otherwise fallback to regex.
    if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
        return wphm_li_extract_links_dom($html, $types);
    }
    return wphm_li_extract_links_regex($html, $types);
}

function wphm_li_extract_links_dom(string $html, array $types): array {
    $out = [];
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $wrapped = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
    $dom->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($dom);

    if (!empty($types['a'])) {
        $nodes = $xpath->query('//a[@href]');
        if ($nodes instanceof DOMNodeList) foreach ($nodes as $n) if ($n instanceof DOMElement) $out[] = ['type'=>'a','url'=>$n->getAttribute('href')];
    }
    if (!empty($types['img'])) {
        $nodes = $xpath->query('//img[@src]');
        if ($nodes instanceof DOMNodeList) foreach ($nodes as $n) if ($n instanceof DOMElement) $out[] = ['type'=>'img','url'=>$n->getAttribute('src')];
    }
    if (!empty($types['video'])) {
        $nodes = $xpath->query('//video[@src]');
        if ($nodes instanceof DOMNodeList) foreach ($nodes as $n) if ($n instanceof DOMElement) $out[] = ['type'=>'video','url'=>$n->getAttribute('src')];
    }
    if (!empty($types['source'])) {
        $nodes = $xpath->query('//source[@src]');
        if ($nodes instanceof DOMNodeList) foreach ($nodes as $n) if ($n instanceof DOMElement) $out[] = ['type'=>'source','url'=>$n->getAttribute('src')];
    }

    return wphm_li_dedupe_links($out);
}

function wphm_li_extract_links_regex(string $html, array $types): array {
    $out = [];
    if (!empty($types['a']) && preg_match_all('~<a[^>]+href\s*=\s*([\'"])(.*?)\1~is', $html, $m)) {
        foreach ($m[2] as $u) $out[] = ['type'=>'a','url'=>$u];
    }
    if (!empty($types['img']) && preg_match_all('~<img[^>]+src\s*=\s*([\'"])(.*?)\1~is', $html, $m)) {
        foreach ($m[2] as $u) $out[] = ['type'=>'img','url'=>$u];
    }
    if (!empty($types['video']) && preg_match_all('~<video[^>]+src\s*=\s*([\'"])(.*?)\1~is', $html, $m)) {
        foreach ($m[2] as $u) $out[] = ['type'=>'video','url'=>$u];
    }
    if (!empty($types['source']) && preg_match_all('~<source[^>]+src\s*=\s*([\'"])(.*?)\1~is', $html, $m)) {
        foreach ($m[2] as $u) $out[] = ['type'=>'source','url'=>$u];
    }
    return wphm_li_dedupe_links($out);
}

function wphm_li_dedupe_links(array $out): array {
    $seen = [];
    $uniq = [];
    foreach ($out as $row) {
        $k = ($row['type'] ?? '') . '|' . ($row['url'] ?? '');
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $uniq[] = $row;
    }
    return $uniq;
}

function wphm_li_patterns_from_text(string $text): array {
    $lines = preg_split("/\r\n|\n|\r/", (string)$text);
    $out = [];
    foreach ($lines as $l) { $l = trim($l); if ($l !== '') $out[] = $l; }
    return $out;
}

function wphm_li_typo_threshold(string $level): int {
    return ($level === 'high') ? 3 : (($level === 'medium') ? 2 : 1);
}

function wphm_li_get_host(string $url): string {
    $p = wp_parse_url($url);
    return (is_array($p) && !empty($p['host'])) ? strtolower((string)$p['host']) : '';
}

function wphm_li_host_norm(string $host): string {
    $h = strtolower(trim($host));
    if (strpos($h, 'www.') === 0) $h = substr($h, 4);
    return $h;
}

function wphm_li_to_absolute_url(string $maybe_url, string $base_url): string {
    if (preg_match('~^https?://~i', $maybe_url)) return $maybe_url;
    if (strpos($maybe_url, '//') === 0) {
        $scheme = wp_parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $maybe_url;
    }
    if (strpos($maybe_url, '/') === 0) {
        $scheme = wp_parse_url($base_url, PHP_URL_SCHEME) ?: 'https';
        $host   = wp_parse_url($base_url, PHP_URL_HOST) ?: '';
        return $host ? ($scheme . '://' . $host . $maybe_url) : $maybe_url;
    }
    $base = trailingslashit(dirname($base_url));
    return $base . ltrim($maybe_url, '/');
}
