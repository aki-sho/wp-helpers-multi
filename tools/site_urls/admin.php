<?php
if (!defined('ABSPATH')) exit;

function wphm_su_require_access(): void {
    if (!current_user_can('manage_options') || !wphm_is_tool_enabled('site_urls')) wp_die('この機能を使用する権限がありません。', '', ['response' => 403]);
}

function wphm_su_search_input(array $input): string {
    $text = isset($input['q']) && is_string($input['q']) ? substr(sanitize_text_field(wp_unslash($input['q'])), 0, 200) : '';
    while ($text !== '' && !preg_match('//u', $text)) $text = substr($text, 0, -1);
    return $text;
}

function wphm_su_sort_input(array $input): array {
    $key = isset($input['orderby']) && is_string($input['orderby']) && isset(wphm_su_columns()[$input['orderby']]) ? $input['orderby'] : 'url';
    $order = isset($input['order']) && is_string($input['order']) && strtoupper($input['order']) === 'DESC' ? 'DESC' : 'ASC';
    return [$key, $order];
}

function wphm_su_response(string $search = '', int $page = 1, string $orderby = 'url', string $order = 'ASC'): array {
    $state = get_option('wphm_site_urls_state', []);
    if (!is_array($state)) $state = [];
    if (!empty($state['run']) && wphm_su_has_clear_request($state['run'])) {
        if (wphm_su_lock()) { try { wphm_su_clear_requested($state['run']); } finally { wphm_su_unlock(); } }
        return ['state' => [], 'result' => ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'orderby' => $orderby, 'order' => $order]];
    }
    return ['state' => $state, 'result' => wphm_su_rows($search, $page, 100, $orderby, $order)];
}

function wphm_su_ajax(): void {
    if (!current_user_can('manage_options') || !wphm_is_tool_enabled('site_urls')) wp_send_json_error(['message' => '権限がないか、機能が無効です。'], 403);
    check_ajax_referer('wphm_site_urls', 'nonce');
    if (!wphm_su_schema()) wp_send_json_error(['message' => '収集結果の保存先を作成できませんでした。'], 500);
    $op = isset($_POST['op']) && is_string($_POST['op']) ? $_POST['op'] : '';
    if (!in_array($op, ['start', 'step', 'list', 'clear'], true)) wp_send_json_error(['message' => '操作が不正です。'], 400);
    if ($op === 'clear') {
        $run = isset($_POST['run']) && is_string($_POST['run']) ? $_POST['run'] : '';
        if (!preg_match('/^[a-f0-9]{32}$/D', $run)) wp_send_json_error(['message' => '削除対象が不正です。'], 400);
        $state = get_option('wphm_site_urls_state', []);
        if (!is_array($state) || empty($state['run']) || !hash_equals($state['run'], $run)) wp_send_json_success(['cleared' => false]);
        set_transient('wphm_su_clear_' . $run, true, 10 * MINUTE_IN_SECONDS);
        if (!get_transient('wphm_su_clear_' . $run)) wp_send_json_error(['message' => '削除要求を保存できませんでした。'], 500);
        $cleared = false;
        if (wphm_su_lock()) { try { $cleared = wphm_su_clear_requested($run); } finally { wphm_su_unlock(); } }
        wp_send_json_success(['cleared' => $cleared, 'pending' => !$cleared]);
    }
    if ($op === 'start' && isset($_POST['exclude_uploads']) && (!is_string($_POST['exclude_uploads']) || !in_array($_POST['exclude_uploads'], ['0', '1'], true))) wp_send_json_error(['message' => '除外設定が不正です。'], 400);
    $error = '';
    if ($op !== 'list') {
        if (!wphm_su_lock()) wp_send_json_error(['message' => '別の収集処理が実行中です。少し待って再開してください。'], 409);
        try {
            if ($op === 'start') wphm_su_start(($_POST['exclude_uploads'] ?? '0') === '1');
            else {
                $run = isset($_POST['run']) && is_string($_POST['run']) ? $_POST['run'] : '';
                wphm_su_step($run);
            }
        } catch (Throwable $exception) { $error = $exception->getMessage(); }
        finally { wphm_su_unlock(); }
    }
    if ($error !== '') wp_send_json_error(['message' => $error], 400);
    $page = isset($_POST['paged']) && is_scalar($_POST['paged']) ? max(1, min(10000, (int)$_POST['paged'])) : 1;
    [$orderby, $order] = wphm_su_sort_input($_POST);
    wp_send_json_success(wphm_su_response(wphm_su_search_input($_POST), $page, $orderby, $order));
}

