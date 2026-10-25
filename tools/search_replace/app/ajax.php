<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_wphm_search_replace', 'wphm_sr_ajax');
function wphm_sr_ajax(): void {
    if (!current_user_can('manage_options') || !wphm_is_tool_enabled('search_replace')) wp_send_json_error(['message' => '権限がないか、機能が無効です。'], 403);
    check_ajax_referer('wphm_search_replace', 'nonce');
    if (!wphm_sr_ensure_schema()) wp_send_json_error(['message' => '置換履歴の保存先を準備できませんでした。'], 500);
    $raw = isset($_POST['payload']) && is_string($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $input = strlen($raw) <= 100000 ? json_decode($raw, true) : null;
    if (!is_array($input) || !isset($input['op']) || !is_string($input['op'])) wp_send_json_error(['message' => 'リクエストが不正です。'], 400);
    foreach (['token', 'post_id', 'history_id', 'page'] as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) wp_send_json_error(['message' => 'リクエストが不正です。'], 400);
    }
    switch ($input['op']) {
        case 'start':
        case 'search':
        case 'apply':
            $result = new WP_Error('editor_only', '検索・置換は投稿・固定ページの編集画面内で実行してください。');
            break;
        case 'history':
            $result = wphm_sr_history_list(max(1, min(100000, absint($input['page'] ?? 1))));
            break;
        case 'detail':
            $row = wphm_sr_history_get(absint($input['history_id'] ?? 0));
            if (!$row || !current_user_can('edit_post', (int) $row['post_id'])) $result = new WP_Error('permission', 'この履歴を表示できません。');
            else $result = ['before' => $row['before_content'], 'after' => $row['after_content'], 'note' => $row['note']];
            break;
        case 'restore':
            $result = wphm_sr_restore(absint($input['history_id'] ?? 0));
            break;
        default:
            $result = new WP_Error('operation', '操作が不正です。');
    }
    if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message(), 'code' => $result->get_error_code()], 400);
    wp_send_json_success($result);
}
