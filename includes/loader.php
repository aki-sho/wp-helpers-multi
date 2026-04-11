<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/../tools/calc.php';
require_once __DIR__ . '/../tools/post_data/post_data.php';
require_once __DIR__ . '/../tools/access_log/access_log.php';
require_once __DIR__ . '/../tools/comment_manager/comment_manager.php';

// 管理画面のときだけ読み込む（本番テストでも影響最小）
if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}