function wphm_su_assets(): void {
    if (($_GET['page'] ?? '') !== 'wphm-site-urls' || !current_user_can('manage_options') || !wphm_is_tool_enabled('site_urls')) return;
    wp_enqueue_style('wphm-site-urls', WPHM_PLUGIN_URL . 'assets/css/site-urls.css', [], WPHM_VERSION);
    wp_enqueue_script('wphm-site-urls', WPHM_PLUGIN_URL . 'assets/js/site-urls.js', [], WPHM_VERSION, true);
    wp_localize_script('wphm-site-urls', 'wphmSiteUrls', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wphm_site_urls')]);
}

function wphm_su_handle_request(): void {
    wphm_su_require_access();
    if (!wphm_su_schema()) wp_die('収集結果の保存先を作成できませんでした。');
    if (isset($_GET['wphm_su_export']) && $_GET['wphm_su_export'] === 'csv') {
        check_admin_referer('wphm_site_urls_export');
        if (!wphm_su_lock()) wp_die('収集中です。少し待ってからCSV出力を実行してください。');
        try {
            $state = get_option('wphm_site_urls_state', []);
            if (is_array($state) && !empty($state['run'])) wphm_su_clear_requested($state['run']);
            [$orderby, $order] = wphm_su_sort_input($_GET);
            $stream = wphm_csv_open_output('site-urls-' . wp_date('Ymd-His') . '.csv');
            wphm_csv_write_row($stream, array_values(wphm_su_columns()));
            $page = 1;
            do {
                $result = wphm_su_rows(wphm_su_search_input($_GET), $page, 200, $orderby, $order);
                foreach ($result['rows'] as $row) wphm_csv_write_row($stream, [$row['url'], $row['source_url'], $row['source_label'], (int)$row['http_status'], $row['confirmation_label'], $row['access_label']]);
                $page++;
            } while ($page <= $result['pages']);
            fclose($stream);
        } finally { wphm_su_unlock(); }
        exit;
    }
}

function wphm_su_render_page(): void {
    wphm_su_require_access();
    if (!wphm_su_schema()) return;
    $search = wphm_su_search_input($_GET);
    $page = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
    [$orderby, $order] = wphm_su_sort_input($_GET);
    $data = wphm_su_response($search, $page, $orderby, $order);
    $result = $data['result']; $state = $data['state'];
    $exclude_uploads = (bool)get_option('wphm_site_urls_exclude_uploads', false);
    $uploads = wp_get_upload_dir();
    $csv = wp_nonce_url(add_query_arg(['page' => 'wphm-site-urls', 'wphm_su_export' => 'csv', 'q' => $search, 'orderby' => $orderby, 'order' => $order], admin_url('admin.php')), 'wphm_site_urls_export');
    echo '<div class="wrap wphm-app">';
    wphm_render_header('サイト内URL一覧');
    ?>
    <div id="wphm-site-urls" data-run="<?php echo esc_attr($state['run'] ?? ''); ?>" data-phase="<?php echo esc_attr($state['phase'] ?? ''); ?>" data-page="<?php echo (int)$result['page']; ?>" data-orderby="<?php echo esc_attr($orderby); ?>" data-order="<?php echo esc_attr($order); ?>">
        <p>サイト内で見つかったURLから、固定ページ・投稿記事のURLだけを除外して一覧表示します。</p>
        <fieldset class="wphm-su-exclusions">
            <legend>収集前の除外設定</legend>
            <label><input type="checkbox" id="wphm-su-exclude-uploads" value="1" <?php checked($exclude_uploads); ?>> アップロードディレクトリ配下のURLを除外する</label>
            <p class="description"><code><?php echo esc_html(trailingslashit($uploads['baseurl'] ?? content_url('/uploads/'))); ?></code> と、その下の画像・ファイル・サブフォルダのURLを収集しません。変更は「収集開始・再収集」で反映します。</p>
        </fieldset>
        <div class="wphm-su-controls">
            <button type="button" class="button button-primary" id="wphm-su-start">収集開始・再収集</button>
            <button type="button" class="button" id="wphm-su-resume" <?php disabled(empty($state['run']) || ($state['phase'] ?? '') === 'done'); ?>>続きから収集</button>
            <button type="button" class="button" id="wphm-su-stop" disabled>一時停止</button>
            <a class="button" id="wphm-su-csv" href="<?php echo esc_url($csv); ?>">CSV出力</a>
        </div>
        <p id="wphm-su-status" role="status" aria-live="polite"><?php echo empty($state) ? 'まだ収集していません。' : esc_html('表示対象 ' . $result['total'] . '件 ／ 発見 ' . ($state['total'] ?? 0) . 'URL ／ 通信 ' . ($state['fetched'] ?? 0) . '回'); ?></p>
        <p id="wphm-su-applied-filters"><?php if (!empty($state['run'])) echo !empty($state['filters']['exclude_uploads']) ? 'この収集の設定：アップロードディレクトリ配下を除外' : 'この収集の設定：アップロードディレクトリの除外なし'; ?></p>
        <p id="wphm-su-notice"><?php if (!empty($state['limited'])) echo '上限に達した箇所があります。収集済みの範囲を表示しています。'; ?></p>
        <form method="get" class="wphm-su-controls" id="wphm-su-search-form">
            <input type="hidden" name="page" value="wphm-site-urls">
            <label for="wphm-su-search">URL・発見元を検索</label>
            <input type="search" id="wphm-su-search" name="q" maxlength="200" value="<?php echo esc_attr($search); ?>">
            <button class="button">検索</button>
        </form>
        <p class="description">このページを離れると収集結果を削除します。検索・並び替え・一時停止では保持します。最大10,000URL・2,000回の通信まで収集します。未リンク・JavaScript実行後だけのURL等をすべて発見するものではありません。</p>
        <p class="description">アクセス権限は未ログイン時の応答とWordPressの標準管理画面の情報から判定します。管理者・編集者などの役割別の可否は確認していません。未取得のURLや判断できない応答は「未確認」です。</p>
        <div class="wphm-su-table-wrap"><table class="widefat striped"><thead><tr>
        <?php foreach (wphm_su_columns() as $key => $label): $active = $orderby === $key; $next = $active && $order === 'ASC' ? 'DESC' : 'ASC'; $href = add_query_arg(['page' => 'wphm-site-urls', 'q' => $search, 'orderby' => $key, 'order' => $next], admin_url('admin.php')); ?>
            <th scope="col" data-sort-column="<?php echo esc_attr($key); ?>" aria-sort="<?php echo $active ? ($order === 'ASC' ? 'ascending' : 'descending') : 'none'; ?>"><a class="wphm-su-sort" href="<?php echo esc_url($href); ?>" data-sort="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?><span class="wphm-su-sort-indicator" aria-hidden="true"><?php echo $active ? ($order === 'ASC' ? '▲' : '▼') : ''; ?></span></a></th>
        <?php endforeach; ?>
        </tr></thead><tbody id="wphm-su-rows">
        <?php if (!$result['rows']): ?><tr><td colspan="6">表示するURLがありません。</td></tr><?php endif; ?>
        <?php foreach ($result['rows'] as $row): ?>
            <tr><td><a href="<?php echo esc_url($row['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row['url']); ?></a></td><td><?php echo esc_html($row['source_url']); ?></td><td><?php echo esc_html($row['source_label']); ?></td><td><?php echo (int)$row['http_status'] ?: '—'; ?></td><td><?php echo esc_html($row['confirmation_label']); ?></td><td><?php echo esc_html($row['access_label']); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="wphm-su-controls wphm-su-pagination"><button class="button" type="button" id="wphm-su-prev">前へ</button><span id="wphm-su-pages"><?php echo (int)$result['page']; ?> / <?php echo (int)$result['pages']; ?></span><button class="button" type="button" id="wphm-su-next">次へ</button></div>
        <noscript><p>収集の実行にはJavaScriptが必要です。</p></noscript>
    </div></div>
    <?php
}
