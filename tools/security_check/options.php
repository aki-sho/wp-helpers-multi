<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_options(): array {
    $saved = get_option('wphm_sc_options', []);
    $defaults=['vulnerabilities'=>true,'cf_zone'=>'','cf_secret'=>'','uploads_execution'=>'unknown','vendor_patches'=>'unknown','evidence'=>'','attested_at'=>0,'auth_failures'=>20,'auth_focus'=>10,'traffic_peak'=>600,'http_errors'=>20,'error_percent'=>5];
    $out=array_merge($defaults,is_array($saved)?array_intersect_key($saved,$defaults):[]);
    foreach(['cf_zone','cf_secret','evidence'] as $key) if(!is_string($out[$key])) $out[$key]='';
    foreach(['uploads_execution','vendor_patches'] as $key) if(!is_string($out[$key]) || !in_array($out[$key],['unknown','yes','no'],true)) $out[$key]='unknown';
    $out['vulnerabilities']=in_array($out['vulnerabilities'],[true,1,'1'],true);
    $out['attested_at']=is_numeric($out['attested_at'])?max(0,(int)$out['attested_at']):0;
    foreach(wphm_sc_threshold_bounds() as $key=>$bound) $out[$key]=is_scalar($out[$key]) && ctype_digit((string)$out[$key]) ? max($bound[0],min($bound[1],(int)$out[$key])) : $defaults[$key];
    return $out;
}

function wphm_sc_threshold_bounds(): array { return ['auth_failures'=>[5,1000],'auth_focus'=>[5,60],'traffic_peak'=>[60,60000],'http_errors'=>[1,1000],'error_percent'=>[1,50]]; }

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
    foreach(['cf_zone','cf_token','clear_cf','vulnerabilities','uploads_execution','vendor_patches','evidence','confirm_server','auth_failures','auth_focus','traffic_peak','http_errors','error_percent'] as $key) if(isset($input[$key]) && !is_scalar($input[$key])) throw new WPHM_Security_Check_Exception('設定項目の形式が不正です。');
    $zone = isset($input['cf_zone']) && is_string($input['cf_zone']) ? trim(wp_unslash($input['cf_zone'])) : '';
    if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/D', $zone)) throw new WPHM_Security_Check_Exception('CloudflareのZone IDは32桁の小文字英数字で指定してください。');
    $token = isset($input['cf_token']) && is_string($input['cf_token']) ? trim(wp_unslash($input['cf_token'])) : '';
    if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{20,256}$/D', $token)) throw new WPHM_Security_Check_Exception('CloudflareのAPIトークンの形式を確認してください。');
    $options['cf_zone'] = $zone;
    if (!empty($input['clear_cf'])) $options['cf_secret'] = '';
    elseif ($token !== '') $options['cf_secret'] = wphm_sc_secret($token);
    $options['vulnerabilities'] = !empty($input['vulnerabilities']);
    $server_changed=false;
    foreach (['uploads_execution', 'vendor_patches'] as $key) {
        $value = $input[$key] ?? 'unknown';
        $clean=is_string($value) && in_array($value, ['unknown','yes','no'], true) ? $value : 'unknown';
        if($options[$key]!==$clean) $server_changed=true;
        $options[$key]=$clean;
    }
    $evidence = isset($input['evidence']) && is_string($input['evidence']) ? sanitize_text_field(wp_unslash(substr($input['evidence'],0,4000))) : '';
    $evidence=function_exists('mb_strcut') ? mb_strcut($evidence,0,500,'UTF-8') : wp_check_invalid_utf8(substr($evidence,0,500),true);
    if($options['evidence']!==$evidence) $server_changed=true;
    $options['evidence']=$evidence;
    if($server_changed || !empty($input['confirm_server'])) $options['attested_at']=time();
    foreach(wphm_sc_threshold_bounds() as $key=>$bound) if(isset($input[$key])) {
        if(!ctype_digit((string)$input[$key]) || strlen((string)$input[$key])>6 || (int)$input[$key]<$bound[0] || (int)$input[$key]>$bound[1]) throw new WPHM_Security_Check_Exception('観測の注意基準が指定範囲外です。');
        $options[$key]=(int)$input[$key];
    }
    update_option('wphm_sc_options', $options, false);
    // Invalidate all administrators' report caches using a shared revision, without enumerating users.
    update_option('wphm_sc_revision', wp_generate_password(16, false, false), false);
    delete_option('wphm_sc_cf_metadata');
    delete_transient('wphm_sc_job_'.get_current_user_id());
}

