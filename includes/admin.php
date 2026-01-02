<?php
if (!defined('ABSPATH')) exit;

//WordPress管理画面の左メニュー（管理メニュー）を組み立てるタイミングで実行されるフック。
//ここに登録した処理で、「管理画面メニューに何を表示するか」を追加できる。
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
    add_submenu_page('wp-helpers-multi', 'リンク点検', 'リンク点検', 'manage_options', 'wphm-link-inspector', 'wphm_render_link_inspector');
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
function wphm_render_bcrypt() {
  require_once __DIR__ . '/../tools/bcrypt.php';
  wphm_render_bcrypt_tool_page();
}
function wphm_render_password() {
  require_once __DIR__ . '/../tools/password.php';
  wphm_render_password_tool_page();
}
function wphm_render_timer() {
  require_once __DIR__ . '/../tools/timer.php';
  wphm_render_timer_tool_page();
}
function wphm_render_link_inspector() {
  require_once __DIR__ . '/../tools/link_inspector.php';
  wphm_render_link_inspector_tool_page();
}

// 管理画面の「admin-post.php」経由で投げられた POST を受け取るためのフック。
// action=wphm_set_fontsize のリクエストが来たら、wphm_handle_set_fontsize() を実行する。
add_action('admin_post_wphm_set_fontsize', 'wphm_handle_set_fontsize');
function wphm_handle_set_fontsize() {
    if (!current_user_can('manage_options')) wp_die('権限がありません');

    if (!isset($_POST['wphm_fontsize_nonce']) || !wp_verify_nonce($_POST['wphm_fontsize_nonce'], 'wphm_fontsize')) {
        wp_die('Nonceが不正です。');
    }

    $val = isset($_POST['wphm_fontsize']) ? (int)$_POST['wphm_fontsize'] : 3; // 標準=3
    $allowed = [1,2,3,4,5];
    if (!in_array($val, $allowed, true)) $val = 3;

    update_user_meta(get_current_user_id(), 'wphm_fontsize', $val);

    $redirect = wp_get_referer();
    if (!$redirect && !empty($_POST['redirect_to'])) $redirect = esc_url_raw($_POST['redirect_to']);
    if (!$redirect) $redirect = admin_url('admin.php?page=wp-helpers-multi');

    wp_safe_redirect($redirect);
    exit;
}

function wphm_get_fontsize_value(): int {
    $v = (int) get_user_meta(get_current_user_id(), 'wphm_fontsize', true);
    return ($v >= 1 && $v <= 5) ? $v : 3;
}

// WP Helpers Multi のページだけ body class を付ける
add_filter('admin_body_class', function($classes){
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    if ($page && (strpos($page, 'wphm') === 0 || $page === 'wp-helpers-multi')) {
        $classes .= ' wphm-fontsize-' . wphm_get_fontsize_value();
    }
    return $classes;
});




// 文字サイズCSS（ページ共通）
add_action('admin_head', function(){
    $css = <<<CSS
/* WP Helpers Multi 全ページで効く */
.wphm-app { font-size: 14px; } /* 標準のベース */

body.wphm-fontsize-1 .wphm-app { font-size: 12px; } /* 小 */
body.wphm-fontsize-2 .wphm-app { font-size: 13px; } /* やや小 */
body.wphm-fontsize-3 .wphm-app { font-size: 14px; } /* 標準 */
body.wphm-fontsize-4 .wphm-app { font-size: 16px; } /* やや大 */
body.wphm-fontsize-5 .wphm-app { font-size: 18px; } /* 大 */

.wphm-header {
  display:flex; align-items:center; justify-content:space-between;
  gap:12px; margin: 8px 0 12px;
}
.wphm-fontsize-control select { min-width: 140px; }
CSS;
    echo '<style>' . $css . '</style>';
});




// 右端のUI（どのページでも呼べる共通ヘッダー）
function wphm_render_header($title) {
    $v = wphm_get_fontsize_value();
    $options = [
        1 => '小',
        2 => 'やや小',
        3 => '標準',
        4 => 'やや大',
        5 => '大',
    ];

    echo '<div class="wphm-header">';
    echo '<h1 style="margin:0;">' . esc_html($title) . '</h1>';

    echo '<form class="wphm-fontsize-control" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="wphm_set_fontsize">';
    echo '<input type="hidden" name="redirect_to" value="' . esc_attr($_SERVER['REQUEST_URI'] ?? '') . '">';
    wp_nonce_field('wphm_fontsize', 'wphm_fontsize_nonce');

    echo '<label style="display:flex; align-items:center; gap:8px;">';
    echo '<span>文字サイズ</span>';
    echo '<select name="wphm_fontsize" onchange="this.form.submit()">';
    foreach ($options as $k => $label) {
        echo '<option value="' . (int)$k . '" ' . selected($v, $k, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select>';
    echo '</label>';

    echo '</form>';
    echo '</div>';
}