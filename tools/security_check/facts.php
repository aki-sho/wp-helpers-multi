<?php
if (!defined('ABSPATH')) exit;

/** Read existing logs without loading, enabling, creating or changing the logging feature. */
function wphm_sc_log_facts(): array {
    global $wpdb;
    $out = ['available' => false, 'enabled' => wphm_is_tool_enabled('access_log'), 'sampled' => 0, 'limited' => false, 'peak' => 0, 'errors' => 0, 'sql_paths' => 0, 'html_paths' => 0, 'traversal_paths' => 0];
    $table = $wpdb->prefix . 'wphm_access_log';
    $previous = $wpdb->suppress_errors(true);
    try {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return $out;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT created_at,status,path FROM {$table} WHERE created_at >= %d AND created_at <= %d ORDER BY created_at DESC,id DESC LIMIT 2001", time() - DAY_IN_SECONDS, time()), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) return $out;
    } finally { $wpdb->suppress_errors($previous); }
    $out['available'] = true; $out['limited'] = count($rows) > 2000; $rows = array_slice($rows, 0, 2000);
    $out['sampled'] = count($rows); $minutes = [];
    foreach ($rows as $row) {
        $minute = (int)floor((int)$row['created_at'] / 60);
        $minutes[$minute] = ($minutes[$minute] ?? 0) + 1;
        if ((int)$row['status'] >= 500 && (int)$row['status'] <= 599) $out['errors']++;
        $path = substr((string)$row['path'], 0, 1024);
        $path = rawurldecode(rawurldecode($path));
        // These are literal observations, never evidence that an attack succeeded.
        if (preg_match('~\bunion\s+select\b|\bsleep\s*\(|\bbenchmark\s*\(~i', $path)) $out['sql_paths']++;
        if (preg_match('~<\s*(?:script|svg|iframe)\b|javascript\s*:~i', $path)) $out['html_paths']++;
        if (preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $path)) $out['traversal_paths']++;
    }
    $out['peak'] = $minutes ? max($minutes) : 0;
    return $out;
}

/** Fixed WordPress.org metadata endpoints only. No site URL, payload, credential or file contents. */
function wphm_sc_api(string $url, ?array $body = null): ?array {
    global $wp_version;
    $allowed = ['https://api.wordpress.org/core/stable-check/1.0/', 'https://api.wordpress.org/core/serve-happy/1.0/', 'https://api.wordpress.org/plugins/update-check/1.1/', 'https://api.wordpress.org/themes/update-check/1.1/'];
    if (!in_array(strtok($url, '?'), $allowed, true)) return null;
    $agent = 'WordPress/' . preg_replace('/[^0-9A-Za-z_.-]/', '', (string)$wp_version) . '; WP Helpers Multi/' . WPHM_VERSION;
    $args = ['timeout' => 3, 'redirection' => 0, 'limit_response_size' => 1048577, 'decompress' => false, 'user-agent' => $agent, 'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity']];
    if ($body !== null) $args['body'] = $body;
    $response = $body === null ? wp_safe_remote_get($url, $args) : wp_safe_remote_post($url, $args);
    if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) return null;
    $text = (string)wp_remote_retrieve_body($response);
    $encoding = strtolower(trim((string)wp_remote_retrieve_header($response, 'content-encoding')));
    if (strlen($text) > 1048576 || !in_array($encoding, ['', 'identity'], true)) return null;
    $data = json_decode($text, true);
    return is_array($data) ? $data : null;
}

function wphm_sc_inventory(): array {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugins = [];
    foreach (get_plugins() as $file => $plugin) $plugins[$file] = ['Name' => (string)$plugin['Name'], 'Version' => (string)$plugin['Version'], 'PluginURI' => (string)$plugin['PluginURI'], 'UpdateURI' => (string)$plugin['UpdateURI']];
    $themes = [];
    foreach (wp_get_themes() as $slug => $theme) $themes[$slug] = ['Name' => $theme->get('Name'), 'Version' => $theme->get('Version'), 'ThemeURI' => $theme->get('ThemeURI'), 'UpdateURI' => $theme->get('UpdateURI')];
    return ['plugins' => $plugins, 'themes' => $themes];
}

