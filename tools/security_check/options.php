<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_options(): array {
    $saved = get_option('wphm_sc_options', []);
    return array_merge(['vulnerabilities' => true, 'cf_zone' => '', 'cf_secret' => '', 'uploads_execution' => 'unknown', 'vendor_patches' => 'unknown', 'evidence' => '', 'attested_at' => 0], is_array($saved) ? $saved : []);
}

/** Credentials are encrypted at rest and never included in reports, HTML values or logs. */
function wphm_sc_secret(string $value, bool $decrypt = false): string {
    if (!function_exists('openssl_encrypt') || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) throw new RuntimeException('この環境では連携キーを暗号化できません。OpenSSLのAES-256-GCMが必要です。');
    $key = hash('sha256', wp_salt('auth') . wp_salt('secure_auth'), true);
    if ($decrypt) {
        $raw = base64_decode($value, true);
        if ($raw === false || strlen($raw) < 29) return '';
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'wphm-security-check');
        return is_string($plain) ? $plain : '';
    }
    $iv = random_bytes(12); $tag = '';
    $encrypted = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'wphm-security-check');
    if ($encrypted === false) throw new RuntimeException('連携キーを保存できませんでした。');
    return base64_encode($iv . $tag . $encrypted);
}

function wphm_sc_save_options(array $input): void {
    if (!wphm_sc_allowed()) throw new RuntimeException('この設定を変更する権限がありません。');
    $options = wphm_sc_options();
    $zone = isset($input['cf_zone']) && is_string($input['cf_zone']) ? trim(wp_unslash($input['cf_zone'])) : '';
    if ($zone !== '' && !preg_match('/^[a-f0-9]{32}$/D', $zone)) throw new RuntimeException('CloudflareのZone IDは32桁の小文字英数字で指定してください。');
    $token = isset($input['cf_token']) && is_string($input['cf_token']) ? trim(wp_unslash($input['cf_token'])) : '';
    if ($token !== '' && !preg_match('/^[A-Za-z0-9_-]{20,256}$/D', $token)) throw new RuntimeException('CloudflareのAPIトークンの形式を確認してください。');
    $options['cf_zone'] = $zone;
    if (!empty($input['clear_cf'])) $options['cf_secret'] = '';
    elseif ($token !== '') $options['cf_secret'] = wphm_sc_secret($token);
    $options['vulnerabilities'] = !empty($input['vulnerabilities']);
    foreach (['uploads_execution', 'vendor_patches'] as $key) {
        $value = $input[$key] ?? 'unknown';
        $options[$key] = is_string($value) && in_array($value, ['unknown','yes','no'], true) ? $value : 'unknown';
    }
    $evidence = isset($input['evidence']) && is_string($input['evidence']) ? sanitize_text_field(wp_unslash($input['evidence'])) : '';
    $options['evidence'] = function_exists('mb_strcut') ? mb_strcut($evidence, 0, 500, 'UTF-8') : substr($evidence, 0, 500);
    $options['attested_at'] = time();
    update_option('wphm_sc_options', $options, false);
    // Invalidate all administrators' report caches using a shared revision, without enumerating users.
    update_option('wphm_sc_revision', wp_generate_password(16, false, false), false);
    delete_option('wphm_sc_cf_metadata');
}

function wphm_sc_settings_html(): void {
    $options = wphm_sc_options();
    ?>
    <details class="wphm-panel wphm-sc-details wphm-sc-settings"><summary>照合・外部連携・サーバー情報の設定</summary>
    <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=wphm-security-check')); ?>">
        <?php wp_nonce_field('wphm_security_check', 'nonce'); ?>
        <input type="hidden" name="wphm_sc_action" value="settings">
        <p><label><input type="checkbox" name="vulnerabilities" value="1" <?php checked($options['vulnerabilities']); ?>>再チェックでWPVulnerabilityの公開データと照合する</label></p>
        <p class="description">送信するのは製品の識別名です。版の比較はこのサイト内で行います。1回につき最大8製品を照会し、続きは次回の再チェックで確認します。入力内容・アクセスログ・認証情報は送信しません。</p>
        <h3>Cloudflareの読取り連携（任意）</h3>
        <p><label>Zone ID <input type="text" name="cf_zone" maxlength="32" value="<?php echo esc_attr($options['cf_zone']); ?>" autocomplete="off"></label></p>
        <p><label>APIトークン <input type="password" name="cf_token" value="" autocomplete="new-password" maxlength="256" placeholder="<?php echo $options['cf_secret'] ? '保存済み（空欄で保持）' : '未設定'; ?>"></label></p>
        <p><label><input type="checkbox" name="clear_cf" value="1">保存したトークンを削除する</label></p>
        <p class="description">対象ゾーンに限定したZone Read・Analytics Read・Zone WAF Readのトークンを使用してください。ゾーンのドメインがこのサイトに一致するか確認してから統計・ルールを取得します。設定変更や攻撃試験は行いません。</p>
        <h3>サーバー情報の記録（管理者申告）</h3>
        <p class="description">サーバー管理画面や提供元で確認した内容を記録できます。自動検証とは区別し、7日を超える申告は期限切れとして表示します。</p>
        <?php foreach (['uploads_execution' => 'アップロード先でスクリプト実行を禁止している', 'vendor_patches' => 'PHP系列に対してホスティング提供元がセキュリティ修正を提供している'] as $key => $label): ?>
        <p><label><?php echo esc_html($label); ?><select name="<?php echo esc_attr($key); ?>"><?php foreach (['unknown'=>'未確認','yes'=>'はい（確認済み）','no'=>'いいえ（確認済み）'] as $value=>$text): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($options[$key], $value); ?>><?php echo esc_html($text); ?></option><?php endforeach; ?></select></label></p>
        <?php endforeach; ?>
        <p><label>確認根拠・提供元の案内 <input class="widefat" type="text" name="evidence" maxlength="500" value="<?php echo esc_attr($options['evidence']); ?>"></label></p>
        <p><button class="button" type="submit">設定を保存</button></p>
    </form></details>
    <?php
}
