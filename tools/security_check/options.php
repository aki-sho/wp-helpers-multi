<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_options(): array {
    $saved = get_option('wphm_sc_options', []);
    $defaults=['vulnerabilities'=>true,'updates'=>true,'cf_zone'=>'','cf_secret'=>'','server_provider'=>'local','server_connection'=>'','server_secret'=>'','edge_provider'=>'cloudflare','edge_connection'=>'','edge_secret'=>'','uploads_execution'=>'unknown','vendor_patches'=>'unknown','evidence'=>'','attested_at'=>0,'auth_failures'=>20,'auth_focus'=>10,'traffic_peak'=>600,'http_errors'=>20,'error_percent'=>5,'login_max_tries'=>20,'login_min_period'=>60,'login_min_lockout'=>60];
    $out=array_merge($defaults,is_array($saved)?array_intersect_key($saved,$defaults):[]);
    $out['vendor_sources']=is_array($saved) && is_array($saved['vendor_sources'] ?? null)?array_slice($saved['vendor_sources'],0,20,true):[];
    $out['gh_secret']=is_array($saved) && is_string($saved['gh_secret'] ?? null)?$saved['gh_secret']:'';
    $out['vendor']=false; $out['public_http']=false;
    foreach(['cf_zone','cf_secret','evidence','server_provider','server_connection','server_secret','edge_provider','edge_connection','edge_secret'] as $key) if(!is_string($out[$key])) $out[$key]=$defaults[$key];
    foreach(['uploads_execution','vendor_patches'] as $key) if(!is_string($out[$key]) || !in_array($out[$key],['unknown','yes','no'],true)) $out[$key]='unknown';
    $out['vulnerabilities']=in_array($out['vulnerabilities'],[true,1,'1'],true);
    $out['updates']=in_array($out['updates'],[true,1,'1'],true);
    $preferences=get_user_option('wphm_sc_sources',get_current_user_id());
    if(is_array($preferences)) foreach(['updates','vulnerabilities','vendor','public_http'] as $key) if(isset($preferences[$key]) && is_bool($preferences[$key])) $out[$key]=$preferences[$key];
    $out['attested_at']=is_numeric($out['attested_at'])?max(0,(int)$out['attested_at']):0;
    foreach(wphm_sc_threshold_bounds() as $key=>$bound) $out[$key]=is_scalar($out[$key]) && ctype_digit((string)$out[$key]) ? max($bound[0],min($bound[1],(int)$out[$key])) : $defaults[$key];
    return $out;
}

function wphm_sc_threshold_bounds(): array { return ['auth_failures'=>[5,1000],'auth_focus'=>[5,60],'traffic_peak'=>[60,60000],'http_errors'=>[1,1000],'error_percent'=>[1,50],'login_max_tries'=>[1,1000],'login_min_period'=>[1,86400],'login_min_lockout'=>[1,86400]]; }

/** Credentials are encrypted at rest and never included in reports, HTML values or logs. */
function wphm_sc_secret(string $value, bool $decrypt = false): string {
    if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) throw new WPHM_Security_Check_Exception('この環境では連携キーを暗号化できません。OpenSSLのAES-256-GCMが必要です。');
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    if ($decrypt) {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'wphm-security-check');
        return is_string($plain) ? $plain : '';
    }
    $iv = random_bytes(12); $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'wphm-security-check');
    if ($encrypted === false) throw new WPHM_Security_Check_Exception('連携キーを保存できませんでした。');
    return base64_encode($iv . $tag . $encrypted);
}