/** Bypass update-provider filters: reading an existing report must not start another updater. */
function wphm_sc_saved_site_transient(string $key) {
    if (wp_using_ext_object_cache()) return wp_cache_get($key, 'site-transient');
    if (!in_array($key, ['update_core', 'update_plugins', 'update_themes'], true)) {
        $expires = (int)get_site_option('_site_transient_timeout_' . $key, 0);
        if ($expires && $expires < time()) return false;
    }
    return get_site_option('_site_transient_' . $key, false);
}

function wphm_sc_wporg_item(array $item): bool {
    $uri = trim((string)($item['UpdateURI'] ?? ''));
    return $uri === '' || in_array(strtolower((string)wp_parse_url($uri, PHP_URL_HOST)), ['wordpress.org', 'www.wordpress.org', 'w.org'], true);
}

function wphm_sc_api_items(array $items): array {
    $public = [];
    foreach ($items as $id => $item) $public[$id] = ['Name' => $item['Name'], 'Version' => $item['Version']];
    return $public;
}

/** Missing/private providers are unverified; an empty response is not an all-clear. */
function wphm_sc_update_group(array $installed, $response, string $key, int $checked_at, string $source): array {
    $data = is_object($response) ? get_object_vars($response) : $response;
    $yes = is_array($data) && isset($data[$key]) && (is_array($data[$key]) || is_object($data[$key])) ? (array)$data[$key] : [];
    $no = is_array($data) && isset($data['no_update']) && (is_array($data['no_update']) || is_object($data['no_update'])) ? (array)$data['no_update'] : [];
    $checked = is_array($data) && isset($data['checked']) && is_array($data['checked']) ? $data['checked'] : [];
    $pending = 0; $unknown = 0;
    foreach ($installed as $file => $plugin) {
        if ($source === 'WordPress.orgへの今回の照会' && !wphm_sc_wporg_item($plugin)) { $unknown++; continue; }
        $item = $yes[$file] ?? $no[$file] ?? null; $item = is_object($item) ? get_object_vars($item) : $item;
        $version = is_array($item) ? ($item['new_version'] ?? '') : '';
        if (!is_string($version) || $version === '' || $checked_at <= 0 || $checked_at > time() + 60 || $checked_at < time() - DAY_IN_SECONDS) { $unknown++; continue; }
        if (isset($data['checked']) && (!isset($checked[$file]) || !is_scalar($checked[$file]) || (string)$checked[$file] !== $plugin['Version'])) { $unknown++; continue; }
        if (isset($yes[$file]) && version_compare($version, $plugin['Version'], '>')) $pending++;
    }
    return ['pending' => $pending, 'unknown' => $unknown, 'total' => count($installed), 'checked_at' => $checked_at, 'source' => $source];
}

