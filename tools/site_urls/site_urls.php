<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../link_inspector_http.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/filters.php';
require_once __DIR__ . '/service.php';
require_once __DIR__ . '/admin.php';

const WPHM_SU_MAX_URLS = 10000;
const WPHM_SU_MAX_FETCHES = 2000;
const WPHM_SU_MAX_BODY = 1048576;

add_action('wp_ajax_wphm_site_urls', 'wphm_su_ajax');
add_action('admin_enqueue_scripts', 'wphm_su_assets');
