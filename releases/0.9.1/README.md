# v0.9.1 配布ファイル

- `wp-helpers-multi.zip`: 修正版のインストール・上書き更新用ZIP。
- `update.json`: 既存の専用配布サーバーへ配置する更新情報。

WordPress管理画面の「プラグイン → 新規追加 → プラグインのアップロード」からZIPを選択すると、既存版を置き換えられます。

専用配布サーバーで公開する場合は、先にZIP、最後にJSONを配置します。

1. `https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip`
2. `https://plugin.pretty-cute.info/wp-helpers-multi/update.json`

ZIP内の最上位フォルダは `wp-helpers-multi` です。既存の設定・ログ・置換履歴を削除する移行処理はありません。

v0.9.1以降の更新情報は正常時6時間キャッシュします。配布後すぐに通知が表示されない場合も、次の情報取得後に反映されます。取得失敗時は10分間隔で再試行し、最大7日間保持した直近の正常情報を使用します。

サーバーへのアップロードは行っていません。
