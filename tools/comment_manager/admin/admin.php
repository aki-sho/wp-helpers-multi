<?php
if (!defined('ABSPATH')) exit;

function wphm_render_comment_manager_tool_page(): void {
    if (!current_user_can('manage_options')) return;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wphm_comment_manager_action'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], 'wphm_comment_manager_action')) {
            wp_die('Nonceが不正です。');
        }

        $action = sanitize_text_field((string)$_POST['wphm_comment_manager_action']);
        $ids = isset($_POST['ids']) ? array_map('intval', (array)$_POST['ids']) : [];

        $allowed_actions = ['approve', 'unapprove', 'spam', 'trash', 'delete'];

        if (in_array($action, $allowed_actions, true) && !empty($ids)) {
            $done = wphm_comment_manager_apply_bulk_action($action, $ids);

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

    $data = [
        'status' => $status,
        'search' => $search,
        'post_id' => $post_id,
        'result' => $result,
        'msg' => isset($_GET['msg']) ? sanitize_text_field((string)$_GET['msg']) : '',
        'done' => isset($_GET['done']) ? (int)$_GET['done'] : 0,
        'counts' => wphm_comment_manager_get_counts(),
        'status_options' => wphm_comment_manager_get_status_options(),
    ];

    require __DIR__ . '/views/page.php';
}