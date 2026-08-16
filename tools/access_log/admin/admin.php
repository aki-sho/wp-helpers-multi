<?php
if (!defined('ABSPATH')) exit;

/**
 * admin.php から呼ばれる想定：
 * wphm_render_access_log() → require tools/access_log/access_log.php → この関数が見つかる
 */
function wphm_render_access_log_tool_page(): void {
    if (!current_user_can('manage_options')) return;

    // 念のため（初回でも確実にテーブルができる）
    wphm_access_log_schema_ensure();
    if (function_exists('wphm_ip_blocklist_schema_ensure')) {
        wphm_ip_blocklist_schema_ensure();
    }

    // ===== actions (POST) =====
    $has_post_action = isset($_POST['wphm_access_log_action'])
        || isset($_POST['wphm_access_log_block_ip'])
        || isset($_POST['wphm_access_log_unblock_ip']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $has_post_action) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], 'wphm_access_log_action')) {
            wp_die('Nonceが不正です。');
        }

        $redirect_args = wphm_access_log_current_filter_args();

        if (isset($_POST['wphm_access_log_block_ip'])) {
            $target_ip = sanitize_text_field((string)$_POST['wphm_access_log_block_ip']);
            $blocked = function_exists('wphm_ip_blocklist_block')
                && wphm_ip_blocklist_block($target_ip, 'access_log');

            $redirect_args['msg'] = $blocked ? 'ip_blocked' : 'ip_invalid';
            wp_safe_redirect(wphm_access_log_admin_url($redirect_args));
            exit;
        }

        if (isset($_POST['wphm_access_log_unblock_ip'])) {
            $target_ip = sanitize_text_field((string)$_POST['wphm_access_log_unblock_ip']);
            $unblocked = function_exists('wphm_ip_blocklist_unblock')
                && wphm_ip_blocklist_unblock($target_ip);

            $redirect_args['msg'] = $unblocked ? 'ip_unblocked' : 'ip_invalid';
            wp_safe_redirect(wphm_access_log_admin_url($redirect_args));
            exit;
        }

        $action = sanitize_text_field((string)$_POST['wphm_access_log_action']);

        if ($action === 'delete_selected') {
            $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
            wphm_access_log_repo_delete_ids($ids);

            $redirect_args['msg'] = 'deleted';
            wp_safe_redirect(wphm_access_log_admin_url($redirect_args));
            exit;
        }

        if ($action === 'purge_days') {
            $days = isset($_POST['days']) ? (int)$_POST['days'] : 30;
            wphm_access_log_repo_purge_older_than_days($days);

            $redirect_args['msg'] = 'purged';
            wp_safe_redirect(wphm_access_log_admin_url($redirect_args));
            exit;
        }

        if ($action === 'truncate') {
            wphm_access_log_repo_truncate();

            $redirect_args['msg'] = 'truncated';
            wp_safe_redirect(wphm_access_log_admin_url($redirect_args));
            exit;
        }
    }

    // ===== export CSV (GET) =====
    if (isset($_GET['wphm_export']) && $_GET['wphm_export'] === 'csv') {
        wphm_access_log_export_csv();
        exit;
    }

    // ===== filters =====
    $q  = isset($_GET['q']) ? sanitize_text_field((string)$_GET['q']) : '';
    $ip = isset($_GET['ip']) ? sanitize_text_field((string)$_GET['ip']) : '';
    $from_ymd = isset($_GET['from']) ? sanitize_text_field((string)$_GET['from']) : '';
    $to_ymd   = isset($_GET['to']) ? sanitize_text_field((string)$_GET['to']) : '';

    $from = $from_ymd ? wphm_access_log_date_to_ts($from_ymd, false) : 0;
    $to   = $to_ymd   ? wphm_access_log_date_to_ts($to_ymd, true) : 0;

    $paged = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;

    $result = wphm_access_log_repo_get([
        'q'        => $q,
        'ip'       => $ip,
        'from'     => $from,
        'to'       => $to,
        'paged'    => $paged,
        'per_page' => 50,
    ]);

    $log_ips = array_column($result['items'], 'ip');
    $blocked_ips = function_exists('wphm_ip_blocklist_get_entries')
        ? wphm_ip_blocklist_get_entries($log_ips)
        : [];
    $spam_comment_counts = function_exists('wphm_ip_blocklist_get_spam_comment_counts')
        ? wphm_ip_blocklist_get_spam_comment_counts($log_ips)
        : [];

    $data = [
        'q' => $q,
        'ip' => $ip,
        'from' => $from_ymd,
        'to' => $to_ymd,
        'result' => $result,
        'msg' => isset($_GET['msg']) ? sanitize_text_field((string)$_GET['msg']) : '',
        'ajax_nonce' => wp_create_nonce('wphm_access_log_ajax'),
        'blocked_ips' => $blocked_ips,
        'spam_comment_counts' => $spam_comment_counts,
    ];

    require __DIR__ . '/views/page.php';
}

