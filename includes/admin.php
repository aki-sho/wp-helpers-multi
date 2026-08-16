<?php
if (!defined('ABSPATH')) exit;

/**
 * WP Helpers Multi - Admin
 * - enabled tool menus
 * - dashboard settings
 * - shared admin UI
 */

/* =========================
 * Menu
 * ========================= */
add_action('admin_menu', 'wphm_register_admin_menu');
function wphm_register_admin_menu(): void {
    add_menu_page(
        'WP Helpers Multi',
        'WP Helpers Multi',
        'manage_options',
        'wp-helpers-multi',
        'wphm_render_dashboard',
        'dashicons-admin-tools',
        60
    );

    add_submenu_page(
        'wp-helpers-multi',
        'ダッシュボード',
        'ダッシュボード',
        'manage_options',
        'wp-helpers-multi',
        'wphm_render_dashboard'
    );

    foreach (wphm_get_tool_definitions() as $id => $tool) {
        if (!wphm_is_tool_enabled((string) $id)) {
            continue;
        }

        add_submenu_page(
            'wp-helpers-multi',
            (string) $tool['page_title'],
            (string) $tool['menu_title'],
            'manage_options',
            (string) $tool['slug'],
            (string) $tool['callback']
        );
    }
}

/* =========================
 * Page helpers
 * ========================= */
function wphm_wrap(string $title, string $desc = ''): void {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap wphm-app">';
    wphm_render_header($title);
    if ($desc !== '') {
        echo '<div class="wphm-panel"><p>' . esc_html($desc) . '</p></div>';
    }
    echo '</div>';
}

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

function wphm_render_brand_mark(): void {
    echo '<span class="wphm-brand-mark" aria-hidden="true"><span></span></span>';
}

/* =========================
 * Dashboard
 * ========================= */
