<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/schema/table.php';
require_once __DIR__ . '/app/repository.php';
require_once __DIR__ . '/app/matcher.php';
require_once __DIR__ . '/app/service.php';
require_once __DIR__ . '/app/ajax.php';
require_once __DIR__ . '/admin/admin.php';
require_once __DIR__ . '/editor.php';

add_action('admin_init', 'wphm_sr_ensure_schema');
