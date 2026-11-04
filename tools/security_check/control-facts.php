<?php
if (!defined('ABSPATH')) exit;

/** Read-only summaries; grant statements can contain credentials and must never leave this function. */
function wphm_sc_database_scope(): ?bool {
    global $wpdb;
    $old=$wpdb->suppress_errors(true);
    try { $grants=$wpdb->get_col('SHOW GRANTS FOR CURRENT_USER()'); }
    finally { $wpdb->suppress_errors($old); }
    if ($wpdb->last_error || !is_array($grants) || !$grants || count($grants)>30) return null;
    $own=false; $restricted=true;
    foreach ($grants as $grant) {
        if (!is_string($grant) || strlen($grant)>8192 || !preg_match('/^GRANT (.+?) ON (`(?:``|[^`])+`|\*)\.(`(?:``|[^`])+`|\*) TO /i',$grant,$match)) return null;
        if (stripos($grant,'WITH GRANT OPTION')!==false) $restricted=false;
        if (strtoupper($match[1])==='USAGE' && $match[2]==='*' && $match[3]==='*') continue;
        $database=$match[2]==='*'?'*':str_replace('``','`',substr($match[2],1,-1));
        if ($database===DB_NAME) $own=true; else $restricted=false;
        if (stripos($grant,'WITH GRANT OPTION')!==false) $restricted=false;
    }
    return $restricted?($own?true:null):false;
}

function wphm_sc_environment_facts(bool $settings): array {
    global $wpdb;
    $commands=['exec','shell_exec','system','passthru','proc_open','popen','pcntl_exec'];
    $disabled=explode(',',substr((string)ini_get('disable_functions'),0,8192));
    $disabled=array_map('trim',$disabled);
    return ['php'=>PHP_VERSION,'max_execution_seconds'=>max(0,(int)ini_get('max_execution_time')),'command_functions_disabled'=>array_values(array_intersect($commands,$disabled)),'command_functions_total'=>count($commands),'db_scope'=>$settings?wphm_sc_database_scope():null,'db_errors_hidden'=>$settings?!$wpdb->show_errors:null];
}

function wphm_sc_control_states(): array { return ['on'=>'設定あり・有効','off'=>'設定なし・無効','unknown'=>'未確認']; }

