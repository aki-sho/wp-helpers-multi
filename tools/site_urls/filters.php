<?php
if (!defined('ABSPATH')) exit;

function wphm_su_filter_labels(): array {
    return ['uploads' => 'アップロードディレクトリ配下', 'image_redirects' => '画像ファイルへ自動転送するURL', 'attachments' => '添付ファイルページ（attachment_id・添付ページURL）'];
}

function wphm_su_filters(?array $value = null): array {
    if ($value === null) {
        $value = get_option('wphm_site_urls_filters', null);
        if (!is_array($value)) $value = ['uploads' => (bool)get_option('wphm_site_urls_exclude_uploads', false)];
    }
    $filters = ['mode' => ($value['mode'] ?? '') === 'only' ? 'only' : 'exclude'];
    foreach (wphm_su_filter_labels() as $key => $label) $filters[$key] = !empty($value[$key]);
    return $filters;
}

function wphm_su_filter_input(array $input): array {
    if (!isset($input['mode']) || !is_string($input['mode']) || !in_array($input['mode'], ['exclude', 'only'], true)) throw new InvalidArgumentException('収集方法が不正です。');
    $value = ['mode' => $input['mode']];
    foreach (wphm_su_filter_labels() as $key => $label) {
        if (!isset($input[$key]) || !is_string($input[$key]) || !in_array($input[$key], ['0', '1'], true)) throw new InvalidArgumentException('収集対象の選択が不正です。');
        $value[$key] = $input[$key] === '1';
    }
    return $value;
}

function wphm_su_save_filters(array $filters): array {
    $filters = wphm_su_filters($filters);
    update_option('wphm_site_urls_filters', $filters, false);
    if (wphm_su_filters() !== $filters) throw new RuntimeException('収集対象の選択を保存できませんでした。');
    return $filters;
}

function wphm_su_filter_summary(array $filters): string {
    $selected = [];
    foreach (wphm_su_filter_labels() as $key => $label) if (!empty($filters[$key])) $selected[] = $label;
    if (!$selected) return $filters['mode'] === 'only' ? '対象が未選択です。収集する種類をチェックしてください。' : '除外なし（すべての種類）';
    return implode('・', $selected) . ($filters['mode'] === 'only' ? ' だけ収集' : ' を除外');
}

function wphm_su_is_upload(string $url): bool {
    $path = wphm_su_directory_path((string)wp_parse_url($url, PHP_URL_PATH));
    foreach (wphm_su_upload_directories() as $directory) if ($path === $directory || strpos($path, $directory . '/') === 0) return true;
    return false;
}

function wphm_su_is_attachment(string $url): bool {
    $query = (string)wp_parse_url($url, PHP_URL_QUERY); $vars = [];
    parse_str($query, $vars);
    if (isset($vars['attachment_id']) && is_scalar($vars['attachment_id']) && preg_match('/^[0-9]+$/D', (string)$vars['attachment_id'])) return true;
    $id = url_to_postid($url);
    if (!$id) $id = url_to_postid(rawurldecode($url));
    return $id && get_post_type($id) === 'attachment';
}

/** SQL is built only from fixed column names; selected types are combined with OR. */
function wphm_su_filter_where(?array $filters = null): string {
    $filters = wphm_su_filters($filters); $matches = [];
    if ($filters['uploads']) $matches[] = 'is_upload = 1';
    if ($filters['attachments']) $matches[] = 'is_attachment = 1';
    if ($filters['image_redirects']) $matches[] = "redirect_state = 'image'";
    if (!$matches) return $filters['mode'] === 'only' ? '0 = 1' : '1 = 1';
    $where = '(' . implode(' OR ', $matches) . ')';
    if ($filters['mode'] === 'exclude') {
        $where = 'NOT ' . $where;
        // Hold unverified URLs rather than claiming that an unknown redirect is not an image.
        if ($filters['image_redirects']) $where .= " AND crawl_state NOT IN ('queued','head','head_get') AND redirect_state NOT IN ('pending','unknown','unverified')";
    }
    return 'classified = 1 AND ' . $where;
}

function wphm_su_classify_url(string $url): array {
    return ['is_upload' => wphm_su_is_upload($url) ? 1 : 0, 'is_attachment' => wphm_su_is_attachment($url) ? 1 : 0, 'classified' => 1];
}

function wphm_su_classification_counts(): array {
    global $wpdb;
    $row = $wpdb->get_row("SELECT SUM(classified = 0 OR crawl_state IN ('queued','head','head_get') OR redirect_state = 'pending') AS pending, SUM(redirect_state IN ('unknown','unverified')) AS unknown FROM " . wphm_su_table() . ' WHERE is_article = 0', ARRAY_A);
    return ['pending' => (int)($row['pending'] ?? 0), 'unknown' => (int)($row['unknown'] ?? 0)];
}
