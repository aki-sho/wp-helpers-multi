<?php
/* =========================
 * tools/link_inspector.php（完成版・これに丸ごと差し替え）
 * ========================= */
if (!defined('ABSPATH')) exit;

const WPHM_LI_OPT_SETTINGS = 'wphm_link_inspector_settings';
const WPHM_LI_OPT_STATE    = 'wphm_link_inspector_state';

function wphm_li_defaults_settings(): array {
    return [
        'sitemap_url'   => home_url('/sitemap.xml'),
        'bad_patterns'  => "https://v2.pretty-cute.info/\nhttps://pretty-cut.info/\nhttps://pretty-cutee.info/",
        'typo_level'    => 'weak', // weak|medium|high
        'target_host'   => 'pretty-cute.info',
        'types'         => [
            'a'      => 1, // a[href]
            'img'    => 1, // img[src]
            'video'  => 0, // video[src]
            'source' => 0, // source[src]
        ],
        'batch_size'    => 20,
        'timeout'       => 8,
        'max_results'   => 5000,
        'max_queue'     => 20000, // sitemap から拾う上限（DB/メモリ保護）
    ];
}

function wphm_li_get_settings(): array {
    $d = wphm_li_defaults_settings();
    $s = get_option(WPHM_LI_OPT_SETTINGS, []);
    if (!is_array($s)) $s = [];

    $s = array_merge($d, $s);
    if (!isset($s['types']) || !is_array($s['types'])) $s['types'] = $d['types'];
    $s['types'] = array_merge($d['types'], $s['types']);

    $s['batch_size']  = max(5, min(100, (int)($s['batch_size'] ?? 20)));
    $s['timeout']     = max(3, min(30, (int)($s['timeout'] ?? 8)));
    $s['max_results'] = max(100, min(50000, (int)($s['max_results'] ?? 5000)));
    $s['max_queue']   = max(100, min(200000, (int)($s['max_queue'] ?? 20000)));

    if (!in_array($s['typo_level'], ['weak','medium','high'], true)) $s['typo_level'] = 'weak';
    $s['target_host'] = strtolower(trim((string)($s['target_host'] ?? '')));
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
/** =========================
 * POST handler（表示関数の先頭で実行）
 * ========================= */
function wphm_li_handle_post_on_render(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (empty($_POST['wphm_li_do'])) return;

    if (!isset($_POST['wphm_link_scan_nonce']) || !wp_verify_nonce($_POST['wphm_link_scan_nonce'], 'wphm_link_scan')) {
        wp_die('Nonceが不正です。');
    }

    $do = sanitize_text_field((string)$_POST['wphm_li_do']);

    if ($do === 'start') {
        wphm_li_do_start();
        wp_safe_redirect(wphm_li_admin_url(['started' => 1]));
        exit;
    }
    if ($do === 'step') {
        wphm_li_do_step();
        wp_safe_redirect(wphm_li_admin_url(['stepped' => 1]));
        exit;
    }
    if ($do === 'reset') {
        wphm_li_clear_state();
        wp_safe_redirect(wphm_li_admin_url(['reset' => 1]));
        exit;
    }
}

/** =========================
 * RENDER
 * ========================= */
function wphm_render_link_inspector_tool_page() {

  wphm_li_handle_post_on_render();

    $settings = wphm_li_get_settings();
    $state    = wphm_li_get_state();
    $action   = wphm_li_admin_url();

    echo '<div class="wrap wphm-app">';
    if (function_exists('wphm_render_header')) {
        wphm_render_header('リンク点検');
    } else {
        echo '<h1>リンク点検</h1>';
    }

    if (!empty($_GET['started'])) echo '<div class="notice notice-success"><p>スキャンを開始しました。</p></div>';
    if (!empty($_GET['stepped'])) echo '<div class="notice notice-info"><p>バッチ処理を実行しました。</p></div>';
    if (!empty($_GET['reset']))   echo '<div class="notice notice-warning"><p>状態をリセットしました。</p></div>';

    if (!empty($state['last_error'])) {
        echo '<div class="notice notice-error"><p>エラー: ' . esc_html((string)$state['last_error']) . '</p></div>';
    }

    // ===== 設定 & 開始 =====
    echo '<h2 style="margin-top:12px;">設定 & 開始</h2>';
    echo '<form method="post" action="' . esc_url($action) . '">';
    wp_nonce_field('wphm_link_scan', 'wphm_link_scan_nonce');
    echo '<input type="hidden" name="wphm_li_do" value="start">';

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label>サイトマップURL</label></th><td>';
    echo '<input type="url" name="sitemap_url" style="width:100%; max-width:720px;" value="' . esc_attr($settings['sitemap_url']) . '">';
    echo '<p class="description">例：' . esc_html(home_url('/sitemap.xml')) . '</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label>一致パターン（部分一致）</label></th><td>';
    echo '<textarea name="bad_patterns" rows="5" style="width:100%; max-width:720px;">' . esc_textarea($settings['bad_patterns']) . '</textarea>';
    echo '<p class="description">1行=1パターン。含んでいれば一致。</p>';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label>typo検出（弱/中/高）</label></th><td>';
    $levels = ['weak' => '弱', 'medium' => '中', 'high' => '高'];
    echo '<select name="typo_level">';
    foreach ($levels as $k => $label) {
        echo '<option value="' . esc_attr($k) . '" ' . selected($settings['typo_level'], $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '　<span class="description">正しいホスト：</span> <input type="text" name="target_host" value="' . esc_attr($settings['target_host']) . '" style="width:220px;">';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label>対象リンク種別</label></th><td>';
    foreach (['a' => 'HTML(a[href])', 'img' => '画像(img[src])', 'video' => '動画(video[src])', 'source' => 'source[src]'] as $key => $label) {
        $checked = !empty($settings['types'][$key]) ? 'checked' : '';
        echo '<label style="margin-right:12px;"><input type="checkbox" name="types[' . esc_attr($key) . ']" value="1" ' . $checked . '> ' . esc_html($label) . '</label>';
    }
    echo '</td></tr>';

    echo '<tr><th scope="row"><label>バッチサイズ</label></th><td>';
    echo '<input type="number" name="batch_size" min="5" max="100" value="' . (int)$settings['batch_size'] . '" style="width:90px;">';
    echo ' <span class="description">（1回で処理するURL数）</span>';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p><button class="button button-primary" type="submit">スキャン開始（キュー作成）</button></p>';
    echo '</form>';

    // ===== 進捗 =====
    echo '<hr style="margin:18px 0;">';
    echo '<h2>進捗</h2>';

    $queue_total = isset($state['queue']) && is_array($state['queue']) ? count($state['queue']) : 0;
    $cursor      = isset($state['cursor']) ? (int)$state['cursor'] : 0;
    $done        = !empty($state['done']);
    $results_cnt = isset($state['results']) && is_array($state['results']) ? count($state['results']) : 0;

    if ($queue_total === 0) {
        echo '<p>まだ開始していません。</p>';
    } else {
        $c = min($cursor, $queue_total);
        echo '<p><strong>' . esc_html((string)$c) . '</strong> / <strong>' . esc_html((string)$queue_total) . '</strong> URL 処理済み';
        echo '　/　検出結果：<strong>' . esc_html((string)$results_cnt) . '</strong> 件';
        if ($done) echo '　/　<strong style="color:#0a7;">完了</strong>';
        echo '</p>';

        if (!$done) {
            echo '<form method="post" action="' . esc_url($action) . '" style="display:inline-block; margin-right:8px;">';
            wp_nonce_field('wphm_link_scan', 'wphm_link_scan_nonce');
            echo '<input type="hidden" name="wphm_li_do" value="step">';
            echo '<button class="button button-secondary" type="submit">次のバッチを処理</button>';
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url($action) . '" style="display:inline-block;">';
        wp_nonce_field('wphm_link_scan', 'wphm_link_scan_nonce');
        echo '<input type="hidden" name="wphm_li_do" value="reset">';
        echo '<button class="button" type="submit" onclick="return confirm(\'状態をリセットします。よろしいですか？\');">リセット</button>';
        echo '</form>';
    }

    // ===== 結果 =====
    echo '<hr style="margin:18px 0;">';
    echo '<h2>検出結果</h2>';

    $results = isset($state['results']) && is_array($state['results']) ? $state['results'] : [];
    if (empty($results)) {
        echo '<p>まだ結果はありません。</p>';
    } else {
        echo '<div style="overflow:auto; max-width:100%;">';
        echo '<table class="widefat striped" style="min-width:980px;">';
        echo '<thead><tr><th>投稿</th><th>投稿URL</th><th>検出リンク</th><th>種別</th><th>理由</th></tr></thead><tbody>';

        $show  = array_reverse($results);
        $limit = min(300, count($show));
        for ($i = 0; $i < $limit; $i++) {
            $r = $show[$i];
            echo '<tr>';
            echo '<td>' . esc_html($r['post_title'] ?? '') . ' (#' . (int)($r['post_id'] ?? 0) . ')</td>';
            echo '<td><a href="' . esc_url($r['post_url'] ?? '') . '" target="_blank" rel="noopener">' . esc_html($r['post_url'] ?? '') . '</a></td>';
            echo '<td><a href="' . esc_url($r['found_url'] ?? '') . '" target="_blank" rel="noopener">' . esc_html($r['found_url'] ?? '') . '</a></td>';
            echo '<td>' . esc_html($r['type'] ?? '') . '</td>';
            echo '<td>' . esc_html($r['reason'] ?? '') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        if (count($results) > 300) {
            echo '<p class="description">表示は最新300件のみ（保存は継続）。</p>';
        }
    }

    echo '</div>';
}

/** =========================
 * START / STEP
 * ========================= */
function wphm_li_do_start(): void {
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

    $queue = wphm_li_build_queue_from_sitemap($settings['sitemap_url'], (int)$settings['timeout'], (int)$settings['max_queue']);

    wphm_li_update_state([
        'queue'       => $queue,
        'cursor'      => 0,
        'results'     => [],
        'done'        => empty($queue),
        'started_at'  => time(),
        'last_step_at'=> 0,
        'last_error'  => '',
    ]);
}

function wphm_li_do_step(): void {
    // fatalをstateに残す
    register_shutdown_function(function() {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $st = wphm_li_get_state();
            $st['last_error'] = $e['message'] . ' in ' . $e['file'] . ':' . $e['line'];
            wphm_li_update_state($st);
        }
    });

    $settings = wphm_li_get_settings();
    $state    = wphm_li_get_state();

    $queue   = isset($state['queue']) && is_array($state['queue']) ? $state['queue'] : [];
    $cursor  = isset($state['cursor']) ? (int)$state['cursor'] : 0;
    $results = isset($state['results']) && is_array($state['results']) ? $state['results'] : [];

    if (empty($queue) || !empty($state['done'])) return;

    $batch = max(5, min(100, (int)($settings['batch_size'] ?? 20)));
    $total = count($queue);
    $end   = min($cursor + $batch, $total);

    $patterns       = wphm_li_patterns_from_text($settings['bad_patterns'] ?? '');
    $target_host    = strtolower((string)($settings['target_host'] ?? ''));
    $typo_threshold = wphm_li_typo_threshold((string)($settings['typo_level'] ?? 'weak'));
    $types          = isset($settings['types']) && is_array($settings['types']) ? $settings['types'] : ['a'=>1,'img'=>1,'video'=>0,'source'=>0];

    for ($i = $cursor; $i < $end; $i++) {
        $url = (string)$queue[$i];

        $post_id = function_exists('url_to_postid') ? (int)url_to_postid($url) : 0;
        if (!$post_id) continue;

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') continue;

        $found = wphm_li_scan_post_for_bad_links($post, $types, $patterns, $target_host, $typo_threshold);
        if (!empty($found)) {
            foreach ($found as $row) $results[] = $row;

            $max = (int)($settings['max_results'] ?? 5000);
            if ($max > 0 && count($results) > $max) $results = array_slice($results, -$max);
        }
    }

    $state['cursor']       = $end;
    $state['results']      = $results;
    $state['done']         = ($end >= $total);
    $state['last_step_at'] = time();
    $state['last_error']   = '';

    wphm_li_update_state($state);
}

/** =========================
 * Sitemap queue
 * ========================= */
function wphm_li_build_queue_from_sitemap(string $sitemap_url, int $timeout = 8, int $max_queue = 20000): array {
    $sitemap_url = esc_url_raw($sitemap_url);
    if (!$sitemap_url) return [];

    $xml = wphm_li_fetch_body($sitemap_url, $timeout);
    if ($xml === '') return [];

    $is_index = wphm_li_sitemap_is_index($xml);

    if (!$is_index) {
        $urls = wphm_li_parse_sitemap_xml($xml);
        return wphm_li_normalize_urls($urls, $max_queue);
    }

    // index: child sitemaps
    $child_sitemaps = wphm_li_parse_sitemap_xml($xml);
    $all = [];

    foreach ($child_sitemaps as $child) {
        if (count($all) >= $max_queue) break;

        $child_xml = wphm_li_fetch_body((string)$child, $timeout);
        if ($child_xml === '') continue;

        $child_urls = wphm_li_parse_sitemap_xml($child_xml);
        foreach ($child_urls as $u) {
            $all[] = $u;
            if (count($all) >= $max_queue) break;
        }
    }

    return wphm_li_normalize_urls($all, $max_queue);
}

function wphm_li_normalize_urls(array $urls, int $max_queue): array {
    $out = [];
    $seen = [];

    foreach ($urls as $u) {
        if (!is_string($u)) continue;
        $u = trim($u);
        if ($u === '') continue;

        if (isset($seen[$u])) continue;
        $seen[$u] = 1;
        $out[] = $u;

        if (count($out) >= $max_queue) break;
    }

    return $out;
}

function wphm_li_fetch_body(string $url, int $timeout): string {
    $res = wp_remote_get($url, ['timeout' => $timeout]);
    if (is_wp_error($res)) return '';
    if (wp_remote_retrieve_response_code($res) !== 200) return '';
    $body = (string)wp_remote_retrieve_body($res);
    return $body ?: '';
}

function wphm_li_sitemap_is_index(string $xml): bool {
    return (stripos($xml, '<sitemapindex') !== false);
}

function wphm_li_parse_sitemap_xml(string $xml): array {
    $prev = libxml_use_internal_errors(true);
    $dom  = new DOMDocument();
    $ok   = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_use_internal_errors($prev);
    if (!$ok) return [];

    $locs = $dom->getElementsByTagName('loc');
    $out = [];
    foreach ($locs as $loc) {
        $u = trim((string)$loc->textContent);
        if ($u !== '') $out[] = $u;
    }
    return $out;
}

/** =========================
 * Scan
 * ========================= */
function wphm_li_scan_post_for_bad_links(WP_Post $post, array $types, array $patterns, string $target_host, int $typo_threshold): array {
    $html = (string)$post->post_content;
    if ($html === '') return [];

    $links = wphm_li_extract_links_from_html($html, $types);
    if (empty($links)) return [];

    $post_url   = get_permalink($post->ID);
    $post_title = get_the_title($post->ID);

    $found = [];

    foreach ($links as $item) {
        $u    = trim((string)($item['url'] ?? ''));
        $type = (string)($item['type'] ?? '');

        if ($u === '') continue;
        if (preg_match('~^(#|mailto:|tel:|javascript:)~i', $u)) continue;

        $abs = wphm_li_to_absolute_url($u, $post_url);

        $reason = '';
        foreach ($patterns as $p) {
            if ($p !== '' && stripos($abs, $p) !== false) {
                $reason = '一致: ' . $p;
                break;
            }
        }

        if ($reason === '' && $typo_threshold > 0 && $target_host !== '') {
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
                'post_id'    => $post->ID,
                'post_title' => $post_title,
                'post_url'   => $post_url,
                'found_url'  => $abs,
                'type'       => $type,
                'reason'     => $reason,
            ];
        }
    }

    return $found;
}

function wphm_li_extract_links_from_html(string $html, array $types): array {
    $out  = [];

    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();

    $wrapped = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
    $dom->loadHTML($wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new DOMXPath($dom);

    if (!empty($types['a'])) {
        foreach ($xpath->query('//a[@href]') as $n) {
            if ($n instanceof DOMElement) $out[] = ['type' => 'a', 'url' => $n->getAttribute('href')];
        }
    }
    if (!empty($types['img'])) {
        foreach ($xpath->query('//img[@src]') as $n) {
            if ($n instanceof DOMElement) $out[] = ['type' => 'img', 'url' => $n->getAttribute('src')];
        }
    }
    if (!empty($types['video'])) {
        foreach ($xpath->query('//video[@src]') as $n) {
            if ($n instanceof DOMElement) $out[] = ['type' => 'video', 'url' => $n->getAttribute('src')];
        }
    }
    if (!empty($types['source'])) {
        foreach ($xpath->query('//source[@src]') as $n) {
            if ($n instanceof DOMElement) $out[] = ['type' => 'source', 'url' => $n->getAttribute('src')];
        }
    }

    $seen = [];
    $uniq = [];
    foreach ($out as $row) {
        $k = ($row['type'] ?? '') . '|' . ($row['url'] ?? '');
        if ($k === '|' || isset($seen[$k])) continue;
        $seen[$k] = 1;
        $uniq[] = $row;
    }

    return $uniq;
}

function wphm_li_patterns_from_text(string $text): array {
    $lines = preg_split("/\r\n|\n|\r/", (string)$text);
    $out = [];
    foreach ($lines as $l) {
        $l = trim((string)$l);
        if ($l === '') continue;
        $out[] = $l;
    }
    return $out;
}

function wphm_li_typo_threshold(string $level): int {
    switch ($level) {
        case 'weak':   return 1;
        case 'medium': return 2;
        case 'high':   return 3;
        default:       return 1;
    }
}

function wphm_li_get_host(string $url): string {
    $p = wp_parse_url($url);
    if (!is_array($p) || empty($p['host'])) return '';
    return strtolower((string)$p['host']);
}

function wphm_li_host_norm(string $host): string {
    $h = strtolower(trim((string)$host));
    if (strpos($h, 'www.') === 0) $h = substr($h, 4);
    return $h;
}

function wphm_li_to_absolute_url(string $maybe_url, string $base_url): string {
    // absolute
    if (preg_match('~^https?://~i', $maybe_url)) return $maybe_url;

    $base = wp_parse_url($base_url);
    if (!is_array($base)) return $maybe_url;

    $scheme = $base['scheme'] ?? 'https';
    $host   = $base['host'] ?? '';
    if ($host === '') return $maybe_url;

    // protocol-relative
    if (strpos($maybe_url, '//') === 0) {
        return $scheme . ':' . $maybe_url;
    }

    // root-relative
    if (strpos($maybe_url, '/') === 0) {
        return $scheme . '://' . $host . $maybe_url;
    }

    // relative: join to base dir
    $path = $base['path'] ?? '/';
    $dir  = preg_replace('~/[^/]*$~', '/', $path); // dirname (url path)
    return $scheme . '://' . $host . $dir . ltrim($maybe_url, '/');
}