/** Only facts with a known setting/value/scope may set a control to on or off. */
function wphm_sc_control_facts(array $facts): array {
    $out=[];
    $put=function(string $key,?bool $value,string $detail,array $sources=[]) use (&$out): void {
        if ($value!==null) $out[$key]=['state'=>$value?'on':'off','value'=>$detail,'sources'=>$sources];
    };
    $selected=$facts['acquisition']['selected'] ?? wphm_sc_source_selection();
    $scopes=wphm_sc_reading_scopes(); $settings=!empty($selected['wordpress']) && $scopes['settings'];
    $identity=$facts['identity'] ?? []; $files=$facts['files'] ?? []; $posture=$facts['posture'] ?? []; $env=$facts['environment'] ?? [];
    if ($settings) {
        if (!empty($facts['telemetry']['available'])) $put('auth_record',true,'本プラグインの認証イベント集計が利用可能です。標準フックの範囲で、入力全文・IP・利用者名を保存せずに件数を記録します。',['wordpress']);
        $put('site_https',!empty($facts['https']),'サイトURL・WordPress URLの設定値を確認しました。',['wordpress']);
        $put('file_edit_disabled',!empty($facts['file_edit_disabled']),'読み込まれたDISALLOW_FILE_EDIT・DISALLOW_FILE_MODSを確認しました。',['wordpress']);
        $put('debug_hidden',empty($posture['debug_display']),'WordPressのWP_DEBUG・WP_DEBUG_DISPLAYを確認しました。独自のエラー表示処理は別途確認が必要です。',['wordpress']);
        $put('db_error_hidden',is_bool($env['db_errors_hidden'] ?? null)?$env['db_errors_hidden']:null,'現在のWordPress DB処理のエラー表示設定です。独自のSQL・画面出力全体は含みません。',['wordpress']);
        $put('db_least_privilege',is_bool($env['db_scope'] ?? null)?$env['db_scope']:null,'現在のDBユーザーの取得可能なGRANTを対象DBの範囲と比較しました。権限名・アカウント名・認証情報は表示しません。',['server']);
        $put('egress_wp',is_bool($posture['http_blocked'] ?? null)?$posture['http_blocked']:null,'WP_HTTP_BLOCK_EXTERNALの値です。WordPress HTTP APIを通らない独自通信は含みません。',['wordpress']);
        $put('registration_closed',!empty($facts['registration_known'])?!$facts['registration']:null,'このWordPressの公開ユーザー登録設定です。独自の登録フォームは含みません。',['wordpress']);
        if (!empty($posture['roles_known']) && empty($posture['roles_limited'])) {
            $put('registration_roles',empty($posture['public_privileged_role']),'公開登録時の初期権限に、管理操作の強い権限があるか確認しました。',['wordpress']);
            $put('admin_roles',empty($posture['privileged_basic_roles']),'subscriber・contributor・authorの設定に管理操作の強い権限があるか確認しました。個別の実効権限とは区別します。',['wordpress']);
            $html=false;
            $install=false;
            foreach ($identity['roles'] ?? [] as $role) if (in_array($role['name'],['subscriber','contributor','author'],true)) {
                if (in_array('unfiltered_html',$role['capabilities'],true)) $html=true;
                if (in_array('install_plugins',$role['capabilities'],true)) $install=true;
            }
            $put('html_roles',!$html,'一般利用者の標準役割に設定されたunfiltered_html権限を確認しました。独自投稿処理は含みません。',['wordpress']);
            $put('install_roles',!$install,'一般利用者の標準役割に設定されたinstall_plugins権限を確認しました。独自のインストール処理や個別権限は含みません。',['wordpress']);
        }
        if (!empty($files['types_checked'])) {
            $put('upload_types',empty($files['executable_types']) && empty($facts['unfiltered_uploads']),'現在の利用者に適用されるWordPress標準の許可形式を確認しました。独自アップロードは含みません。',['wordpress']);
            if (isset($files['active_content_types'])) $put('html_upload',!$files['active_content_types'],'現在の利用者の標準アップロード設定で許可されるHTML・SVG形式：'.($files['active_content_types']?implode(', ',$files['active_content_types']):'確認対象に該当なし').'。独自アップロードは含みません。',['wordpress']);
        }
        $put('upload_size',($files['upload_bytes'] ?? 0)>0?true:null,'WordPress標準アップロードの上限：'.number_format((int)($files['upload_bytes'] ?? 0)).'バイト。',['wordpress']);
        if (($identity['admins'] ?? 0)>0 && empty($identity['limited']) && empty($identity['mfa_error'])) {
            $known=(int)($identity['mfa_known'] ?? 0); $enabled=(int)($identity['mfa_enabled'] ?? 0); $admins=(int)$identity['admins'];
            $put('mfa_enrolled',$known>$enabled?false:($known===$admins && $enabled===$admins?true:null),'管理者'.$admins.'人／設定を取得'.$known.'人／追加認証の登録'.$enabled.'人。必須化・全認証経路への適用は別の項目です。',['wordpress']);
        }
        if (array_key_exists('xmlrpc_auth',$identity) && array_key_exists('application_passwords',$identity) && $identity['application_passwords']!==null && !$identity['xmlrpc_auth'] && !$identity['application_passwords']) $put('unused_auth',true,'XML-RPC認証・アプリケーションパスワード受付が無効です。独自の認証入口は未確認です。',['wordpress']);
        $cookie=$posture['cookie_policy'] ?? [];
        if (!empty($cookie['known'])) {
            $put('cookie_http_only',true,'標準の認証Cookie発行処理を使用する設定です。独自Cookieは含みません。',['wordpress']);
            $put('cookie_secure',!empty($cookie['secure_auth']) && !empty($cookie['secure_logged_in']),'標準の認証CookieのSecure設定です。現在のHTTPS・サイトURL設定の範囲です。',['wordpress']);
            $put('session_rotate',!empty($cookie['native_tokens']),'WordPress標準のセッショントークン発行処理を使用する設定です。独自認証は含みません。',['wordpress']);
        }
        $session=$posture['session'] ?? [];
        if (($session['status'] ?? '')==='ok' && ($session['active'] ?? 0)>0) $put('session_expiry',true,'現在の管理者の保存済みセッションに有効期限があります。他の利用者・独自認証全体の確認ではありません。',['wordpress']);
        $distribution=$posture['distribution'] ?? [];
        if (!empty($distribution['offers_known']) && ($distribution['offers'] ?? 0)>0 && empty($distribution['limited'])) $put('https_updates',empty($distribution['insecure_offers']),'取得した更新情報に含まれるダウンロード先のHTTPS設定です。未取得の配布元は含みません。',['wordpress']);
    }
    if (!empty($selected['wordpress']) || !empty($selected['server'])) {
        $disabled=(array)($env['command_functions_disabled'] ?? []);
        if (($env['command_functions_total'] ?? 0)>0) $put('os_exec_restrict',count($disabled)>0,'確認対象のPHPコマンド実行関数の無効化：'.count($disabled).'/'.$env['command_functions_total'].'種類。未制限の関数やOS全体の制限、コード実行耐性は含みません。',['server']);
        $put('resource_limits',isset($env['max_execution_seconds'])?$env['max_execution_seconds']>0:null,'このPHP環境の最大実行時間：'.(int)($env['max_execution_seconds'] ?? 0).'秒（0は時間制限なし）。設定元のファイル・コード、同時接続数や上流の通信量制限は未確認です。');
    }
    foreach ($facts['acquisition']['sources'] ?? [] as $source=>$row) {
        if (empty($selected[$source])) continue;
        foreach ($row['login_limits'] ?? [] as $limit) {
            if (empty($limit['applied']) || !in_array('wp_login',$limit['routes'],true)) continue;
            $period=($limit['period'] ?? 0)>0?$limit['period'].'秒間':'計数期間は未取得';
            $value='標準ログイン／'.$limit['tries'].'回・'.$period.'／停止'.$limit['seconds'].'秒。回数制限の設定を取得しました。IP単位・アカウント単位・除外条件の確認は別に行います。';
            if (!isset($out['login_limit']) || !empty($limit['enabled'])) $put('login_limit',(bool)$limit['enabled'],$value,[$source]);
            if (!empty($limit['enabled']) && $limit['seconds']>0) $put('login_lockout',true,$value,[$source]);
        }
        $capabilities=['uploads_execution'=>'script_execution','public_boundary'=>'file_access','sql_rules'=>'sql_waf','xss_rules'=>'xss_waf','rce_rules'=>'rce_waf','upload_rules'=>'upload_waf'];
        foreach ($capabilities as $key=>$feature) if (is_bool($row['capabilities'][$key] ?? null)) {
            if (!isset($out[$feature]) || $row['capabilities'][$key]) $put($feature,$row['capabilities'][$key],'対象サイトに一致する読取り連携から、この対策の設定値を取得しました。適用範囲は連携の取得対象に限ります。',[$source]);
        }
    }
    $updates=$facts['updates'] ?? [];
    if (!empty($updates['core']['known'])) $put('core_updated',empty($updates['core']['pending']),'WordPress本体の版と取得した更新情報を照合しました。',['wordpress']);
    foreach (['plugins','themes'] as $key) if (($updates[$key]['total'] ?? 0)>0 && ($updates[$key]['unknown'] ?? 1)===0) $put($key.'_updated',empty($updates[$key]['pending']),'照合対象'.$updates[$key]['total'].'製品／更新あり'.$updates[$key]['pending'].'製品。',['wordpress']);
    if (!empty($updates['php']['known'])) $put('php_supported',!empty($updates['php']['secure']),'PHPの版を公式の上流サポート情報と照合しました。提供元独自の修正は含みません。',['server']);
    $v=$facts['vulnerabilities'] ?? [];
    if (!empty($selected['wordpress']) && isset($facts['audit']['known'])) $put('own_integrity',!empty($facts['audit']['known'])?true:(($facts['audit']['changed'] ?? 0)>0?false:null),'本プラグインの監査対象'.(int)($facts['audit']['files'] ?? 0).'ファイルと配布時の整合性を照合しました。他製品・未知の欠陥・サーバー全体は含みません。',['wordpress']);
    if (!empty($v['enabled'])) {
        $put('vulnerability_lookup',($v['checked'] ?? 0)>0?true:null,'既知脆弱性の公開情報を照合した製品：'.(int)($v['checked'] ?? 0).'。',['wordpress']);
        if (($v['affected'] ?? 0)>0) $put('vulnerability_fixed',false,'取得した公開情報に影響版への該当があります。詳細で製品・条件・修正版を確認してください。',['wordpress']);
        elseif (($v['total'] ?? 0)>0 && ($v['unknown'] ?? 1)===0 && empty($v['limited'])) $put('vulnerability_fixed',true,'照合できた全対象で、公開情報の影響版への該当はありません。未知の脆弱性や情報の欠落は含みません。',['wordpress']);
    }
    $login=$facts['public_response']['responses']['login'] ?? [];
    $home=$facts['public_response']['responses']['home'] ?? [];
    if (!empty($facts['public_response']['enabled']) && ($home['status'] ?? '')==='ok' && ($home['code'] ?? 0)>=200 && ($home['code'] ?? 0)<300) {
        $scope='取得した未ログインの公開トップ応答の範囲です。設定元はこの応答だけでは特定できません。';
        $put('csp',($home['csp'] ?? '')==='restricted'?true:(($home['csp'] ?? '')==='absent'?false:null),$scope);
        $put('hsts',($home['hsts'] ?? 0)>0 && !empty($home['https']),$scope);
        $put('frame_options',in_array($home['frame_xfo'] ?? '',['deny','self'],true)?true:(($home['frame_xfo'] ?? '')==='missing'?false:null),$scope);
        $put('frame_ancestors',in_array($home['frame_csp'] ?? '',['deny','self'],true)?true:(($home['frame_csp'] ?? '')==='missing'?false:null),$scope);
        if (!empty($home['https']) && !empty($home['tls_verified'])) $put('tls_valid',true,$scope);
        if (($home['cache'] ?? '')==='shared' || ($home['cache_age'] ?? 0)>0) $put('public_cache',true,$scope);
        if (($home['cors'] ?? '')==='same-site' || ($home['cors'] ?? '')==='none') $put('cors_origins',true,$scope);
        if (empty($home['cors_credentials'])) $put('cors_credentials',true,'取得した公開トップ応答は、認証情報付きCORSを許可していません。認証後のAPIは未確認です。');
        if (!empty($home['vary_origin'])) $put('cors_vary',true,$scope);
    }
    if (!empty($facts['public_response']['enabled']) && ($login['status'] ?? '')==='ok' && ($login['code'] ?? 0)>=200 && ($login['code'] ?? 0)<300) {
        $put('login_cache',($login['cache'] ?? '')==='private'?true:(($login['cache'] ?? '')==='shared'?false:null),'取得した未ログインの標準ログイン画面のCache-Controlです。管理画面・認証後の全応答は含みません。');
        if (empty($login['cookies_limited'])) foreach ($login['cookies'] ?? [] as $cookie) if (($cookie['kind'] ?? '')==='wp_auth') $put('cookie_same_site',in_array($cookie['same_site'],['lax','strict'],true)?true:null,'取得した標準ログイン応答の認証CookieのSameSite属性です。Cookieの値は保存しません。');
    }
    return $out;
}

