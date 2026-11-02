<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_allowed(): bool {
    return current_user_can('manage_options') && wphm_is_tool_enabled('security_check');
}

function wphm_sc_report(bool $refresh = false, string $operation = 'recheck', string $run = ''): array {
    if (!wphm_sc_allowed()) throw new WPHM_Security_Check_Exception('この機能を使用する権限がないか、機能が無効です。');
    if (!in_array($operation,['recheck','continue'],true)) throw new WPHM_Security_Check_Exception('操作が不正です。');
    $key = 'wphm_security_check_' . WPHM_VERSION . '_' . get_current_user_id();
    $revision = get_option('wphm_sc_revision', '');
    if (is_string($revision) && $revision !== '') $key .= '_' . substr(hash('sha256',$revision),0,16);
    if (!$refresh) {
        $cached = get_transient($key);
        if (is_array($cached) && ($cached['schema'] ?? 0)===2 && isset($cached['checked_at'], $cached['items'], $cached['facts']) && is_int($cached['checked_at']) && $cached['checked_at'] >= time()-5*MINUTE_IN_SECONDS && $cached['checked_at'] <= time()) return $cached;
    }
    $lease = wphm_sc_acquire_lease();
    $job_key='wphm_sc_job_' . get_current_user_id(); $job=[];
    try {
        if ($refresh && $operation==='continue') {
            $job=get_transient($job_key);
            if (!preg_match('/^[a-f0-9]{32}$/D',$run) || !is_array($job) || !is_string($job['id'] ?? null) || !hash_equals($job['id'],$run) || ($job['owner'] ?? 0)!==get_current_user_id() || !is_int($job['created'] ?? null) || $job['created']<time()-600 || $job['created']>time()+60 || !is_int($job['steps'] ?? null) || $job['steps']<0 || $job['steps']>=64) throw new WPHM_Security_Check_Exception('この照合は終了・期限切れです。再チェックしてください。');
            if ((int)get_option('wphm_sc_last_step',0)>time()-2) throw new WPHM_Security_Check_Exception('照合の続行は2秒以上間隔を空けてください。');
        } elseif ($refresh) {
            $last = (int)get_option('wphm_sc_last_recheck',0);
            if ($last > time()-20 && $last <= time()+90) throw new WPHM_Security_Check_Exception('再チェックは20秒以上間隔を空けてください。');
            update_option('wphm_sc_last_recheck',time(),false);
            $job=['id'=>bin2hex(random_bytes(16)),'owner'=>get_current_user_id(),'created'=>time(),'steps'=>0,'fingerprint'=>''];
        }
        if ($refresh) update_option('wphm_sc_last_step',time(),false);
        $report=wphm_sc_collect($refresh,$operation==='continue',$job);
        $pending=(int)($report['facts']['vulnerabilities']['pending'] ?? 0);
        $scan=['run'=>'','pending'=>$pending,'auto'=>false,'stopped'=>false];
        if ($refresh) {
            $job['fingerprint']=$report['fingerprint']; $job['steps']++;
            if ($pending>0 && $job['created']>=time()-600 && $job['steps']<64 && empty($report['facts']['vulnerabilities']['limited'])) {
                set_transient($job_key,$job,max(1,600-(time()-$job['created'])));
                $scan['run']=$job['id']; $scan['auto']=true;
            } else {
                delete_transient($job_key); $scan['stopped']=$pending>0;
            }
        }
        $report['schema']=2; $report['scan']=$scan;
        set_transient($key,$report,5*MINUTE_IN_SECONDS);
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
    if (!is_array($value) || ($value['expires'] ?? PHP_INT_MAX)>=time()) throw new WPHM_Security_Check_Exception('別のチェックが実行中です。少し待って再チェックしてください。');
    $updated=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s",$lease,$name,$old));
    wp_cache_delete($name,'options');
    if ($updated!==1) throw new WPHM_Security_Check_Exception('チェックの実行権を取得できませんでした。少し待って再試行してください。');
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
    if(!is_string($_REQUEST['nonce'] ?? null)) wp_die('nonceの形式が不正です。','',['response'=>400]);
    check_admin_referer('wphm_security_check', 'nonce');
    if (($_POST['wphm_sc_action'] ?? '') === 'settings') {
        try { wphm_sc_save_options($_POST); $GLOBALS['wphm_sc_page_message']='設定を保存しました。「再チェック」で最新の連携情報を確認します。'; }
        catch (Throwable $exception) { $GLOBALS['wphm_sc_page_error']=wphm_sc_failure_message($exception); }
        return;
    }
    $operation=$_POST['wphm_sc_action'] ?? '';
    if (!is_string($operation) || !in_array($operation,['recheck','continue'],true)) wp_die('操作が不正です。', '', ['response' => 400]);
    $run=isset($_POST['run']) && is_string($_POST['run']) ? $_POST['run'] : '';
    try { $GLOBALS['wphm_sc_page_report'] = wphm_sc_report(true,$operation,$run); }
    catch (Throwable $exception) { $GLOBALS['wphm_sc_page_error'] = wphm_sc_failure_message($exception); }
}

function wphm_sc_ajax(): void {
    if (!wphm_sc_allowed()) wp_send_json_error(['message' => 'この機能を使用する権限がないか、機能が無効です。'], 403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_send_json_error(['message' => '再チェックにはPOSTを使用してください。'], 405);
    if(!is_string($_REQUEST['nonce'] ?? null) || (isset($_POST['run']) && !is_string($_POST['run']))) wp_send_json_error(['message'=>'操作の形式が不正です。'],400);
    check_ajax_referer('wphm_security_check', 'nonce');
    $operation=$_POST['operation'] ?? 'recheck';
    if(!is_string($operation) || !in_array($operation,['recheck','continue'],true)) wp_send_json_error(['message'=>'操作が不正です。'],400);
    $run=isset($_POST['run']) && is_string($_POST['run']) ? $_POST['run'] : '';
    $buffer_level=ob_get_level();
    try {
        $report = wphm_sc_report(true,$operation,$run);
        ob_start(); wphm_sc_report_html($report); $html = ob_get_clean();
    } catch (Throwable $exception) {
        while(ob_get_level()>$buffer_level) ob_end_clean();
        wp_send_json_error(['message' => wphm_sc_failure_message($exception)], $exception instanceof WPHM_Security_Check_Exception ? 409 : 500);
    }
    wp_send_json_success(['html' => $html,'scan'=>$report['scan']]);
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
    $facts = $report['facts']; $updates = $facts['updates']; $labels=wphm_sc_check_labels(); $scan=$report['scan'] ?? [];
    ?>
    <div class="wphm-sc-meta">
        <p><strong>確認日時：</strong><time datetime="<?php echo esc_attr(wp_date('c', $report['checked_at'])); ?>"><?php echo esc_html(wphm_format_timestamp($report['checked_at'])); ?></time></p>
        <p><?php echo $report['refreshed'] ? '設定・集計を読み直し、設定された外部照会を実行しました。製品ごとの取得日時と未確認範囲は詳細に表示します。' : '保存済みの照会根拠と現在の設定・集計を参照しています。「再チェック」で外部情報を更新します。'; ?></p>
    </div>
    <?php if(!empty($scan['pending'])): ?>
    <div class="wphm-sc-scan" data-run="<?php echo esc_attr($scan['run'] ?? ''); ?>" role="status">
        <span>既知脆弱性：未照合 <?php echo (int)$scan['pending']; ?>製品。<?php echo !empty($scan['stopped']) ? '処理上限に達しました。再チェックで保存済みの続きから照合できます。' : 'この画面の自動照合で続きを確認します。画面を閉じると続行を停止します。'; ?></span>
        <?php if(!empty($scan['run'])): ?><form method="post" class="wphm-sc-continue-form" action="<?php echo esc_url(admin_url('admin.php?page=wphm-security-check')); ?>"><?php wp_nonce_field('wphm_security_check','nonce',false); ?><input type="hidden" name="wphm_sc_action" value="continue"><input type="hidden" name="run" value="<?php echo esc_attr($scan['run']); ?>"><button class="button" type="submit">照合を続ける</button></form><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="wphm-sc-summary" aria-label="確認結果の件数">
    <?php foreach ($levels as $key => $label): ?>
        <div class="wphm-sc-total wphm-sc-<?php echo esc_attr($key); ?>"><span><?php echo esc_html($label); ?></span><strong><?php echo (int)$counts[$key]; ?><small>項目</small></strong></div>
    <?php endforeach; ?>
    </div>
    <ol class="wphm-sc-results" aria-label="10種類の攻撃に関するチェック結果">
    <?php foreach ($report['items'] as $index => $item): $level = $item['level']; $size = ['low' => 33.333, 'medium' => 66.667, 'high' => 100, 'unknown' => 100][$level]; ?>
        <li class="wphm-sc-item wphm-sc-<?php echo esc_attr($level); ?>" data-check="<?php echo esc_attr($item['id']); ?>">
            <div class="wphm-sc-item-title"><h2><span class="wphm-sc-number"><?php echo (int)$index + 1; ?></span><?php echo esc_html($item['name']); ?></h2><span class="wphm-sc-badge"><?php echo esc_html($levels[$level]); ?></span></div>
            <div class="wphm-sc-bar" <?php if ($level !== 'unknown'): ?>role="meter" aria-label="<?php echo esc_attr($item['name'] . 'の確認範囲でのリスク'); ?>" aria-valuemin="1" aria-valuemax="3" aria-valuenow="<?php echo ['low' => 1, 'medium' => 2, 'high' => 3][$level]; ?>" aria-valuetext="<?php echo esc_attr($levels[$level]); ?>"<?php else: ?>role="img" aria-label="リスク未確認"<?php endif; ?>><span style="width:<?php echo esc_attr((string)$size); ?>%"><small><?php echo esc_html($levels[$level]); ?></small></span></div>
            <p class="wphm-sc-scope">確認範囲：<?php echo esc_html($item['scope']); ?></p>
            <p><strong>現在の状態</strong><span><?php echo esc_html($item['state']); ?></span></p>
            <p><strong>判定理由</strong><span><?php echo esc_html($item['reason']); ?></span></p>
            <?php if(!empty($item['checks'])): $check_counts=array_fill_keys(array_keys($labels),0); foreach($item['checks'] as $check) $check_counts[$check['status']]++; $total=count($item['checks']); $description=[]; foreach($check_counts as $key=>$count) if($count) $description[]=$labels[$key].' '.$count.'項目'; ?>
            <div class="wphm-sc-check-summary"><b><?php echo esc_html($item['result_status']); ?></b><span><?php echo esc_html(implode(' ／ ',$description)); ?></span></div>
            <div class="wphm-sc-coverage" role="img" aria-label="<?php echo esc_attr('確認項目の内訳：'.implode('、',$description)); ?>"><?php foreach($check_counts as $key=>$count) if($count): ?><span class="wphm-sc-check-<?php echo esc_attr($key); ?>" style="width:<?php echo esc_attr((string)(100*$count/$total)); ?>%"></span><?php endif; ?></div>
            <ul class="wphm-sc-checks"><?php foreach($item['checks'] as $check): ?><li><div><strong><?php echo esc_html($check['label']); ?></strong><span class="wphm-sc-check-badge wphm-sc-check-<?php echo esc_attr($check['status']); ?>"><?php echo esc_html($labels[$check['status']]); ?></span></div><span><?php echo esc_html($check['value']); ?></span><?php if($check['help']!==''): ?><small><?php echo esc_html($check['help']); ?></small><?php endif; ?></li><?php endforeach; ?></ul>
            <a class="wphm-sc-settings-link" href="#wphm-sc-settings">この項目の設定・確認方法を見る</a>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ol>
    <?php wphm_sc_evidence_html($facts); ?>
    <details class="wphm-sc-details wphm-panel"><summary>参照した情報と判定の範囲</summary>
        <p>横棒は各項目に書いた確認範囲のリスクです。低は確認した設定・通信・対象製品の照合範囲だけを表します。未確認も灰色の棒と理由を表示します。その下の細い棒は確認項目の内訳で、リスクや安全性の点数ではありません。他プラグイン・テーマを含むサイト全体の耐性や侵害の有無は別に確認が必要です。</p>
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
    catch (Throwable $exception) { $report = null; $GLOBALS['wphm_sc_page_error'] = wphm_sc_failure_message($exception); }
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
            <button type="button" class="button" id="wphm-sc-pause" hidden>自動照合を一時停止</button>
            <button type="button" class="button" id="wphm-sc-resume" hidden>照合を続ける</button>
            <span id="wphm-sc-status" role="status" aria-live="polite"></span>
        </form>
        <p class="description">再チェックを一度押すと、設定・公式更新情報と既知脆弱性を確認し、残りを最大8製品ずつ自動照合します。初回の外部照会枠は25秒、続行は各12秒、全体で最長10分・最大500製品です。再チェックは20秒間隔です。WordPress.orgへ版・名称等、WPVulnerabilityへ製品識別名を照会します。Cloudflareは設定時のみ読取り照会します。</p>
        <p class="description">この機能を有効にして管理者が画面を開くと、以後のWordPress到達通信・認証イベントを上限付きで集計します。入力全文・パスワード・IP・利用者名は保存しません。無効にすると集計も停止します。</p>
    </section>
    <p id="wphm-sc-error" role="alert"><?php echo esc_html($GLOBALS['wphm_sc_page_error'] ?? ''); ?></p>
    <?php if (!empty($GLOBALS['wphm_sc_page_message'])) echo '<p role="status">' . esc_html($GLOBALS['wphm_sc_page_message']) . '</p>'; ?>
    <div id="wphm-sc-report" aria-busy="false"><?php if ($report !== null) wphm_sc_report_html($report); ?></div>
    <?php wphm_sc_settings_html(); ?>
    </div>
    <?php
}
