<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_allowed(): bool {
    return current_user_can('manage_options') && wphm_is_tool_enabled('security_check');
}

function wphm_sc_report(bool $refresh = false): array {
    if (!wphm_sc_allowed()) throw new RuntimeException('この機能を使用する権限がないか、機能が無効です。');
    $key = 'wphm_security_check_' . WPHM_VERSION . '_' . get_current_user_id();
    $revision = get_option('wphm_sc_revision', '');
    if (is_string($revision) && $revision !== '') $key .= '_' . substr(hash('sha256',$revision),0,16);
    if (!$refresh) {
        $cached = get_transient($key);
        if (is_array($cached) && isset($cached['checked_at'], $cached['items'], $cached['facts']) && $cached['checked_at'] >= time() - 5 * MINUTE_IN_SECONDS && $cached['checked_at'] <= time()) return $cached;
    }
    $lease = wphm_sc_acquire_lease();
    try {
        if ($refresh) {
            $last = (int)get_option('wphm_sc_last_recheck',0);
            if ($last > time()-20 && $last <= time()+90) throw new RuntimeException('再チェックは20秒以上間隔を空けてください。');
            update_option('wphm_sc_last_recheck',time(),false);
        }
        $report = wphm_sc_collect($refresh);
        set_transient($key, $report, 5 * MINUTE_IN_SECONDS);
        return $report;
    } finally { wphm_sc_release_lease($lease); }
}

/** Expiring compare-and-swap lease works without MySQL GET_LOCK or object-cache locks. */
function wphm_sc_acquire_lease(): string {
    global $wpdb;
    $name='wphm_sc_check_lock'; $lease=wp_json_encode(['token'=>wp_generate_password(32,false,false),'expires'=>time()+90]);
    if (add_option($name,$lease,'',false)) return $lease;
    $old=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$name));
    $value=is_string($old) ? json_decode($old,true) : null;
    if (!is_array($value) || ($value['expires'] ?? PHP_INT_MAX)>=time()) throw new RuntimeException('別のチェックが実行中です。少し待って再チェックしてください。');
    $updated=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",$lease,$name,$old));
    wp_cache_delete($name,'options');
    if ($updated!==1) throw new RuntimeException('チェックの実行権を取得できませんでした。少し待って再試行してください。');
    return $lease;
}

function wphm_sc_release_lease(string $lease): void {
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s",'wphm_sc_check_lock',$lease));
    wp_cache_delete('wphm_sc_check_lock','options');
}

function wphm_sc_handle_request(): void {
    if (!wphm_sc_allowed()) wp_die('この機能を使用する権限がないか、機能が無効です。', '', ['response' => 403]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    check_admin_referer('wphm_security_check', 'nonce');
    if (($_POST['wphm_sc_action'] ?? '') === 'settings') {
        try { wphm_sc_save_options($_POST); $GLOBALS['wphm_sc_page_message']='設定を保存しました。「再チェック」で最新の連携情報を確認します。'; }
        catch (RuntimeException $exception) { $GLOBALS['wphm_sc_page_error']=$exception->getMessage(); }
        return;
    }
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
        <p><?php echo $report['refreshed'] ? '設定・集計を読み直し、設定された外部照会を実行しました。製品ごとの取得日時と未確認範囲は詳細に表示します。' : '保存済みの照会根拠と現在の設定・集計を参照しています。「再チェック」で外部情報を更新します。'; ?></p>
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
    <?php wphm_sc_evidence_html($facts); ?>
    <details class="wphm-sc-details wphm-panel"><summary>参照した情報と判定の範囲</summary>
        <p>横棒は各項目に書いた確認範囲のリスクです。低には本プラグインの監査済み処理や確認できた制限設定だけの判定を含みます。他プラグイン・テーマを含むサイト全体の耐性や侵害の有無は未確認です。攻撃の発生確率や安全性の点数ではありません。</p>
        <p>ログは直近24時間の最新2,000件までを参照します。<?php echo $facts['logs']['limited'] ? '参照上限に達しています。' : ''; ?><?php echo $facts['logs']['enabled'] ? '' : 'アクセスログ機能は無効です。残っている過去の記録だけを参照します。'; ?>記録されない通信もあるため、記録が少ない・該当文字列がないだけで「低」とは判定しません。</p>
        <?php foreach (['core' => 'コア', 'plugins' => 'プラグイン', 'themes' => 'テーマ'] as $key => $name): $group = $updates[$key]; ?>
        <p><?php echo esc_html($name . '：' . $group['source'] . '。' . ($group['checked_at'] ? '情報の参照日時 ' . wphm_format_timestamp($group['checked_at']) : '情報を確認できませんでした') . ($key === 'core' ? '' : '。更新情報が未確認のもの ' . $group['unknown'] . '/' . $group['total'] . '件')); ?></p>
        <?php endforeach; ?>
        <p>独自配布元の更新、既知脆弱性データの欠落、サーバーの実効設定は未確認として区別します。PHPの判定は上流のサポート状況です。攻撃・ログイン試行・ファイル送信・コード実行・サイト巡回・パスワード検査・自動修正は行いません。</p>
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
        <p class="description">再チェックは20秒間隔、外部照会の処理枠は合計25秒です。WordPress.orgへ版・名称等を照会し、既知脆弱性照合が有効ならWPVulnerabilityへ製品識別名を送信します。Cloudflareは設定時のみ読取り照会します。</p>
        <p class="description">この機能を有効にして管理者が画面を開くと、以後のWordPress到達通信・認証イベントを上限付きで集計します。入力全文・パスワード・IP・利用者名は保存しません。無効にすると集計も停止します。</p>
    </section>
    <p id="wphm-sc-error" role="alert"><?php echo esc_html($GLOBALS['wphm_sc_page_error'] ?? ''); ?></p>
    <?php if (!empty($GLOBALS['wphm_sc_page_message'])) echo '<p role="status">' . esc_html($GLOBALS['wphm_sc_page_message']) . '</p>'; ?>
    <div id="wphm-sc-report" aria-busy="false"><?php if ($report !== null) wphm_sc_report_html($report); ?></div>
    <?php wphm_sc_settings_html(); ?>
    </div>
    <?php
}
