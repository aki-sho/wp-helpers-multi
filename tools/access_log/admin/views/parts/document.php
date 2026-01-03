<?php
if (!defined('ABSPATH')) exit;

/**
 * tools/access_log/admin/views/parts/document.php
 * - Access Log: usage document (simple)
 */
?>
<div class="notice notice-info" style="margin-top:12px; padding:12px 14px;">
  <h2 style="margin:0 0 8px;">使い方</h2>

  <ul style="margin:0; padding-left:18px; line-height:1.7;">
    <li><strong>記録対象</strong>：フロント（管理画面以外）のアクセスが自動で記録されます。</li>
    <li><strong>絞り込み</strong>：上部の「検索」「IP」「From/To」で条件を指定して <strong>絞り込み</strong> を押します。</li>
    <li><strong>CSV出力</strong>：現在の絞り込み条件のまま <strong>最大200件</strong> をCSVでダウンロードします。</li>
    <li><strong>選択削除</strong>：テーブル左のチェックを付けて <strong>選択削除</strong> を押します。</li>
    <li><strong>古いログ削除</strong>：指定日数より前のログをまとめて削除します（例：30日）。</li>
    <li><strong>全削除</strong>：すべてのログを削除します（取り消し不可）。</li>
  </ul>

  <hr style="margin:12px 0; border:0; border-top:1px solid #dcdcde;">

  <p style="margin:0;">
    <strong>メモ：</strong>
    IP取得は環境により異なります（Cloudflare/ALB等）。必要なら <code>wphm_access_log_ip</code> フィルタで上書きしてください。
  </p>
</div>
