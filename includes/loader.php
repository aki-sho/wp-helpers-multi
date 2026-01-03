<?php
if (!defined('ABSPATH')) exit;
require_once __DIR__ . '/../tools/calc.php';

// 管理画面のときだけ読み込む（本番テストでも影響最小）
if (is_admin()) {
    require_once __DIR__ . '/admin.php';
}
// access log (front/admin 両方で使うので loader で常時読み込み)
require_once __DIR__ . '/../tools/access_log/access_log.php';