<?php
if (!defined('ABSPATH')) exit;

function wphm_comment_manager_prepare_block(array $ids): void {
    $targets = [];
    foreach (array_slice(array_unique($ids), 0, 200) as $id) {
        $comment = get_comment((int)$id);
        if (!$comment || !current_user_can('edit_comment', (int)$id)) continue;
        $targets[(int)$id] = wphm_ip_blocklist_normalize_ip((string)$comment->comment_author_IP);
    }
    if (!$targets) wp_die('IP拒否を確認できるコメントがありません。');
    $token = str_replace('-', '', wp_generate_uuid4());
    $review = ['targets' => $targets, 'duration' => wphm_ip_blocklist_default_duration(), 'token' => $token];
    set_transient('wphm_ip_review_' . get_current_user_id() . '_' . $token, $review, 10 * MINUTE_IN_SECONDS);
    $GLOBALS['wphm_comment_block_review'] = $review;
}

function wphm_comment_manager_confirm_block(): array {
    $token = isset($_POST['review_token']) && is_string($_POST['review_token']) ? $_POST['review_token'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) wp_die('確認情報が不正です。');
    $key = 'wphm_ip_review_' . get_current_user_id() . '_' . $token;
    $review = get_transient($key);
    if (!is_array($review) || empty($review['targets'])) wp_die('確認の有効期限が切れました。対象を選び直してください。');
    foreach ($review['targets'] as $id => $ip) {
        $comment = get_comment((int)$id);
        if (!$comment || !current_user_can('edit_comment', (int)$id) || wphm_ip_blocklist_normalize_ip((string)$comment->comment_author_IP) !== $ip) wp_die('対象の状態が変わりました。対象を選び直してください。');
    }
    delete_transient($key);
    return $review;
}

function wphm_comment_manager_render_block_review(array $review): void {
    echo '<div class="wrap wphm-app">';
    wphm_render_header('スパム指定とIP拒否の確認');
    echo '<p>次のコメントをスパムにし、拒否対象のIPを期限付きで拒否します。共有回線では他の利用者にも影響するため、対象を確認してください。</p><ul>';
    $self = wphm_ip_blocklist_get_current_ip();
    foreach ($review['targets'] as $id => $ip) {
        $protected = $ip === '' || $ip === $self || wphm_ip_blocklist_is_allowed($ip);
        echo '<li>コメント #' . (int)$id . '：' . esc_html($ip ?: 'IP不明') . ($protected ? '（IP拒否の対象外）' : '（IP拒否対象）') . '</li>';
    }
    echo '</ul><p>拒否期間：' . (int)($review['duration'] / 3600) . '時間</p><form method="post">';
    wp_nonce_field('wphm_comment_manager_action');
    echo '<input type="hidden" name="review_token" value="' . esc_attr($review['token']) . '"><button class="button button-primary" name="wphm_comment_manager_action" value="confirm_spam_block">確認した対象へ実行</button> <a class="button" href="' . esc_url(wphm_comment_manager_admin_url()) . '">キャンセル</a></form></div>';
}
