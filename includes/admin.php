<?php
if (!defined('ABSPATH')) exit;

/**
 * WP Helpers Multi - Admin
 * - menu registrations
 * - common UI (header / font size)
 */

/* =========================
 * Menu
 * ========================= */
add_action('admin_menu', 'wphm_register_admin_menu');
function wphm_register_admin_menu() {

    // 親メニュー
    add_menu_page(
        'WP Helpers Multi',
        'WP Helpers Multi',
        'manage_options',
        'wp-helpers-multi',
        'wphm_render_dashboard',
        'dashicons-admin-tools',
        60
    );

    // 子メニュー：Dashboard（親と同じslug）
    add_submenu_page(
        'wp-helpers-multi',
        'Dashboard',
        'Dashboard',
        'manage_options',
        'wp-helpers-multi',
        'wphm_render_dashboard'
    );

    // 子メニュー：各ツール
    add_submenu_page('wp-helpers-multi', 'QRコード',       'QRコード',       'manage_options', 'wphm-qr',            'wphm_render_qr');
    add_submenu_page('wp-helpers-multi', '電卓',           '電卓',           'manage_options', 'wphm-calc',          'wphm_render_calc');
    add_submenu_page('wp-helpers-multi', 'bcrypt',         'bcrypt',         'manage_options', 'wphm-bcrypt',        'wphm_render_bcrypt');
    add_submenu_page('wp-helpers-multi', 'パスワード生成', 'パスワード生成', 'manage_options', 'wphm-password',      'wphm_render_password');
    add_submenu_page('wp-helpers-multi', 'タイマー',       'タイマー',       'manage_options', 'wphm-timer',         'wphm_render_timer');
    add_submenu_page('wp-helpers-multi', 'リンク点検',     'リンク点検',     'manage_options', 'wphm-link-inspector','wphm_render_link_inspector');
    add_submenu_page('wp-helpers-multi', 'アクセスログ',   'アクセスログ',   'manage_options', 'wphm-access-log',    'wphm_render_access_log');
}

/* =========================
 * Page helpers
 * ========================= */
function wphm_wrap($title, $desc = ''): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap wphm-app">';
    echo '<h1 style="margin:0 0 8px;">' . esc_html($title) . '</h1>';
    if ($desc !== '') echo '<p>' . esc_html($desc) . '</p>';
    echo '</div>';
}

/* =========================
 * Tool loader helper
 * ========================= */
function wphm_require_and_render_tool(string $path, string $title, string $render_fn): void {
    if (!current_user_can('manage_options')) return;

    if (!file_exists($path)) {
        wphm_wrap($title, 'エラー: ' . basename($path) . ' が見つかりません。');
        return;
    }

    require_once $path;

    if (!function_exists($render_fn)) {
        wphm_wrap($title, 'エラー: 読み込みましたが ' . $render_fn . '() がありません。');
        return;
    }

    $render_fn();
}
/* =========================
 * Pages
 * ========================= */
function wphm_render_dashboard(): void {
    wphm_wrap('WP Helpers Multi', 'ここにツールを追加していきます。左の子メニューから各ツールへ。');
}

function wphm_render_qr(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/qr.php',
        'QRコード',
        'wphm_render_qr_tool_page'
    );
}
function wphm_render_calc(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/calc.php',
        '電卓',
        'wphm_render_calc_tool_page'
    );
}

function wphm_render_bcrypt(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/bcrypt.php',
        'bcrypt',
        'wphm_render_bcrypt_tool_page'
    );
}

function wphm_render_password(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/password.php',
        'パスワード生成',
        'wphm_render_password_tool_page'
    );
}

function wphm_render_timer(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/timer.php',
        'タイマー',
        'wphm_render_timer_tool_page'
    );
}

function wphm_render_link_inspector(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/link_inspector.php',
        'リンク点検',
        'wphm_render_link_inspector_tool_page'
    );
}

/* ★追加：アクセスログ（フォルダ構成版） */
function wphm_render_access_log(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/access_log/access_log.php',
        'アクセスログ',
        'wphm_render_access_log_tool_page'
    );
}

/* =========================
 * Font size (common UI)
 * ========================= */
add_action('admin_post_wphm_set_fontsize', 'wphm_handle_set_fontsize');
function wphm_handle_set_fontsize(): void {
    if (!current_user_can('manage_options')) wp_die('権限がありません');

    if (!isset($_POST['wphm_fontsize_nonce']) || !wp_verify_nonce($_POST['wphm_fontsize_nonce'], 'wphm_fontsize')) {
        wp_die('Nonceが不正です。');
    }

    $val = isset($_POST['wphm_fontsize']) ? (int)$_POST['wphm_fontsize'] : 3;
    $allowed = [1,2,3,4,5];
    if (!in_array($val, $allowed, true)) $val = 3;

    update_user_meta(get_current_user_id(), 'wphm_fontsize', $val);

    $redirect = wp_get_referer();
    if (!$redirect && !empty($_POST['redirect_to'])) $redirect = esc_url_raw((string)$_POST['redirect_to']);
    if (!$redirect) $redirect = admin_url('admin.php?page=wp-helpers-multi');

    wp_safe_redirect($redirect);
    exit;
}

function wphm_get_fontsize_value(): int {
    $v = (int) get_user_meta(get_current_user_id(), 'wphm_fontsize', true);
    return ($v >= 1 && $v <= 5) ? $v : 3;
}

/**
 * WP Helpers Multi のページだけ body class を付ける
 * - wp-helpers-multi（ダッシュボード）
 * - wphm-...（各ツール）
 */
add_filter('admin_body_class', 'wphm_admin_body_class');
function wphm_admin_body_class(string $classes): string {
    $page = isset($_GET['page']) ? sanitize_text_field((string)$_GET['page']) : '';
    if ($page && (strpos($page, 'wphm') === 0 || $page === 'wp-helpers-multi')) {
        $classes .= ' wphm-fontsize-' . wphm_get_fontsize_value();
    }
    return $classes;
}

/* =========================
 * Common CSS
 * ========================= */
add_action('admin_head', 'wphm_admin_common_css');
function wphm_admin_common_css(): void {
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
}

/* =========================
 * Common header
 * ========================= */
function wphm_render_header(string $title): void {
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
