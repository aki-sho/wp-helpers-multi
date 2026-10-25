<?php
if (!defined('ABSPATH')) exit;

function wphm_sr_normalize_settings(array $input) {
    $search = str_replace(["\r\n", "\r"], "\n", (string) ($input['search'] ?? ''));
    $replacement = str_replace(["\r\n", "\r"], "\n", (string) ($input['replacement'] ?? ''));
    if ($search === '') return new WP_Error('empty_search', '検索する文字列を入力してください。');
    if (strlen($search) > 5000 || strlen($replacement) > 10000 || !preg_match('//u', $search . $replacement) || strpos($search . $replacement, "\0") !== false) return new WP_Error('invalid_text', '検索文字列は5,000バイト、置換文字列は10,000バイト以内のUTF-8テキストを指定してください。');
    $type = (string) ($input['type'] ?? 'both');
    $status = (string) ($input['status'] ?? 'publish');
    if (!in_array($type, ['post', 'page', 'both'], true) || !in_array($status, ['all', 'publish', 'draft', 'pending', 'private', 'future'], true)) return new WP_Error('invalid_filter', '検索対象の指定が不正です。');
    return [
        'search' => $search, 'replacement' => $replacement, 'case_sensitive' => !empty($input['case_sensitive']),
        'type' => $type, 'status' => $status, 'post_id' => absint($input['post_id'] ?? 0),
        'category' => absint($input['category'] ?? 0), 'title' => sanitize_text_field($input['title'] ?? ''),
    ];
}

function wphm_sr_session_key(string $token): string {
    return 'wphm_sr_' . get_current_user_id() . '_' . $token;
}

function wphm_sr_session(string $token) {
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) return new WP_Error('session', '検索結果を再取得してください。');
    $state = get_transient(wphm_sr_session_key($token));
    if (!is_array($state) || $state['user_id'] !== get_current_user_id()) return new WP_Error('expired', '検索結果の有効期限が切れました。再検索してください。');
    return $state;
}

function wphm_sr_start(array $input) {
    $settings = wphm_sr_normalize_settings($input);
    if (is_wp_error($settings)) return $settings;
    global $wpdb;
    $token = str_replace('-', '', wp_generate_uuid4());
    $state = ['user_id' => get_current_user_id(), 'settings' => $settings, 'cursor' => 0,
        'max_id' => (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts}"), 'entries' => [],
        'scanned' => 0, 'match_count' => 0, 'done' => false, 'limited' => false];
    if (!set_transient(wphm_sr_session_key($token), $state, HOUR_IN_SECONDS)) return new WP_Error('session', '検索条件を保存できませんでした。');
    return ['token' => $token];
}

function wphm_sr_search(string $token) {
    $state = wphm_sr_session($token);
    if (is_wp_error($state)) return $state;
    $items = [];
    $skipped = [];
    if (!$state['done']) {
        $ids = wphm_sr_candidate_ids($state['settings'], $state['cursor'], $state['max_id']);
        if (is_wp_error($ids)) return $ids;
        $has_more = count($ids) > 20;
        foreach (array_slice($ids, 0, 20) as $id) {
            $state['cursor'] = $id;
            $state['scanned']++;
            if (!current_user_can('edit_post', $id)) continue;
            $post = get_post($id);
            if (!$post) continue;
            if (strlen($post->post_content) > 1024 * 1024) { $skipped[] = ['id' => $id, 'title' => $post->post_title, 'reason' => '本文が1MBを超えるため対象外です。']; continue; }
            $scan = wphm_sr_scan($post->post_content, $state['settings']['search'], $state['settings']['case_sensitive']);
            if ($scan['invalid']) { $skipped[] = ['id' => $id, 'title' => $post->post_title, 'reason' => '本文の文字コードまたはHTML・ブロック構造を確認してください。']; continue; }
            if ($scan['limited']) { $skipped[] = ['id' => $id, 'title' => $post->post_title, 'reason' => '一致が500件を超えるため、検索条件を絞ってください。']; continue; }
            if ($scan['unsupported']) $skipped[] = ['id' => $id, 'title' => $post->post_title, 'reason' => '次のブロックの中は検索対象外です: ' . implode(', ', $scan['unsupported'])];
            if (!$scan['matches']) continue;
            if (count($state['entries']) >= 200 || $state['match_count'] + count($scan['matches']) > 2000) { $state['limited'] = true; $state['done'] = true; break; }
            $hash = hash('sha256', $post->post_content);
            $state['entries'][$id] = $hash;
            $state['match_count'] += count($scan['matches']);
            // Raw byte offsets stay on the server; only ordinal IDs may be submitted for replacement.
            $matches = array_map(function ($match) { unset($match['start'], $match['length']); return $match; }, $scan['matches']);
            $items[] = ['id' => $id, 'title' => $post->post_title, 'type' => $post->post_type, 'status' => $post->post_status,
                'edit_url' => get_edit_post_link($id, 'raw'), 'matches' => $matches];
        }
        $state['done'] = $state['done'] || !$has_more;
        if (!set_transient(wphm_sr_session_key($token), $state, HOUR_IN_SECONDS)) return new WP_Error('session', '検索結果を保存できませんでした。再検索してください。');
    }
    return ['items' => $items, 'skipped' => $skipped, 'done' => $state['done'], 'limited' => $state['limited'], 'scanned' => $state['scanned'], 'count' => $state['match_count']];
}