function wphm_sc_control_candidates(string $feature,array $facts): array {
    if (empty($facts['acquisition']['selected']['wordpress'])) return [];
    $scopes=wphm_sc_reading_scopes();
    $aliases=['ip_allow'=>['ip_allow','ip_access'],'ip_deny'=>['ip_deny','ip_access'],'ip_autoblock'=>['ip_access','login_limit_code'],'login_ip_limit'=>['login_limit_code'],'login_account_limit'=>['login_limit_code'],'login_lockout'=>['login_limit_code'],'login_limit'=>['login_limit_code'],'login_extra_access'=>['basic_auth','ip_access'],'admin_access'=>['basic_auth','ip_access'],'mfa_required'=>['mfa_code'],'all_auth_limits'=>['login_limit_code','auth_hook'],'password_policy'=>['password_policy'],'unused_auth'=>['xmlrpc_setting'],'sql_prepared'=>['sql_prepared'],'output_escape'=>['output_escape'],'script_execution'=>['script_execution','file_access'],'file_access'=>['file_access'],'log_access'=>['file_access'],'config_access'=>['file_access'],'git_access'=>['file_access'],'backup_access'=>['file_access'],'file_path_check'=>['file_path_check'],'filename_check'=>['file_path_check'],'url_validate'=>['safe_http'],'redirect_validate'=>['safe_redirect','safe_http'],'dns_validate'=>['safe_http'],'safe_redirect'=>['safe_redirect'],'redirect_hosts'=>['safe_redirect','redirect_code'],'login_redirect'=>['safe_redirect'],'no_tls_downgrade'=>['https_redirect','redirect_code'],'cookie_same_site'=>['cookie_code'],'cookie_secure'=>['cookie_code'],'cookie_http_only'=>['cookie_code'],'mfa_enrolled'=>['mfa_code']];
    $rows=[];
    foreach ($aliases[$feature] ?? [$feature] as $key) foreach ($facts['reading']['features'][$key] ?? [] as $row) if (count($rows)<8 && !empty($scopes[$row['group'] ?? ''])) $rows[]=$row;
    return $rows;
}

