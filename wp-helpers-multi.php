<?php
/**
 * Plugin Name: WP Helpers Multi
 * Description: Utility tools plugin.
 * Version: 0.5.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Your Name
 * Text Domain: wp-helpers-multi
 */
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/includes/loader.php';
require_once __DIR__ . '/tools/post_data/post_data.php';


////////////////////
////////////////////
// ===== Self-hosted updater (update.json) =====
add_filter('site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) return $transient;

    $plugin_file = plugin_basename(__FILE__);
    $current_ver = '0.5.0'; // ←この値はプラグインヘッダーVersionと合わせる

    $url = 'https://plugin.pretty-cute.info/wp-helpers-multi/update.json';
    $res = wp_remote_get($url, ['timeout' => 8]);

    if (is_wp_error($res)) return $transient;
    if (wp_remote_retrieve_response_code($res) !== 200) return $transient;

    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) return $transient;

    if (version_compare($current_ver, $data['version'], '<')) {
        $item = (object)[
            'slug'        => 'wp-helpers-multi',
            'plugin'      => $plugin_file,
            'new_version' => $data['version'],
            'package'     => $data['download_url'],
            'url'         => $data['homepage'] ?? '',
            'tested'      => $data['tested'] ?? '',
            'requires'    => $data['requires'] ?? '',
            'requires_php'=> $data['requires_php'] ?? '',
        ];
        $transient->response[$plugin_file] = $item;
    } else {
        // 最新ならここ（必要なら残す）
        $transient->no_update[$plugin_file] = (object)[
            'slug'        => 'wp-helpers-multi',
            'plugin'      => $plugin_file,
            'new_version' => $current_ver,
            'package'     => '',
            'url'         => $data['homepage'] ?? '',
        ];
    }

    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    if (empty($args->slug) || $args->slug !== 'wp-helpers-multi') return $result;

    $url = 'https://plugin.pretty-cute.info/wp-helpers-multi/update.json';
    $res = wp_remote_get($url, ['timeout' => 8]);

    if (is_wp_error($res)) return $result;
    if (wp_remote_retrieve_response_code($res) !== 200) return $result;

    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data)) return $result;

    $info = (object)[
        'name'          => $data['name'] ?? 'WP Helpers Multi',
        'slug'          => $data['slug'] ?? 'wp-helpers-multi',
        'version'       => $data['version'] ?? '',
        'author'        => '',
        'homepage'      => $data['homepage'] ?? '',
        'requires'      => $data['requires'] ?? '',
        'requires_php'  => $data['requires_php'] ?? '',
        'tested'        => $data['tested'] ?? '',
        'last_updated'  => $data['last_updated'] ?? '',
        'download_link' => $data['download_url'] ?? '',
        'sections'      => $data['sections'] ?? [],
    ];

    // changelogを配列で持ちたい場合にも対応
    if (isset($data['changelog']) && is_array($data['changelog'])) {
        $info->sections['changelog'] = implode("\n", $data['changelog']);
    }

    return $info;
}, 10, 3);
////////////////////
////////////////////
