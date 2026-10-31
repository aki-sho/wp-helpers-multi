# v0.9.8：URLの種類ごとの除外・限定収集

- アップロードディレクトリ配下・画像へ自動転送するURL・添付ファイルページを個別にチェックできます。
- 「チェックした種類を除外」と「チェックした種類だけ収集」を切り替え、複数選択できます。
- チェックの変更を自動で反映します。設定用の保存や再収集は不要です。
- 画像への転送は転送先の応答で確認し、未判定の件数も表示します。

## WordPressへの導入

GitHub Releaseの `wp-helpers-multi-0.9.8.zip` をWordPressのプラグインアップロードから導入・上書き更新できます。

## 専用配布サーバーへ配置する場合

このフォルダの `wp-helpers-multi.zip` を `plugin-server/wp-helpers-multi/` 直下へ先にアップロードし、完了後に `update.json` を上書きします。公開URLは `https://plugin.pretty-cute.info/wp-helpers-multi/` です。GitHub公開だけでは専用配布サーバーのファイルは更新されません。

動作・上限・検証の範囲は ../../docs/site-url-filters-0.9.8.md を参照してください。
