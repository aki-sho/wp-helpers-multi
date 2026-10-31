<?php
if (!defined('ABSPATH')) exit;

function wphm_ip_blocklist_is_allowed(string $ip): bool {
    $allowed = get_option('wphm_ip_allowlist', []);
    return is_array($allowed) && in_array(wphm_ip_blocklist_normalize_ip($ip), $allowed, true);
}

function wphm_ip_blocklist_default_duration(): int {
    return max(3600, min(30 * DAY_IN_SECONDS, (int)get_option('wphm_ip_block_duration', DAY_IN_SECONDS)));
}

add_action('admin_post_wphm_ip_safety_settings', 'wphm_ip_blocklist_save_settings');
function wphm_ip_blocklist_save_settings(): void {
    if (!current_user_can('manage_options')) wp_die('権限がありません。', '', ['response' => 403]);
    check_admin_referer('wphm_ip_safety_settings');
    if (!isset($_POST['allowlist']) || !is_string($_POST['allowlist']) || strlen($_POST['allowlist']) > 10000) wp_die('許可リストの入力が不正です。');
    $ips = preg_split('/[\s,]+/', trim(wp_unslash($_POST['allowlist'])), -1, PREG_SPLIT_NO_EMPTY);
    if (count($ips) > 200) wp_die('許可リストは200件以内で指定してください。');
    $allowed = [];
    foreach ($ips as $ip) {
        $normalized = wphm_ip_blocklist_normalize_ip($ip);
        if ($normalized === '') wp_die('許可リストには正しいIPアドレスを指定してください。');
        $allowed[] = $normalized;
    }
    $duration = isset($_POST['duration']) && is_scalar($_POST['duration']) ? (int)$_POST['duration'] : DAY_IN_SECONDS;
    update_option('wphm_ip_allowlist', array_values(array_unique($allowed)), false);
    update_option('wphm_ip_block_duration', max(3600, min(30 * DAY_IN_SECONDS, $duration)), false);
    $target = isset($_POST['return_page']) && $_POST['return_page'] === 'wphm-comment-manager' ? 'wphm-comment-manager' : 'wphm-access-log';
    wp_safe_redirect(add_query_arg(['page' => $target, 'ip_settings_saved' => '1'], admin_url('admin.php')));
    exit;
}

function wphm_ip_blocklist_render_settings(string $page): void {
    $allowed = get_option('wphm_ip_allowlist', []);
    if (!is_array($allowed)) $allowed = [];
    echo '<details style="margin:16px 0"><summary>IP拒否の保護設定</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('wphm_ip_safety_settings');
    echo '<input type="hidden" name="action" value="wphm_ip_safety_settings"><input type="hidden" name="return_page" value="' . esc_attr($page) . '">';
    echo '<p><label>許可するIP（1行に1件、最大200件）<br><textarea name="allowlist" rows="4" cols="50">' . esc_textarea(implode("\n", $allowed)) . '</textarea></label></p>';
    echo '<p><label>新規IP拒否の有効期間 <select name="duration">';
    foreach ([3600 => '1時間', 86400 => '24時間', 604800 => '7日', 2592000 => '30日'] as $duration => $label) echo '<option value="' . $duration . '" ' . selected(wphm_ip_blocklist_default_duration(), $duration, false) . '>' . esc_html($label) . '</option>';
    echo '</select></label></p><p>許可したIPは既存の拒否登録より優先します。操作中の自分のIPは新たに拒否できません。以前の期限なし登録は、必要に応じて解除してください。</p><button class="button">保存</button></form></details>';
}
