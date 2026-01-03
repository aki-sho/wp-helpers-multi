<?php
if (!defined('ABSPATH')) exit;

function wphm_access_log_repo_insert(array $row): void {
    global $wpdb;

    $table = wphm_access_log_table_name();

    // 念のため型を揃える
    $data = [
        'created_at'  => (int)($row['created_at'] ?? time()),
        'method'      => (string)($row['method'] ?? ''),
        'status'      => (int)($row['status'] ?? 0),
        'url'         => (string)($row['url'] ?? ''),
        'path'        => (string)($row['path'] ?? ''),
        'referrer'    => (string)($row['referrer'] ?? ''),
        'ip'          => (string)($row['ip'] ?? ''),
        'user_id'     => (int)($row['user_id'] ?? 0),
        'user_agent'  => (string)($row['user_agent'] ?? ''),
    ];

    $wpdb->insert($table, $data, [
        '%d','%s','%d','%s','%s','%s','%s','%d','%s'
    ]);
}

/**
 * Get list + total (pagination)
 * $args: paged, per_page, q, ip, from, to
 */
function wphm_access_log_repo_get(array $args): array {
    global $wpdb;

    $table = wphm_access_log_table_name();

    $paged    = max(1, (int)($args['paged'] ?? 1));
    $per_page = max(1, min(200, (int)($args['per_page'] ?? 50)));
    $offset   = ($paged - 1) * $per_page;

    $where = [];
    $params = [];

    // search (url/path/referrer/user_agent)
    $q = isset($args['q']) ? trim((string)$args['q']) : '';
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where[] = "(url LIKE %s OR path LIKE %s OR referrer LIKE %s OR user_agent LIKE %s)";
        array_push($params, $like, $like, $like, $like);
    }

    $ip = isset($args['ip']) ? trim((string)$args['ip']) : '';
    if ($ip !== '') {
        $where[] = "ip = %s";
        $params[] = $ip;
    }

    // from/to are unix timestamps
    $from = (int)($args['from'] ?? 0);
    if ($from > 0) {
        $where[] = "created_at >= %d";
        $params[] = $from;
    }
    $to = (int)($args['to'] ?? 0);
    if ($to > 0) {
        $where[] = "created_at <= %d";
        $params[] = $to;
    }

    $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // total
    $sql_total = "SELECT COUNT(*) FROM {$table} {$where_sql}";
    $total = $params
        ? (int)$wpdb->get_var($wpdb->prepare($sql_total, $params))
        : (int)$wpdb->get_var($sql_total);

    // items
    $sql_items = "SELECT * FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
    $params_items = $params;
    $params_items[] = $per_page;
    $params_items[] = $offset;

    $items = (array)$wpdb->get_results($wpdb->prepare($sql_items, $params_items), ARRAY_A);

    return [
        'items'    => $items,
        'total'    => $total,
        'paged'    => $paged,
        'per_page' => $per_page,
        'pages'    => (int)max(1, ceil($total / $per_page)),
    ];
}

function wphm_access_log_repo_delete_ids(array $ids): int {
    global $wpdb;
    $table = wphm_access_log_table_name();

    $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
    if (!$ids) return 0;

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $sql = "DELETE FROM {$table} WHERE id IN ({$placeholders})";
    $wpdb->query($wpdb->prepare($sql, $ids));

    return (int)$wpdb->rows_affected;
}

function wphm_access_log_repo_purge_older_than_days(int $days): int {
    global $wpdb;
    $table = wphm_access_log_table_name();

    $days = max(1, $days);
    $threshold = time() - ($days * 86400);

    $sql = "DELETE FROM {$table} WHERE created_at < %d";
    $wpdb->query($wpdb->prepare($sql, $threshold));
    return (int)$wpdb->rows_affected;
}

function wphm_access_log_repo_truncate(): void {
    global $wpdb;
    $table = wphm_access_log_table_name();
    $wpdb->query("TRUNCATE TABLE {$table}");
}
