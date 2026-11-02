<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_wphm_access_log_delete', 'wphm_access_log_ajax_delete');
function wphm_access_log_ajax_delete(): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => '権限がありません'], 403);
    }
    if (!wphm_is_tool_enabled('access_log')) wp_send_json_error(['message'=>'機能が無効です'],403);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_send_json_error(['message'=>'POSTで操作してください'],405);

    check_ajax_referer('wphm_access_log_ajax', 'nonce');

    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        wp_send_json_error(['message' => 'idが不正です'], 400);
    }

    $deleted = wphm_access_log_repo_delete_ids([$id]);
    wp_send_json_success(['deleted' => $deleted]);
}
