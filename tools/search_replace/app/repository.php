<?php
if (!defined('ABSPATH')) exit;

function wphm_sr_candidate_ids(array $settings, int $cursor, int $max_id) {
    global $wpdb;
    $types = $settings['type'] === 'both' ? ['post', 'page'] : [$settings['type']];
    if ($settings['category']) $types = ['post'];
    $statuses = $settings['status'] === 'all' ? ['publish', 'draft', 'pending', 'private', 'future'] : [$settings['status']];
    $where = 'p.ID > %d AND p.ID <= %d AND p.post_type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
    $where .= ' AND p.post_status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
    $values = array_merge([$cursor, $max_id], $types, $statuses);
    if ($settings['post_id']) { $where .= ' AND p.ID = %d'; $values[] = $settings['post_id']; }
    if ($settings['title'] !== '') { $where .= ' AND p.post_title LIKE %s'; $values[] = '%' . $wpdb->esc_like($settings['title']) . '%'; }
    if ($settings['category']) {
        $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = p.ID AND tt.taxonomy = 'category' AND tt.term_id = %d)";
        $values[] = $settings['category'];
    }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p WHERE {$where} ORDER BY p.ID ASC LIMIT 21", $values));
    return $wpdb->last_error ? new WP_Error('database', '記事の取得に失敗しました。') : array_map('intval', $ids);
}

function wphm_sr_history_insert(array $data): int {
    global $wpdb;
    return $wpdb->insert(wphm_sr_table(), $data) ? (int) $wpdb->insert_id : 0;
}

function wphm_sr_history_update(int $id, array $data): bool {
    global $wpdb;
    return $wpdb->update(wphm_sr_table(), $data, ['id' => $id]) !== false;
}

function wphm_sr_history_get(int $id): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . wphm_sr_table() . ' WHERE id = %d', $id), ARRAY_A);
    return is_array($row) ? $row : null;
}

function wphm_sr_history_for_run(string $run, int $post_id): bool {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . wphm_sr_table() . ' WHERE run_id = %s AND post_id = %d', $run, $post_id));
}

function wphm_sr_history_list(int $page) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, post_id, user_id, created_at, restored_at, replacements, status, note FROM ' . wphm_sr_table() . ' ORDER BY id DESC LIMIT 21 OFFSET %d', ($page - 1) * 20), ARRAY_A);
    if ($wpdb->last_error) return new WP_Error('database', '履歴の取得に失敗しました。');
    $more = count($rows) > 20;
    $items = [];
    foreach (array_slice($rows, 0, 20) as $row) {
        if (!current_user_can('edit_post', (int) $row['post_id'])) continue;
        $post = get_post((int) $row['post_id']);
        $user = get_userdata((int) $row['user_id']);
        $row['title'] = $post ? $post->post_title : '削除済みの記事';
        $row['user'] = $user ? $user->display_name : '削除済みのユーザー';
        $items[] = $row;
    }
    return ['items' => $items, 'more' => $more, 'page' => $page];
}

/** Serialize this tool's replace/restore requests for a given post. */
function wphm_sr_lock(int $post_id): bool {
    global $wpdb;
    $name = 'wphm_sr_' . substr(hash('sha256', $wpdb->prefix . ':' . $post_id), 0, 48);
    return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) === '1';
}

function wphm_sr_unlock(int $post_id): void {
    global $wpdb;
    $name = 'wphm_sr_' . substr(hash('sha256', $wpdb->prefix . ':' . $post_id), 0, 48);
    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
}
