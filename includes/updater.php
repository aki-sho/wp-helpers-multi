<?php
if (!defined('ABSPATH')) exit;

define('WPHM_UPDATE_URI', 'https://plugin.pretty-cute.info/wp-helpers-multi/');
define('WPHM_UPDATE_JSON_URL', WPHM_UPDATE_URI . 'update.json');

/** Validate metadata before using it in either the update list or plugin details. */
function wphm_validate_update_metadata($data): ?array {
    if (!is_array($data) || !isset($data['version'], $data['download_url']) || !is_string($data['version']) || !is_string($data['download_url'])) return null;
    if (!preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/D', $data['version'])) return null;
    $package = esc_url_raw($data['download_url'], ['https']);
    $parts = wp_parse_url($package);
    if (!$package || !is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    $clean = ['version' => $data['version'], 'download_url' => $package, 'slug' => 'wp-helpers-multi', 'sections' => []];
    foreach (['name', 'requires', 'requires_php', 'tested', 'last_updated'] as $field) {
        $clean[$field] = isset($data[$field]) && is_string($data[$field]) ? sanitize_text_field($data[$field]) : '';
    }
    if ($clean['name'] === '') $clean['name'] = 'WP Helpers Multi';
    $clean['homepage'] = isset($data['homepage']) && is_string($data['homepage']) ? esc_url_raw($data['homepage']) : '';
    $clean['author'] = isset($data['author']) && is_string($data['author']) ? wp_kses_post($data['author']) : '';
    if (!empty($data['sections']) && is_array($data['sections'])) {
        foreach ($data['sections'] as $key => $value) if (is_string($key) && is_string($value)) $clean['sections'][sanitize_key($key)] = wp_kses_post($value);
    }
    if (!empty($data['changelog']) && is_array($data['changelog'])) {
        $clean['sections']['changelog'] = wp_kses_post(implode("\n", array_filter($data['changelog'], 'is_string')));
    }
    return $clean;
}

/** Normal checks use cache; an explicit administrator check always fetches again. */
function wphm_get_update_metadata(bool $force = false): ?array {
    $cached = get_site_transient('wphm_update_metadata_v1');
    if (!$force && is_array($cached) && array_key_exists('data', $cached)) return wphm_validate_update_metadata($cached['data']);
    $response = wp_safe_remote_get(WPHM_UPDATE_JSON_URL, [
        'timeout' => 5, 'redirection' => 3, 'limit_response_size' => 262144,
        'headers' => $force ? ['Accept' => 'application/json', 'Cache-Control' => 'no-cache'] : ['Accept' => 'application/json'],
    ]);
    $data = null;
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = wphm_validate_update_metadata(json_decode(wp_remote_retrieve_body($response), true));
    }
    if ($data !== null) {
        set_site_transient('wphm_update_last_good_v1', $data, 7 * DAY_IN_SECONDS);
        set_site_transient('wphm_update_metadata_v1', ['data' => $data, 'fresh' => true], 6 * HOUR_IN_SECONDS);
        return $data;
    }
    $fallback = wphm_validate_update_metadata(get_site_transient('wphm_update_last_good_v1'));
    set_site_transient('wphm_update_metadata_v1', ['data' => $fallback, 'fresh' => false], 10 * MINUTE_IN_SECONDS);
    return $fallback;
}

// WordPress's built-in "Check again" must also refresh this self-hosted updater.
add_action('load-update-core.php', 'wphm_recheck_updates_on_core_screen');
function wphm_recheck_updates_on_core_screen(): void {
    if (!current_user_can('update_plugins') || !isset($_GET['force-check']) || !is_scalar($_GET['force-check']) || (string) $_GET['force-check'] !== '1') return;
    wphm_get_update_metadata(true);
}

add_filter('plugin_action_links_' . plugin_basename(WPHM_PLUGIN_FILE), 'wphm_update_check_action_link');
add_filter('network_admin_plugin_action_links_' . plugin_basename(WPHM_PLUGIN_FILE), 'wphm_update_check_action_link');
function wphm_update_check_action_link(array $links): array {
    if (!current_user_can('update_plugins')) return $links;
    $url = admin_url('admin-post.php?action=wphm_check_updates');
    if (is_network_admin()) $url = add_query_arg('wphm-network', '1', $url);
    $url = wp_nonce_url($url, 'wphm_check_updates');
    $links['wphm_check_updates'] = '<a href="' . esc_url($url) . '">更新を確認</a>';
    return $links;
}

/** Refresh only our metadata and record a per-user result, without clearing other plugins' caches. */
function wphm_check_updates_now(): array {
    $data = wphm_get_update_metadata(true);
    $cached = get_site_transient('wphm_update_metadata_v1');
    return ['fresh' => !empty($cached['fresh']), 'version' => $data['version'] ?? ''];
}

add_action('admin_post_wphm_check_updates', 'wphm_handle_update_check');
function wphm_handle_update_check(): void {
    if (!current_user_can('update_plugins')) wp_die('更新を確認する権限がありません。', '', ['response' => 403]);
    check_admin_referer('wphm_check_updates');
    set_transient('wphm_update_check_' . get_current_user_id(), wphm_check_updates_now(), 5 * MINUTE_IN_SECONDS);
    $network = is_multisite() && current_user_can('manage_network_plugins') && isset($_GET['wphm-network']) && is_scalar($_GET['wphm-network']) && (string) $_GET['wphm-network'] === '1';
    $url = $network ? network_admin_url('plugins.php') : self_admin_url('plugins.php');
    wp_safe_redirect(add_query_arg('wphm-update-checked', '1', $url));
    exit;
}

/** Plain text status: a failed request with old fallback data is never announced as a fresh check. */
function wphm_update_check_message(array $result): string {
    if (empty($result['fresh'])) return empty($result['version'])
        ? '最新の更新情報を取得できませんでした。時間を置いて「更新を確認」を押してください。'
        : '最新の更新情報を取得できなかったため、以前取得した情報を表示しています。時間を置いて「更新を確認」を押してください。';
    $version = $result['version'] ?? '';
    if (version_compare(WPHM_VERSION, $version, '<')) return '配布サーバーを再確認しました。v' . $version . 'への更新があります。プラグイン一覧から更新してください。';
    return '配布サーバーを再確認しました。配布版はv' . $version . '、インストール済みはv' . WPHM_VERSION . 'です。利用可能な更新はありません。';
}

add_action('admin_notices', 'wphm_update_check_notice');
add_action('network_admin_notices', 'wphm_update_check_notice');
function wphm_update_check_notice(): void {
    global $pagenow;
    if ($pagenow !== 'plugins.php' || !isset($_GET['wphm-update-checked']) || !current_user_can('update_plugins')) return;
    $key = 'wphm_update_check_' . get_current_user_id();
    $result = get_transient($key);
    if (!is_array($result)) return;
    delete_transient($key);
    echo '<div class="notice ' . (!empty($result['fresh']) ? 'notice-success' : 'notice-warning') . '"><p>' . esc_html(wphm_update_check_message($result)) . '</p></div>';
}

function wphm_update_item(array $data): array {
    return [
        'id' => WPHM_UPDATE_URI, 'slug' => 'wp-helpers-multi', 'plugin' => plugin_basename(WPHM_PLUGIN_FILE),
        'version' => $data['version'], 'new_version' => $data['version'], 'package' => $data['download_url'],
        'url' => $data['homepage'], 'tested' => $data['tested'], 'requires' => $data['requires'], 'requires_php' => $data['requires_php'],
    ];
}

add_filter('update_plugins_plugin.pretty-cute.info', 'wphm_update_for_hostname', 10, 4);
function wphm_update_for_hostname($update, array $plugin_data, string $plugin_file, array $locales) {
    if ($plugin_file !== plugin_basename(WPHM_PLUGIN_FILE)) return $update;
    $data = wphm_get_update_metadata();
    return $data ? wphm_update_item($data) : false;
}

// Also reconcile plugin lists cached before an install, rollback or metadata change.
add_filter('site_transient_update_plugins', 'wphm_filter_plugin_updates');
function wphm_filter_plugin_updates($transient) {
    if (!is_object($transient)) return $transient;
    $plugin = plugin_basename(WPHM_PLUGIN_FILE);
    $data = wphm_get_update_metadata();
    if (!isset($transient->response) || !is_array($transient->response)) $transient->response = [];
    if (!isset($transient->no_update) || !is_array($transient->no_update)) $transient->no_update = [];
    unset($transient->response[$plugin], $transient->no_update[$plugin]);
    if (!$data) return $transient;
    $item = wphm_update_item($data);
    if (version_compare(WPHM_VERSION, $data['version'], '<')) {
        $transient->response[$plugin] = (object) $item;
    } else {
        $item['version'] = WPHM_VERSION;
        $item['new_version'] = WPHM_VERSION;
        $item['package'] = '';
        $transient->no_update[$plugin] = (object) $item;
    }
    return $transient;
}

add_filter('plugins_api', 'wphm_filter_plugin_information', 10, 3);
function wphm_filter_plugin_information($result, string $action, $args) {
    if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== 'wp-helpers-multi') return $result;
    $data = wphm_get_update_metadata();
    if (!$data) return $result;
    $data['download_link'] = $data['download_url'];
    unset($data['download_url']);
    return (object) $data;
}

add_action('upgrader_process_complete', 'wphm_clear_update_cache_after_upgrade', 10, 2);
function wphm_clear_update_cache_after_upgrade($upgrader, array $options): void {
    if (($options['type'] ?? '') !== 'plugin' || ($options['action'] ?? '') !== 'update') return;
    $plugins = isset($options['plugins']) && is_array($options['plugins']) ? $options['plugins'] : [];
    if (isset($options['plugin']) && is_string($options['plugin'])) $plugins[] = $options['plugin'];
    if (in_array(plugin_basename(WPHM_PLUGIN_FILE), $plugins, true)) {
        delete_site_transient('wphm_update_metadata_v1');
        delete_site_transient('wphm_update_last_good_v1');
    }
}
