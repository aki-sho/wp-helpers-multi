<?php
if (!defined('ABSPATH')) exit;

add_action('admin_enqueue_scripts', 'wphm_sr_enqueue_assets');
function wphm_sr_enqueue_assets(): void {
    if (($_GET['page'] ?? '') !== 'wphm-search-replace' || !current_user_can('manage_options')) return;
    wp_enqueue_style('wphm-search-replace', WPHM_PLUGIN_URL . 'assets/css/search-replace.css', ['wphm-admin-modern'], WPHM_VERSION);
    wp_enqueue_script('wphm-search-replace', WPHM_PLUGIN_URL . 'assets/js/search-replace.js', [], WPHM_VERSION, true);
    wp_localize_script('wphm-search-replace', 'wphmSearchReplace', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wphm_search_replace')]);
}

function wphm_render_search_replace_tool_page(): void {
    if (!current_user_can('manage_options')) return;
    require __DIR__ . '/views/page.php';
}
