<?php
if (!defined('ABSPATH')) exit;

/**
 * Calculator tool
 * slug: wphm-calc
 */
add_action('admin_enqueue_scripts', function () {
    if (empty($_GET['page']) || $_GET['page'] !== 'wphm-calc') return;

    $base_url  = plugin_dir_url(__DIR__ . '/../wp-helpers-multi.php');
    $js_path   = __DIR__ . '/../assets/js/calc.js';
    $css_path  = __DIR__ . '/../assets/css/calc.css';

    wp_enqueue_style(
        'wphm-calc-css',
        $base_url . 'assets/css/calc.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : '1.0.0'
    );

    wp_enqueue_script(
        'wphm-calc-js',
        $base_url . 'assets/js/calc.js',
        [],
        file_exists($js_path) ? filemtime($js_path) : '1.0.0',
        true
    );

    // 読み込み確認（Consoleに出る）
    wp_add_inline_script('wphm-calc-js', 'console.log("WPHM calc.js loaded");', 'before');
});

/**
 * admin.php からこれを呼ぶだけにする
 */
function wphm_render_calc_tool_page() {
    if (!current_user_can('manage_options')) return;

    echo '<div class="wrap wphm-app">';
    if (function_exists('wphm_render_header')) {
        wphm_render_header('電卓');
    } else {
        echo '<h1>電卓</h1>';
    }

    // 電卓UI
    echo '<div class="wphm-calc" data-wphm-calc>';
    echo '  <div class="wphm-calc-display" id="wphmCalcDisplay" data-wphm-calc-display aria-label="display">0</div>';
    // 追加ボタン用の空き1段（ここに後で拡張できる）
    echo '  <div class="wphm-calc-extra" aria-hidden="true"></div>';

    // ボタン
    echo '  <div class="wphm-calc-keys" id="wphmCalcKeys">';

    // row1
    echo '    <button type="button" data-act="ac" data-key="AC">AC</button>';
    echo '    <button type="button" data-op="÷" data-key="÷">÷</button>';
    echo '    <button type="button" data-op="×" data-key="×">×</button>';
    echo '    <button type="button" data-op="-" data-key="-">-</button>';

    // row2
    echo '    <button type="button" data-num="7" data-key="7">7</button>';
    echo '    <button type="button" data-num="8" data-key="8">8</button>';
    echo '    <button type="button" data-num="9" data-key="9">9</button>';
    echo '    <button type="button" data-op="+" data-key="+">+</button>';

    // row3
    echo '    <button type="button" data-num="4" data-key="4">4</button>';
    echo '    <button type="button" data-num="5" data-key="5">5</button>';
    echo '    <button type="button" data-num="6" data-key="6">6</button>';
    echo '    <button type="button" data-act="eq" data-key="=" class="eq">=</button>';

    // row4
    echo '    <button type="button" data-num="1" data-key="1">1</button>';
    echo '    <button type="button" data-num="2" data-key="2">2</button>';
    echo '    <button type="button" data-num="3" data-key="3">3</button>';

    // row5
    echo '    <button type="button" data-num="0" data-key="0" class="zero">0</button>';
    echo '    <button type="button" data-num="." data-key=".">.</button>';

    echo '  </div>';
    echo '</div>';
}