function wphm_access_log_current_filter_args(): array {
    return [
        'q' => isset($_GET['q']) ? sanitize_text_field((string)$_GET['q']) : '',
        'ip' => isset($_GET['ip']) ? sanitize_text_field((string)$_GET['ip']) : '',
        'from' => isset($_GET['from']) ? sanitize_text_field((string)$_GET['from']) : '',
        'to' => isset($_GET['to']) ? sanitize_text_field((string)$_GET['to']) : '',
        'paged' => isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1,
    ];
}

function wphm_access_log_admin_url(array $args = []): string {
    $base = admin_url('admin.php?page=wphm-access-log');
    if (!$args) return $base;
    return add_query_arg($args, $base);
}

function wphm_access_log_export_csv(): void {
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません');
    }

    // フィルタは画面と同じ
    $q  = isset($_GET['q']) ? sanitize_text_field((string)$_GET['q']) : '';
    $ip = isset($_GET['ip']) ? sanitize_text_field((string)$_GET['ip']) : '';
    $from_ymd = isset($_GET['from']) ? sanitize_text_field((string)$_GET['from']) : '';
    $to_ymd   = isset($_GET['to']) ? sanitize_text_field((string)$_GET['to']) : '';

    $from = $from_ymd ? wphm_access_log_date_to_ts($from_ymd, false) : 0;
    $to   = $to_ymd   ? wphm_access_log_date_to_ts($to_ymd, true) : 0;

    // 大量すぎると重いので上限（必要なら上げる）
    $result = wphm_access_log_repo_get([
        'q'        => $q,
        'ip'       => $ip,
        'from'     => $from,
        'to'       => $to,
        'paged'    => 1,
        'per_page' => 200,
    ]);

    $export_ips = array_column($result['items'], 'ip');
    $blocked_ips = function_exists('wphm_ip_blocklist_get_entries')
        ? wphm_ip_blocklist_get_entries($export_ips)
        : [];
    $spam_comment_counts = function_exists('wphm_ip_blocklist_get_spam_comment_counts')
        ? wphm_ip_blocklist_get_spam_comment_counts($export_ips)
        : [];

    $filename = 'access-log-' . date('Ymd-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Excel 対策（UTF-8 BOM）
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','datetime','method','status','ip','ip_blocked','spam_comment_count','user_id','url','path','referrer','user_agent']);

    foreach ($result['items'] as $r) {
        $normalized_ip = function_exists('wphm_ip_blocklist_normalize_ip')
            ? wphm_ip_blocklist_normalize_ip((string)$r['ip'])
            : trim((string)$r['ip']);
        fputcsv($out, [
            $r['id'],
            date('Y-m-d H:i:s', (int)$r['created_at']),
            $r['method'],
            $r['status'],
            $r['ip'],
            isset($blocked_ips[$normalized_ip]) ? '拒否中' : '未拒否',
            (int)($spam_comment_counts[$normalized_ip] ?? 0),
            $r['user_id'],
            $r['url'],
            $r['path'],
            $r['referrer'],
            $r['user_agent'],
        ]);
    }

    fclose($out);
}