function wphm_sc_save_options(array $input): void {
    if (!wphm_sc_allowed()) throw new WPHM_Security_Check_Exception('この設定を変更する権限がありません。');
    $options = wphm_sc_options();
    foreach(array_merge(['cf_zone','cf_token','clear_cf','vulnerabilities','uploads_execution','vendor_patches','evidence','confirm_server'],array_keys(wphm_sc_threshold_bounds())) as $key) if(isset($input[$key]) && !is_scalar($input[$key])) throw new WPHM_Security_Check_Exception('設定項目の形式が不正です。');
    $zone = isset($input['cf_zone']) && is_string($input['cf_zone']) ? trim(wp_unslash($input['cf_zone'])) : $options['cf_zone'];
    if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/D', $zone)) throw new WPHM_Security_Check_Exception('CloudflareのZone IDは32桁の小文字英数字で指定してください。');
    $token = isset($input['cf_token']) && is_string($input['cf_token']) ? trim(wp_unslash($input['cf_token'])) : '';
    if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{20,256}$/D', $token)) throw new WPHM_Security_Check_Exception('CloudflareのAPIトークンの形式を確認してください。');
    $options['cf_zone'] = $zone;
    if (!empty($input['clear_cf'])) $options['cf_secret'] = '';
    elseif ($token !== '') $options['cf_secret'] = wphm_sc_secret($token);
    $options['vulnerabilities'] = !empty($input['vulnerabilities']);
    $server_changed=false;
    foreach (['uploads_execution', 'vendor_patches'] as $key) {
        if (!array_key_exists($key,$input)) continue;
        $value = $input[$key];
        $clean=is_string($value) && in_array($value, ['unknown','yes','no'], true) ? $value : 'unknown';
        if($options[$key]!==$clean) $server_changed=true;
        $options[$key]=$clean;
    }
    $evidence = isset($input['evidence']) && is_string($input['evidence']) ? sanitize_text_field(wp_unslash(substr($input['evidence'],0,4000))) : $options['evidence'];
    $evidence=function_exists('mb_strcut') ? mb_strcut($evidence,0,500,'UTF-8') : wp_check_invalid_utf8(substr($evidence,0,500),true);
    if($options['evidence']!==$evidence) $server_changed=true;
    $options['evidence']=$evidence;
    if($server_changed || !empty($input['confirm_server'])) $options['attested_at']=time();
    foreach(wphm_sc_threshold_bounds() as $key=>$bound) if(isset($input[$key])) {
        if(!ctype_digit((string)$input[$key]) || strlen((string)$input[$key])>6 || (int)$input[$key]<$bound[0] || (int)$input[$key]>$bound[1]) throw new WPHM_Security_Check_Exception('観測の注意基準が指定範囲外です。');
        $options[$key]=(int)$input[$key];
    }
    if(($input['wphm_sc_thresholds_form'] ?? '')==='1') {
        $global=get_option('wphm_sc_options',[]);
        foreach(['updates','vulnerabilities'] as $key) $options[$key]=is_array($global) && array_key_exists($key,$global)?in_array($global[$key],[true,1,'1'],true):true;
    }
    update_option('wphm_sc_options', $options, false);
    // Invalidate all administrators' report caches using a shared revision, without enumerating users.
    update_option('wphm_sc_revision', wp_generate_password(16, false, false), false);
    delete_option('wphm_sc_cf_metadata');
    delete_transient('wphm_sc_job_'.get_current_user_id());
}