function wphm_sr_editable_post(int $id) {
    if (!current_user_can('edit_post', $id)) return new WP_Error('permission', 'この記事を編集する権限がありません。');
    clean_post_cache($id);
    $post = get_post($id);
    if (!$post || !in_array($post->post_type, ['post', 'page'], true) || !in_array($post->post_status, ['publish', 'draft', 'pending', 'private', 'future'], true)) return new WP_Error('post', 'この記事は現在、処理対象にできません。');
    if (function_exists('wp_check_post_lock') && wp_check_post_lock($id)) return new WP_Error('locked', '別のユーザーが編集中のためスキップしました。');
    return $post;
}

function wphm_sr_apply(string $token, int $id, array $selected) {
    $state = wphm_sr_session($token);
    if (is_wp_error($state)) return $state;
    if (!isset($state['entries'][$id])) return new WP_Error('selection', '検索結果に含まれていない記事です。');
    if (!$selected || count($selected) > 500) return new WP_Error('selection', '置換する箇所を選択してください。');
    foreach ($selected as $value) if (!is_int($value) || $value < 0) return new WP_Error('selection', '置換箇所の指定が不正です。');
    $selected = array_values(array_unique($selected));
    if (!wphm_sr_lock($id)) return new WP_Error('busy', 'この記事は別の処理が実行中です。しばらくしてから再検索してください。');
    try {
        if (wphm_sr_history_for_run($token, $id)) return new WP_Error('processed', 'この検索結果の記事は処理済みです。履歴を確認し、必要に応じて再検索してください。');
        $post = wphm_sr_editable_post($id);
        if (is_wp_error($post)) return $post;
        if (!hash_equals($state['entries'][$id], hash('sha256', $post->post_content))) return new WP_Error('conflict', '検索後に本文が変更されています。再検索してください。');
        $settings = $state['settings'];
        $scan = wphm_sr_scan($post->post_content, $settings['search'], $settings['case_sensitive']);
        if ($scan['invalid'] || $scan['limited'] || array_diff($selected, array_column($scan['matches'], 'id'))) return new WP_Error('selection', '検索結果が変わっています。再検索してください。');
        $new = wphm_sr_replace_matches($post->post_content, $scan['matches'], $selected, $settings['replacement']);
        if ($new === $post->post_content) return new WP_Error('unchanged', '本文に変更がないため、保存しませんでした。');
        $history_id = wphm_sr_history_insert([
            'run_id' => $token, 'post_id' => $id, 'user_id' => get_current_user_id(), 'created_at' => current_time('mysql', true),
            'status' => 'pending', 'replacements' => count($selected), 'before_content' => $post->post_content, 'after_content' => $new,
            'before_hash' => hash('sha256', $post->post_content), 'after_hash' => hash('sha256', $new), 'note' => '',
        ]);
        if (!$history_id) return new WP_Error('backup', '置換前の本文を保存できないため、更新を中止しました。');
        // Recheck after writing the backup, immediately before the WordPress save operation.
        $fresh = wphm_sr_editable_post($id);
        if (is_wp_error($fresh) || !hash_equals($state['entries'][$id], hash('sha256', $fresh->post_content))) {
            wphm_sr_history_update($history_id, ['status' => 'failed', 'note' => '保存直前に記事の変更または編集ロックを検出しました。']);
            return new WP_Error('conflict', '保存直前に記事の変更または編集ロックを検出しました。再検索してください。');
        }
        $saved = wp_update_post(wp_slash(['ID' => $id, 'post_content' => $new]), true);
        if (is_wp_error($saved) || !$saved) {
            wphm_sr_history_update($history_id, ['status' => 'failed', 'note' => 'WordPressによる保存に失敗しました。']);
            return new WP_Error('save', '記事の保存に失敗しました。履歴を確認してください。');
        }
        clean_post_cache($id);
        $actual = (string) get_post_field('post_content', $id, 'raw');
        $note = $actual === $new ? '' : 'WordPressの保存処理によって本文が調整されました。記事を確認してください。';
        if (!wphm_sr_history_update($history_id, ['status' => 'applied', 'after_content' => $actual, 'after_hash' => hash('sha256', $actual), 'note' => $note])) return new WP_Error('history', '記事は更新されましたが履歴の確定に失敗しました。履歴を確認してください。');
        return ['id' => $id, 'history_id' => $history_id, 'count' => count($selected), 'note' => $note];
    } finally { wphm_sr_unlock($id); }
}

