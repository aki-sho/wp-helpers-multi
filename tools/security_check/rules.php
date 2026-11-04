<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_levels(): array { return ['low'=>'低','medium'=>'中','high'=>'高','unknown'=>'未確認']; }

function wphm_sc_observation(array $logs, string $field): string {
    return $logs['available'] ? (int)$logs[$field] . '件（保存された範囲）' : '未計測（アクセスログを参照できません）';
}

function wphm_sc_assess(array $facts): array {
    $logs=$facts['logs']; $updates=$facts['updates'];
    $telemetry=$facts['telemetry'] ?? ['available'=>false];
    $identity=$facts['identity'] ?? [];
    $audit=$facts['audit'] ?? ['known'=>false];
    $files=$facts['files'] ?? [];
    $items=[];
    $add=function(string $id,string $name,string $scope,string $state,string $reason) use (&$items): void { $items[$id]=compact('id','name','scope','state','reason')+['level'=>'unknown']; };
    $auth=$telemetry['available'] ? '直近24時間の認証失敗' . $telemetry['failures'] . '件、パスワード段階の認証成功' . $telemetry['successes'] . '件、失敗後の成功候補' . $telemetry['after_failure'] . '件。' : '認証履歴は未計測です。計測開始後のイベントだけを参照します。';
    $add('brute_force','ブルートフォース攻撃','認証失敗の集計・確認できた試行制限',$auth,'認証試行は実行しません。成功件数には2段階認証前のパスワード確認を含みます。共有ハッシュ枠の集中は概数で、攻撃成立の証拠ではありません。');
    if (!empty($identity['limiter'])) {
        $limit=$identity['limiter'];
        $items['brute_force']['level']='low';
        $items['brute_force']['state'].=$limit['name'] . '：' . $limit['tries'] . '回、停止' . $limit['seconds'] . '秒。';
        $items['brute_force']['reason']='登録中の認証フィルターと実設定を読みました。低はこの試行制限設定の範囲です。除外設定・別の認証経路・外部WAFを含む全体の耐性は未確認です。';
    }
    if ($telemetry['available'] && ($telemetry['auth_peak']>=20 || $telemetry['focused_failures']>=10)) {
        $items['brute_force']['level']='medium';
        $items['brute_force']['reason']='認証失敗の集中を観測しました。1分20件以上、または共有枠で5分10件以上を注意の基準とします。正当な操作・共有回線・枠の衝突もあり、攻撃と断定しません。';
    }
    $mfa=($identity['mfa_known'] ?? 0)>0 ? '確認した管理者' . $identity['admins'] . '人中、対応する追加認証の設定を取得できた' . $identity['mfa_known'] . '人、有効' . $identity['mfa_enabled'] . '人。' : '管理者の追加認証は未確認です。対応する認証機能から設定を取得します。';
    $add('credential_stuffing','クレデンシャルスタッフィング攻撃','追加認証の要求・認証経路・失敗後の成功',$mfa . $auth,'漏えいパスワードの収集・解析・照合は行いません。2FAの要求は現在の要求条件で確認した設定です。SSO・XML-RPC・REST等への適用を一律には推測しません。');
    if (($identity['mfa_known'] ?? 0)>($identity['mfa_enabled'] ?? 0) || ($telemetry['available'] && $telemetry['after_failure']>0)) {
        $items['credential_stuffing']['level']='medium';
        $items['credential_stuffing']['reason']='追加認証を要求しない管理者、または失敗集中後のパスワード認証成功候補があります。設定の見直し・履歴確認が必要です。使い回しや侵害の成立は未確認です。';
    }
    $add('sql_injection','SQLインジェクション','本プラグインのSQL監査・入力分類','SQL風パス：' . wphm_sc_observation($logs,'sql_paths') . '。','サイト全体のSQL処理は未監査です。文字列の一致だけで脆弱性や攻撃成立は判断しません。');
    $add('xss','クロスサイトスクリプティング（XSS）','本プラグインの出力監査・HTML権限','HTML風パス：' . wphm_sc_observation($logs,'html_paths') . '。既存利用者の強いHTML編集権限は確認範囲で' . ($identity['html_users'] ?? 0) . '人。','既存利用者の権限は用途の確認が必要です。強い権限があることだけでXSS成立とは判定しません。独自の会員登録や他プラグインの出力は未監査です。');
    $request=$telemetry['available'] ? 'WordPress到達通信' . $telemetry['requests'] . '件、記録した分の最大' . $telemetry['peak'] . '件/分、5xx ' . $telemetry['errors'] . '件。' : 'WordPress到達通信は未計測です。';
    $add('ddos','DDoS攻撃','WordPress到達通信・任意のCDN読取り統計',$request,'ボットを名乗る通信も集計します。WordPressより前で処理されたキャッシュ・遮断通信・回線負荷はこの集計には含みません。件数からDDoS成立を断定しません。');
    if ($telemetry['available'] && ($telemetry['peak']>=600 || $telemetry['errors']>=20)) {
        $items['ddos']['level']='medium'; $items['ddos']['reason']='記録した1分600件以上、または24時間の5xx 20件以上を注意の基準として検出しました。サイトの通常負荷・サーバー側統計との確認が必要です。';
    }
    $cf=$facts['cloudflare'] ?? [];
    if (!empty($cf['known'])) $items['ddos']['state'].='Cloudflare：24時間集計' . ($cf['requests']===null ? '未確認' : $cf['requests'] . '件') . '、有効な管理WAF呼出ルール' . ($cf['waf_rules']===null ? '未確認' : $cf['waf_rules'] . '件') . '。';
    $add('csrf','CSRF攻撃','本プラグインの状態変更受付・nonce・権限・メソッド','監査対象ファイルと現在の配布ファイルの一致を確認します。','WordPressにnonce APIがあるだけでは耐性を判定しません。他テーマ・プラグインの操作受付は未監査です。');
    $add('rce','RCE（リモートコード実行）攻撃','PHPサポート・実行コードの編集/追加権限','PHP ' . $updates['php']['version'] . '。現在の管理者のコード編集：' . ($facts['file_edit_allowed'] ? '許可' : '不許可') . '。確認範囲で実行コードを編集・追加できる利用者' . ($identity['code_users'] ?? 0) . '人。','PHPサポート、権限設定、既知脆弱性は別の根拠として扱います。実際のコード実行試験は行いません。');
    if ($updates['php']['known'] && $updates['php']['secure']===false) {
        $items['rce']['level']='high'; $items['rce']['reason']='WordPress公式のPHP情報で上流セキュリティサポート対象外です。提供元独自パッチは別途確認が必要で、RCEの成立を示す判定ではありません。';
    } elseif ($facts['file_edit_allowed'] || ($identity['code_users'] ?? 0)>0 || !empty($files['code_tools'])) {
        $items['rce']['level']='medium'; $items['rce']['reason']='実行コードの編集・インストール権限、または有効なコード追加機能を確認しました。認証情報を奪われた場合の影響に関する注意です。DISALLOW_FILE_EDITだけでインストール経路も停止するとは扱いません。';
    }
    $add('file_upload','ファイルアップロード攻撃','PHP標準受信・許可形式・アップロード先','未検査アップロード設定：' . ($facts['unfiltered_uploads'] ? '有効' : '無効') . '。現在の利用者の許可形式' . ($files['mime_count'] ?? 0) . '種類、上限' . ($files['upload_bytes'] ?? 0) . 'バイト。','独自受付・URL取込み・サーバーの実行可否は別に確認します。公開AJAXの登録件数だけでは匿名アップロードの可否は判断しません。');
    if ($facts['file_uploads']===false) {
        $items['file_upload']['level']='low'; $items['file_upload']['state']='PHP標準のファイルアップロード受信は無効です。'; $items['file_upload']['reason']='低はPHP標準の受信経路の範囲です。独自の保存・取込み経路は未確認です。';
    } elseif ($facts['unfiltered_uploads'] || !empty($files['executable_types']) || ($files['scripts'] ?? 0)>0) {
        $items['file_upload']['level']=$facts['unfiltered_uploads'] && $facts['registration'] && $facts['raw_upload'] ? 'high' : 'medium';
        $items['file_upload']['reason']='未検査アップロード設定、実行形式の許可、またはアップロード先のスクリプト形式ファイルを確認しました。ファイルの悪意・実行可能性は未確認です。公開登録者に未検査アップロード権限がある場合は高とします。';
    }
    $add('vulnerability_exploit','脆弱性悪用攻撃','公式更新情報・既知脆弱性の版照合','WordPress ' . $updates['core']['version'] . '。更新待ち：コア' . ($updates['core']['known'] ? (int)$updates['core']['pending'] . '件' : '未確認') . '・プラグイン' . $updates['plugins']['pending'] . '件（未確認' . $updates['plugins']['unknown'] . '件）・テーマ' . $updates['themes']['pending'] . '件（未確認' . $updates['themes']['unknown'] . '件）。','更新待ち、既知脆弱性の影響版候補、侵害の有無を区別します。独自配布・改名・同名製品を含む製品同定の限界があります。');
    if ($updates['core']['status']==='insecure') {
        $items['vulnerability_exploit']['level']='high'; $items['vulnerability_exploit']['reason']='WordPress公式が現在のコアをinsecureと判定しています。コア更新を優先してください。侵害の有無は未確認です。';
    } elseif ($updates['core']['pending'] || $updates['plugins']['pending'] || $updates['themes']['pending']) {
        $items['vulnerability_exploit']['level']='medium'; $items['vulnerability_exploit']['reason']='更新待ちを確認しました。更新内容がセキュリティ修正かは、既知脆弱性情報や配布元の案内で確認してください。';
    }
    $add('directory_traversal','ディレクトリトラバーサル攻撃','本プラグインのファイル参照監査・入力分類','相対パス記号：' . wphm_sc_observation($logs,'traversal_paths') . '。','入力文字列からファイル読取りの成立は判定しません。他プラグインの展開・ダウンロード・シンボリックリンク処理は未監査です。');
    if ($audit['known']) foreach (['sql_injection','xss','csrf','directory_traversal'] as $id) {
        $items[$id]['level']='low';
        $items[$id]['state'].='本プラグインの配布時監査と' . $audit['files'] . 'ファイルが一致。';
        $items[$id]['reason']='低はWP Helpers Multi ' . $audit['version'] . 'の配布時に監査・検証した処理の範囲です。ファイル一致は侵害や未知の欠陥がない証明ではありません。他のテーマ・プラグインを含むサイト全体は未確認です。';
    }
    if ($facts['registration'] && $facts['raw_html']) {
        $items['xss']['level']='high'; $items['xss']['state']='公開登録者の初期ロールが未フィルターHTMLを編集できる構成です。'; $items['xss']['reason']='新規登録者の役割と強いHTML編集権限を見直してください。独自登録処理の追加制約とXSSの実行成立は未確認です。';
    }
    if ($telemetry['available']) foreach (['sql_injection'=>'sql_hits','xss'=>'xss_hits','directory_traversal'=>'traversal_hits'] as $id=>$key) $items[$id]['state'].='現在の入力分類：' . $telemetry[$key] . '件（全文は保存しません）。';
    $vulns=$facts['vulnerabilities'] ?? [];
    if (isset($vulns['checked'])) $items['vulnerability_exploit']['state'].='既知脆弱性照合：' . $vulns['checked'] . '/' . $vulns['total'] . '製品、未確認または条件不明' . $vulns['unknown'] . '件。';
    $mapping=['CWE-89'=>'sql_injection','CWE-79'=>'xss','CWE-352'=>'csrf','CWE-78'=>'rce','CWE-94'=>'rce','CWE-434'=>'file_upload','CWE-22'=>'directory_traversal','CWE-23'=>'directory_traversal','CWE-36'=>'directory_traversal'];
    foreach ($vulns['matches'] ?? [] as $match) {
        $items['vulnerability_exploit']['level']='high'; $items['vulnerability_exploit']['reason']='識別名とインストール版が既知脆弱性の影響条件に一致する候補があります。下の情報源・影響範囲・修正版・必要権限を確認してください。実際の悪用・侵害は未確認です。';
        foreach ($match['cwe'] as $cwe) if (isset($mapping[$cwe])) { $id=$mapping[$cwe]; $items[$id]['level']='high'; $items[$id]['reason']='既知脆弱性データの分類と影響版条件が一致する候補があります。製品の同定・成立条件・修正版を下の詳細で確認してください。攻撃試験は行っていません。'; }
    }
    // Keep classifications from findings beyond the bounded detail list.
    if(!empty($vulns['affected'])) { $items['vulnerability_exploit']['level']='high'; $items['vulnerability_exploit']['reason']='識別名とインストール版が既知脆弱性の影響条件に一致する候補があります。表示件数の上限外も含めて検出しました。製品・成立条件・修正版を照合結果で確認してください。'; }
    foreach($vulns['cwe'] ?? [] as $cwe) if(isset($mapping[$cwe])) { $id=$mapping[$cwe]; $items[$id]['level']='high'; $items[$id]['reason']='影響版候補に該当する既知脆弱性の分類があります。表示件数の上限外も含めた判定です。製品と成立条件を照合結果で確認してください。'; }
    $items=wphm_sc_focus_assessment($facts,$items);
    if(isset($facts['acquisition'])) $items=wphm_sc_source_assessment($facts,$items);
    $items=wphm_sc_extended_assessment($facts,$items);
    return array_values($items);
}