function wphm_render_dashboard(): void {
    if (!current_user_can('manage_options')) return;

    $tools = wphm_get_tool_definitions();
    $states = wphm_get_tool_states();
    $enabled_count = count(array_filter($states));
    $total_count = count($tools);
    $groups = [
        'content' => [
            'label' => 'コンテンツ',
            'description' => '記事やコメントに関わる機能',
        ],
        'management' => [
            'label' => '管理',
            'description' => 'サイトの確認・保守に関わる機能',
        ],
        'utility' => [
            'label' => 'ユーティリティ',
            'description' => '日々の作業を支える便利な機能',
        ],
    ];
    ?>
    <div class="wrap wphm-app wphm-dashboard">
        <section class="wphm-dashboard-hero">
            <div class="wphm-dashboard-brand">
                <?php wphm_render_brand_mark(); ?>
                <div>
                    <div class="wphm-eyebrow">SITE OPERATOR TOOLKIT</div>
                    <h1>WP Helpers Multi</h1>
                    <p>便利なツール群をひとつに。あなたのサイト運営を、静かに、確かに支えます。</p>
                </div>
            </div>
            <div class="wphm-version-card">
                <span>Current version</span>
                <strong>v<?php echo esc_html(WPHM_VERSION); ?></strong>
            </div>
        </section>

        <nav class="wphm-dashboard-nav" aria-label="ダッシュボード内メニュー">
            <a class="is-active" href="<?php echo esc_url(admin_url('admin.php?page=wp-helpers-multi')); ?>">ダッシュボード</a>
            <?php if (!empty($states['site_links'])) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wphm-site-links')); ?>">サイトリンク</a>
            <?php endif; ?>
            <?php foreach ($groups as $group_id => $group) : ?>
                <a href="#wphm-group-<?php echo esc_attr($group_id); ?>"><?php echo esc_html($group['label']); ?></a>
            <?php endforeach; ?>
        </nav>

        <?php if (isset($_GET['settings-updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p>機能設定を保存しました。左メニューへ反映されています。</p></div>
        <?php endif; ?>

        <div class="wphm-dashboard-layout">
            <main class="wphm-dashboard-main">
                <section class="wphm-section-heading">
                    <div>
                        <span class="wphm-section-kicker">TOOL SETTINGS</span>
                        <h2>ツール有効化</h2>
                        <p>使用する機能だけをオンにして、管理画面をすっきり整理できます。</p>
                    </div>
                    <div class="wphm-enabled-summary" aria-live="polite">
                        <strong data-wphm-enabled-count><?php echo (int) $enabled_count; ?></strong>
                        <span>/ <?php echo (int) $total_count; ?> 有効</span>
                    </div>
                </section>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-wphm-settings-form>
                    <input type="hidden" name="action" value="wphm_save_tool_settings">
                    <?php wp_nonce_field('wphm_save_tool_settings', 'wphm_tool_settings_nonce'); ?>

                    <?php foreach ($groups as $group_id => $group) : ?>
                        <section class="wphm-tool-group" id="wphm-group-<?php echo esc_attr($group_id); ?>">
                            <div class="wphm-tool-group-heading">
                                <h3><?php echo esc_html($group['label']); ?></h3>
                                <span><?php echo esc_html($group['description']); ?></span>
                            </div>
                            <div class="wphm-tool-grid">
                                <?php foreach ($tools as $id => $tool) : ?>
                                    <?php if ($tool['group'] !== $group_id) continue; ?>
                                    <?php $enabled = !empty($states[$id]); ?>
                                    <article class="wphm-tool-card <?php echo $enabled ? 'is-enabled' : 'is-disabled'; ?>" data-wphm-tool-card>
                                        <div class="wphm-tool-card-top">
                                            <span class="wphm-tool-icon dashicons <?php echo esc_attr($tool['icon']); ?>" aria-hidden="true"></span>
                                            <label class="wphm-switch" for="wphm-tool-<?php echo esc_attr($id); ?>">
                                                <input
                                                    id="wphm-tool-<?php echo esc_attr($id); ?>"
                                                    type="checkbox"
                                                    name="wphm_tools[<?php echo esc_attr($id); ?>]"
                                                    value="1"
                                                    data-wphm-toggle
                                                    <?php checked($enabled); ?>
                                                >
                                                <span aria-hidden="true"></span>
                                                <span class="screen-reader-text"><?php echo esc_html($tool['label']); ?>を有効化</span>
                                            </label>
                                        </div>
                                        <h4><?php echo esc_html($tool['label']); ?></h4>
                                        <p><?php echo esc_html($tool['description']); ?></p>
                                        <div class="wphm-tool-state">
                                            <span class="wphm-state-dot" aria-hidden="true"></span>
                                            <span data-wphm-state-label><?php echo $enabled ? '有効' : '無効'; ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>

                    <div class="wphm-save-bar" data-wphm-save-bar>
                        <span data-wphm-save-status>変更後は保存してください。</span>
                        <?php submit_button('設定を保存', 'primary wphm-primary-button', 'submit', false); ?>
                    </div>
                </form>
            </main>

            <aside class="wphm-dashboard-aside">
                <section class="wphm-tip-card">
                    <span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
                    <h2>使い方のヒント</h2>
                    <p>使わない機能を無効にすると、左メニューから非表示になり、必要のない処理も読み込まれません。</p>
                </section>
                <section class="wphm-tip-card wphm-tip-card-muted">
                    <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                    <h2>共有IP拒否</h2>
                    <p>アクセスログとコメント管理はIP拒否情報を共有します。どちらか一方が有効なら共通の拒否基盤を維持します。</p>
                </section>
                <section class="wphm-roadmap-card">
                    <span>EXTENSIBLE</span>
                    <h2>これからの拡張にも対応</h2>
                    <p>機能定義を共通化し、追加ツールや将来の設定項目を同じダッシュボードへ拡張できる構成です。</p>
                </section>
            </aside>
        </div>
    </div>
    <?php
}

add_action('admin_post_wphm_save_tool_settings', 'wphm_handle_save_tool_settings');
function wphm_handle_save_tool_settings(): void {
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません。');
    }

    check_admin_referer('wphm_save_tool_settings', 'wphm_tool_settings_nonce');
    $posted = isset($_POST['wphm_tools']) && is_array($_POST['wphm_tools'])
        ? wp_unslash($_POST['wphm_tools'])
        : [];
    $states = [];

    foreach (wphm_get_tool_definitions() as $id => $tool) {
        $states[$id] = isset($posted[$id]) && (string) $posted[$id] === '1' ? 1 : 0;
    }

    update_option('wphm_tool_states', $states, false);
    wp_safe_redirect(add_query_arg('settings-updated', '1', admin_url('admin.php?page=wp-helpers-multi')));
    exit;
}

/* =========================
 * Tool pages
 * ========================= */
function wphm_render_site_links(): void {
    wphm_require_and_render_tool(
        __DIR__ . '/../tools/site_links/site_links.php',
        'サイトリンク',
        'wphm_render_site_links_tool_page'
    );
}

function wphm_render_qr(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/qr.php', 'QRコード', 'wphm_render_qr_tool_page');
}

function wphm_render_calc(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/calc.php', '電卓', 'wphm_render_calc_tool_page');
}

function wphm_render_bcrypt(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/bcrypt.php', 'bcrypt', 'wphm_render_bcrypt_tool_page');
}

function wphm_render_password(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/password.php', 'パスワード生成', 'wphm_render_password_tool_page');
}

function wphm_render_timer(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/timer.php', 'タイマー', 'wphm_render_timer_tool_page');
}

function wphm_render_link_inspector(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/link_inspector.php', 'リンク点検', 'wphm_render_link_inspector_tool_page');
}

function wphm_render_access_log(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/access_log/access_log.php', 'アクセスログ', 'wphm_render_access_log_tool_page');
}

function wphm_post_data_log(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/post_data/post_data.php', '投稿データ', 'wphm_post_data_log_tool_page');
}

function wphm_render_comment_manager(): void {
    wphm_require_and_render_tool(__DIR__ . '/../tools/comment_manager/comment_manager.php', 'コメント管理', 'wphm_render_comment_manager_tool_page');
}

/* =========================
 * Assets and common UI
 * ========================= */
function wphm_is_plugin_admin_page(): bool {
    $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
    return $page === 'wp-helpers-multi' || strpos($page, 'wphm-') === 0;
}

add_action('admin_enqueue_scripts', 'wphm_enqueue_admin_assets');
function wphm_enqueue_admin_assets(): void {
    if (!wphm_is_plugin_admin_page()) return;

    $css_path = WPHM_PLUGIN_DIR . 'assets/css/admin-modern.css';
    $js_path = WPHM_PLUGIN_DIR . 'assets/js/admin-dashboard.js';

    wp_enqueue_style(
        'wphm-admin-modern',
        WPHM_PLUGIN_URL . 'assets/css/admin-modern.css',
        [],
        file_exists($css_path) ? (string) filemtime($css_path) : WPHM_VERSION
    );
    wp_enqueue_script(
        'wphm-admin-dashboard',
        WPHM_PLUGIN_URL . 'assets/js/admin-dashboard.js',
        [],
        file_exists($js_path) ? (string) filemtime($js_path) : WPHM_VERSION,
        true
    );
}

add_filter('admin_body_class', 'wphm_admin_body_class');
function wphm_admin_body_class(string $classes): string {
    if (wphm_is_plugin_admin_page()) {
        $classes .= ' wphm-suite-admin wphm-fontsize-' . wphm_get_fontsize_value();
    }
    return $classes;
}

function wphm_render_header(string $title): void {
    $v = wphm_get_fontsize_value();
    $options = [
        1 => '小',
        2 => 'やや小',
        3 => '標準',
        4 => 'やや大',
        5 => '大',
    ];
    ?>
    <header class="wphm-page-header">
        <div class="wphm-page-title">
            <?php wphm_render_brand_mark(); ?>
            <div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wp-helpers-multi')); ?>">WP Helpers Multi</a>
                <h1><?php echo esc_html($title); ?></h1>
            </div>
        </div>
        <div class="wphm-page-actions">
            <span class="wphm-version-pill">v<?php echo esc_html(WPHM_VERSION); ?></span>
            <form class="wphm-fontsize-control" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wphm_set_fontsize">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? ''); ?>">
                <?php wp_nonce_field('wphm_fontsize', 'wphm_fontsize_nonce'); ?>
                <label>
                    <span>文字サイズ</span>
                    <select name="wphm_fontsize" onchange="this.form.submit()">
                        <?php foreach ($options as $key => $label) : ?>
                            <option value="<?php echo (int) $key; ?>" <?php selected($v, $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>
    </header>
    <?php
}

/* =========================
 * Font size
 * ========================= */
add_action('admin_post_wphm_set_fontsize', 'wphm_handle_set_fontsize');
function wphm_handle_set_fontsize(): void {
    if (!current_user_can('manage_options')) wp_die('権限がありません。');

    if (!isset($_POST['wphm_fontsize_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wphm_fontsize_nonce'])), 'wphm_fontsize')) {
        wp_die('Nonceが不正です。');
    }

    $val = isset($_POST['wphm_fontsize']) ? (int) $_POST['wphm_fontsize'] : 3;
    if (!in_array($val, [1, 2, 3, 4, 5], true)) {
        $val = 3;
    }

    update_user_meta(get_current_user_id(), 'wphm_fontsize', $val);
    $redirect = wp_get_referer();
    if (!$redirect && !empty($_POST['redirect_to'])) {
        $redirect = esc_url_raw((string) wp_unslash($_POST['redirect_to']));
    }
    if (!$redirect) {
        $redirect = admin_url('admin.php?page=wp-helpers-multi');
    }

    wp_safe_redirect($redirect);
    exit;
}

function wphm_get_fontsize_value(): int {
    $value = (int) get_user_meta(get_current_user_id(), 'wphm_fontsize', true);
    return $value >= 1 && $value <= 5 ? $value : 3;
}
