<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/settings.php';

// フロント側または管理画面で処理が必要な機能だけを読み込みます。
if (wphm_is_tool_enabled('post_data')) {
    require_once __DIR__ . '/../tools/post_data/post_data.php';
}

// IP拒否情報はアクセスログとコメント管理で共有します。
if (wphm_is_tool_enabled('access_log') || wphm_is_tool_enabled('comment_manager')) {
    require_once __DIR__ . '/../tools/ip_blocklist/ip_blocklist.php';
}

if (wphm_is_tool_enabled('access_log')) {
    require_once __DIR__ . '/../tools/access_log/access_log.php';
}

if (wphm_is_tool_enabled('comment_manager')) {
    require_once __DIR__ . '/../tools/comment_manager/comment_manager.php';
}

// 管理画面のときだけ読み込む（本番テストでも影響最小）
if (is_admin()) {
    // 電卓は admin_enqueue_scripts フックを先に登録する必要があります。
    if (wphm_is_tool_enabled('calc')) {
        require_once __DIR__ . '/../tools/calc.php';
    }
    require_once __DIR__ . '/admin.php';
}