/** Called only by a capability/nonce-protected POST, before any acquisition starts. */
function wphm_sc_save_sources(array $input): void {
    if(!wphm_sc_allowed()) throw new WPHM_Security_Check_Exception('この設定を変更する権限がありません。');
    wphm_sc_save_reading_scopes($input,false);
    $old=wphm_sc_options(); $next=$old; $selection=[];
    foreach(array_merge(array_keys(wphm_sc_source_names()),['updates','vulnerabilities','vendor','public_http']) as $key) {
        $field='source_'.$key;
        if(isset($input[$field]) && (!is_string($input[$field]) || $input[$field]!=='1')) throw new WPHM_Security_Check_Exception('取得元の選択形式が不正です。');
        $selection[$key]=isset($input[$field]);
    }
    $readers=wphm_sc_readers();
    wphm_sc_vendor_save($input,$next);
    foreach(['server','edge'] as $source) {
        foreach(['provider','connection','token'] as $suffix) if(isset($input[$source.'_'.$suffix]) && !is_string($input[$source.'_'.$suffix])) throw new WPHM_Security_Check_Exception('読取り連携の入力形式が不正です。');
        $id=isset($input[$source.'_provider'])?trim(wp_unslash($input[$source.'_provider'])):$old[$source.'_provider'];
        if(!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$id)) throw new WPHM_Security_Check_Exception('読取り連携IDの形式が不正です。');
        if(!(($source==='server' && $id==='local') || ($source==='edge' && $id==='cloudflare') || (isset($readers[$id]) && $readers[$id]['source']===$source) || (!$selection[$source] && $id===$old[$source.'_provider']))) throw new WPHM_Security_Check_Exception('この読取り連携は登録されていません。');
        $next[$source.'_provider']=$id;
        if(in_array($id,['local','cloudflare'],true)) {
            // These built-ins do not consume the generic connection fields, even if hidden inputs retain an old value.
            $next[$source.'_connection']=''; $next[$source.'_secret']='';
            continue;
        }
        if(isset($input[$source.'_connection'])) {
            $value=trim(wp_unslash($input[$source.'_connection']));
            $valid_connection=$id==='cpanel'?wphm_sc_cpanel_connection($value)!==null:((bool)preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$value) && !preg_match('/^[A-Za-z]:/D',$value));
            if($value!=='' && !$valid_connection) throw new WPHM_Security_Check_Exception('接続IDの形式を確認してください。cPanelはaccount@panel.example.com形式、他の連携は128文字以内の接続IDです。URLやファイルパスは指定できません。');
            if($id==='fastly' && $value!=='' && !preg_match('/^[A-Za-z0-9]{6,64}$/D',$value)) throw new WPHM_Security_Check_Exception('Fastly Service IDの形式を確認してください。');
            $next[$source.'_connection']=$value;
        }
        $token=isset($input[$source.'_token'])?trim(wp_unslash($input[$source.'_token'])):'';
        if($id!==$old[$source.'_provider'] || $next[$source.'_connection']!==$old[$source.'_connection']) $next[$source.'_secret']='';
        if($token!=='' && !preg_match('/^[A-Za-z0-9_.+\/=~-]{20,512}$/D',$token)) throw new WPHM_Security_Check_Exception('読取りキーの形式を確認してください。');
        if(isset($input['clear_'.$source]) && $input['clear_'.$source]!=='1') throw new WPHM_Security_Check_Exception('キー削除の指定が不正です。');
        if(!empty($input['clear_'.$source])) $next[$source.'_secret']='';
        elseif($token!=='') $next[$source.'_secret']=wphm_sc_secret($token);
    }
    foreach(['cf_zone','cf_token','clear_cf'] as $key) if(isset($input[$key]) && !is_string($input[$key])) throw new WPHM_Security_Check_Exception('Cloudflare接続の入力形式が不正です。');
    if(isset($input['cf_zone'])) {
        $zone=trim(wp_unslash($input['cf_zone']));
        if($zone!=='' && !preg_match('/^[a-f0-9]{32}$/D',$zone)) throw new WPHM_Security_Check_Exception('CloudflareのZone IDは32桁の小文字英数字で指定してください。');
        if($zone!==$old['cf_zone']) $next['cf_secret']='';
        $next['cf_zone']=$zone;
    }
    $token=isset($input['cf_token'])?trim(wp_unslash($input['cf_token'])):'';
    if($token!=='' && !preg_match('/^[A-Za-z0-9_-]{20,256}$/D',$token)) throw new WPHM_Security_Check_Exception('Cloudflareの読取りトークンの形式を確認してください。');
    if(isset($input['clear_cf']) && $input['clear_cf']!=='1') throw new WPHM_Security_Check_Exception('キー削除の指定が不正です。');
    if(!empty($input['clear_cf'])) $next['cf_secret']=''; elseif($token!=='') $next['cf_secret']=wphm_sc_secret($token);
    // Do not overwrite other administrators' public-reference choices.
    $stored=get_option('wphm_sc_options',[]);
    foreach(['updates','vulnerabilities'] as $key) $next[$key]=is_array($stored) && array_key_exists($key,$stored)?in_array($stored[$key],[true,1,'1'],true):true;
    $old_stored=$old;
    foreach(['updates','vulnerabilities'] as $key) $old_stored[$key]=$next[$key];
    if($next!==$old_stored) {
        update_option('wphm_sc_options',$next,false);
        update_option('wphm_sc_revision',wp_generate_password(16,false,false),false);
        foreach(['wphm_sc_cf_metadata','wphm_sc_reader_server','wphm_sc_reader_edge'] as $key) delete_option($key);
    }
    wphm_sc_save_reading_scopes($input);
    update_user_option(get_current_user_id(),'wphm_sc_sources',$selection,false);
}

function wphm_sc_settings_html(): void {
    $options = wphm_sc_options();
    ?>
    <details id="wphm-sc-settings" class="wphm-panel wphm-sc-details wphm-sc-settings"><summary>記録・集計の注意基準</summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wphm-security-check')); ?>">
        <?php echo str_replace('id="nonce"','id="wphm-sc-threshold-nonce"',wp_nonce_field('wphm_security_check','nonce',true,false)); ?>
        <input type="hidden" name="wphm_sc_action" value="settings">
        <input type="hidden" name="wphm_sc_thresholds_form" value="1">
        <input type="hidden" name="vulnerabilities" value="<?php echo $options['vulnerabilities']?'1':'0'; ?>">
        <p><a href="#wphm-sc-form">上部の共通取得元・接続設定へ戻る</a> ／ <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">利用中の機能を確認</a></p>
        <h3>認証・通信の記録を確認する基準</h3>
        <p class="description">計測した範囲の注意表示に使います。認証を遮断したり、他機能の設定を変更したりする値ではありません。</p>
        <?php foreach(['auth_failures'=>'認証失敗（件/分）','auth_focus'=>'共有枠の認証失敗（件/5分）','traffic_peak'=>'通信量（件/分）','http_errors'=>'HTTP 5xx（件/24時間）','error_percent'=>'100件以上ある場合の5xx率（％）'] as $key=>$label): $bound=wphm_sc_threshold_bounds()[$key]; ?>
        <p><label><?php echo esc_html($label); ?> <input type="number" name="<?php echo esc_attr($key); ?>" min="<?php echo (int)$bound[0]; ?>" max="<?php echo (int)$bound[1]; ?>" value="<?php echo (int)$options[$key]; ?>"></label></p>
        <?php endforeach; ?>
        <p><button class="button" type="submit">注意基準を保存</button></p>
    </form></details>
    <?php
}