function wphm_sc_settings_html(): void {
    $options = wphm_sc_options();
    ?>
    <details id="wphm-sc-settings" class="wphm-panel wphm-sc-details wphm-sc-settings"><summary>各項目の設定・連携・観測基準</summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wphm-security-check')); ?>">
        <?php wp_nonce_field('wphm_security_check', 'nonce'); ?>
        <input type="hidden" name="wphm_sc_action" value="settings">
        <p><label><input type="checkbox" name="vulnerabilities" value="1" <?php checked($options['vulnerabilities']); ?>>再チェックでWPVulnerabilityの公開データと照合する</label></p>
        <p class="description">⑨の設定です。送信するのは製品識別名（コアは版）で、版の比較はこのサイト内で行います。再チェックを押すと最大8製品ずつ自動で続きを照合します。入力内容・アクセスログ・認証情報は送信しません。</p>
        <h3>①② 認証設定の確認</h3>
        <p>ログイン試行制限はLimit Login Attempts / Reloaded・Wordfence・ローカル連携、2段階認証はTwo-Factor・Wordfence Login Securityの設定を読み取ります。対応外・取得失敗を区別して表示します。認証設定をこの画面から自動変更することはありません。</p>
        <p><a href="<?php echo esc_url(admin_url('plugins.php')); ?>">利用中の認証機能を確認</a> ／ <a href="<?php echo esc_url(admin_url('profile.php')); ?>">自分の認証設定を確認</a></p>
        <h3>⑤ Cloudflareの読取り連携（任意）</h3>
        <p><label>Zone ID <input type="text" name="cf_zone" maxlength="32" value="<?php echo esc_attr($options['cf_zone']); ?>" autocomplete="off"></label></p>
        <p><label>APIトークン <input type="password" name="cf_token" value="" autocomplete="new-password" maxlength="256" placeholder="<?php echo $options['cf_secret'] ? '保存済み（空欄で保持）' : '未設定'; ?>"></label></p>
        <p><label><input type="checkbox" name="clear_cf" value="1">保存したトークンを削除する</label></p>
        <p class="description">対象ゾーンに限定したZone Read・Analytics Read・Zone WAF Readのトークンを使用してください。ゾーンのドメインがこのサイトに一致するか確認してから統計・ルールを取得します。設定変更や攻撃試験は行いません。</p>
        <h3>⑧ サーバー情報の記録（管理者申告）</h3>
        <p class="description">サーバー管理画面や提供元で確認した内容を記録できます。自動検証とは区別し、7日を超える申告は期限切れとして表示します。</p>
        <?php foreach (['uploads_execution' => 'アップロード先でスクリプト実行を禁止している', 'vendor_patches' => 'PHP系列に対してホスティング提供元がセキュリティ修正を提供している'] as $key => $label): ?>
        <p><label><?php echo esc_html($label); ?><select name="<?php echo esc_attr($key); ?>"><?php foreach (['unknown'=>'未確認','yes'=>'はい（確認済み）','no'=>'いいえ（確認済み）'] as $value=>$text): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($options[$key], $value); ?>><?php echo esc_html($text); ?></option><?php endforeach; ?></select></label></p>
        <?php endforeach; ?>
        <p><label>確認根拠・提供元の案内 <input class="widefat" type="text" name="evidence" maxlength="500" value="<?php echo esc_attr($options['evidence']); ?>"></label></p>
        <p><label><input type="checkbox" name="confirm_server" value="1">変更のないサーバー情報も今回確認し直した</label></p>
        <h3>①⑤ 観測の注意基準</h3>
        <p class="description">計測した範囲の注意表示に使います。認証を遮断したり、他機能の設定を変更したりする値ではありません。</p>
        <?php foreach(['auth_failures'=>'認証失敗（件/分）','auth_focus'=>'共有枠の認証失敗（件/5分）','traffic_peak'=>'通信量（件/分）','http_errors'=>'HTTP 5xx（件/24時間）','error_percent'=>'100件以上ある場合の5xx率（％）'] as $key=>$label): $bound=wphm_sc_threshold_bounds()[$key]; ?>
        <p><label><?php echo esc_html($label); ?> <input type="number" name="<?php echo esc_attr($key); ?>" min="<?php echo (int)$bound[0]; ?>" max="<?php echo (int)$bound[1]; ?>" value="<?php echo (int)$options[$key]; ?>"></label></p>
        <?php endforeach; ?>
        <p><button class="button" type="submit">設定を保存</button></p>
    </form></details>
    <?php
}
