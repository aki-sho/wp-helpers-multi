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

    // ===== actions (POST) =====
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wphm_access_log_action'])) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], 'wphm_access_log_action')) {
            wp_die('Nonceが不正です。');
        }

        $action = sanitize_text_field((string)$_POST['wphm_access_log_action']);

        if ($action === 'delete_selected') {
            $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : [];
            wphm_access_log_repo_delete_ids($ids);

            wp_safe_redirect(wphm_access_log_admin_url(['msg' => 'deleted']));
            exit;
        }

        if ($action === 'purge_days') {
            $days = isset($_POST['days']) ? (int)$_POST['days'] : 30;
            wphm_access_log_repo_purge_older_than_days($days);

            wp_safe_redirect(wphm_access_log_admin_url(['msg' => 'purged']));
            exit;
        }

        if ($action === 'truncate') {
            wphm_access_log_repo_truncate();

            wp_safe_redirect(wphm_access_log_admin_url(['msg' => 'truncated']));
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

    $data = [
        'q' => $q,
        'ip' => $ip,
        'from' => $from_ymd,
        'to' => $to_ymd,
        'result' => $result,
        'msg' => isset($_GET['msg']) ? sanitize_text_field((string)$_GET['msg']) : '',
        'ajax_nonce' => wp_create_nonce('wphm_access_log_ajax'),
    ];

    require __DIR__ . '/views/page.php';
}

function wphm_access_log_admin_url(array $args = []): string {
    $base = admin_url('admin.php?page=wphm-access-log');
    if (!$args) return $base;
    return add_query_arg(array_map('rawurlencode', $args), $base);
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

    $filename = 'access-log-' . date('Ymd-His') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    // Excel 対策（UTF-8 BOM）
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id','datetime','method','status','ip','user_id','url','path','referrer','user_agent']);

    foreach ($result['items'] as $r) {
        fputcsv($out, [
            $r['id'],
            date('Y-m-d H:i:s', (int)$r['created_at']),
            $r['method'],
            $r['status'],
            $r['ip'],
            $r['user_id'],
            $r['url'],
            $r['path'],
            $r['referrer'],
            $r['user_agent'],
        ]);
    }

    fclose($out);
}
