<?php
if (!defined('ABSPATH')) exit;

/**
 * WP Helpers Multi の機能定義。
 *
 * 新しい機能はここへ追加することで、ダッシュボードの切り替えと
 * 左メニューの表示を同じ設定から管理できます。
 */
function wphm_get_tool_definitions(): array {
    $tools = [
        'site_links' => [
            'label'       => 'サイトリンク',
            'description' => 'サイト運営でよく使う外部サービスへ、カテゴリ別にすばやくアクセスします。',
            'icon'        => 'dashicons-admin-links',
            'group'       => 'utility',
            'menu_title'  => 'サイトリンク',
            'page_title'  => 'サイトリンク',
            'slug'        => 'wphm-site-links',
            'callback'    => 'wphm_render_site_links',
            'default'     => true,
        ],
        'qr' => [
            'label'       => 'QRコード',
            'description' => 'URLやテキストからQRコードを作成します。',
            'icon'        => 'dashicons-screenoptions',
            'group'       => 'utility',
            'menu_title'  => 'QRコード',
            'page_title'  => 'QRコード',
            'slug'        => 'wphm-qr',
            'callback'    => 'wphm_render_qr',
            'default'     => true,
        ],
        'calc' => [
            'label'       => '電卓',
            'description' => '管理画面を離れずに、すぐ計算できます。',
            'icon'        => 'dashicons-calculator',
            'group'       => 'utility',
            'menu_title'  => '電卓',
            'page_title'  => '電卓',
            'slug'        => 'wphm-calc',
            'callback'    => 'wphm_render_calc',
            'default'     => true,
        ],
        'bcrypt' => [
            'label'       => 'bcrypt',
            'description' => 'bcryptハッシュの生成と検証を行います。',
            'icon'        => 'dashicons-lock',
            'group'       => 'utility',
            'menu_title'  => 'bcrypt',
            'page_title'  => 'bcrypt',
            'slug'        => 'wphm-bcrypt',
            'callback'    => 'wphm_render_bcrypt',
            'default'     => true,
        ],
        'password' => [
            'label'       => 'パスワード生成',
            'description' => '条件を指定して安全なパスワードを生成します。',
            'icon'        => 'dashicons-admin-network',
            'group'       => 'utility',
            'menu_title'  => 'パスワード生成',
            'page_title'  => 'パスワード生成',
            'slug'        => 'wphm-password',
            'callback'    => 'wphm_render_password',
            'default'     => true,
        ],
        'timer' => [
            'label'       => 'タイマー',
            'description' => 'カウントダウン、ストップウォッチ、アラームを使えます。',
            'icon'        => 'dashicons-clock',
            'group'       => 'utility',
            'menu_title'  => 'タイマー',
            'page_title'  => 'タイマー',
            'slug'        => 'wphm-timer',
            'callback'    => 'wphm_render_timer',
            'default'     => true,
        ],
        'link_inspector' => [
            'label'       => 'リンク点検',
            'description' => 'サイト内リンクの応答状態をまとめて確認します。',
            'icon'        => 'dashicons-admin-links',
            'group'       => 'management',
            'menu_title'  => 'リンク点検',
            'page_title'  => 'リンク点検',
            'slug'        => 'wphm-link-inspector',
            'callback'    => 'wphm_render_link_inspector',
            'default'     => true,
        ],
        'access_log' => [
            'label'       => 'アクセスログ',
            'description' => 'アクセス履歴を確認し、IPアドレス単位で拒否・解除します。',
            'icon'        => 'dashicons-chart-bar',
            'group'       => 'management',
            'menu_title'  => 'アクセスログ',
            'page_title'  => 'アクセスログ',
            'slug'        => 'wphm-access-log',
            'callback'    => 'wphm_render_access_log',
            'default'     => true,
        ],
        'post_data' => [
            'label'       => '投稿データ',
            'description' => '記事情報や閲覧数を一覧・並び替え・CSV出力します。',
            'icon'        => 'dashicons-media-document',
            'group'       => 'content',
            'menu_title'  => '投稿データ',
            'page_title'  => '投稿データ',
            'slug'        => 'wphm-post-data-log',
            'callback'    => 'wphm_post_data_log',
            'default'     => true,
        ],
        'comment_manager' => [
            'label'       => 'コメント管理',
            'description' => 'コメントとスパムを管理し、IP拒否状態を確認します。',
            'icon'        => 'dashicons-admin-comments',
            'group'       => 'content',
            'menu_title'  => 'コメント管理',
            'page_title'  => 'コメント管理',
            'slug'        => 'wphm-comment-manager',
            'callback'    => 'wphm_render_comment_manager',
            'default'     => true,
        ],
    ];

    /**
     * 外部コードから機能定義を拡張できます。
     *
     * @param array $tools 機能定義。
     */
    return (array) apply_filters('wphm_tool_definitions', $tools);
}

function wphm_get_tool_states(): array {
    $saved = get_option('wphm_tool_states', []);
    if (!is_array($saved)) {
        $saved = [];
    }

    $states = [];
    foreach (wphm_get_tool_definitions() as $id => $tool) {
        $default = !empty($tool['default']);
        $states[$id] = array_key_exists($id, $saved) ? (bool) $saved[$id] : $default;
    }

    return $states;
}

function wphm_is_tool_enabled(string $tool_id): bool {
    $states = wphm_get_tool_states();
    return !empty($states[$tool_id]);
}

function wphm_get_enabled_tool_count(): int {
    return count(array_filter(wphm_get_tool_states()));
}
