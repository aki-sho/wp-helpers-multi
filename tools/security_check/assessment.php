<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_check_labels(): array {
    return ['ok'=>'確認済み','attention'=>'要確認','issue'=>'要対応','setup'=>'要設定','error'=>'取得失敗','pending'=>'未確認・計測中','unavailable'=>'確認範囲外','skip'=>'未選択','manual'=>'管理者メモ'];
}

/** Separate an observed result from missing settings and unavailable evidence. */
function wphm_sc_focus_assessment(array $facts, array $items): array {
    $identity=$facts['identity'] ?? []; $t=$facts['telemetry'] ?? []; $f=$facts['files'] ?? [];
    $v=$facts['vulnerabilities'] ?? []; $cf=$facts['cloudflare'] ?? []; $settings=wphm_sc_options();
    $add=function(string $id,string $label,string $status,string $value,string $help='') use (&$items): void {
        $items[$id]['checks'][]=compact('label','status','value','help');
    };
    $set=function(string $id,string $level,string $reason) use (&$items): void {
        if($items[$id]['level']!=='high') { $items[$id]['level']=$level; $items[$id]['reason']=$reason; }
    };
    foreach(['brute_force','credential_stuffing','ddos','file_upload','vulnerability_exploit'] as $id) $items[$id]['checks']=[];

    $limiter=$identity['limiter'] ?? null;
    $items['brute_force']['scope']='読み取れたログイン試行制限の設定・記録した認証失敗';
    $add('brute_force','ログイン試行制限',$limiter ? 'ok' : (!empty($identity['limiter_error'])?'error':'setup'),$limiter ? $limiter['name'].'：'.$limiter['tries'].'回／停止'.$limiter['seconds'].'秒' : '制限設定を取得できていません', '対応するログイン制限機能の設定を確認してください。Limit Login Attempts / Reloaded、Wordfence、ローカル連携を読み取ります。未取得だけで制限なしとは断定しません。');
    $measured=!empty($t['available']); $auth=(int)($t['failures'] ?? 0)+(int)($t['successes'] ?? 0);
    $add('brute_force','認証記録',$measured && $auth>0 ? 'ok' : 'pending',$measured ? '計測開始後の失敗'.($t['failures'] ?? 0).'件、パスワード段階の成功'.($t['successes'] ?? 0).'件' : '計測を開始するための情報を準備中', '実際に発生した認証イベントを記録します。試験的なログイン操作は不要です。成功には2FA前の段階を含みます。');
    $concentrated=$measured && (($t['auth_peak'] ?? 0)>=$settings['auth_failures'] || ($t['focused_failures'] ?? 0)>=$settings['auth_focus']);
    $add('brute_force','失敗の集中',$concentrated ? 'attention' : ($measured ? 'ok' : 'pending'),$concentrated ? '注意基準に達した認証失敗があります' : ($measured ? '記録した範囲で注意基準に達していません' : 'まだ記録を参照できません'), '基準：1分'.$settings['auth_failures'].'件、または5分の共有枠で'.$settings['auth_focus'].'件。共有回線・枠の衝突もあるため攻撃成立とは判定しません。');
    $set('brute_force',$concentrated ? 'medium' : ($limiter ? 'low' : 'unknown'),$concentrated ? '記録した認証失敗に集中があります。記録と制限設定を確認してください。' : ($limiter ? '登録中の認証処理から試行制限の実設定を取得しました。低はこの設定の確認範囲です。除外条件・別の認証経路は別途確認します。' : '試行制限の設定を読み取れていません。下の確認項目で、計測結果と必要な設定を分けて表示しています。'));

    $admins=(int)($identity['admins'] ?? 0); $known=(int)($identity['mfa_known'] ?? 0); $enabled=(int)($identity['mfa_enabled'] ?? 0);
    $all_mfa=$admins>0 && $known===$admins && $enabled===$admins && empty($identity['limited']) && empty($identity['mfa_error']);
    $missing_mfa=$known>$enabled;
    $items['credential_stuffing']['scope']='確認対象の管理者の標準ログイン・追加認証の設定';
    $add('credential_stuffing','管理者の追加認証',!empty($identity['mfa_error']) ? 'error' : ($all_mfa ? 'ok' : ($missing_mfa ? 'attention' : 'setup')),'対象'.$admins.'人／設定を取得'.$known.'人／有効'.$enabled.'人', 'Two-FactorまたはWordfence Login Securityの設定を読みます。対応外の認証機能は有効・無効を推測しません。');
    $add('credential_stuffing','WordPressのURL設定',!empty($facts['https']) ? 'ok' : 'attention',!empty($facts['https']) ? 'サイトURL・WordPress URLがHTTPSです' : 'HTTPSでないURL設定があります','URL設定の確認です。TLS証明書やプロキシ設定の完全な検証ではありません。');
    $app_users=(int)($identity['app_password_users'] ?? 0);
    $add('credential_stuffing','別の認証経路',$app_users>0 ? 'attention' : 'unavailable','アプリケーションパスワードの登録を確認した利用者'.$app_users.'人／XML-RPC認証設定'.(!empty($identity['xmlrpc_auth'])?'有効':'無効'), 'API認証・SSO・独自ログイン全体への2FAの適用は標準ログインと分けて確認してください。');
    $after=$measured && ($t['after_failure'] ?? 0)>0;
    $add('credential_stuffing','失敗集中後の認証',$after ? 'attention' : ($measured ? 'ok' : 'pending'),$measured ? 'パスワード段階の成功候補'.($t['after_failure'] ?? 0).'件' : '計測開始後の記録待ち','共有枠の概数です。使い回しや漏えいパスワードそのものは収集・解析しません。');
    $set('credential_stuffing',$missing_mfa || $after || ($known>0 && empty($facts['https'])) || $app_users>0 ? 'medium' : ($all_mfa && !empty($facts['https']) ? 'low' : 'unknown'),$all_mfa && !$after && $app_users===0 && !empty($facts['https']) ? '確認範囲の管理者に追加認証が有効で、URL設定もHTTPSです。低は標準ログインの設定確認に限ります。全経路の認証耐性や使い回しの有無の判定ではありません。' : '管理者ごとの追加認証設定・別経路・記録を分けて表示します。設定を取得できない場合は要設定、連携処理の失敗は取得失敗と表示します。');

    $requests=(int)($t['requests'] ?? 0); $errors=(int)($t['errors'] ?? 0);
    $error_rate=$requests>0 ? 100*$errors/$requests : 0;
    $baseline=(int)($t['baseline_minutes'] ?? 0); $base_requests=(int)($t['baseline_requests'] ?? 0); $recent=(int)($t['recent_requests'] ?? 0);
    $surge=$baseline>=60 && $base_requests>=100 && $recent>=100 && $recent/10>=$base_requests/$baseline*5;
    $busy=$measured && (($t['peak'] ?? 0)>=$settings['traffic_peak'] || $errors>=$settings['http_errors'] || ($requests>=100 && $error_rate>=$settings['error_percent']) || !empty($t['saturated']) || $surge);
    $items['ddos']['scope']='計測開始後にWordPressへ到達した通信の量・HTTPエラー';
    $add('ddos','通信量・HTTPエラー',$measured && $requests>0 ? ($busy?'attention':'ok') : 'pending',$measured ? $requests.'件／記録した分の最大'.($t['peak'] ?? 0).'件／5xx '.$errors.'件' : '計測開始後の通信待ち','基準：1分'.$settings['traffic_peak'].'件、5xx '.$settings['http_errors'].'件、または100件以上の通信で5xx率'.$settings['error_percent'].'％。到達前の遮断・回線状態は含みません。');
    $add('ddos','通常時との比較',$baseline>=60 && $base_requests>=100 ? ($surge?'attention':'ok') : 'pending',$baseline>=60 && $base_requests>=100 ? '直近10分と、それ以前の最大6時間を比較'.($surge?'：5倍以上の増加':'：増加の注意基準未検出') : '比較用の期間・通信数が不足しています','比較は60分以上・100件以上の基準期間がある場合だけ行います。');
    $add('ddos','CDN・WAFの情報',empty($cf['configured']) ? 'setup' : (!empty($cf['known'])?'ok':'error'),$cf['state'] ?? '連携が未設定です','Cloudflareを利用している場合は、この画面の連携設定にZone IDと読取りトークンを登録してください。');
    if(!empty($cf['known'])) {
        $add('ddos','CDN通信量',($cf['requests'] ?? null)===null ? 'error' : 'ok',($cf['requests'] ?? null)===null ? '通信量を取得できませんでした' : '完了した24時間で'.$cf['requests'].'件','契約・権限で取得できない統計は、取得できた情報と分けて表示します。');
        $add('ddos','管理WAFの呼出設定',($cf['waf_rules'] ?? null)===null ? 'error' : 'ok',($cf['waf_rules'] ?? null)===null ? '管理WAFの呼出設定を取得できませんでした' : '有効な呼出ルール'.$cf['waf_rules'].'件','ルール数だけで遮断効果・他の防御設定の有無は判断しません。');
        $add('ddos','WAFの遮断記録',($cf['blocked'] ?? null)===null ? 'error' : 'ok',($cf['blocked'] ?? null)===null ? '遮断記録を取得できませんでした' : '直近のサンプル'.($cf['events'] ?? 0).'件中、遮断'.$cf['blocked'].'件','取得した最大100件のサンプルです。遮断件数だけからDDoSの発生・耐性は断定しません。');
    }
    $set('ddos',$busy ? 'medium' : ($measured && $requests>0 ? 'low' : 'unknown'),$busy ? '計測した通信に量・エラー・増加の注意基準が見つかりました。通常のアクセス増も含むため、DDoS成立の判定とは区別してください。' : ($measured && $requests>0 ? '記録した通信量とHTTPエラーは設定した注意基準に達していません。低はこの観測範囲の評価です。DDoSへの耐性全体を保証するものではありません。' : 'まだ通信の記録を評価できません。計測待ちと、CDN連携の設定・取得状態を下に分けて表示します。'));

    $types_known=!empty($f['types_checked']); $exec=!empty($f['executable_types']); $scripts=(int)($f['scripts'] ?? 0);
    $items['file_upload']['scope']='現在の利用者に適用されるWordPress標準のアップロード設定';
    $add('file_upload','標準の種類制限',$types_known ? ($exec?'attention':'ok') : 'error',$types_known ? ($exec ? '実行形式が許可されています：'.implode(', ',$f['executable_types']) : '確認した実行形式は標準の種類制限で許可されていません') : '種類制限を取得できませんでした','固定のファイル名をWordPressの種類判定APIで確認します。ファイルの作成・送信・実行は行いません。');
    $add('file_upload','未検査アップロード',!empty($facts['unfiltered_uploads']) ? 'attention':'ok',!empty($facts['unfiltered_uploads']) ? 'ALLOW_UNFILTERED_UPLOADSが有効です':'ALLOW_UNFILTERED_UPLOADSは有効になっていません');
    $add('file_upload','最大受信サイズ',($f['upload_bytes'] ?? 0)>0 ? 'ok':'error',($f['upload_bytes'] ?? 0)>0 ? number_format($f['upload_bytes']).'バイト':'有効な上限値を取得できませんでした');
    $add('file_upload','保存先の限定読取り',empty($f['scan_available']) ? 'error' : ($scripts>0 || !empty($f['limited']) ? 'attention':'ok'),empty($f['scan_available']) ? '読取りできませんでした' : '確認'.($f['scanned'] ?? 0).'項目／スクリプト形式'.$scripts.'件', '最大40ディレクトリ・500項目。シンボリックリンクは追わず、ファイルの悪意・実行可否は判定しません。');
    $server=$facts['server'] ?? []; $valid=($server['attested_at'] ?? 0)>0 && $server['attested_at']<=time()+60 && $server['attested_at']>=time()-7*DAY_IN_SECONDS;
    $manual=$valid ? ($server['uploads_execution'] ?? 'unknown') : 'unknown';
    $add('file_upload','サーバーの実行禁止',$manual==='yes' ? 'ok' : ($manual==='no'?'attention':'setup'),$manual==='unknown' ? '管理者の確認情報が未登録・期限切れです' : '管理者の確認：'.($manual==='yes'?'実行禁止あり':'実行禁止なし'), '管理者申告は自動検証と区別して表示します。下の設定欄で確認根拠を記録できます。');
    $upload_warn=!empty($facts['unfiltered_uploads']) || $exec || $scripts>0;
    $set('file_upload',$upload_warn ? 'medium' : (($facts['file_uploads'] ?? null)===false || ($types_known && ($f['upload_bytes'] ?? 0)>0) ? 'low':'unknown'),$upload_warn ? '標準の種類制限・未検査アップロード設定・保存先に見直す項目があります。下の項目で根拠を確認してください。' : (($facts['file_uploads'] ?? null)===false ? 'PHP標準のファイル受信は無効です。低はこの受信経路に限る判定です。独自受付とサーバーの実行可否は別に確認します。' : 'WordPress標準の種類制限とサイズ上限を確認しました。低はこの利用者に適用される標準設定の範囲です。独自受付とサーバー側の実行可否は別項目で示します。'));

    $eligible=(int)($v['eligible'] ?? 0); $checked=(int)($v['checked'] ?? 0); $pending=(int)($v['pending'] ?? 0); $failed=(int)($v['failed'] ?? 0); $unsupported=(int)($v['unsupported'] ?? 0); $conditional=(int)($v['conditional'] ?? 0);
    $complete=$eligible>0 && $checked===$eligible && $pending===0 && $failed===0 && $conditional===0 && empty($v['limited']);
    $items['vulnerability_exploit']['scope']='照合対象の製品・版についての既知脆弱性情報と更新待ち';
    $add('vulnerability_exploit','既知脆弱性の照合',empty($v['enabled']) ? 'setup' : ($failed>0 ? 'error' : ($pending>0?'pending':($complete?'ok':'attention'))),'対象'.$eligible.'製品／応答取得'.$checked.'／未照合'.$pending.'／取得失敗'.$failed,empty($v['enabled']) ? '下の設定で既知脆弱性の照合を有効にしてください。':'再チェックから残りの製品を自動照合します。個別の結果と失敗理由を詳細に表示します。');
    $affected=(int)($v['affected'] ?? count($v['matches'] ?? []));
    $add('vulnerability_exploit','影響版への該当',$affected>0 ? 'issue' : ($complete ? 'ok':'pending'),$affected>0 ? '影響版候補'.$affected.'件（詳細は最大100件）' : ($complete ? '照合が完了した対象に該当はありません':'照合した範囲の候補は詳細に表示します'),'該当なしは、未知の脆弱性や侵害がないことの証明ではありません。');
    $add('vulnerability_exploit','照合できない製品',$unsupported>0 || !empty($v['limited']) ? 'unavailable':'ok','独自配布・MU等 '.$unsupported.'製品'.(!empty($v['limited']) ? '／処理上限あり':''),'対象外製品を安全と扱いません。製品ごとの理由を下の詳細に表示します。');
    $updates=$facts['updates']; $waiting=!empty($updates['core']['pending']) || !empty($updates['plugins']['pending']) || !empty($updates['themes']['pending']);
    $update_unknown=(int)$updates['plugins']['unknown']+(int)$updates['themes']['unknown']+(!empty($updates['core']['known'])?0:1);
    $add('vulnerability_exploit','公式更新情報',$waiting?'attention':($update_unknown>0?'unavailable':'ok'),$waiting ? '未適用の更新があります' : '取得した更新情報に更新待ちはありません', '更新情報の未取得・独自配布等 '.$update_unknown.'件。更新待ちと、既知脆弱性への該当は別の情報です。');
    if($waiting || !empty($v['closed'])) $set('vulnerability_exploit','medium','未適用更新、または配布停止情報があります。照合結果と各配布元の案内を確認してください。');
    elseif($complete) $set('vulnerability_exploit','low','照合対象の製品・版について、取得した既知脆弱性情報への該当は見つかりませんでした。低は照合が完了した対象だけの評価です。対象外製品・未知の欠陥・侵害の有無は含みません。');

    foreach(['brute_force','credential_stuffing','ddos','file_upload','vulnerability_exploit'] as $id) {
        $labels=wphm_sc_check_labels(); $items[$id]['result_status']='確認結果あり';
        foreach(['error','setup','pending','unavailable','attention','issue'] as $status) {
            if(in_array($status,array_column($items[$id]['checks'],'status'),true)) { $items[$id]['result_status']=$labels[$status].'の項目あり'; break; }
        }
    }
    return $items;
}
