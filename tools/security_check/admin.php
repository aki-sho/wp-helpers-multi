<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_allowed(): bool {
    return current_user_can('manage_options') && wphm_is_tool_enabled('security_check');
}

function wphm_sc_report(bool $refresh = false): array {
    if (!wphm_sc_allowed()) throw new RuntimeException('この機能を使用する権限がないか、機能が無効です。');
    $key = 'wphm_security_check_' . WPHM_VERSION . '_' . get_current_user_id();
    if (!$refresh) {
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['checked_at'], $cached['items'], $cached['facts']) && $cached['checked_at'] >= time() - 5 * MINUTE_IN_SECONDS && $cached['checked_at'] <= time()) return $cached;
    }
    global $wpdb;
    $lock = 'wphm_sc_' . substr(hash('sha256', (defined('DB_NAME') ? DB_NAME : '') . ':' . $wpdb->prefix), 0, 40);
    if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') throw new RuntimeException('別のチェックが実行中です。少し待って再チェックしてください。');
    try {
        $report = wphm_sc_collect($refresh);
        set_transient($key, $report, 5 * MINUTE_IN_SECONDS);
        return $report;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
}

function wphm_sc_handle_request(): void {
    if (!wphm_sc_allowed()) wp_die('この機能を使用する権限がないか、機能が無効です。', '', ['response' => 403]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    check_admin_referer('wphm_security_check', 'nonce');
    if (($_POST['wphm_sc_action'] ?? '') !== 'recheck') wp_die('操作が不正です。', '', ['response' => 400]);
    try { $GLOBALS['wphm_sc_page_report'] = wphm_sc_report(true); }
    catch (RuntimeException $exception) { $GLOBALS['wphm_sc_page_error'] = $exception->getMessage(); }
}

function wphm_sc_ajax(): void {
    if (!wphm_sc_allowed()) wp_send_json_error(['message' => 'この機能を使用する権限がないか、機能が無効です。'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_send_json_error(['message' => '再チェックにはPOSTを使用してください。'], 405);
    check_ajax_referer('wphm_security_check', 'nonce');
    try {
        $report = wphm_sc_report(true);
        ob_start(); wphm_sc_report_html($report); $html = ob_get_clean();
        wp_send_json_success(['html' => $html]);
    } catch (RuntimeException $exception) { wp_send_json_error(['message' => $exception->getMessage()], 409); }
}

function wphm_sc_assets(): void {
    if (($_GET['page'] ?? '') !== 'wphm-security-check' || !wphm_sc_allowed()) return;
    wp_enqueue_style('wphm-security-check', WPHM_PLUGIN_URL . 'assets/css/security-check.css', ['wphm-admin-modern'], WPHM_VERSION);
    wp_enqueue_script('wphm-security-check', WPHM_PLUGIN_URL . 'assets/js/security-check.js', [], WPHM_VERSION, true);
    wp_localize_script('wphm-security-check', 'wphmSecurityCheck', ['url' => admin_url('admin-ajax.php')]);
}

function wphm_sc_report_html(array $report): void {
    $levels = wphm_sc_levels(); $counts = array_fill_keys(array_keys($levels), 0);
    foreach ($report['items'] as $item) $counts[$item['level']]++;
    $facts = $report['facts']; $updates = $facts['updates'];
    ?>
    <div class="wphm-sc-meta">
        <p><strong>確認日時：</strong><time datetime="<?php echo esc_attr(wp_date('c', $report['checked_at'])); ?>"><?php echo esc_html(wphm_format_timestamp($report['checked_at'])); ?></time></p>
        <p><?php echo $report['refreshed'] ? 'WordPress.orgの版・更新情報を今回照会しました。取得できない情報は未確認として扱います。' : '保存済みの更新情報を参照しています。「再チェック」で公式の版・更新情報を照会します。'; ?></p>
    </div>
    <div class="wphm-sc-summary" aria-label="確認結果の件数">
    <?php foreach ($levels as $key => $label): ?>
        <div class="wphm-sc-total wphm-sc-<?php echo esc_attr($key); ?>"><span><?php echo esc_html($label); ?></span><strong><?php echo (int)$counts[$key]; ?><small>項目</small></strong></div>
    <?php endforeach; ?>
    </div>
    <ol class="wphm-sc-results" aria-label="10種類の攻撃に関するチェック結果">
    <?php foreach ($report['items'] as $index => $item): $level = $item['level']; $size = ['low' => 33.333, 'medium' => 66.667, 'high' => 100, 'unknown' => 0][$level]; ?>
        <li class="wphm-sc-item wphm-sc-<?php echo esc_attr($level); ?>" data-check="<?php echo esc_attr($item['id']); ?>">
            <div class="wphm-sc-item-title"><h2><span class="wphm-sc-number"><?php echo (int)$index + 1; ?></span><?php echo esc_html($item['name']); ?></h2><span class="wphm-sc-badge"><?php echo esc_html($levels[$level]); ?></span></div>
            <div class="wphm-sc-bar" <?php if ($level !== 'unknown'): ?>role="meter" aria-label="<?php echo esc_attr($item['name'] . 'の確認範囲でのリスク'); ?>" aria-valuemin="1" aria-valuemax="3" aria-valuenow="<?php echo ['low' => 1, 'medium' => 2, 'high' => 3][$level]; ?>" aria-valuetext="<?php echo esc_attr($levels[$level]); ?>"<?php else: ?>role="img" aria-label="リスク未確認"<?php endif; ?>><span style="width:<?php echo esc_attr((string)$size); ?>%"></span></div>
            <p class="wphm-sc-scope">確認範囲：<?php echo esc_html($item['scope']); ?></p>
            <p><strong>現在の状態</strong><span><?php echo esc_html($item['state']); ?></span></p>
            <p><strong>判定理由</strong><span><?php echo esc_html($item['reason']); ?></span></p>
        </li>
    <?php endforeach; ?>
    </ol>
    <details class="wphm-sc-details wphm-panel"><summary>参照した情報と判定の範囲</summary>
        <p>横棒は確認範囲でのリスクを3段階で表したもので、攻撃の発生確率や安全性の点数ではありません。低＝確認範囲の受信経路が無効など、中＝更新・設定上の注意、高＝強い権限の公開や安全でない版等を確認、未確認＝判定材料が不足、を表します。</p>
        <p>ログは直近24時間の最新2,000件までを参照します。<?php echo $facts['logs']['limited'] ? '参照上限に達しています。' : ''; ?><?php echo $facts['logs']['enabled'] ? '' : 'アクセスログ機能は無効です。残っている過去の記録だけを参照します。'; ?>記録されない通信もあるため、記録が少ない・該当文字列がないだけで「低」とは判定しません。</p>
        <?php foreach (['core' => 'コア', 'plugins' => 'プラグイン', 'themes' => 'テーマ'] as $key => $name): $group = $updates[$key]; ?>
        <p><?php echo esc_html($name . '：' . $group['source'] . '。' . ($group['checked_at'] ? '情報の参照日時 ' . wphm_format_timestamp($group['checked_at']) : '情報を確認できませんでした') . ($key === 'core' ? '' : '。更新情報が未確認のもの ' . $group['unknown'] . '/' . $group['total'] . '件')); ?></p>
        <?php endforeach; ?>
        <p>独自配布のプラグイン・テーマなど、WordPress.orgが更新情報を提供しないものは未確認です。PHPの判定はWordPress公式情報による上流のサポート状況です。攻撃・ログイン試行・ファイル送信・コード実行・サイト巡回・パスワード検査・自動修正は行いません。</p>
        <p><a href="https://developer.wordpress.org/advanced-administration/security/hardening/" target="_blank" rel="noopener noreferrer">WordPress公式のセキュリティ強化ガイド</a> ／ <a href="https://developer.wordpress.org/apis/security/nonces/" target="_blank" rel="noopener noreferrer">WordPress公式のnonce解説</a></p>
    </details>
    <?php
}

function wphm_sc_render(): void {
    if (!wphm_sc_allowed()) wp_die('この機能を使用する権限がないか、機能が無効です。', '', ['response' => 403]);
    try { $report = $GLOBALS['wphm_sc_page_report'] ?? wphm_sc_report(); }
    catch (RuntimeException $exception) { $report = null; $GLOBALS['wphm_sc_page_error'] = $exception->getMessage(); }
    echo '<div class="wrap wphm-app wphm-security-check">';
    wphm_render_header('セキュリティチェック');
    ?>
    <section class="wphm-panel wphm-sc-intro">
        <h2>設定と記録から、確認できることを整理</h2>
        <p>WordPressの設定・更新情報・既存アクセスログから10種類の攻撃に関する状態を確認します。判定は各項目に示した確認範囲に限り、判断材料が足りない項目は「未確認」と表示します。</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wphm-security-check')); ?>" id="wphm-sc-form">
            <?php wp_nonce_field('wphm_security_check', 'nonce'); ?>
            <input type="hidden" name="wphm_sc_action" value="recheck">
            <button type="submit" class="button button-primary wphm-primary-button" id="wphm-sc-recheck"><span class="dashicons dashicons-update" aria-hidden="true"></span>再チェック</button>
            <span id="wphm-sc-status" role="status" aria-live="polite"></span>
        </form>
        <p class="description">再チェック時はWordPress.orgへWordPress/PHPの版と、プラグイン・テーマの名称・版等を照会します。既存の更新通知や設定は変更しません。攻撃を伴う診断は行いません。</p>
    </section>
    <p id="wphm-sc-error" role="alert"><?php echo esc_html($GLOBALS['wphm_sc_page_error'] ?? ''); ?></p>
    <div id="wphm-sc-report" aria-busy="false"><?php if ($report !== null) wphm_sc_report_html($report); ?></div>
    </div>
    <?php
}
