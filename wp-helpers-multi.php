<?php
/**
 * Plugin Name: WP Helpers Multi
 * Description: Utility tools plugin.
 * Version: 0.9.5
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://plugin.pretty-cute.info/wp-helpers-multi/
 * Author: Your Name
 * Text Domain: wp-helpers-multi
 */
if (!defined('ABSPATH')) exit;

define('WPHM_VERSION', '0.9.5');
define('WPHM_PLUGIN_FILE', __FILE__);
define('WPHM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPHM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once __DIR__ . '/includes/loader.php';
require_once __DIR__ . '/includes/updater.php';