function wphm_sc_update_facts(array $inventory, bool $refresh): array {
    global $wp_version;
    $version = (string)$wp_version;
    $core = ['known' => false, 'status' => 'unknown', 'pending' => false, 'version' => $version, 'checked_at' => 0, 'source' => 'WordPressの保存済み更新情報'];
    $php = ['known' => false, 'secure' => null, 'version' => PHP_VERSION];
    if ($refresh) {
        $map = wphm_sc_api('https://api.wordpress.org/core/stable-check/1.0/');
        if (is_array($map) && isset($map[$version]) && in_array($map[$version], ['latest', 'outdated', 'insecure'], true)) {
            $core['known'] = true; $core['status'] = $map[$version]; $core['pending'] = $map[$version] !== 'latest'; $core['checked_at'] = time();
        }
        $core['source'] = 'WordPress.orgへの今回の照会';
        $php_data = wphm_sc_api(add_query_arg('php_version', PHP_VERSION, 'https://api.wordpress.org/core/serve-happy/1.0/'));
        $eligible_plugins = array_filter($inventory['plugins'], 'wphm_sc_wporg_item');
        $eligible_themes = array_filter($inventory['themes'], 'wphm_sc_wporg_item');
        $plugin_data = wphm_sc_api('https://api.wordpress.org/plugins/update-check/1.1/', ['plugins' => wp_json_encode(['plugins' => wphm_sc_api_items($eligible_plugins), 'active' => array_values(array_intersect((array)get_option('active_plugins', []), array_keys($eligible_plugins)))]), 'all' => 'true', 'locale' => wp_json_encode([get_locale()])]);
        $theme_data = wphm_sc_api('https://api.wordpress.org/themes/update-check/1.1/', ['themes' => wp_json_encode(['themes' => wphm_sc_api_items($eligible_themes), 'active' => isset($eligible_themes[get_stylesheet()]) ? get_stylesheet() : '']), 'all' => 'true', 'locale' => wp_json_encode([get_locale()])]);
        $plugins = wphm_sc_update_group($inventory['plugins'], $plugin_data, 'plugins', $plugin_data === null ? 0 : time(), 'WordPress.orgへの今回の照会');
        $themes = wphm_sc_update_group($inventory['themes'], $theme_data, 'themes', $theme_data === null ? 0 : time(), 'WordPress.orgへの今回の照会');
    } else {
        $cached = wphm_sc_saved_site_transient('update_core');
        if (is_object($cached) && isset($cached->version_checked) && $cached->version_checked === $version && !empty($cached->last_checked) && $cached->last_checked <= time() + 60 && $cached->last_checked >= time() - DAY_IN_SECONDS) {
            foreach ((array)($cached->updates ?? []) as $offer) {
                if (!is_object($offer) || !isset($offer->current) || !is_string($offer->current)) continue;
                if (($offer->response ?? '') === 'upgrade' && version_compare($offer->current, $version, '>')) { $core['known'] = true; $core['pending'] = true; }
                if (($offer->response ?? '') === 'latest' && $offer->current === $version) $core['known'] = true;
            }
            $core['checked_at'] = (int)$cached->last_checked;
        }
        $plugin_data = wphm_sc_saved_site_transient('update_plugins'); $theme_data = wphm_sc_saved_site_transient('update_themes');
        $plugins = wphm_sc_update_group($inventory['plugins'], $plugin_data, 'response', is_object($plugin_data) ? (int)($plugin_data->last_checked ?? 0) : 0, 'WordPressの保存済み更新情報');
        $themes = wphm_sc_update_group($inventory['themes'], $theme_data, 'response', is_object($theme_data) ? (int)($theme_data->last_checked ?? 0) : 0, 'WordPressの保存済み更新情報');
        $php_data = wphm_sc_saved_site_transient('php_check_' . md5(PHP_VERSION));
    }
    if (is_array($php_data) && isset($php_data['is_secure']) && is_bool($php_data['is_secure'])) { $php['known'] = true; $php['secure'] = $php_data['is_secure']; }
    return ['core' => $core, 'plugins' => $plugins, 'themes' => $themes, 'php' => $php];
}

function wphm_sc_collect(bool $refresh = false): array {
    $role = get_role((string)get_option('default_role', 'subscriber'));
    $file_edit_disabled = (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS);
    $file_uploads = ini_get('file_uploads');
    $facts = [
        'registration' => !is_multisite() && (bool)get_option('users_can_register', false),
        'raw_html' => !is_multisite() && !(defined('DISALLOW_UNFILTERED_HTML') && DISALLOW_UNFILTERED_HTML) && $role && $role->has_cap('unfiltered_html') && ($role->has_cap('edit_posts') || $role->has_cap('edit_pages')),
        'raw_upload' => !is_multisite() && $role && $role->has_cap('unfiltered_upload') && $role->has_cap('upload_files'),
        'unfiltered_uploads' => defined('ALLOW_UNFILTERED_UPLOADS') && ALLOW_UNFILTERED_UPLOADS,
        'file_edit_disabled' => (bool)$file_edit_disabled,
        'file_edit_allowed' => !$file_edit_disabled && (current_user_can('edit_plugins') || current_user_can('edit_themes')),
        'file_uploads' => $file_uploads === false ? null : filter_var($file_uploads, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        'https' => wp_parse_url(home_url('/'), PHP_URL_SCHEME) === 'https' && wp_parse_url(site_url('/'), PHP_URL_SCHEME) === 'https',
        'logs' => wphm_sc_log_facts(),
        'updates' => wphm_sc_update_facts(wphm_sc_inventory(), $refresh),
    ];
    return ['checked_at' => time(), 'refreshed' => $refresh, 'facts' => $facts, 'items' => wphm_sc_assess($facts)];
}
