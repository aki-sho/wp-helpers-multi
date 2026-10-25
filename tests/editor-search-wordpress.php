<?php
if (PHP_SAPI !== 'cli') exit;
$root = dirname(__DIR__);
$fixture = getenv('WPHM_EDITOR_FIXTURE') ?: $root . '/.wphm-test';
$runtime = json_decode(file_get_contents($fixture . '/editor-runtime.json'), true);
define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:8767'; $_SERVER['REQUEST_URI'] = '/wp-admin/post.php'; $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
require $fixture . '/wordpress/wp-load.php';
require ABSPATH . 'wp-admin/includes/admin.php';
$checks = 0;
function editor_same($expected, $actual, $label) { global $checks; $checks++; if ($expected !== $actual) throw new RuntimeException($label . ': ' . var_export($actual, true)); }
function editor_reset_assets() { foreach (['wphm-editor-search', 'wphm-editor-search-adapters', 'wphm-editor-search-engine'] as $handle) { wp_dequeue_script($handle); wp_deregister_script($handle); } wp_dequeue_style('wphm-editor-search'); }
wp_set_current_user($runtime['admin']);
set_current_screen('post'); get_current_screen()->is_block_editor(true);
$post = get_post($runtime['post']);
wphm_sr_enqueue_editor('post.php');
editor_same(true, wp_script_is('wphm-editor-search', 'enqueued'), 'post editor has search');
editor_same(true, strpos(wp_scripts()->registered['wphm-editor-search']->extra['data'], 'editor-search-worker.js') !== false, 'regex worker URL is provided to the editor');
editor_same(true, in_array('wp-block-editor', wp_scripts()->registered['wphm-editor-search-adapters']->deps, true), 'block adapter declares editor store');
editor_reset_assets(); $post = get_post($runtime['page']); get_current_screen()->post_type = 'page';
wphm_sr_enqueue_editor('post.php'); editor_same(true, wp_script_is('wphm-editor-search', 'enqueued'), 'page editor has search');
editor_reset_assets(); wphm_sr_enqueue_editor('edit.php'); editor_same(false, wp_script_is('wphm-editor-search', 'enqueued'), 'post list has no editor search');
editor_reset_assets(); get_current_screen()->post_type = 'attachment'; wphm_sr_enqueue_editor('post.php'); editor_same(false, wp_script_is('wphm-editor-search', 'enqueued'), 'attachments excluded');
get_current_screen()->post_type = 'post'; $post = get_post($runtime['own']); wp_set_current_user($runtime['author']);
wphm_sr_enqueue_editor('post.php'); editor_same(true, wp_script_is('wphm-editor-search', 'enqueued'), 'author can edit own article');
editor_reset_assets(); $post = get_post($runtime['post']); wphm_sr_enqueue_editor('post.php'); editor_same(false, wp_script_is('wphm-editor-search', 'enqueued'), 'author cannot edit another users article');
wp_set_current_user($runtime['admin']);
$states = get_option('wphm_tool_states'); $disabled = $states; $disabled['search_replace'] = 0; update_option('wphm_tool_states', $disabled);
editor_reset_assets(); wphm_sr_enqueue_editor('post.php'); editor_same(false, wp_script_is('wphm-editor-search', 'enqueued'), 'disabled feature loads no editor code'); update_option('wphm_tool_states', $states);
get_current_screen()->is_block_editor(false); wphm_sr_enqueue_editor('post.php');
editor_same(['wphm-editor-search-engine'], wp_scripts()->registered['wphm-editor-search-adapters']->deps, 'classic adapter does not load Gutenberg');
wphm_register_admin_menu();
$menus = array_filter($submenu['wp-helpers-multi'], function ($item) { return $item[2] === 'wphm-search-replace'; });
editor_same(0, count($menus), 'standalone search menu removed');
ob_start(); wphm_render_search_replace_tool_page(); $html = ob_get_clean();
editor_same(true, strpos($html, 'wphm-search-replace-history') !== false, 'old history is preserved');
editor_same(false, strpos($html, 'wphm-sr-apply') !== false, 'old bulk replacement UI is absent');
echo "PASS: {$checks} real WordPress editor permission and loading assertions.\n";
