# v0.10.2 配布ファイル

セキュリティチェックの共通取得元・接続欄、10項目の開閉、確認項目の設定場所を追加しました。確認項目の結果からリスクを判定し、未取得を安全とは扱いません。[改修内容・連携仕様・検証](../../docs/security-check-0.10.2.md) を参照してください。

GitHub Releasesの `wp-helpers-multi-0.10.2.zip` は、WordPressの「プラグインのアップロード」から上書き更新できます。

専用配布サーバーには、このフォルダの `wp-helpers-multi.zip` を先に `https://plugin.pretty-cute.info/wp-helpers-multi/wp-helpers-multi.zip` に配置し、最後に `update.json` を同ディレクトリへ配置します。GitHubへの公開だけでは、この配布サーバーやWordPress実サイトは更新されません。

WordPress内の標準読取りとCloudflareに対応します。ホスティングの非公開設定・その他の外部サービスには提供元の読取り連携が必要です。攻撃や実際のログイン試行を伴う診断、防御設定の自動変更は行いません。
