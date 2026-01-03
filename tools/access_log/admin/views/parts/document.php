<?php
if (!defined('ABSPATH')) exit;

/**
 * tools/access_log/admin/views/parts/document.php
 * - Access Log: usage document (simple)
 */
?>
<div class="wphm-accesslog-doc" style="margin-top:12px; padding:12px 14px; border:1px solid #dcdcde; background:#fff;">
  <h2 style="margin:0 0 8px;">使い方</h2>

  <ul style="margin:0; padding-left:18px; line-height:1.7;">
    <li><strong>記録対象</strong>：フロント（管理画面以外）のアクセスが自動で記録されます。</li>
    <li><strong>絞り込み</strong>：上部の「検索」「IP」「From/To」で条件を指定して「絞り込み」を押します。</li>
    <li><strong>CSV出力</strong>：現在の絞り込み条件のまま最大200件をCSVでダウンロードします。</li>
    <li><strong>選択削除</strong>：チェックを付けて「選択削除」を押します。</li>
    <li><strong>古いログ削除</strong>：指定日数より前のログを削除します（例：30日）。</li>
    <li><strong>全削除</strong>：すべてのログを削除します（取り消し不可）。</li>
  </ul>

  <h3 style="margin:12px 0 6px; font-size:14px;">項目の説明</h3>
  <ul style="margin:0; padding-left:18px; line-height:1.7;">
    <li><strong>日時</strong>：アクセスが記録された日時です。</li>
    <li><strong>ID</strong>：ログの連番IDです（削除・確認用）。</li>
    <li><strong>Method</strong>：HTTPメソッド（GET/POST など）です。</li>
    <li><strong>Status</strong>：HTTPステータスコードです（現在は基本 200 として記録）。</li>
    <li><strong>IP</strong>：アクセス元IPアドレスです。</li>
    <li><strong>User</strong>：ログイン中ユーザーIDです（未ログインは 0）。</li>
    <li><strong>URL</strong>：アクセスされた完全なURLです。</li>
    <li><strong>Referrer</strong>：参照元URLです（無い場合は空）。</li>
    <li><strong>UA</strong>：User-Agent（ブラウザ/端末情報）です。</li>
  </ul>

  <hr style="margin:12px 0; border:0; border-top:1px solid #dcdcde;">

  <p style="margin:0;">
    <strong>メモ：</strong>
    IP取得は環境により異なります（Cloudflare/ALB等）。必要なら <code>wphm_access_log_ip</code> フィルタで上書きしてください。
  </p>
</div>