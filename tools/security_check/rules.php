<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_levels(): array {
    return ['low' => '低', 'medium' => '中', 'high' => '高', 'unknown' => '未確認'];
}

/** Assess only positively observed conditions. Missing findings never prove a site safe. */
function wphm_sc_assess(array $facts): array {
    $logs = $facts['logs']; $updates = $facts['updates'];
    $observations = $logs['available'] ? '直近24時間の保存ログから' . $logs['sampled'] . '件を参照。' : '参照できるアクセスログがありません。';
    $items = [
        'brute_force' => ['name' => 'ブルートフォース攻撃', 'scope' => 'ログイン試行・試行制限', 'level' => 'unknown', 'state' => 'ログインの成功・失敗履歴は取得できません。', 'reason' => '既存ログはフロント側のアクセス記録です。認証失敗数やログイン試行制限の設定を確認できないため、耐性は判定できません。'],
        'credential_stuffing' => ['name' => 'クレデンシャルスタッフィング攻撃', 'scope' => '認証情報の再利用・追加認証', 'level' => 'unknown', 'state' => 'パスワードの使い回し・漏えい・全利用者の追加認証は未確認です。', 'reason' => '保存パスワードの解析、漏えい情報との照合、ログインの試行は行いません。HTTPS設定は' . ($facts['https'] ? '有効です' : '統一されていません') . 'が、この攻撃への耐性を示すものではありません。'],
        'sql_injection' => ['name' => 'SQLインジェクション', 'scope' => '入力とSQL組み立て・保存ログ', 'level' => 'unknown', 'state' => $observations . 'SQL風の文字列を含むパスは' . $logs['sql_paths'] . '件です。', 'reason' => 'ログにはクエリやPOST本文がなく、テーマ・プラグインのSQL組み立ても監査していません。文字列の一致だけでは攻撃の有無・成立を判断できません。'],
        'xss' => ['name' => 'クロスサイトスクリプティング（XSS）', 'scope' => '公開登録者のHTML権限・出力処理', 'level' => 'unknown', 'state' => 'HTMLタグ風の文字列を含むパスは' . $logs['html_paths'] . '件です。', 'reason' => '入力の無害化と出力のエスケープをコード全体で監査していないため、XSS耐性は未確認です。文字列の一致は攻撃成立を意味しません。'],
        'ddos' => ['name' => 'DDoS攻撃', 'scope' => '保存されたフロントアクセスの観測', 'level' => 'unknown', 'state' => $observations . '記録内の最大は1分あたり' . $logs['peak'] . '件、HTTP 5xxは' . $logs['errors'] . '件です。', 'reason' => '記録頻度制限・ボット除外・キャッシュ等で記録されない通信があります。回線負荷、CDN・WAFの設定や未到達の通信は確認できず、DDoSの有無・耐性は判定できません。'],
        'csrf' => ['name' => 'CSRF攻撃', 'scope' => '操作ごとのnonce・権限検証', 'level' => 'unknown', 'state' => 'テーマ・プラグイン全体の操作受付処理は未監査です。', 'reason' => 'WordPressにnonceの仕組みがあっても、各処理が正しく検証しているかは設定や通常のアクセスログだけでは確認できません。'],
        'rce' => ['name' => 'RCE（リモートコード実行）攻撃', 'scope' => 'PHPのサポート状況・管理画面のコード編集', 'level' => 'unknown', 'state' => 'PHP ' . $updates['php']['version'] . '。管理画面のコード編集は' . ($facts['file_edit_allowed'] ? '現在の管理者に許可されています。' : '現在の管理者には許可されていません。'), 'reason' => 'テーマ・プラグインの実行経路やサーバー全体は未監査のため、RCE耐性は未確認です。'],
        'file_upload' => ['name' => 'ファイルアップロード攻撃', 'scope' => 'PHP標準の受信・WordPressの未検査アップロード権限', 'level' => 'unknown', 'state' => '未検査アップロードを許可する設定は' . ($facts['unfiltered_uploads'] ? '有効です。' : '無効です。'), 'reason' => '独自のアップロード受付処理、保存後の検査、Webサーバーの実行禁止設定は未確認です。通常設定だけで安全とは判定しません。'],
        'vulnerability_exploit' => ['name' => '脆弱性悪用攻撃', 'scope' => 'WordPress公式のコア状態・更新待ち', 'level' => 'unknown', 'state' => 'WordPress ' . $updates['core']['version'] . '。更新待ち：コア' . ($updates['core']['known'] ? ($updates['core']['pending'] ? '1件' : '0件') : '未確認') . '・プラグイン' . $updates['plugins']['pending'] . '件（未確認' . $updates['plugins']['unknown'] . '件）・テーマ' . $updates['themes']['pending'] . '件（未確認' . $updates['themes']['unknown'] . '件）。', 'reason' => '更新待ちがなくても脆弱性がないとは判断できません。全プラグイン・テーマのCVE照合とコード監査は行っていません。'],
        'directory_traversal' => ['name' => 'ディレクトリトラバーサル攻撃', 'scope' => '保存ログのパス・ファイル参照処理', 'level' => 'unknown', 'state' => $observations . '相対パス記号「../」等を含むパスは' . $logs['traversal_paths'] . '件です。', 'reason' => 'ファイル参照処理の検証やアクセス先の内容確認は行いません。ログの文字列だけで、不正なファイル読取りの成立や耐性は判断できません。'],
    ];
    if ($facts['registration'] && $facts['raw_html']) {
        $items['xss']['level'] = 'high';
        $items['xss']['state'] = '公開登録で作られる利用者に、未フィルターHTMLを保存できる権限があります。';
        $items['xss']['reason'] = '新規登録者の役割に強いHTML編集権限が付与されています。登録者の権限を見直してください。XSSの実行テストや出力処理全体の監査は行っていません。';
    }
    if ($updates['php']['known'] && $updates['php']['secure'] === false) {
        $items['rce']['level'] = 'high';
        $items['rce']['reason'] = 'WordPress公式のPHP情報では、このPHP系列は上流のセキュリティ更新対象外です。ホスティング事業者の独自パッチとRCEの成立は未確認です。';
    } elseif ($facts['file_edit_allowed']) {
        $items['rce']['level'] = 'medium';
        $items['rce']['reason'] = '管理者の認証情報を奪われた場合、管理画面から実行コードを変更できる設定です。コード編集の制限を検討してください。その他のRCE経路は未確認です。';
    }
    if ($facts['file_uploads'] === false) {
        $items['file_upload']['level'] = 'low';
        $items['file_upload']['state'] = 'PHP標準のファイルアップロード受信は無効です。';
        $items['file_upload']['reason'] = 'この受信経路が停止している範囲に限った判定です。URL取込みやプラグイン独自のファイル保存・実行経路は未確認です。';
    } elseif ($facts['unfiltered_uploads']) {
        $items['file_upload']['level'] = $facts['registration'] && $facts['raw_upload'] ? 'high' : 'medium';
        $items['file_upload']['reason'] = $items['file_upload']['level'] === 'high' ? '公開登録者の役割がファイルの未検査アップロードを行える構成です。登録者の権限とALLOW_UNFILTERED_UPLOADSを見直してください。' : 'ALLOW_UNFILTERED_UPLOADSが有効で、権限を持つ利用者が標準の種類制限を回避できます。独自の受付処理と保存後の実行可否は未確認です。';
    }
    if ($updates['core']['status'] === 'insecure') {
        $items['vulnerability_exploit']['level'] = 'high';
        $items['vulnerability_exploit']['reason'] = 'WordPress公式の版情報が、現在のコアを「insecure（安全でない版）」と返しています。コアの更新を優先してください。侵害の有無やプラグイン・テーマの脆弱性は未確認です。';
    } elseif ($updates['core']['pending'] || $updates['plugins']['pending'] || $updates['themes']['pending']) {
        $items['vulnerability_exploit']['level'] = 'medium';
        $items['vulnerability_exploit']['reason'] = '未適用の更新を確認しました。更新状況に関する注意判定です。更新内容がセキュリティ修正か、悪用可能な脆弱性があるかは未確認です。';
    }
    foreach ($items as $id => &$item) $item['id'] = $id;
    unset($item);
    return array_values($items);
}
