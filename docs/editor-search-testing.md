# 編集画面内の検索・置換（v0.9.2）

以下はv0.9.2時点の検証記録です。v0.9.4では正規表現モードを追加しています。追加分と現行の回帰検証は [正規表現の検索・置換](regex-search-testing.md) を参照してください。

## 対象と保存の動作

投稿・固定ページの新規作成／編集画面に、検索・置換パネルを表示します。対象は現在のエディターが保持している本文で、未保存の変更も含みます。検索と置換で記事更新用の独自APIは呼びません。確定保存・公開・自動保存・リビジョンはWordPress標準の処理に従います。

ブロックエディターでは各ブロックの文章属性を更新し、複数ブロックの変更を1回のstore actionへまとめます。クラシックのビジュアル表示ではTinyMCEのundo transaction、テキスト表示ではtextareaの編集履歴を使用します。

強調表示はCSS Custom Highlight APIを使用します。対応しない環境では、編集本文の外側に描画するオーバーレイを使用します。検索用のタグを本文へ挿入しません。

## 検証

2026-10-25、既存サイトとは別のデータベース・コピーしたWordPressコア・loopbackのHTTPサーバー・専用ブラウザープロファイルで実施しています。既存サイトのwp-configやデータベースは使用しません。

- `tests/editor-search-engine.cjs`：48項目。日本語、絵文字、HTMLエンティティ、大小文字、装飾をまたぐ一致、属性保持、改行、削除、文字列としての置換、ショートコード保護、5,000件の上限。
- `tests/editor-search-browser.cjs`：実際のWordPress編集画面で54項目。ブロック／クラシック、新規投稿、固定ページ、未保存の入力、個別／一括置換、標準Undo、置換前からの編集の保持、通常保存、別記事の不変、投稿者権限、テキスト表示、ショートカット、狭い画面、コード表示への切り替え、強調表示のフォールバックを検証。
- `tests/editor-search-wordpress.php`：12項目。投稿／固定ページへの読み込み、編集権限、有効・無効、一覧や添付ファイルへの非読み込み、旧メニューの終了、過去の履歴の保持。
- 既存の `tests/search-replace.php`：84項目、`tests/search-replace-wordpress.php`：30項目、`tests/maintenance.php`：83項目の回帰検証。

ブラウザー検証環境：WordPress 6.0と7.0でそれぞれ54項目が成功。PHP 8.2.12、MariaDB 10.4.32、Chromeを使用しました。権限・読み込みと旧機能のPHP統合テストはWordPress 7.0で実施しています。宣言上の最低PHP 7.4の実機検証は未実施です。

## 再実行の準備

1. `.wphm-test/wordpress/` にWordPressコアのみをコピーします。既存サイトのwp-config・アップロード・プラグインはコピーしません。
2. `wp-content/plugins/wp-helpers-multi/` にこのプラグインの本体・assets・includes・toolsをコピーします。`wp-content/mu-plugins/` を作成します。
3. 専用の空のMariaDBデータディレクトリを初期化し、127.0.0.1:33077で起動します。このテストは専用環境のroot空パスワードを前提にします。
4. `php tests/editor-search-setup.php` を実行します。ランダム名のデータベースとテストユーザー、投稿・固定ページ、専用の認証fixtureを作成します。
5. コピーしたコアを127.0.0.1:8767でPHPサーバーから配信します。
6. `WPHM_NODE_MODULES` にPlaywrightの入ったnode_modulesの場所を設定し、`node tests/editor-search-engine.cjs` と `node tests/editor-search-browser.cjs` を実行します。Chromeのパスは `WPHM_CHROME` で変更できます。
7. `php tests/editor-search-wordpress.php` を実行します。
8. 終了後は専用サーバーを停止し、fixtureのデータベース・コア・認証情報を削除します。

別コアでの検証には `WPHM_EDITOR_FIXTURE` で別の一時フォルダ、`WPHM_EDITOR_URL` で別のloopback URLを指定できます。ブラウザーテストは127.0.0.1以外への接続を拒否します。

## 範囲

文字列検索のみ対応します。タイトル・抜粋・URL属性・正規表現・未対応の独自ブロック・同期パターン・コード領域は対象外です。本文は200万文字、一致箇所は5,000件までです。上限に達した場合、一括置換を実行できない形で表示します。

過去の専用履歴テーブルは削除せず、管理者用の旧URLで参照・復元できます。旧一括検索・更新APIは処理を拒否します。
