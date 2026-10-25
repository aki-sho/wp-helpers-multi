# v0.9.2 配布ファイル

投稿・固定ページの編集画面内で、今編集中の本文を検索・置換する版です。

## WordPressにすぐ反映する場合

1. WordPressの「プラグイン → 新規プラグインを追加 → プラグインのアップロード」で `wp-helpers-multi.zip` を選びます。解凍しません。
2. 同じプラグインがインストール済みと表示されたら「アップロードしたもので現在のものを置き換える」を選びます。
3. プラグイン一覧でバージョン `0.9.2` を確認します。
4. 投稿・固定ページの編集画面を開き直し、右上の「検索・置換」を押します。

## 専用配布サーバーを更新する場合

Cyberduckで次のフォルダを開きます。

`plugin-server/wp-helpers-multi/`

1. このフォルダ直下に `wp-helpers-multi.zip` をアップロード・上書きします（`packages` の中には入れません）。
2. ZIPの転送完了後、同じ場所の `update.json` を上書きします。

```text
plugin-server/
└─ wp-helpers-multi/
   ├─ update.json
   ├─ wp-helpers-multi.zip
   └─ packages/
```

公開URLは `https://plugin.pretty-cute.info/wp-helpers-multi/update.json` と `https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip` です。

v0.9.1は更新情報を6時間キャッシュするため、配布サーバーへの配置後、通知まで時間がかかる場合があります。すぐ使う場合は上記のZIPによる上書き更新を使用してください。

ZIPの最上位フォルダ名は `wp-helpers-multi` です。既存の設定・ログ・過去の置換履歴は削除しません。編集画面内での置換の保存・リビジョンはWordPress標準の操作に従います。