/** UI status distribution replaces the previous risk score; records stay outside defensive controls. */
function wphm_sc_controls(array $facts): array {
    $catalog=wphm_sc_catalog(); $known=wphm_sc_control_facts($facts); $items=[];
    foreach (wphm_sc_control_catalog() as $id=>$definitions) {
        $item=['id'=>$id,'name'=>$catalog[$id]['name'],'group'=>$catalog[$id]['group'],'checks'=>[],'counts'=>['on'=>0,'off'=>0,'unknown'=>0]];
        foreach ($definitions as $definition) {
            [$feature,$label,$place,$help,$kind]=$definition;
            $row=['id'=>$id.'-'.$feature,'feature'=>$feature,'label'=>$label,'state'=>'unknown','sources'=>[],'value'=>'','reason'=>'この対策の設定値と適用範囲を、現在の読取り処理では確認できていません。','action'=>'表示する設定場所の例で、実際に対策を実装している場所を確認します。独自形式の場合は、このプラグイン側の読取り・判定処理の追加が必要です。','place'=>$place,'help'=>$help,'kind'=>$kind,'evidence'=>wphm_sc_control_candidates($feature,$facts),'link'=>''];
            if (isset($known[$feature])) {
                $row=array_replace($row,$known[$feature]); $row['reason']='';
                $row['action']=$row['state']==='off'?'この取得範囲の設定を、実装元の管理画面・設定ファイル・コードで確認してください。このプラグインは防御設定を変更しません。':'取得した設定の適用対象を確認してください。別の認証経路や独自処理まで確認済みとは扱いません。';
            } elseif ($row['evidence']) {
                $row['sources']=array_values(array_unique(array_column($row['evidence'],'source')));
                $row['reason']='関連するコード・設定記述を検出しました。対象・条件・実際の適用まで確定できないため、対策の有効・無効は未確認です。';
                $row['action']='下のファイル位置・行番号で処理を確認します。記述を検出しただけでは確認済みにしません。';
            }
            if ($row['state']==='unknown' && !$row['evidence']) {
                $remote=in_array($feature,['ddos_upstream','sql_waf','xss_waf','rce_waf','traversal_waf','file_malware','ip_reputation','risk_auth','origin_tls','tls_renew','tls_versions','origin_restrict','db_network','db_admin_access','egress_network','metadata_restrict'],true);
                $public=in_array($feature,['tls_valid','csp','frame_ancestors','frame_options','hsts','cors_origins','cors_credentials','cors_vary','login_cache','public_cache'],true);
                if ($remote) {
                    $row['reason']='この対策の実設定を取得できる管理API等の連携情報は、現在取得できていません。WordPressのフォルダ指定や一般的なWAFの有効情報だけでは確定できません。';
                    $row['action']='設定場所の例で実装元を確認します。接続設定には、選択した連携が取得できる項目を表示します。対応していない項目はプラグイン側の連携追加が必要です。';
                } elseif ($public && empty($facts['public_response']['enabled'])) {
                    $row['reason']='公開応答の取得が未選択で、設定の有効性を裏付ける情報も取得できていません。';
                    $row['action']='上部の「公開応答を確認する」を選択して再チェックします。取得範囲は公開トップ・標準ログイン画面です。';$row['link']='#wphm-sc-form';
                } elseif (!empty($facts['reading']['pending'])) $row['reason']='WordPress内の読取りが処理中です。この対策の設定はまだ特定できていません。読取り後も未対応の形式は未確認になります。';
                elseif (empty($facts['acquisition']['selected']['wordpress'])) { $row['reason']='WordPress内が未選択です。他の取得元も含め、この対策の設定を特定できていません。'; $row['link']='#wphm-sc-connect-wordpress'; }
                elseif (($facts['reading']['checked_at'] ?? 0)===0) { $row['reason']='WordPress内のファイル読取りは、まだ実行していません。取得済みの設定にも、この対策を確定できる情報がありません。'; $row['link']='#wphm-sc-connect-wordpress'; }
            }
            if (in_array($feature,['vulnerability_fixed','vulnerability_lookup'],true)) $row['link']='#wphm-sc-vulnerabilities';
            if ($row['state']==='unknown' && !wphm_sc_reading_scopes()['settings'] && in_array($feature,['site_https','file_edit_disabled','debug_hidden','db_error_hidden','db_least_privilege','egress_wp','registration_closed','registration_roles','admin_roles','html_roles','install_roles','upload_types','upload_size','mfa_enrolled','cookie_http_only','cookie_secure','session_expiry','session_rotate'],true)) {
                $row['reason']='WordPressの読取り対象「WordPressの設定（DB・実行中の値）」が未選択です。';
                $row['action']='「WordPressの接続設定」で、この読取り対象を選んで再チェックします。';$row['link']='#wphm-sc-connect-wordpress';
            }
            if ($row['state']==='unknown' && $feature==='own_integrity' && !wphm_sc_reading_scopes()['plugins']) {
                $row['reason']='読取り対象「プラグイン・MUプラグイン」が未選択のため、配布時ファイルとの照合をしていません。';
                $row['action']='「WordPressの接続設定」で、この読取り対象を選んで再チェックします。';$row['link']='#wphm-sc-connect-wordpress';
            }
            if ($row['state']==='unknown' && $feature==='db_least_privilege' && !empty($facts['acquisition']['selected']['wordpress']) && wphm_sc_reading_scopes()['settings'] && array_key_exists('db_scope',$facts['environment'] ?? [])) {
                $row['reason']='このWordPressのDB接続から、権限情報を取得・解釈できませんでした。';
                $row['action']='DB管理画面で対象ユーザーの権限を確認します。取得権限・DB形式の対応が必要な場合があり、WordPressの接続欄にAPIキーを入力する作業では解決しません。';$row['link']='';
            }
            if ($row['state']==='unknown' && $feature==='registration_closed' && is_multisite() && !empty($facts['acquisition']['selected']['wordpress']) && wphm_sc_reading_scopes()['settings']) {
                $row['reason']='マルチサイトのネットワークを含む登録条件は、この標準設定の読取り範囲では確認できていません。';
                $row['action']='ネットワーク管理の登録設定と、独自の参加・承認処理を確認します。';$row['link']='';
            }
            if ($row['state']==='unknown' && in_array($feature,['core_updated','plugins_updated','themes_updated','php_supported'],true)) {
                $row['reason']='対象の版に一致する、取得済みの公式更新・サポート情報がありません。';
                $row['action']='上部の「公式の更新情報と照合する」を選択して再チェックします。外部照会の失敗・対象外は詳細に表示します。';$row['link']='#wphm-sc-form';
            }
            if ($kind==='defense') $item['counts'][$row['state']]++;
            $item['checks'][]=$row;
        }
        $items[]=$item;
    }
    return $items;
}