function wphm_sr_restore(int $history_id) {
    $history = wphm_sr_history_get($history_id);
    if (!$history) return new WP_Error('history', '履歴が見つかりません。');
    $id = (int) $history['post_id'];
    if (!wphm_sr_lock($id)) return new WP_Error('busy', 'この記事は別の処理が実行中です。');
    try {
        $history = wphm_sr_history_get($history_id);
        if (!$history || !in_array($history['status'], ['applied', 'pending'], true)) return new WP_Error('history', 'この履歴は復元対象ではありません。');
        if (!hash_equals($history['before_hash'], hash('sha256', $history['before_content']))) return new WP_Error('backup', '履歴の本文と保存時の照合情報が一致しないため、復元を中止しました。');
        $post = wphm_sr_editable_post($id);
        if (is_wp_error($post)) return $post;
        $hash = hash('sha256', $post->post_content);
        if (hash_equals($history['before_hash'], $hash)) {
            if (!wphm_sr_history_update($history_id, ['status' => 'restored', 'restored_at' => current_time('mysql', true), 'note' => '置換前の本文のままであることを確認しました。'])) return new WP_Error('history', '履歴を更新できませんでした。');
            return ['id' => $id, 'message' => '本文は置換前の状態でした。履歴を更新しました。'];
        }
        if (!hash_equals($history['after_hash'], $hash)) return new WP_Error('conflict', '置換後に本文が編集されています。履歴の本文と現在の記事を確認してください。');
        $saved = wp_update_post(wp_slash(['ID' => $id, 'post_content' => $history['before_content']]), true);
        if (is_wp_error($saved) || !$saved) return new WP_Error('save', '復元に失敗しました。');
        clean_post_cache($id);
        if (!hash_equals($history['before_hash'], hash('sha256', (string) get_post_field('post_content', $id, 'raw')))) return new WP_Error('filtered', '保存処理によって本文が調整され、完全には復元できませんでした。履歴の本文と記事を確認してください。');
        if (!wphm_sr_history_update($history_id, ['status' => 'restored', 'restored_at' => current_time('mysql', true)])) return new WP_Error('history', '本文は復元されましたが、履歴の更新に失敗しました。');
        return ['id' => $id, 'message' => '置換前の本文に復元しました。'];
    } finally { wphm_sr_unlock($id); }
}
