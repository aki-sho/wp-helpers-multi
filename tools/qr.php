<?php
if (!defined('ABSPATH')) exit;

function wphm_render_qr_tool_page(): void {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap wphm-app">';
    wphm_render_header('QRコード');
    ?>
    <p>テキストやURLから、このブラウザ内でQRコードを生成します。入力内容をサーバーや外部サービスへ送信しません。</p>
    <div id="wphm-qr-tool">
        <p><label for="wphm-qr-text">内容（URL / テキスト）</label></p>
        <textarea id="wphm-qr-text" rows="4" maxlength="3000" style="width:520px;max-width:100%"></textarea>
        <p><label>サイズ <input id="wphm-qr-size" type="number" min="120" max="1000" value="240"> px</label></p>
        <p><label>誤り訂正 <select id="wphm-qr-ecc"><option value="L">L（7%）</option><option value="M" selected>M（15%）</option><option value="Q">Q（25%）</option><option value="H">H（30%）</option></select></label></p>
        <p><label>余白 <input id="wphm-qr-margin" type="number" min="0" max="16" value="4"></label></p>
        <p><button type="button" class="button button-primary" id="wphm-qr-generate">生成</button> <button type="button" class="button" id="wphm-qr-clear">クリア</button></p>
        <p id="wphm-qr-message" role="status" aria-live="polite"></p>
        <div id="wphm-qr-result" hidden><canvas id="wphm-qr-canvas" aria-label="生成したQRコード" role="img" style="max-width:100%;height:auto"></canvas><p><a class="button" id="wphm-qr-download" download="qr.png">PNGをダウンロード</a></p></div>
        <noscript><p>QRコードの生成にはJavaScriptを有効にしてください。</p></noscript>
    </div>
    <?php
    echo '</div>';
}
