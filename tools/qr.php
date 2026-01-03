<?php
if (!defined('ABSPATH')) exit;

/**
 * tools/qr.php
 * QRコード作成ツール（管理画面用）
 *
 * 依存：なし
 * 生成：QuickChart QR API（PNG）を利用
 * 参考：https://quickchart.io/documentation/qr-codes/  :contentReference[oaicite:2]{index=2}
 */

function wphm_render_qr_tool_page(): void {
    if (!current_user_can('manage_options')) return;

    // 入力値
    $text   = isset($_POST['wphm_qr_text']) ? (string) wp_unslash($_POST['wphm_qr_text']) : '';
    $size   = isset($_POST['wphm_qr_size']) ? (int) $_POST['wphm_qr_size'] : 240;
    $ecc    = isset($_POST['wphm_qr_ecc']) ? sanitize_text_field((string) $_POST['wphm_qr_ecc']) : 'M';
    $margin = isset($_POST['wphm_qr_margin']) ? (int) $_POST['wphm_qr_margin'] : 2;

    // 正規化
    $text = trim($text);
    $size = max(120, min(1000, $size));
    $margin = max(0, min(16, $margin));
    if (!in_array($ecc, ['L','M','Q','H'], true)) $ecc = 'M';

    $is_post = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
    if ($is_post) {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string) $_POST['_wpnonce'], 'wphm_qr_make')) {
            wp_die('Nonceが不正です。');
        }
    }

    // QuickChart QR URL（作成時のみ）
    $qr_url = '';
    if ($is_post && $text !== '') {
        // https://quickchart.io/qr?text=...&size=...&margin=...&ecLevel=...&format=png
        $qr_url = add_query_arg([
            'text'    => $text,
            'size'    => $size,
            'margin'  => $margin,
            'ecLevel' => $ecc,
            'format'  => 'png',
        ], 'https://quickchart.io/qr');
    }

    echo '<div class="wrap wphm-app">';

    if (function_exists('wphm_render_header')) {
        wphm_render_header('QRコード');
    } else {
        echo '<h1 style="margin:0 0 12px;">QRコード</h1>';
    }

    echo '<p style="margin: 0 0 12px;">テキストやURLを入力するとQRコードを生成します。</p>';

    echo '<form method="post" action="">';
    wp_nonce_field('wphm_qr_make');

    echo '<table class="form-table" role="presentation">';

    echo '<tr>';
    echo '<th scope="row"><label for="wphm_qr_text">内容（URL / テキスト）</label></th>';
    echo '<td>';
    echo '<textarea id="wphm_qr_text" name="wphm_qr_text" rows="4" style="width:520px; max-width:100%;">' . esc_textarea($text) . '</textarea>';
    echo '<p class="description">例：https://example.com/</p>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="wphm_qr_size">サイズ</label></th>';
    echo '<td>';
    echo '<input id="wphm_qr_size" type="number" name="wphm_qr_size" value="' . (int)$size . '" min="120" max="1000" step="10"> px';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row">誤り訂正レベル</th>';
    echo '<td>';
    $ecc_labels = [
        'L' => 'L（7%）',
        'M' => 'M（15%）',
        'Q' => 'Q（25%）',
        'H' => 'H（30%）',
    ];
    foreach ($ecc_labels as $k => $label) {
        echo '<label style="margin-right:12px;">';
        echo '<input type="radio" name="wphm_qr_ecc" value="' . esc_attr($k) . '" ' . checked($ecc, $k, false) . '> ';
        echo esc_html($label);
        echo '</label>';
    }
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="wphm_qr_margin">余白</label></th>';
    echo '<td>';
    echo '<input id="wphm_qr_margin" type="number" name="wphm_qr_margin" value="' . (int)$margin . '" min="0" max="16" step="1">';
    echo '</td>';
    echo '</tr>';

    echo '</table>';

    echo '<p>';
    echo '<button type="submit" class="button button-primary">生成</button> ';
    echo '<button type="button" class="button" onclick="document.getElementById(\'wphm_qr_text\').value=\'\';">クリア</button>';
    echo '</p>';

    echo '</form>';

    // 結果表示
    if ($is_post) {
        if ($text === '') {
            echo '<div class="notice notice-warning"><p>内容が空です。URLまたはテキストを入力してください。</p></div>';
        } else {
            echo '<hr style="margin:16px 0;">';
            echo '<h2 style="margin:0 0 10px;">生成結果</h2>';

            echo '<div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">';

            echo '<div style="background:#fff; border:1px solid #dcdcde; padding:12px; display:inline-block;">';
            echo '<img src="' . esc_url($qr_url) . '" alt="QR Code" width="' . (int)$size . '" height="' . (int)$size . '">';
            echo '</div>';

            echo '<div style="min-width:320px; flex:1;">';
            echo '<p style="margin-top:0;"><strong>内容</strong></p>';
            echo '<textarea rows="4" readonly style="width:100%; max-width:720px;">' . esc_textarea($text) . '</textarea>';

            echo '<p><strong>画像URL</strong></p>';
            echo '<input type="text" readonly style="width:100%; max-width:720px;" value="' . esc_attr($qr_url) . '" onclick="this.select();">';

            echo '<p style="margin-top:10px;">';
            echo '<a class="button" href="' . esc_url($qr_url) . '" target="_blank" rel="noopener">別タブで開く</a> ';
            // ※download属性はブラウザ/クロスオリジンで効かない場合あり
            echo '<a class="button" href="' . esc_url($qr_url) . '" download="qr.png">ダウンロード</a>';
            echo '</p>';

            echo '<p class="description">※ ダウンロードが効かない場合は「別タブで開く」→画像を右クリック保存してください。</p>';
            echo '</div>';

            echo '</div>';
        }
    }

    echo '</div>';
}
