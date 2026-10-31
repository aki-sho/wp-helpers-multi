<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/block_review.php';

function wphm_comment_manager_handle_request(): void {
    if (!current_user_can('manage_options')) wp_die('権限がありません。', '', ['response' => 403]);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wphm_comment_manager_action'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], 'wphm_comment_manager_action')) {
            wp_die('Nonceが不正です。');
        }

        $action = sanitize_text_field((string)$_POST['wphm_comment_manager_action']);
        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];
        $ids = array_slice(array_values(array_unique($ids)), 0, 200);
        $duration = DAY_IN_SECONDS;
        if ($action === 'spam_block') {
            wphm_comment_manager_prepare_block($ids);
            return;
        }
        if ($action === 'confirm_spam_block') {
            $review = wphm_comment_manager_confirm_block();
            $ids = array_keys($review['targets']);
            $duration = $review['duration'];
            $action = 'spam_block';
        }

        $allowed_actions = ['approve', 'unapprove', 'spam', 'spam_block', 'trash', 'delete'];

        if (in_array($action, $allowed_actions, true) && !empty($ids)) {
            $done = wphm_comment_manager_apply_bulk_action($action, $ids, $duration);

            $redirect_args = [
                'msg' => 'updated',
                'done' => $done,
            ];

            if (isset($_POST['status'])) {
                $redirect_args['status'] = sanitize_text_field((string)$_POST['status']);
            }
            if (isset($_POST['s'])) {
                $redirect_args['s'] = sanitize_text_field((string)$_POST['s']);
            }
            if (isset($_POST['post_id'])) {
                $redirect_args['post_id'] = (int)$_POST['post_id'];
            }
            if (isset($_POST['paged'])) {
                $redirect_args['paged'] = max(1, (int)$_POST['paged']);
            }

            wp_safe_redirect(wphm_comment_manager_admin_url($redirect_args));
            exit;
        }
    }
}

function wphm_render_comment_manager_tool_page(): void {
    if (!current_user_can('manage_options')) return;
    if (isset($GLOBALS['wphm_comment_block_review'])) {
        wphm_comment_manager_render_block_review($GLOBALS['wphm_comment_block_review']);
        return;
    }

    $status  = isset($_GET['status']) ? wphm_comment_manager_normalize_status((string)$_GET['status']) : 'all';
    $search  = isset($_GET['s']) ? sanitize_text_field((string)$_GET['s']) : '';
    $post_id = isset($_GET['post_id']) ? (int)$_GET['post_id'] : 0;
    $paged   = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;

    $result = wphm_comment_manager_get_comments([
        'status' => $status,
        's' => $search,
        'post_id' => $post_id,
        'paged' => $paged,
        'per_page' => 20,
    ]);

    $comment_ips = [];
    foreach ($result['items'] as $comment) {
        $comment_ips[] = (string)($comment->comment_author_IP ?? '');
    }
    $blocked_ips = function_exists('wphm_ip_blocklist_get_entries')
        ? wphm_ip_blocklist_get_entries($comment_ips)
        : [];

    $data = [
        'status' => $status,
        'search' => $search,
        'post_id' => $post_id,
        'result' => $result,
        'msg' => isset($_GET['msg']) ? sanitize_text_field((string)$_GET['msg']) : '',
        'done' => isset($_GET['done']) ? (int)$_GET['done'] : 0,
        'counts' => wphm_comment_manager_get_counts(),
        'status_options' => wphm_comment_manager_get_status_options(),
        'blocked_ips' => $blocked_ips,
    ];

    require __DIR__ . '/views/page.php';
}
