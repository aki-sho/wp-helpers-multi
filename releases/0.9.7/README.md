# v0.9.7：サイト内URL一覧の改善

- 収集前にアップロードディレクトリ配下のURL除外を選択できます。
- 一覧ページを離れると収集結果・件数・収集状態を削除します。
- 確認状態の右隣に、未ログインでのアクセス可否などの「アクセス権限」を追加しました。
- 全6列を昇順・降順に並び替えでき、CSVにも同じ条件を反映します。

## WordPressへの導入

GitHub Releaseの `wp-helpers-multi-0.9.7.zip` をWordPressのプラグインアップロードから導入・上書き更新できます。

## 専用配布サーバーへ配置する場合

このフォルダの `wp-helpers-multi.zip` を `plugin-server/wp-helpers-multi/` 直下へ先にアップロードし、完了後に `update.json` を上書きします。公開URLは `https://plugin.pretty-cute.info/wp-helpers-multi/` です。ZIP内の最上位フォルダは `wp-helpers-multi` です。GitHub Releaseの公開だけでは専用配布サーバーのファイルは更新されません。

動作・判定の範囲と検証内容は ../../docs/site-url-options-0.9.7.md を参照してください。
