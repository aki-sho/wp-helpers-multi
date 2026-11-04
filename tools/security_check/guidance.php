<?php
if (!defined('ABSPATH')) exit;

/** Location tags contain only the three names requested for the interface. */
function wphm_sc_location_tags(array $check): array {
    $tags=[]; $names=wphm_sc_source_names();
    foreach($check['sources'] ?? ['wordpress'] as $source) if(isset($names[$source]) && !in_array($names[$source],$tags,true)) $tags[]=$names[$source];
    return $tags;
}

/** Next actions describe existing controls; they never install or enable a defence. */
function wphm_sc_check_actions(): array {
    return [
        'ログイン試行制限（標準ログイン）'=>'WordPress管理画面の「プラグイン」→「インストール済みプラグイン」でログイン保護に使っている機能を確認し、その機能の「設定」から標準ログインへの適用・許可回数・計数期間・停止時間を確認してください。本画面の判定基準は、実際のログイン制限を設定する欄ではありません。',
        '管理者の追加認証'=>'WordPress管理画面の「ユーザー」で対象管理者のプロフィールを開き、利用中の追加認証機能の設定を確認してください。対応していない認証機能の設定は、この画面を待っても取得されません。',
        '別の認証経路'=>'WordPress管理画面の「ユーザー」→対象利用者のプロフィールでアプリケーションパスワードを確認し、利用中の認証機能でXML-RPC・SSO・独自ログインへの適用を確認してください。',
        '認証処理の発見'=>'WordPress管理画面の「プラグイン」と、サイトで使用するMUプラグイン・テーマの認証処理を確認してください。処理の発見だけでは設定値を取得できません。',
        '標準の種類制限'=>'WordPressで使用中のアップロード制御機能・MIME種類の変更処理を確認してください。独自の受信処理は、その機能の設定とコードの確認が必要です。',
        '未検査アップロード'=>'WordPressの利用者権限と、wp-config.phpのALLOW_UNFILTERED_UPLOADSの設定を確認してください。',
        '最大受信サイズ'=>'サーバー管理画面のPHP設定でupload_max_filesize・post_max_sizeを確認し、WordPressのアップロード上限を変更する機能の設定も確認してください。',
        '保存先の限定読取り'=>'WordPressのアップロード保存先の読取り権限を確認してください。この確認は固定位置・件数上限内のメタ情報だけで、全ファイルの検査ではありません。',
        '保存先のスクリプト実行禁止'=>'サーバー管理画面のWebサーバー設定で、アップロード保存先のPHP等の実行可否を確認してください。この値を返す対応読取り連携がなければ、本画面から自動取得できません。',
        'サーバーの公開範囲'=>'サーバー管理画面の公開ディレクトリ・アクセス制限を確認してください。実効設定を返す読取り連携がなければ、接続情報の入力だけで自動取得できる項目ではありません。',
        '提供元の独自修正'=>'サーバー管理画面または提供会社のPHPサポート情報で、使用中のPHPに対する独自修正の提供状況を確認してください。対応する読取り情報が必要です。',
        'ローカルPHP環境'=>'上部の「サーバー側」を選び、「接続設定」→「取得方法」で「ローカルPHP情報」を選択して「再チェック」を押してください。この取得方法はログイン制限の設定を読みません。',
        'サーバーの通信・負荷'=>'サーバー管理画面のアクセス統計・負荷統計を確認してください。本画面で自動取得するには、通信統計を返す読取り連携が必要です。ローカルPHP情報とcPanelのModSecurity読取りは、通信・負荷統計を取得する方法ではありません。',
        '外部の通信統計'=>'上部の「外部サービスの接続設定」で利用中の取得方法・対象サイトの接続情報・統計の読取り権限を確認し、「再チェック」を押してください。外部サービスの管理画面でも対象サイトのアクセス統計を確認できます。',
        '関連する外部の防御設定'=>'利用中の外部サービスのWAF・セキュリティ設定画面で、この攻撃分類のルールと例外を確認してください。選択中の連携がルール別の有効・無効を返さない場合、接続情報の入力や待機だけでは自動判定できません。',
        '実行コードの編集・追加権限'=>'WordPress管理画面の「ユーザー」で付与権限を確認し、wp-config.phpのDISALLOW_FILE_EDIT・DISALLOW_FILE_MODSと運用上必要な権限を確認してください。',
        '標準ロール・新規登録の権限'=>'WordPress管理画面の「設定」→「一般」の「新規ユーザーのデフォルト権限グループ」と、権限を変更する機能の設定を確認してください。',
        '他製品のAPI・所有者確認'=>'使用中のテーマ・プラグインの提供元に、APIの操作権限・対象データの所有者確認の実装を確認してください。検証環境でのコード点検が必要で、本画面の設定を埋めても確認済みにはなりません。',
        'WordPress標準の認証Cookie方針'=>'WordPressのHTTPS設定と、認証Cookieを変更する認証機能・MUプラグイン・テーマの設定を確認してください。独自Cookieの方針は標準処理の読取りからは判定できません。',
        '現在の管理者のセッション集計'=>'WordPress管理画面の「ユーザー」→「プロフィール」で現在の管理者のセッションを確認してください。独自形式や読取り上限を超える記録は、この集計では取得できません。',
        'WordPressの外向き通信方針'=>'wp-config.phpのWP_HTTP_BLOCK_EXTERNAL・WP_ACCESSIBLE_HOSTSと、外部通信を変更する機能の設定を確認してください。通常の外部連携もあるため、一律の遮断を必須とはしません。',
        '公開領域のバックアップ・ログ候補'=>'サーバーのファイル管理で、公開領域のバックアップ・ログの配置とアクセス制限を確認してください。この機能は秘密ファイルの内容や公開URLを取得しません。',
        'WordPressのエラー画面表示'=>'WordPressのwp-config.phpでWP_DEBUG・WP_DEBUG_DISPLAYを確認してください。独自のエラー出力とサーバーのエラー画面は、各機能・サーバー側で確認してください。',
        '登録・保存済みの更新経路'=>'WordPress管理画面の「ダッシュボード」→「更新」と、使用する製品の公式配布元を確認してください。独自配布製品は、その製品の更新設定を確認してください。',
        '他製品・外部スクリプトの整合性'=>'WordPress本体・各テーマ・プラグイン・外部スクリプトの公式配布元でチェックサムや署名を照合してください。本プラグインの整合性確認だけでは他製品を確認できません。',
        'WordPressのHTTPS URL設定'=>'WordPress管理画面の「設定」→「一般」でWordPressアドレス・サイトアドレスを確認し、サーバーの証明書・HTTPS転送設定と対応させてください。',
        'WordPressのURL設定'=>'WordPress管理画面の「設定」→「一般」でWordPressアドレス・サイトアドレスを確認し、サーバーのHTTPS設定と対応させてください。',
        '認証後のキャッシュ分離'=>'使用中のキャッシュ機能・サーバー・CDNの設定画面で、ログインCookieによる除外とキャッシュキーを確認してください。認証後の応答は隔離環境で確認し、未ログイン応答だけで確認済みにはしません。',
        'TLSの全許可構成'=>'サーバーまたはCDNのSSL/TLS設定画面で、許可するTLS方式・暗号方式を確認してください。今回のHTTPS接続だけでは全許可構成を取得できません。',
        '画面の埋め込み制限'=>'ヘッダーを設定するWordPressの機能、Webサーバー、CDN/WAFの設定画面で、CSPのframe-ancestorsまたはX-Frame-Optionsを確認してください。応答だけでは設定元を特定できません。',
        'HTTPSと証明書検証'=>'サーバーまたはCDNのSSL/TLS設定画面で、対象サイトの証明書・有効期限・証明書チェーンを確認してください。',
        '証明書の期限'=>'サーバーまたはCDNの証明書管理画面で、対象サイトの証明書の期限と更新設定を確認してください。接続方式から期限を取得できない場合、待機だけでは完了しません。',
        'HSTS'=>'サーバーまたはCDN、ヘッダーを付与するWordPressの機能でStrict-Transport-Securityを確認してください。HTTPS運用を確認してから用途に合わせて設定してください。',
        'セッション用Cookie候補の属性'=>'認証機能またはPHPセッションを使用する機能の設定で、Secure・HttpOnly・SameSiteを確認してください。未ログイン応答でCookieが発行されない場合、待っても認証後の属性は取得されません。',
        'CookieのSameSite補助対策'=>'認証機能・PHPセッションのCookie設定でSameSiteを確認してください。未ログイン応答でCookieを発行しないサイトでは、この確認だけで認証後の属性を取得できません。',
        'キャッシュ方針'=>'キャッシュ機能・サーバー・CDNの設定画面で、対象ページのCache-Controlとログイン画面の除外を確認してください。設定元は応答だけでは特定できません。',
        '他サイトからの読取り許可'=>'API・ヘッダーを設定するWordPressの機能、サーバー、CDNで、許可オリジンと認証情報付きCORSを確認してください。公開ページと認証後のAPIは分けて確認してください。',
        'スクリプト用CSP'=>'ヘッダーを設定するWordPressの機能、サーバー、CDNで、強制するContent-Security-Policyのscript-src等を確認してください。Report-Onlyは強制設定として扱いません。',
        'Content-Typeの補助制限'=>'ヘッダーを設定するWordPressの機能、サーバー、CDNでX-Content-Type-Optionsを確認してください。アップロードファイル自体の応答は別途確認してください。',
        '参照元情報の制限'=>'ヘッダーを設定するWordPressの機能、サーバー、CDNでReferrer-Policyを確認してください。応答だけでは設定元を特定できません。',
        '通常転送の追跡'=>'WordPressの転送機能・サーバー・CDNで通常転送の設定を確認してください。任意パラメータの安全性は、転送を実装する機能のコード点検が必要です。',
        '通常転送'=>'WordPressの転送機能・サーバー・CDNで転送先を確認してください。SSO等の意図した転送と、入力値から任意サイトへ転送する欠陥は分けて確認してください。',
        '関連する既知脆弱性の影響版'=>'下部の「既知脆弱性の照合結果・対象製品」で製品・版・成立条件・修正版を確認し、各製品の公式更新手順で対応してください。',
    ];
}

