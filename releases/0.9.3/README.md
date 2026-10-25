# v0.9.3：更新の再確認を修正

## いまv0.9.1／v0.9.2で通知が出ない場合

旧版には6時間キャッシュを無視する処理がありません。この修正を使うには、最初の一度だけ次のどちらかでv0.9.3を導入します。

- WordPressの「プラグイン → 新規プラグインを追加 → プラグインのアップロード」から、このフォルダの `wp-helpers-multi.zip` を選択し、「アップロードしたもので現在のものを置き換える」を押す。
- 専用配布サーバーへ最新版を配置し、旧版のキャッシュの期限切れ後に通常の更新を実行する。

設定画面の直接編集や、サーバーのセキュリティ設定の変更は必要ありません。

## v0.9.3の導入後

「プラグイン → インストール済みプラグイン」で、WP Helpers Multiの「更新を確認」を押します。キャッシュに関係なく専用サーバーへ問い合わせ、結果を画面に表示します。「ダッシュボード → 更新 → 再確認」も同じ動作に対応します。

## 専用配布サーバーへの配置

`plugin-server/wp-helpers-multi/` の直下に、先に `wp-helpers-multi.zip`、転送完了後に `update.json` を上書きします。`packages` の中には配置しません。

公開URL：

- `https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip`
- `https://plugin.pretty-cute.info/wp-helpers-multi/update.json`

プラグインの設定・ログ・過去の置換履歴は削除しません。
