<?php
if (!defined('ABSPATH')) exit;

/** Add observations, not simulated attacks. A missing required observation never becomes a green bar. */
function wphm_sc_extended_assessment(array $facts, array $items): array {
    $catalog=wphm_sc_catalog(); $selection=wphm_sc_source_selection(); $posture=$facts['posture'] ?? [];
    $public=$facts['public_response'] ?? ['enabled'=>false,'responses'=>[]]; $audit=$facts['audit'] ?? [];
    foreach($catalog as $id=>$definition) {
        if(!isset($items[$id])) $items[$id]=['id'=>$id,'name'=>$definition['name'],'scope'=>'取得できた設定・記録・公開応答の範囲','state'=>'','reason'=>'','level'=>'unknown','checks'=>[],'sources'=>$facts['acquisition']['sources'] ?? []];
        $items[$id]['name']=$definition['name']; $items[$id]['group']=$definition['group'];
    }
    $add=function(string $id,string $label,string $status,string $value,array $places=['wordpress'],string $help='',bool $reference=false,bool $response=false,bool $independent=false) use(&$items,$selection,$public) {
        $enabled=$independent || ($response?!empty($public['enabled']):(bool)array_filter($places,function($place) use($selection){return !empty($selection[$place]);}));
        if(!$enabled) { $status='skip'; $value=$response?'公開応答の確認は未選択です。上部で選び、再チェックしてください。':'今回はこの場所を調査していません。'; }
        $items[$id]['checks'][]=['label'=>$label,'status'=>$status,'value'=>$value,'help'=>$help,'sources'=>$places,'source_confirmed'=>!$response && $enabled && !in_array($status,['pending','skip','error','setup'],true),'reference'=>$reference,'must_confirm'=>($response || $independent) && !$reference,'public_response'=>$response];
    };
    $reviewed=!empty($audit['known']); $wp=!empty($posture['enabled']);
    $audit_value=$reviewed?'WP Helpers Multiの点検対象'.(int)$audit['files'].'ファイルが配布時の記録と一致しています。':'点検対象ファイルとの一致を確認できません。';
    $audit_help='本プラグインの確認対象に限る評価です。他のテーマ・プラグインの処理や未知の欠陥は別に確認が必要です。';

    // 11: roles and the reviewed capability/ownership boundaries, without invoking other products' endpoints.
    $roles_known=!empty($posture['roles_known']) && empty($posture['roles_limited']);
    $add('access_control','標準ロール・新規登録の権限',!empty($posture['public_privileged_role'])?'issue':(!empty($posture['privileged_basic_roles'])?'attention':($roles_known?'ok':'pending')),!empty($posture['public_privileged_role'])?'公開登録者の初期ロールに強い管理・編集権限があります。':'確認した低権限の標準ロールで、強い管理・編集権限を持つもの'.(int)($posture['privileged_basic_roles'] ?? 0).'件。',['wordpress'],'強い権限を意図して付けた場合も用途を確認してください。独自登録・ユーザー個別の例外はこのロール確認に含みません。');
    $add('access_control','本プラグインの操作権限・対象ID',$reviewed?'ok':'pending',$audit_value,['wordpress'],$audit_help);
    $add('access_control','他製品のAPI・所有者確認','pending','他のテーマ・プラグインの権限境界は、対象コードと検証環境で確認が必要です。',['wordpress'],'APIの存在やnonceの有無だけで、操作権限・所有者確認の実施済みとは判定しません。',true);

    // 12: the WordPress policy and aggregate metadata, never cookie values or session identifiers.
    $policy=$posture['cookie_policy'] ?? []; $sessions=$posture['session'] ?? [];
    $add('session','WordPress標準の認証Cookie方針',empty($policy['known'])?'pending':(!empty($policy['secure_auth']) && !empty($policy['secure_logged_in'])?'ok':'attention'),empty($policy['known'])?'独自の認証Cookie・トークン処理があるか、標準方針を確認できません。':(!empty($policy['secure_logged_in'])?'標準処理でSecure・HttpOnlyを使用する方針を確認しました。':'標準の認証CookieにSecureを使用しない条件があります。'),['wordpress'],'現在の要求に対する標準処理の方針です。Cookieを発行せずに確認します。SameSiteの実応答、全ログイン経路・独自処理への適用は別です。');
    $add('session','現在の管理者のセッション集計',($sessions['status'] ?? '')==='ok'?'ok':'pending',($sessions['status'] ?? '')==='ok'?'期限内'.(int)$sessions['active'].'件／期限切れ'.(int)$sessions['expired'].'件。':'標準セッションの有効な集計は未取得です。',['wordpress'],'現在の管理者だけの集計です。最大64KiB・100件。識別子・Cookie・IP・端末名は結果に保存せず、他人のセッションを操作しません。',true);

    // 13: the HTTP boundaries reviewed in this distribution and the existing egress policy.
    $add('ssrf','本プラグインのHTTP取得先・転送制限',$reviewed?'ok':'pending',$audit_value,['wordpress'],$audit_help.' 安全なHTTP API、取得先・転送先、公開IPと接続IP固定、サイズ・時間の上限を点検しています。');
    $add('ssrf','WordPressの外向き通信方針',$wp?'ok':'pending',!empty($posture['http_blocked'])?'WP_HTTP_BLOCK_EXTERNALが有効です。許可先・独自通信は別に確認します。':'WordPress全体の外向き通信を一括遮断する設定は有効ではありません。',['wordpress'],'一括遮断がないことをSSRFの成立とは扱いません。通常の外部連携もあるため、各通信処理の取得先検証が必要です。',true);

    // 16: metadata of a small fixed set of files, no secret-body probes or filesystem crawling.
    $files=$posture['public_files'] ?? [];
    $add('information_exposure','公開領域のバックアップ・ログ候補',!empty($files['candidates'])?'attention':(!empty($files['links'])?'pending':(!empty($files['known'])?'ok':'pending')),'固定'.(int)($files['checked'] ?? 0).'箇所／存在候補'.(int)($files['candidates'] ?? 0).'件／未追跡リンク等'.(int)($files['links'] ?? 0).'件。',['wordpress'],'配置メタ情報だけを確認します。存在は外部から取得できる証拠ではありません。ファイルの中身・秘密URLを取得せず、全ファイルの調査もしません。');
    $add('information_exposure','WordPressのエラー画面表示',!empty($posture['debug_display'])?'attention':($wp?'ok':'pending'),!empty($posture['debug_display'])?'デバッグ時の画面表示が有効な構成です。':'標準のデバッグ画面表示は有効になっていません。',['wordpress'],'独自の例外出力やWebサーバーのエラー画面は別の確認範囲です。');

    // 17: keep known-vulnerability checks in item 9; this item examines origin/integrity metadata.
    $distribution=$posture['distribution'] ?? [];
    $add('supply_chain','本プラグインのファイル整合性',$reviewed?'ok':(!empty($audit['changed'])?'attention':'pending'),$audit_value,['wordpress'],'記録との一致は未知の欠陥や配布元の侵害がない証明ではありません。意図した編集も不一致になります。');
    $bad_source=!empty($distribution['insecure_sources']) || !empty($distribution['insecure_offers']);
    $add('supply_chain','登録・保存済みの更新経路',$bad_source?'attention':($wp && empty($distribution['limited'])?'ok':'pending'),'HTTPの独自配布元'.(int)($distribution['insecure_sources'] ?? 0).'件／安全なHTTPS URLを確認できない保存済み配布先'.(int)($distribution['insecure_offers'] ?? 0).'件。',['wordpress'],'製品ごと200件まで。保存済み更新情報は対象版・24時間の期限を確認します。URL・署名付きクエリは表示せず、ZIPは取得・実行しません。');
    $add('supply_chain','他製品・外部スクリプトの整合性','pending','本体・他のテーマ／プラグイン・外部スクリプトの全配布物は、署名・チェックサム・配布元の確認が必要です。',['wordpress','edge'],'このプラグインのファイル一致を、他製品や更新経路全体の信頼性へ拡大しません。',true);

    // 18: no attacker-supplied redirect parameter is sent; only normal page redirects are observed below.
    $add('redirect','本プラグインの転送先検証',$reviewed?'ok':'pending',$audit_value,['wordpress'],$audit_help.' 外部転送を誘発するパラメータや試験URLは送りません。');
    $add('transport','WordPressのHTTPS URL設定',!empty($facts['https'])?'ok':'attention',!empty($facts['https'])?'サイトURL・WordPress URLはHTTPSです。':'HTTPSでないURL設定があります。',['wordpress'],'設定値の確認です。実際の証明書・公開応答は下の取得結果で別に確認します。');

    $public_places=['wordpress','server','edge'];
    $http_help='公開トップ・標準ログインページへの未ログインGET応答だけです。設定を付与した層は応答だけでは特定できません。値・本文は保存せず、設定変更は行いません。';
    foreach(['home'=>'公開トップ','login'=>'ログインページ'] as $kind=>$name) {
        $row=$public['responses'][$kind] ?? wphm_sc_public_row(); $known=$row['status']==='ok';
        $missing=$row['status']==='error'?'error':'pending';
        $missing_value=$row['status']==='error'?wphm_sc_public_reason($row['reason']):'このページの公開応答は、まだ取得できていません。';
        $response=function($id,$label,$status,$value,$help='',$reference=false) use($add,$name,$known,$missing,$missing_value,$public_places,$http_help) { $add($id,$name.'：'.$label,$known?$status:$missing,$known?$value:$missing_value,$public_places,$http_help.' '.$help,$reference,true); };

        // 14: report-only CSP is never counted as enforced framing protection.
        $frame_ok=in_array($row['frame'],['deny','self'],true);
        $response('clickjacking','画面の埋め込み制限',!$row['html']?'pending':($frame_ok?'ok':'attention'),!$row['html']?'HTML画面の制限として評価できる応答ではありません。':($frame_ok?'取得した応答は外部サイトからの埋め込みを制限しています。':'制限が未設定・不明確、または外部からの埋め込みを許可する設定です。'),'強制されるCSP frame-ancestorsを優先し、なければX-Frame-Optionsを確認します。用途により外部埋め込みを許可する場合は設定内容を確認してください。');

        // 15: a successful TLS-verified request is narrower than a complete TLS configuration audit.
        $response('transport','HTTPSと証明書検証',$row['https'] && $row['tls_verified']?'ok':'attention',$row['https'] && $row['tls_verified']?'今回のHTTPS接続で証明書の検証に成功しました。':'今回の応答に対するHTTPS・証明書検証を確認できません。');
        $expiry=$row['cert_expires'];
        $response('transport','証明書の期限',$expiry===0?'pending':($expiry<=time()?'issue':($expiry<time()+14*DAY_IN_SECONDS?'attention':'ok')),$expiry===0?'接続で使用した証明書の期限情報は未取得です。':'接続で使用した証明書の期限：'.wphm_format_timestamp($expiry),'cURLから得た今回の証明書の期限です。自動更新の成功や別の配信拠点の証明書は未確認です。');
        $response('transport','HSTS',$row['https'] && $row['hsts_present'] && $row['hsts']>0?'ok':'attention',$row['hsts']>0?'取得したHTTPS応答のmax-ageは'.$row['hsts'].'秒です。':'有効なHSTSのmax-ageを確認できません。','導入前にHTTPS運用を確認してください。サブドメインやpreloadへの一律適用は求めません。');

        // 12: do not mistake a WordPress test cookie or an analytics cookie for an authentication cookie.
        $cookies=array_values(array_filter($row['cookies'],function($cookie){return in_array($cookie['kind'],['wp_auth','php_session'],true);})); $unsafe=false;
        $auth_cookie=(bool)array_filter($cookies,function($cookie){return $cookie['kind']==='wp_auth';});
        foreach($cookies as $cookie) if(!$cookie['secure'] || !$cookie['http_only'] || in_array($cookie['same_site'],['missing','invalid'],true)) $unsafe=true;
        $response('session','セッション用Cookie候補の属性',$row['cookies_limited']?'pending':($cookies?($unsafe?'attention':'ok'):'pending'),$cookies?'候補'.count($cookies).'件。'.($unsafe?'Secure・HttpOnly・SameSiteの設定を見直す候補があります。':'取得した候補にSecure・HttpOnly・SameSiteが指定されています。'):'今回の未ログイン応答では認証・PHPセッション用Cookie候補が発行されていません。','WordPress標準名・PHPSESSIDだけを分類します。PHPセッションの用途や認証後のCookieは別途確認します。SameSite未指定は攻撃成立を意味しません。',!$auth_cookie);
        $same_site=$cookies && !$row['cookies_limited'];
        foreach($cookies as $cookie) if(!in_array($cookie['same_site'],['lax','strict'],true)) $same_site=false;
        $response('csrf','CookieのSameSite補助対策',$cookies?($same_site?'ok':'attention'):'pending',$same_site?'取得したセッション用Cookie候補にLax／Strictが指定されています。':'候補が未取得、またはLax／Strict以外の設定を含みます。','SameSiteは補助対策です。nonce・操作権限・対象操作の検証の代用にはしません。',true);

        // 19: anonymous cache observations cannot prove authenticated cache separation.
        $session_cookie=(bool)$cookies;
        $cache_warning=($kind==='login' || $session_cookie) && ($row['cache']==='shared' || $row['cache_age']>0) && $row['cache']!=='private';
        $response('cache','キャッシュ方針',$cache_warning?'attention':($row['cache']==='unspecified'?'pending':'ok'),$cache_warning?'ログイン画面・セッション用Cookie候補を含む応答に共有キャッシュの確認が必要です。':($row['cache']==='private'?'private／no-storeの方針を取得しました。':($row['cache']==='revalidate'?'再検証を求める方針を取得しました。':($row['cache']==='shared'?'未ログインの公開応答に共有キャッシュの方針があります。':'明示的なキャッシュ方針は未取得です。'))),'Cookieの存在だけで個人別の本文や情報漏えいとは断定しません。キャッシュキーの全体は別に確認が必要です。');

        // 20: browsers reject '*' plus credentials; do not call that combination a proven data leak.
        $cors_warning=in_array($row['cors'],['invalid','null'],true) || ($row['cors_credentials'] && in_array($row['cors'],['wildcard','external'],true));
        $cors_text=['none'=>'今回の応答に他サイトへの読取り許可ヘッダーはありません。','same-site'=>'同じオリジンへの読取り許可を取得しました。','wildcard'=>'すべてのオリジンへの読取り許可があります。','external'=>'別のオリジンへの読取り許可があります。','null'=>'nullオリジンへの読取り許可があります。','invalid'=>'CORSヘッダーの形式・組合せの確認が必要です。'][$row['cors']];
        if($row['cors']==='wildcard' && $row['cors_credentials']) $cors_text.=' 「*」と認証情報付き許可の組合せはブラウザが拒否します。情報窃取の成立とは判定しません。';
        $response('cors','他サイトからの読取り許可',$cors_warning?'attention':'ok',$cors_text,'公開応答でのワイルドカードは用途次第です。Originで変わる応答・認証後のAPI・許可先の信頼性は別に確認します。');

        // Public redirects that stopped before an external/query-bearing URL remain visible as observations.
        if($row['external_redirect'] || $row['query_redirect']) $add('redirect',$name.'の通常転送','attention','サイト外・クエリ等を含む転送先には接続せず停止しました。',$public_places,$http_help.' 通常の外部転送やSSOの場合もあるため、オープンリダイレクト成立の証拠ではありません。',true,true);
        else $response('redirect','通常転送の追跡','ok','今回の通常取得で、許可外への転送は追跡していません。','最大2段階。任意パラメータでの転送先検証の代用にはなりません。',true);

        $response('xss','スクリプト用CSP',$row['csp']==='restricted'?'ok':($row['csp']==='invalid'?'pending':'attention'),$row['csp']==='restricted'?'取得した強制CSPに、確認対象の広い実行許可は見つかりません。':'スクリプト用の強制CSPが未設定・不明確、または広い許可を含みます。','CSPの補助的な確認です。出力処理の欠陥の有無やポリシー全体の有効性は別です。',true);
        $response('file_upload','Content-Typeの補助制限',$row['nosniff']?'ok':'attention',$row['nosniff']?'X-Content-Type-Options: nosniffを取得しました。':'nosniffは取得した応答で確認できません。','画像・アップロードされたファイルそのものの応答は取得していません。',true);
        $response('information_exposure','参照元情報の制限',$row['referrer']==='strict'?'ok':($row['referrer']==='invalid'?'pending':'attention'),$row['referrer']==='strict'?'取得した応答に、参照元情報を制限する方針があります。':'明示的な方針が未設定・不明確、または広い参照元送信を許可します。','ブラウザの既定動作やリンク単位の設定は別に扱います。',true);
    }
    $add('cache','認証後のキャッシュ分離','pending','認証後の本文を取得せず、個人別応答とキャッシュキーの分離は未確認として残します。',['wordpress','server','edge'],'キャッシュ設定・CDNのキー構成と、隔離環境での確認が必要です。',true);
    $add('transport','TLSの全許可構成','pending','今回のHTTPS接続以外に許可するTLS方式・暗号方式は未確認です。',['server','edge'],'対応する読取り連携や外部診断の結果で確認します。攻撃・負荷試験は行いません。',true);

    $mapping=wphm_sc_cwe_mapping(); $affected=[];
    foreach($facts['vulnerabilities']['cwe'] ?? [] as $cwe) if(isset($mapping[$cwe])) $affected[$mapping[$cwe]]=true;
    foreach($facts['vulnerabilities']['matches'] ?? [] as $match) foreach($match['cwe'] ?? [] as $cwe) if(isset($mapping[$cwe])) $affected[$mapping[$cwe]]=true;
    foreach(array_keys($affected) as $id) if(!in_array('関連する既知脆弱性の影響版',array_column($items[$id]['checks'],'label'),true)) $add($id,'関連する既知脆弱性の影響版','issue','製品・版・分類が関連する既知脆弱性の影響候補に一致します。',['wordpress'],'製品同定・必要条件・修正版は詳細で確認してください。実際の悪用・侵害は未確認です。',false,false,true);
    $ordered=[];
    foreach($catalog as $id=>$definition) {
        $items[$id]['level']=wphm_sc_checks_level($items[$id]['checks']);
        $items[$id]['reason']=$items[$id]['level']==='low'?'低は取得できた確認項目の対象だけの評価です。サイト全体の安全や侵害がないことを保証しません。':'取得できた確認項目の範囲の評価です。未確認・参考情報は安全の証明に使用しません。';
        $items[$id]['result_status']='確認項目の状態から判定'; $ordered[$id]=$items[$id];
    }
    return $ordered;
}
