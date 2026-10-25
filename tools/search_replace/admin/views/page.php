<?php
if (!defined('ABSPATH')) exit;
?>
<div class="wrap wphm-app wphm-sr" id="wphm-search-replace">
    <?php wphm_render_header('記事検索・置換'); ?>
    <p class="wphm-sr-intro">投稿・固定ページの文章を検索し、置換前後を確認してから保存します。</p>
    <form id="wphm-sr-form" class="wphm-sr-search">
        <fieldset id="wphm-sr-fields">
            <div class="wphm-sr-inputs">
                <label>検索する文字列<textarea name="search" rows="2" required placeholder="検索する文章（複数行にも対応）"></textarea></label>
                <label>置換後の文字列<textarea name="replacement" rows="2" placeholder="空欄にすると、一致した文字列を削除します"></textarea></label>
            </div>
            <div class="wphm-sr-filters">
                <label>記事種別<select name="type"><option value="both">投稿と固定ページ</option><option value="post">投稿のみ</option><option value="page">固定ページのみ</option></select></label>
                <label>状態<select name="status"><option value="publish">公開済み</option><option value="draft">下書き</option><option value="pending">承認待ち</option><option value="private">非公開</option><option value="future">予約済み</option><option value="all">すべて（ゴミ箱を除く）</option></select></label>
                <label>記事ID<input name="post_id" type="number" min="1" placeholder="指定なし"></label>
                <label>タイトルで絞る<input name="title" type="text" maxlength="200" placeholder="タイトルの一部"></label>
                <label>カテゴリ（指定時は投稿のみ）<?php wp_dropdown_categories(['name' => 'category', 'show_option_all' => 'すべて', 'hide_empty' => false, 'hierarchical' => true, 'selected' => 0]); ?></label>
                <label class="wphm-sr-case"><input type="checkbox" name="case_sensitive" value="1">大文字・小文字を区別</label>
            </div>
            <button class="button button-primary" type="submit">検索して差分を確認</button>
        </fieldset>
        <p class="description">文章モード：HTMLタグ・属性、ブロック設定、コード、登録済みショートコードは対象外です。未対応ブロックは結果に表示します。タグをまたぐ文字列は一致しません。</p>
        <p class="description">1回の検索は最大200記事・2,000箇所、1記事は500箇所・本文1MBまで。結果の有効期限は最後の検索処理から1時間です。</p>
    </form>
    <div class="wphm-sr-toolbar">
        <p id="wphm-sr-status" role="status" aria-live="polite">条件を入力して検索してください。</p>
        <button id="wphm-sr-stop" type="button" class="button" disabled>処理を停止</button>
    </div>
    <details id="wphm-sr-notices" hidden><summary>対象外・処理結果の詳細</summary><ul id="wphm-sr-notice-list"></ul></details>
    <div class="wphm-sr-workspace">
        <aside class="wphm-sr-sidebar" aria-label="記事ごとの検索結果">
            <div class="wphm-sr-list-heading"><strong>検索結果</strong><button type="button" class="button button-small" id="wphm-sr-select-all" disabled>すべて選択／解除</button></div>
            <div id="wphm-sr-results"><p class="wphm-sr-empty">一致した記事がここに並びます。</p></div>
        </aside>
        <section class="wphm-sr-preview" aria-label="置換前後の確認">
            <div id="wphm-sr-preview"><p class="wphm-sr-empty">検索後、記事を選ぶと置換箇所を確認できます。</p></div>
        </section>
    </div>
    <div class="wphm-sr-savebar">
        <span id="wphm-sr-selected">選択中：0記事・0箇所</span>
        <button type="button" id="wphm-sr-apply" class="button button-primary" disabled>選択した箇所を置換</button>
    </div>
    <section class="wphm-sr-history">
        <div class="wphm-sr-history-heading"><h2>置換履歴・復元</h2><button type="button" class="button" id="wphm-sr-history-refresh">履歴を更新</button></div>
        <p>置換前の本文は履歴に保存します。置換後に本文が編集された記事は、自動で復元せず変更の確認を求めます。履歴は自動削除されません。</p>
        <div id="wphm-sr-history" aria-live="polite"></div>
        <div class="wphm-sr-history-nav"><button type="button" class="button" id="wphm-sr-history-prev" disabled>前の20件</button><span id="wphm-sr-history-page"></span><button type="button" class="button" id="wphm-sr-history-next" disabled>次の20件</button></div>
        <details id="wphm-sr-history-detail" hidden><summary>履歴に保存した本文ソース</summary><p id="wphm-sr-history-note"></p><div class="wphm-sr-history-sources"><label>置換前<textarea id="wphm-sr-history-before" readonly rows="12"></textarea></label><label>置換後<textarea id="wphm-sr-history-after" readonly rows="12"></textarea></label></div></details>
    </section>
</div>
