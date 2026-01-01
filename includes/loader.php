<?php
if (!defined('ABSPATH')) exit;

// 管理画面のときだけ読み込む（本番テストでも影響最小）
if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}
