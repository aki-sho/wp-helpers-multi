# v0.9.5：セキュリティ改修

アクセスログ、リンク点検、QR、bcrypt、コメント/IP拒否、編集画面内の検索・置換を改修しました。
変更内容と設定上の違いは ../../docs/security-0.9.5.md を参照してください。

## インストール

GitHub Releaseの `wp-helpers-multi-0.9.5.zip` をWordPressのプラグインアップロードから導入・上書き更新できます。

## 専用配布サーバーへ配置する場合

1. `wp-helpers-multi.zip` を先にアップロードします。
2. 転送完了後に `update.json` を公開します。
3. WordPressのプラグイン一覧「更新を確認」から更新します。

GitHub Releaseの公開だけでは専用配布サーバーのファイルは変更されません。
