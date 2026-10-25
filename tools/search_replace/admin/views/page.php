<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap wphm-app wphm-sr" id="wphm-search-replace-history">
    <?php wphm_render_header('過去の置換履歴'); ?>
    <div class="notice notice-info inline"><p>検索・置換は、投稿・固定ページの編集画面内に移動しました。記事を開いて「検索・置換」を押してください。</p></div>
    <p><a class="button" href="<?php echo esc_url(admin_url('edit.php')); ?>">投稿を開く</a> <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>">固定ページを開く</a></p>
    <section class="wphm-sr-history">
        <h2>v0.9.0〜v0.9.1で実行した置換の履歴</h2>
        <p>保存済みの履歴は引き続き参照・復元できます。編集画面内での置換は、WordPress標準の元に戻す・リビジョンを使用します。</p>
        <p id="wphm-history-status" role="status" aria-live="polite"></p>
        <div id="wphm-history-list"></div>
        <p><button type="button" class="button" id="wphm-history-prev">前の20件</button> <span id="wphm-history-page"></span> <button type="button" class="button" id="wphm-history-next">次の20件</button></p>
        <details id="wphm-history-detail" hidden><summary>履歴の本文ソース</summary><label>置換前<textarea id="wphm-history-before" readonly rows="12" class="large-text"></textarea></label><label>置換後<textarea id="wphm-history-after" readonly rows="12" class="large-text"></textarea></label></details>
    </section>
</div>
