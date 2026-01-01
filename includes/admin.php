<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {

    // 親メニュー（トップ）
    add_menu_page(
        'WP Helpers Multi',
        'WP Helpers Multi',
        'manage_options',
        'wp-helpers-multi',
        'wphm_render_dashboard',
        'dashicons-admin-tools',
        60
    );

    // 子メニュー：ダッシュボード（親と同じslugのページを“ダッシュボード”として表示）
    add_submenu_page(
        'wp-helpers-multi',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'wp-helpers-multi',
        'wphm_render_dashboard'
    );

    // 子メニュー：各ツール（中身は後で実装）
    add_submenu_page('wp-helpers-multi', 'QRコード', 'QRコード', 'manage_options', 'wphm-qr', 'wphm_render_qr');
    add_submenu_page('wp-helpers-multi', '電卓', '電卓', 'manage_options', 'wphm-calc', 'wphm_render_calc');
    add_submenu_page('wp-helpers-multi', 'bcrypt', 'bcrypt', 'manage_options', 'wphm-bcrypt', 'wphm_render_bcrypt');
    add_submenu_page('wp-helpers-multi', 'パスワード生成', 'パスワード生成', 'manage_options', 'wphm-password', 'wphm_render_password');
    add_submenu_page('wp-helpers-multi', 'タイマー', 'タイマー', 'manage_options', 'wphm-timer', 'wphm_render_timer');
});

function wphm_wrap($title, $desc = '') {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap">';
    echo '<h1>' . esc_html($title) . '</h1>';
    if ($desc !== '') {
        echo '<p>' . esc_html($desc) . '</p>';
    }
    echo '</div>';
}

function wphm_render_dashboard() {
    wphm_wrap('WP Helpers Multi', 'ここにツールを追加していきます。左の子メニューから各ツールへ。');
}

function wphm_render_qr()       { wphm_wrap('QRコード', '（ここにQRコード作成ツールを実装します）'); }
function wphm_render_calc()     { wphm_wrap('電卓', '（ここに電卓ツールを実装します）'); }
function wphm_render_bcrypt()   { wphm_wrap('bcrypt', '（ここにbcryptツールを実装します）'); }
function wphm_render_password() { wphm_wrap('パスワード生成', '（ここにパスワード生成ツールを実装します）'); }
function wphm_render_timer()    { wphm_wrap('タイマー', '（ここにタイマーツールを実装します）'); }