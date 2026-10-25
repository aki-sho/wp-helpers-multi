# v0.9.0 配布ファイル

- `wp-helpers-multi.zip`: WordPress管理画面からインストール・上書き更新できるZIP。
- `update.json`: 既存の専用更新サーバーへ配置する更新情報。

ZIPは初回インストールにも使用できます。「プラグイン → 新規追加 → プラグインのアップロード」から選択し、有効化してください。導入済みの場合は同じ操作で既存版を置き換えられます。

専用サーバーで配布する場合は、次の公開URLになる場所へ配置します。

1. `https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip` にZIPを配置。
2. ZIPの配置後、`https://plugin.pretty-cute.info/wp-helpers-multi/update.json` に更新情報を配置。
3. WordPress側で更新情報が再確認されると、v0.8.0以前からの更新通知が表示されます。

サーバー上のZIP名を変更する場合は、`update.json` の `download_url` も同じ公開URLに変更します。FTPのフォルダと公開URLの対応は既存の設定に従います。

このフォルダは配布準備用です。サーバーへのアップロードは行っていません。