function wphm_sc_connection_action(string $source, array $facts): string {
    if(empty($facts['acquisition']['selected'][$source])) return '上部の「調べる場所」で「'.wphm_sc_source_names()[$source].'」を選択して「再チェック」を押してください。';
    if($source==='wordpress') return 'WordPress内の設定読取りには接続キーは不要です。利用中の機能と、この項目の対応する読取り範囲を確認してください。';
    $options=wphm_sc_options();
    $fields=$source==='edge' && $options['edge_provider']==='cloudflare'?'Cloudflare Zone ID・読取りトークン・対象ゾーンの読取り権限':'取得方法・連携の接続ID・読取りキーと対象サイト';
    return '上部の「'.wphm_sc_source_names()[$source].'の接続設定」で'.$fields.'を確認し、「再チェック」を押してください。';
}

/** Build explanations from typed facts only. Raw errors, credentials and connection IDs are never included. */
function wphm_sc_check_guidance(array $check, array $facts, string $item_id): array {
    $status=$check['status']; $label=$check['label']; $plain=preg_replace('/^(公開トップ|ログインページ)：/u','',$label);
    $unread=in_array($status,['skip','setup','error','pending','unavailable'],true);
    $out=['unread'=>$unread,'reason'=>'','action'=>'','link'=>'','link_label'=>'','status_label'=>wphm_sc_check_labels()[$status] ?? '未確認'];
    if(!$unread) return $out;
    $options=wphm_sc_options(); $sources=$facts['acquisition']['sources'] ?? [];
    $places=array_values(array_intersect($check['sources'] ?? ['wordpress'],array_keys(wphm_sc_source_names())));
    $actions=wphm_sc_check_actions(); $out['action']=$actions[$plain] ?? '';
    $public=!empty($check['public_response']);
    $manual_scope=in_array($label,['他製品のAPI・所有者確認','他製品・外部スクリプトの整合性','認証後のキャッシュ分離','TLSの全許可構成'],true);
    $vulnerability=in_array($label,['既知脆弱性の照合','影響版への該当','照合できない製品'],true);
    $php_support=$label==='PHP上流のセキュリティサポート'; $updates=$label==='公式更新情報' || $php_support;
    if($status==='skip') {
        $out['reason']=$public?'「公開応答を確認する」が未選択のため、このページの応答を取得していません。':($vulnerability?'「既知脆弱性の公開情報と照合する」が未選択です。':($updates?'「公式の更新情報と照合する」が未選択です。':'この項目で調べる場所が未選択のため、設定・記録を読み取っていません。'));
        $choice=$public?'公開応答を確認する':($vulnerability?'既知脆弱性の公開情報と照合する':($updates?'公式の更新情報と照合する':implode('・',wphm_sc_location_tags($check))));
        $out['action']='上部の「調べる場所」で「'.$choice.'」を選択して「再チェック」を押してください。選択しない間は自動で取得しません。';
        $out['link']='#wphm-sc-form'; $out['link_label']='取得元の選択へ';
        return $out;
    }
    if($public) {
        $kind=strpos($label,'ログインページ：')===0?'login':'home'; $row=$facts['public_response']['responses'][$kind] ?? [];
        if(($row['status'] ?? '')!=='ok') {
            $out['reason']=wphm_sc_public_reason(is_string($row['reason'] ?? null)?$row['reason']:'recheck');
            $out['action']='上部の「公開応答を確認する」を選択して「再チェック」を押してください。通信・証明書・公開IP等のエラーが残る場合は、上記の取得条件をサーバーまたはCDNの管理画面で確認してください。';
            $out['link']='#wphm-sc-form'; $out['link_label']='公開応答の選択へ';
            return $out;
        }
        $out['reason']=$check['value']; // A normalized public observation, not an unselected or failed request.
    }
    if($label==='ログイン試行制限（標準ログイン）') {
        $reasons=[]; $next=[];
        foreach($places as $source) {
            $row=$sources[$source] ?? ['status'=>'skip']; $name=wphm_sc_source_names()[$source]; $state=$row['status'];
            if($state==='skip') { $reasons[]=$name.'は未選択です。'; continue; }
            if(in_array($state,['setup','pending','error','unsupported'],true)) {
                $reasons[]=$name.'：'.wphm_sc_source_reason($row); $next[]=wphm_sc_connection_action($source,$facts);
            } elseif($source==='wordpress') {
                $reasons[]=!empty($facts['identity']['limiter_error'])?'WordPress内の制限設定の読取りに失敗しました。':'WordPress内で、標準ログインへの適用・許可回数・計数期間・停止時間をそろえて読み取れる制限設定を確認できませんでした。この取得結果から具体的な設定画面も特定できていません。';
            } elseif($source==='server' && isset($row['local_php'])) $reasons[]='選択中の「ローカルPHP情報」は、ログイン試行制限の設定を取得する方法ではありません。';
            elseif($source==='server' && wphm_sc_options()['server_provider']==='cpanel') $reasons[]='選択中のcPanel連携はModSecurity設定の読取りで、ログイン試行制限の設定には対応していません。';
            elseif($source==='edge' && !empty($row['failures']['login_limits'])) { $reasons[]='外部サービスのログイン制限：'.wphm_sc_read_reason($row['failures']['login_limits']); $next[]=wphm_sc_connection_action($source,$facts); }
            else $reasons[]=$name.'の取得結果に、標準ログインへ適用される制限の全設定値がありません。';
        }
        $out['reason']=implode(' ',$reasons).' 設定の有無・不足は断定できず、待機だけでは確認は終わりません。';
        $out['action'].=' '.implode(' ',array_unique($next)).' サーバー・外部サービスで制限している場合は、その管理画面で適用先と値を確認してください。選択中の連携がこの項目に未対応の場合、接続設定を入力するだけでは取得できません。';
        $out['link']='#wphm-sc-form'; $out['link_label']='取得元・接続設定へ';
        return $out;
    }
    foreach(($updates || $manual_scope || $public)?[]:$places as $source) {
        $row=$sources[$source] ?? [];
        if(in_array($row['status'] ?? '',['setup','pending','error','unsupported'],true)) {
            $out['reason']=wphm_sc_source_names()[$source].'：'.wphm_sc_source_reason($row);
            $out['action']=wphm_sc_connection_action($source,$facts).' '.$out['action'];
            $out['link']=$source==='wordpress'?'#wphm-sc-form':'#wphm-sc-connect-'.$source;
            $out['link_label']=$source==='wordpress'?'取得元の選択へ':'接続設定へ';
            break;
        }
    }
    $telemetry=$facts['telemetry'] ?? [];
    if(in_array($label,['認証記録','失敗の集中','失敗集中後の認証','通信量・HTTPエラー','通常時との比較'],true)) {
        if(!empty($telemetry['available'])) {
            $out['status_label']='記録不足';
            $out['reason']=$label==='通常時との比較'?'比較に必要な基準期間60分以上・通信100件以上がそろっていません。現在は基準期間'.(int)($telemetry['baseline_minutes'] ?? 0).'分・通信'.(int)($telemetry['baseline_requests'] ?? 0).'件です。':'計測開始後の必要な通信・認証記録がまだそろっていません。';
            $out['action']='通常のサイト利用で記録が増えた後、「再チェック」を押してください。件数条件があるため、経過時間だけでは完了しません。試験的なログイン失敗や大量アクセスは不要です。';
        } else {
            $out['reason']='WordPress到達後の集計記録を読み取れていません。計測が進んでいるとは確認できません。';
            $out['action']='ダッシュボードで「セキュリティチェック」を有効にし、この画面を開いてから「再チェック」を押してください。改善しない場合はサイト管理者がDBの読取り・集計テーブルの作成権限を確認してください。';
        }
        return $out;
    }
    if($vulnerability || $updates) {
        $out['link']=$updates?'#wphm-sc-form':'#wphm-sc-vulnerabilities'; $out['link_label']=$updates?'照合の選択へ':'照合結果・対象製品へ';
        $out['reason']=$php_support?'使用中のPHP版に対応する上流のサポート情報を、WordPress.orgから取得できていません。サーバー読取り連携の接続情報とは別の照合です。':($updates?'保存済みまたは取得した更新情報に、確認できない製品・版があります。':($label==='照合できない製品'?'独自配布・未対応製品または処理上限があり、公開データで照合できない対象があります。':'既知脆弱性の照合に、未照合・取得失敗または影響条件を確認できない対象が残っています。'));
        $out['action']=$updates?'上部の「公式の更新情報と照合する」を選び「再チェック」を押してください。独自配布製品は、その製品の公式更新画面・配布元で確認してください。':'「既知脆弱性の照合結果・対象製品」を開き、製品ごとの理由を確認してください。未照合分は「再チェック」後にこの画面で照合を続けます。取得失敗は表示された接続・権限を確認し、未対応製品は公式配布元の脆弱性情報・修正版を確認してください。';
        if($php_support) $out['action']='上部の「公式の更新情報と照合する」を選び「再チェック」を押してください。取得できない場合は、サーバーからWordPress.orgへのHTTPS通信と対象PHP版の公開サポート情報を確認してください。';
        return $out;
    }
    if($label==='外部の通信統計' && !empty($sources['edge']['failures']['traffic'])) {
        $out['reason']='外部サービスの通信統計：'.wphm_sc_read_reason($sources['edge']['failures']['traffic']);
        $out['link']='#wphm-sc-connect-edge'; $out['link_label']='接続設定へ';
    }
    if($out['reason']==='') {
        if(strpos($label,'本プラグイン')===0) {
            $out['reason']='本プラグインの点検対象ファイルと、配布時の記録との一致を確認できません。設定が不足していると判断したものではありません。';
            $out['action']='正規配布元の本プラグインの版とファイルを照合してください。意図した編集の有無も確認し、必要な場合は公式配布物から更新して再チェックしてください。';
        } elseif($label==='管理者の追加認証') $out['reason']=!empty($facts['identity']['mfa_error'])?'追加認証の設定読取りに失敗しました。':(!empty($facts['identity']['pending'])?'対象利用者の読取りが途中です。':'対象管理者の追加認証設定を、対応する取得方法で確認できません。設定不足か未対応かは、この結果だけでは断定できません。');
        elseif($label==='認証処理の発見') $out['reason']='認証処理を調べる件数上限に達したため、全候補を確認できません。';
        elseif($label==='標準ロール・新規登録の権限') $out['reason']='WordPressの権限情報の読取りを完了できないか、件数上限を超えています。';
        elseif($label==='別の認証経路') $out['reason']='標準ログイン以外の認証経路と、各経路への保護の適用をすべて確認できていません。';
        elseif($label==='WordPress標準の認証Cookie方針') $out['reason']='Cookie方針を変える独自処理があるか、標準方針の根拠を取得できません。';
        elseif($label==='現在の管理者のセッション集計') $out['reason']='標準形式のセッション集計を取得できません。独自形式・不正な期限・64KiBまたは100件の上限を超える記録は集計しません。';
        elseif($label==='公開領域のバックアップ・ログ候補') $out['reason']='固定位置の読取りが完了していないか、追跡しないリンクが含まれます。公開可否や全ファイルの状態は確認できません。';
        elseif($places===['server']) $out['reason']='選択中のサーバー読取り情報には、この項目を判定する設定・統計が含まれていません。ローカルPHP情報だけでは取得できない項目です。';
        elseif($places===['edge']) $out['reason']='選択中の外部サービスの取得情報には、この項目を判定する設定・統計が含まれていません。';
        elseif(in_array($label,['他製品のAPI・所有者確認','他製品・外部スクリプトの整合性','認証後のキャッシュ分離','TLSの全許可構成'],true)) $out['reason']='この項目は、今回の自動読取りの範囲では判定できません。待機や接続情報の入力だけでは確認済みになりません。';
        else $out['reason']=$status==='error'?'この項目の設定・記録の読取りを完了できませんでした。設定不足とは断定できません。':'この項目を判定する設定・記録の根拠を取得できていません。設定不足とは断定できません。';
    }
    if($out['action']==='') {
        $by_item=[
            'ddos'=>'サーバー管理画面またはCDNのアクセス統計・負荷統計を確認してください。本画面の上部で、利用する統計を返す取得方法・接続情報を設定して再チェックしてください。',
            'file_upload'=>'WordPressのアップロード制御機能とサーバーのPHP・保存先設定を確認してください。実効設定を取得するには、その項目を返す対応読取り連携が必要です。',
            'rce'=>'サーバー管理画面のPHPの版・サポート状況と、WordPressのコード編集権限を確認してください。',
        ];
        $out['action']=$by_item[$item_id] ?? 'WordPress管理画面で利用中の対象機能の設定・権限を確認し、「再チェック」を押してください。独自処理や未対応の設定は、その機能の提供元での確認が必要です。';
    }
    if(!empty($facts['identity']['pending']) && in_array($label,['管理者の追加認証','実行コードの編集・追加権限','別の認証経路'],true)) $out['action']='「再チェック」を押して、この画面で利用者の分割読取りを続けてください。完了後も未確認なら、'.$out['action'];
    return $out;
}
