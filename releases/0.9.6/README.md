# v0.9.6：投稿データとサイト内URL一覧

- 投稿データのアイキャッチの隣に「記事内画像数」「記事内リンク数」を追加。項目選択・数値での並び替え・CSVに対応。
- 新しい「サイト内URL一覧」で、収集したサイト内URLから固定ページ・投稿記事のURLだけを除外して一覧表示。検索・CSV・収集の停止/再開に対応。

## WordPressへの導入

GitHub Releaseの `wp-helpers-multi-0.9.6.zip` をWordPressのプラグインアップロードから導入・上書き更新できます。

## 専用配布サーバーへ配置する場合

このフォルダの `wp-helpers-multi.zip` を `plugin-server/wp-helpers-multi/` 直下へ先にアップロードし、完了後に `update.json` を上書きします。ZIP内の最上位フォルダは `wp-helpers-multi` です。GitHub Releaseの公開だけでは専用配布サーバーのファイルは更新されません。

収集の範囲・上限と検証内容は ../../docs/site-data-0.9.6.md を参照してください。
