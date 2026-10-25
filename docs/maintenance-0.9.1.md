# v0.9.1 共通処理の修正と検証

2026-10-25に実施。配布サーバー・公開中のサイトへの変更は行っていません。

## 修正範囲

1. 管理メニューの登録時に機能ごとの `load_callback` を `load-*` に登録。アクセスログのCSV・更新、コメントの更新、リンク点検の開始・バッチ・リセットは、画面描画から分離しました。リンク点検のモジュールは対象画面の読み込み時に登録します。既存のフォーム・URL・nonce・権限確認を維持しています。
2. bcrypt入力はWordPressのPOSTエスケープを `wp_unslash()` で復元します。パスワードのトリムや文字置換は行いません。
3. 共通日時ヘルパーで、UNIX時刻の表示、ローカル日付のフィルター範囲、投稿に保存されたローカル日時を区別しました。日付の実在性と、夏時間の日の長さも検証します。
4. フロントリクエストのログは終了処理まで保留し、最終的な `http_response_code()` を保存します。WordPressの通常の終了・エラー処理後に実行し、二重保存を防ぎます。未知のステータスは0です。以前の記録は書き換えません。
5. 専用更新サーバーの情報を共通化し、成功時6時間・失敗時10分のキャッシュと、最大7日間の正常情報の保持を追加しました。HTTPSのダウンロードURL・メタデータ形式を検証し、古い更新通知を解除します。Update URIに対応したWordPress標準フックとプラグイン詳細画面で同じ情報を使用します。通信量には上限を設け、プラグイン自身の更新後にはキャッシュを解除します。
6. 共通CSV出力でBOM・ヘッダー・値の保護を統一しました。空白・制御文字の後に数式開始文字がある文字列や全角の開始文字も保護します。数値型は維持し、引用符・カンマ・改行・バックスラッシュはCSVとして往復可能な形式で出力します。

## 検証結果

- `php -n tests/maintenance.php`: 83項目成功。CSV、日時・夏時間、bcrypt、表示前フック、HTTPステータス、更新キャッシュ・障害時動作を検証。
- `python tests/maintenance-http.py`: 64項目成功。実際のHTTP経由でCSVのBOM・ヘッダー・HTML混入防止・日付範囲・数式保護、更新処理の302応答、bcryptの入力と生成・照合、200/301/302/403/404/500応答と保存値の一致を検証。
- `php tests/maintenance-wordpress.php check`: 32項目成功。WordPress標準API、DBの更新結果、管理メニューのフック、実際のsite transient、更新サーバーの成功・失敗応答、HTTPで記録したログを検証。
- 既存の検索・置換回帰テスト84項目、WordPress統合テスト29項目も成功。

WordPress統合テストは、コピーしたWordPress 7.0コア、PHP 8.2.12、専用のMariaDB 10.4.32インスタンスで実施しました。既存サイトの設定・DBを使用せず、外部通信・メールを遮断し、更新情報はテスト用HTTP応答に差し替えています。500応答の検証では意図的なPHPエラーを1回発生させ、その応答とログを照合しました。

宣言上の最低要件であるWordPress 6.0 / PHP 7.4の実機検証、および本番配布サーバーとの接続確認は未実施です。

## 隔離テストの実行

専用の `.wphm-test/wordpress` にWordPressコアを用意し、その `wp-content/plugins/wp-helpers-multi` に実装ファイルをコピーします。テスト専用MariaDBを127.0.0.1:33077で起動し、そのrootパスワードを `.wphm-test/db-password.txt` に置きます。既存サイトのデータディレクトリは使用しないでください。

1. `php tests/maintenance-wordpress.php setup`
2. `php -S 127.0.0.1:8766 tests/maintenance-router.php`
3. 別のターミナルで `python tests/maintenance-http.py`
4. `php tests/maintenance-wordpress.php check`
5. HTTPサーバーを終了し、`php tests/maintenance-wordpress.php cleanup`

HTTPルーターはループバック接続と生成したテスト用ヘッダーキーを確認します。テストDBはランダム名で作成し、cleanupでそのDBだけを削除します。終了後はMariaDBも停止し、一時ファイルを削除してください。配布ZIPにテストコード・資格情報・一時環境は含めません。
