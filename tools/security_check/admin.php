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
    $preferences=wphm_sc_options();
    $key.='_'.substr(wphm_sc_users_signature(),0,12);
    $key.='_'.substr(hash('sha256',wp_json_encode([wphm_sc_source_selection(),wphm_sc_reading_scopes(),$preferences['updates'],$preferences['vulnerabilities'],$preferences['vendor'],$preferences['public_http'],home_url('/'),site_url('/')])),0,12);
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
        $users_pending=!empty($report['facts']['identity']['pending']); $reading_pending=!empty($report['facts']['reading']['pending']); $has_more=$pending>0 || $users_pending || $reading_pending;
        $scan=['run'=>'','pending'=>$pending,'users_pending'=>$users_pending,'reading_pending'=>$reading_pending,'auto'=>false,'stopped'=>false];
        if ($refresh) {
            $job['fingerprint']=$report['fingerprint']; $job['steps']++;
            if ($has_more && $job['created']>=time()-600 && $job['steps']<64 && ($users_pending || $reading_pending || empty($report['facts']['vulnerabilities']['limited']))) {
                set_transient($job_key,$job,max(1,600-(time()-$job['created'])));
                $scan['run']=$job['id']; $scan['auto']=true;
            } else {
                delete_transient($job_key); $scan['stopped']=$has_more;
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
    try {
        if($operation==='recheck' && isset($_POST['wphm_sc_sources_form'])) {
            if($_POST['wphm_sc_sources_form']!=='1') throw new WPHM_Security_Check_Exception('取得元フォームの形式が不正です。');
            wphm_sc_save_sources($_POST);
        }
        $GLOBALS['wphm_sc_page_report'] = wphm_sc_report(true,$operation,$run);
    }
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
        if($operation==='recheck' && isset($_POST['wphm_sc_sources_form'])) {
            if($_POST['wphm_sc_sources_form']!=='1') throw new WPHM_Security_Check_Exception('取得元フォームの形式が不正です。');
            wphm_sc_save_sources($_POST);
        }
        $report = wphm_sc_report(true,$operation,$run);
        ob_start(); wphm_sc_report_html($report); $html = ob_get_clean();
    } catch (Throwable $exception) {
        while(ob_get_level()>$buffer_level) ob_end_clean();
        wp_send_json_error(['message' => wphm_sc_failure_message($exception)], $exception instanceof WPHM_Security_Check_Exception ? 409 : 500);
    }
    $options=wphm_sc_options();
    wp_send_json_success(['html' => $html,'scan'=>$report['scan'],'connections'=>['cf'=>$options['cf_secret']!=='','server'=>$options['server_secret']!=='','edge'=>$options['edge_secret']!=='','gh'=>$options['gh_secret']!=='']]);
}

function wphm_sc_assets(): void {
    if (($_GET['page'] ?? '') !== 'wphm-security-check' || !wphm_sc_allowed()) return;
    wp_enqueue_style('wphm-security-check', WPHM_PLUGIN_URL . 'assets/css/security-check.css', ['wphm-admin-modern'], WPHM_VERSION);
    wp_enqueue_script('wphm-security-check', WPHM_PLUGIN_URL . 'assets/js/security-check.js', [], WPHM_VERSION, true);
    wp_localize_script('wphm-security-check', 'wphmSecurityCheck', ['url' => admin_url('admin-ajax.php')]);
}

function wphm_sc_render(): void {
    if (!wphm_sc_allowed()) wp_die('この機能を使用する権限がないか、機能が無効です。', '', ['response' => 403]);
    try { $report = $GLOBALS['wphm_sc_page_report'] ?? wphm_sc_report(); }
    catch (Throwable $exception) { $report = null; $GLOBALS['wphm_sc_page_error'] = wphm_sc_failure_message($exception); }
    echo '<div class="wrap wphm-app wphm-security-check">';
    wphm_render_header('セキュリティチェック');
    ?>
    <section class="wphm-panel wphm-sc-intro">
        <h2>どの対策が設定されているかを確認</h2>
        <p>項目を開くと対策の状態と確認先を表示します。読み取れない設定を「なし」とは扱いません。</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wphm-security-check')); ?>" id="wphm-sc-form">
            <?php wp_nonce_field('wphm_security_check', 'nonce'); ?>
            <input type="hidden" name="wphm_sc_action" value="recheck">
            <?php wphm_sc_acquisition_fields_html(); ?>
            <div class="wphm-sc-run-row">
            <button type="submit" class="button button-primary wphm-primary-button" id="wphm-sc-recheck"><span class="dashicons dashicons-update" aria-hidden="true"></span>再チェック</button>
            <button type="button" class="button" id="wphm-sc-pause" hidden>自動照合を一時停止</button>
            <button type="button" class="button" id="wphm-sc-resume" hidden>照合を続ける</button>
            <span id="wphm-sc-status" role="status" aria-live="polite"></span>
            </div>
        </form>
        <p class="description">選択・入力後は「再チェック」で保存と取得を行います。別の保存・接続テストは不要です。選択した公開照合は最大8製品ずつ、最長10分・最大500製品で停止します。再チェックは20秒間隔です。途中の取得失敗は各項目で確認できます。</p>
    </section>
    <p id="wphm-sc-error" role="alert"><?php echo esc_html($GLOBALS['wphm_sc_page_error'] ?? ''); ?></p>
    <?php if (!empty($GLOBALS['wphm_sc_page_message'])) echo '<p role="status">' . esc_html($GLOBALS['wphm_sc_page_message']) . '</p>'; ?>
    <div id="wphm-sc-report" aria-busy="false"><?php if ($report !== null) wphm_sc_report_html($report); ?></div>
    <dialog id="wphm-sc-dialog" class="wphm-sc-dialog" aria-labelledby="wphm-sc-dialog-title"><div class="wphm-sc-dialog-head"><h2 id="wphm-sc-dialog-title">確認する場所・内容</h2><button type="button" class="wphm-sc-dialog-close" aria-label="閉じる">×</button></div><div id="wphm-sc-dialog-body"></div></dialog>
    <?php wphm_sc_settings_html(); ?>
    </div>
    <?php
}
