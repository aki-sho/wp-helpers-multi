<?php
if (!defined('ABSPATH')) exit;

function wphm_comment_manager_admin_url(array $args = []): string {
    $base = admin_url('admin.php?page=wphm-comment-manager');
    if (!$args) return $base;
    return add_query_arg($args, $base);
}

function wphm_comment_manager_get_status_options(): array {
    return [
        'all'     => 'すべて',
        'hold'    => '承認待ち',
        'approve' => '承認済み',
        'spam'    => 'スパム',
        'trash'   => 'ゴミ箱',
    ];
}

function wphm_comment_manager_normalize_status(string $status): string {
    $allowed = array_keys(wphm_comment_manager_get_status_options());
    return in_array($status, $allowed, true) ? $status : 'all';
}

function wphm_comment_manager_get_status_label(string $status): string {
    $labels = [
        '0'        => '承認待ち',
        '1'        => '承認済み',
        'hold'     => '承認待ち',
        'approve'  => '承認済み',
        'approved' => '承認済み',
        'spam'     => 'スパム',
        'trash'    => 'ゴミ箱',
        'post-trashed' => '投稿ゴミ箱',
    ];

    return $labels[$status] ?? $status;
}

function wphm_comment_manager_get_counts(): array {
    $c = wp_count_comments();

    return [
        'all'     => (int) ($c->total_comments ?? 0),
        'hold'    => (int) ($c->moderated ?? 0),
        'approve' => (int) ($c->approved ?? 0),
        'spam'    => (int) ($c->spam ?? 0),
        'trash'   => (int) ($c->trash ?? 0),
    ];
}

function wphm_comment_manager_build_query_args(array $input): array {
    $status  = wphm_comment_manager_normalize_status((string)($input['status'] ?? 'all'));
    $search  = sanitize_text_field((string)($input['s'] ?? ''));
    $post_id = isset($input['post_id']) ? (int)$input['post_id'] : 0;
    $paged   = isset($input['paged']) ? max(1, (int)$input['paged']) : 1;
    $per_page = isset($input['per_page']) ? max(1, (int)$input['per_page']) : 20;

    $args = [
        'orderby' => 'comment_date_gmt',
        'order' => 'DESC',
        'number' => $per_page,
        'offset' => ($paged - 1) * $per_page,
        'status' => $status === 'all' ? 'all' : $status,
    ];

    if ($search !== '') {
        $args['search'] = $search;
    }

    if ($post_id > 0) {
        $args['post_id'] = $post_id;
    }

    return $args;
}

function wphm_comment_manager_count_comments(array $input): int {
    $status  = wphm_comment_manager_normalize_status((string)($input['status'] ?? 'all'));
    $search  = sanitize_text_field((string)($input['s'] ?? ''));
    $post_id = isset($input['post_id']) ? (int)$input['post_id'] : 0;

    $args = [
        'count' => true,
        'status' => $status === 'all' ? 'all' : $status,
    ];

    if ($search !== '') {
        $args['search'] = $search;
    }

    if ($post_id > 0) {
        $args['post_id'] = $post_id;
    }

    $query = new WP_Comment_Query();
    return (int) $query->query($args);
}

function wphm_comment_manager_get_comments(array $input): array {
    $args = wphm_comment_manager_build_query_args($input);
    $query = new WP_Comment_Query();

    $items = $query->query($args);
    if (!is_array($items)) {
        $items = [];
    }

    $total = wphm_comment_manager_count_comments($input);
    $per_page = (int) ($args['number'] ?? 20);
    $paged = isset($input['paged']) ? max(1, (int)$input['paged']) : 1;
    $pages = max(1, (int) ceil($total / $per_page));

    return [
        'items' => $items,
        'total' => $total,
        'paged' => $paged,
        'pages' => $pages,
        'per_page' => $per_page,
    ];
}

function wphm_comment_manager_excerpt(string $text, int $length = 80): string {
    $text = wp_strip_all_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim((string)$text);

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . '…';
    }

    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

function wphm_comment_manager_apply_bulk_action(string $action, array $ids): int {
    $done = 0;

    foreach ($ids as $id) {
        $comment_id = (int) $id;
        if ($comment_id <= 0) continue;

        if (!get_comment($comment_id)) continue;

        switch ($action) {
            case 'approve':
                if (wp_set_comment_status($comment_id, 'approve')) {
                    $done++;
                }
                break;

            case 'unapprove':
                if (wp_set_comment_status($comment_id, 'hold')) {
                    $done++;
                }
                break;

            case 'spam':
                if (wp_spam_comment($comment_id)) {
                    $done++;
                }
                break;

            case 'trash':
                if (wp_trash_comment($comment_id)) {
                    $done++;
                }
                break;

            case 'delete':
                if (wp_delete_comment($comment_id, true)) {
                    $done++;
                }
                break;
        }
    }

    return $done;
}

function wphm_comment_manager_get_notice_message(string $msg, int $done = 0): string {
    if ($msg === 'updated') {
        return $done . '件のコメントを更新しました。';
    }

    return '';
}