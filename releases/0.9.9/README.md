# v0.9.9：セキュリティチェック

10種類の攻撃に関する設定・更新情報・既存ログの確認結果を、低・中・高の横棒と、現在の状態・理由・確認範囲で表示します。情報不足は未確認とし、再チェックに対応します。ダッシュボードで機能を無効にできます。攻撃や自動修正は行いません。

## WordPressへの導入

GitHub Releaseの `wp-helpers-multi-0.9.9.zip` をプラグインアップロードから導入・上書き更新できます。

## 専用配布サーバーへ配置する場合

このフォルダの `wp-helpers-multi.zip` を `plugin-server/wp-helpers-multi/` 直下へ先にアップロードし、完了後に `update.json` を上書きします。公開URLは `https://plugin.pretty-cute.info/wp-helpers-multi/` です。GitHub公開だけでは専用配布サーバーへ配置されません。

判定の範囲と検証は ../../docs/security-check-0.9.9.md を参照してください。
