<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/options.php';
require_once __DIR__ . '/integrations.php';
require_once __DIR__ . '/local.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/rules.php';
require_once __DIR__ . '/evidence.php';
require_once __DIR__ . '/admin.php';

add_action('wp_ajax_wphm_security_check', 'wphm_sc_ajax');
add_action('admin_enqueue_scripts', 'wphm_sc_assets');
