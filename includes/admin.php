<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_menu_page(
        'WP Helpers Multi',
        'WP Helpers Multi',
        'manage_options',
        'wp-helpers-multi',
        function () {
            if (!current_user_can('manage_options')) return;
            echo '<div class="wrap">';
            echo '<h1>WP Helpers Multi</h1>';
            echo '<p>ここにツールを追加していきます。</p>';
            echo '</div>';
        },
        'dashicons-admin-tools',
        60
    );
});