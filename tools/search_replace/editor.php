<?php
if (!defined('ABSPATH')) exit;

add_action('admin_enqueue_scripts', 'wphm_sr_enqueue_editor');
function wphm_sr_enqueue_editor(string $hook): void {
    if (!in_array($hook, ['post.php', 'post-new.php'], true) || !wphm_is_tool_enabled('search_replace')) return;
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, ['post', 'page'], true) || !post_type_supports($screen->post_type, 'editor')) return;
    global $post, $shortcode_tags;
    if (!$post || !current_user_can('edit_post', $post->ID)) return;
    $block_editor = $screen->is_block_editor();
    wp_enqueue_style('wphm-editor-search', WPHM_PLUGIN_URL . 'assets/css/editor-search.css', [], WPHM_VERSION);
    wp_enqueue_script('wphm-editor-search-engine', WPHM_PLUGIN_URL . 'assets/js/editor-search-engine.js', [], WPHM_VERSION, true);
    $deps = ['wphm-editor-search-engine'];
    if ($block_editor) $deps = array_merge($deps, ['wp-data', 'wp-blocks', 'wp-editor', 'wp-block-editor']);
    wp_enqueue_script('wphm-editor-search-adapters', WPHM_PLUGIN_URL . 'assets/js/editor-search-adapters.js', $deps, WPHM_VERSION, true);
    wp_enqueue_script('wphm-editor-search', WPHM_PLUGIN_URL . 'assets/js/editor-search.js', ['wphm-editor-search-adapters'], WPHM_VERSION, true);
    wp_localize_script('wphm-editor-search', 'wphmEditorSearch', [
        'blockEditor' => $block_editor,
        'shortcodes' => is_array($shortcode_tags) ? array_keys($shortcode_tags) : [],
        'workerUrl' => WPHM_PLUGIN_URL . 'assets/js/editor-search-worker.js?ver=' . rawurlencode(WPHM_VERSION),
    ]);
}
