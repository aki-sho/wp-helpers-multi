# v0.10.0 配布ファイル

セキュリティチェックの10項目と共通5項目を拡充しました。項目ごとの内容・確認範囲・データ保存・連携設定は [改修と検証の詳細](../../docs/security-check-0.10.0.md) を参照してください。

GitHub Releasesの `wp-helpers-multi-0.10.0.zip` は、そのままWordPressの「プラグインのアップロード」から上書き更新できます。

専用配布サーバーへ反映する場合は、このフォルダの `wp-helpers-multi.zip` を先に `https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip` へ配置し、最後に `update.json` を同ディレクトリへ配置してください。GitHubの公開だけでは専用配布サーバーや実サイトは更新されません。

Cloudflareは設定画面にゾーン限定の読取り情報を登録した場合だけ確認します。サーバー独自の実効設定・未知の脆弱性・他製品の未監査処理は未確認として扱います。攻撃を伴う診断・実サイトへの設定変更は行いません。
