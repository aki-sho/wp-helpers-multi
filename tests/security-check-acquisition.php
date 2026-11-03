<?php
/** Source-selection, reader-boundary and disclosure regressions in disposable native WordPress. */
if(PHP_SAPI!=='cli' || !isset($connection,$checks)) exit;
$acq_admin=get_current_user_id(); $acq_options=get_option('wphm_sc_options',[]); $acq_preferences=get_user_option('wphm_sc_sources',$acq_admin);
$reader_calls=0; $reader_mode='valid';
$reader_filter=function($readers) use (&$reader_calls,&$reader_mode) {
    $read=function($context) use (&$reader_calls,&$reader_mode) {
        $reader_calls++;
        if($reader_mode==='exception') throw new RuntimeException('private-reader-error-marker');
        $reply=['schema'=>1,'site'=>$context['site'],'checked_at'=>time(),'raw_secret'=>'must-not-survive-normalization','login_limits'=>[['enabled'=>true,'applied'=>true,'tries'=>7,'period'=>300,'seconds'=>600,'routes'=>['wp_login']]],'capabilities'=>['uploads_execution'=>true,'vendor_patches'=>false],'traffic'=>['requests'=>100,'peak'=>10,'errors'=>0,'window_seconds'=>3600]];
        if($reader_mode==='wrong_site') $reply['site']='https://other.invalid/';
        if($reader_mode==='stale') $reply['checked_at']=time()-301;
        if($reader_mode==='bad_counter') $reply['traffic']['requests']='100';
        return $reply;
    };
    $readers['fixture_server']=['source'=>'server','name'=>'Fixture hosting reader','needs_id'=>true,'needs_token'=>true,'read'=>$read];
    $readers['fixture_edge']=['source'=>'edge','name'=>'Fixture external reader','needs_id'=>false,'needs_token'=>false,'read'=>$read];
    $readers['../../bad']=['source'=>'server','name'=>'bad','read'=>$read];
    return $readers;
};
add_filter('wphm_security_readers',$reader_filter);
try {
    sc_same(false,isset(wphm_sc_readers()['../../bad']),'reader IDs cannot be paths');
    $sources_input=['wphm_sc_sources_form'=>'1','source_wordpress'=>'1','source_server'=>'1','source_updates'=>'1','source_vulnerabilities'=>'1','server_provider'=>'fixture_server','server_connection'=>'fixture-contract','server_token'=>str_repeat('s',32),'edge_provider'=>'cloudflare','cf_zone'=>''];
    wphm_sc_save_sources($sources_input);
    sc_same(['wordpress'=>true,'server'=>true,'edge'=>false],wphm_sc_source_selection(),'three sources saved without requiring all connections');
    $saved=wphm_sc_options();
    sc_same(false,strpos($saved['server_secret'],str_repeat('s',32))!==false,'hosting credential encrypted');
    sc_same(str_repeat('s',32),wphm_sc_secret($saved['server_secret'],true),'hosting key decrypts only for the reader');
    $before_calls=$reader_calls;
    sc_same('pending',wphm_sc_reader_facts('server',false)['status'],'view alone never invokes a newly configured reader');
    sc_same($before_calls,$reader_calls,'no reader call on view');
    $reader=wphm_sc_reader_facts('server',true);
    sc_same('ok',$reader['status'],'registered reader succeeds');
    sc_same(7,$reader['login_limits'][0]['tries'],'bounded setting retained');
    sc_same(true,$reader['capabilities']['uploads_execution'],'automatic server fact retained');
    sc_same(false,strpos(wp_json_encode($reader),'must-not-survive-normalization')!==false,'unrecognized response text discarded');
    sc_same(false,strpos(wp_json_encode($reader),'fixture-contract')!==false,'connection ID absent from report');
    sc_same(false,strpos(wp_json_encode(get_option('wphm_sc_reader_server')),str_repeat('s',32))!==false,'cached reader data contains no credential');
    $before_calls=$reader_calls;
    sc_same('ok',wphm_sc_reader_facts('server',false)['status'],'validated current reader cache reused');
    sc_same($before_calls,$reader_calls,'cache display does not contact provider');
    foreach(['wrong_site','stale','bad_counter','exception'] as $mode) {
        $reader_mode=$mode; $bad=wphm_sc_reader_facts('server',true);
        sc_same('error',$bad['status'],'unsafe reader response rejected: '.$mode);
        sc_same([], $bad['login_limits'],'failed reader cannot reuse old protection: '.$mode);
        sc_same(false,strpos(wp_json_encode($bad),'private-reader-error-marker')!==false,'reader exceptions do not expose internal details');
    }
    $reader_mode='valid';
    $raw=['schema'=>1,'site'=>home_url('/'),'checked_at'=>time(),'login_limits'=>[['enabled'=>true,'applied'=>true,'tries'=>5,'period'=>60,'seconds'=>60,'routes'=>['wp_login']]]];
    sc_same(true,wphm_sc_normalize_reader($raw,'server','fixture')!==null,'exact site binding accepted');
    foreach(['https://other.invalid/','https://example.invalid/other/','https://user:secret@example.invalid/','https://example.invalid/?token=secret','not a URL'] as $url) {
        $invalid=$raw; $invalid['site']=$url;
        sc_same(null,wphm_sc_normalize_reader($invalid,'server','fixture'),'wrong or unsafe site binding rejected');
    }
    foreach([['tries',-1],['tries','5'],['period',0],['seconds',86401],['enabled','true'],['applied',1],['routes',['../../wp-login']]] as $bad) {
        $invalid=$raw; $invalid['login_limits'][0][$bad[0]]=$bad[1];
        sc_same(null,wphm_sc_normalize_reader($invalid,'server','fixture'),'invalid reader setting rejected: '.$bad[0]);
    }
    $before_options=serialize(get_option('wphm_sc_options')); $before_preferences=serialize(get_user_option('wphm_sc_sources',$acq_admin));
    foreach([['server_provider'=>'../../shell'],['server_connection'=>'https://127.0.0.1/'],['server_connection'=>'C:\\private'],['server_token'=>['bad']],['source_wordpress'=>['1']],['cf_zone'=>'<script>']] as $bad) {
        $rejected=false;
        try { wphm_sc_save_sources(array_merge($sources_input,$bad)); } catch(WPHM_Security_Check_Exception $error) { $rejected=true; }
        sc_same(true,$rejected,'invalid source input rejected before save: '.implode(',',array_keys($bad)));
        sc_same($before_options,serialize(get_option('wphm_sc_options')),'bad input cannot partially replace credentials');
        sc_same($before_preferences,serialize(get_user_option('wphm_sc_sources',$acq_admin)),'bad input cannot change source preferences');
    }
    $second=wp_create_user('sourceobserver',bin2hex(random_bytes(12)),'sourceobserver@example.invalid');
    $second_user=new WP_User($second); $second_user->set_role('administrator'); wp_set_current_user($second);
    sc_same(['wordpress'=>true,'server'=>false,'edge'=>false],wphm_sc_source_selection(),'administrator source choices are isolated');
    wp_set_current_user($acq_admin);
    sc_same(true,wphm_sc_source_selection()['server'],'original administrator keeps own selection');
    $executed=0; $auth_probe=function($value) use (&$executed){$executed++;return $value;};
    add_filter('authenticate',$auth_probe,33);
    $discovery=wphm_sc_discover_auth();
    sc_same(0,$executed,'discovery never executes authentication callbacks');
    sc_same(true,$discovery['custom']>0,'unrecognized custom authentication location discovered');
    sc_same(true,count($discovery['callbacks'])<=60,'discovery display bounded');
    sc_same(false,strpos(wp_json_encode($discovery),$root)!==false,'absolute local paths not reported');
    remove_filter('authenticate',$auth_probe,33);
    $mfa_reader=function(){return ['known'=>true,'enabled'=>true,'name'=>'Local MFA reader'];};
    add_filter('wphm_security_mfa_facts',$mfa_reader);
    sc_same(true,wphm_sc_mfa_for_user(wp_get_current_user())['enabled'],'additional registered MFA reader supported');
    remove_filter('wphm_security_mfa_facts',$mfa_reader);
    $legacy_mode='valid';
    $legacy_reader=function() use (&$legacy_mode) {
        $limit=['name'=>'Legacy reader','tries'=>'5','seconds'=>'300','period'=>'60'];
        if($legacy_mode==='bad_tries') $limit['tries']='5.5';
        if($legacy_mode==='bad_name') unset($limit['name']);
        if($legacy_mode==='bad_period') $limit['period']='60.1';
        return ['limiter'=>$limit];
    };
    $legacy_builtin=new Limit_Login_Attempts();
    add_filter('wp_authenticate_user',[$legacy_builtin,'wp_authenticate_user'],99999,2);
    add_filter('wphm_security_auth_facts',$legacy_reader);
    try {
        $legacy_facts=wphm_sc_identity_facts();
        sc_same(2,count($legacy_facts['limiters']),'legacy and built-in limiters remain separate');
        sc_same(60,$legacy_facts['limiters'][1]['period'],'legacy integer strings accepted');
        foreach(['bad_tries','bad_name'] as $legacy_mode) {
            $legacy_facts=wphm_sc_identity_facts();
            sc_same(1,count($legacy_facts['limiters']),'invalid legacy facts cannot borrow built-in settings');
            sc_same(true,$legacy_facts['limiter_error'],'invalid legacy facts disclose acquisition error');
            sc_same('Limit Login Attempts / Reloaded',$legacy_facts['limiter']['name'],'invalid legacy facts preserve actual built-in evidence');
        }
        $legacy_mode='bad_period'; $legacy_facts=wphm_sc_identity_facts();
        sc_same(0,$legacy_facts['limiters'][1]['period'],'non-integer legacy period stays unconfirmed');
    } finally {
        remove_filter('wp_authenticate_user',[$legacy_builtin,'wp_authenticate_user'],99999);
        remove_filter('wphm_security_auth_facts',$legacy_reader);
    }
    $rule=['enabled'=>true,'action'=>'block','expression'=>'(http.request.uri.path eq "'.wp_parse_url(site_url('wp-login.php'),PHP_URL_PATH).'" and http.request.method eq "POST")','ratelimit'=>['requests_per_period'=>10,'period'=>60,'mitigation_timeout'=>300]];
    sc_same(10,wphm_sc_cloudflare_login_rule($rule)['tries'],'simple login rate-limit expression recognized');
    $cf_input=$sources_input; $cf_input['source_edge']='1'; $cf_input['cf_zone']=str_repeat('a',32); $cf_input['cf_token']=str_repeat('c',32); $cf_input['server_token']='';
    wphm_sc_save_sources($cf_input); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
    sc_same(true,wphm_sc_cloudflare(true)['known'],'Cloudflare fixture available for target-binding regression');
    $changed_home=function($url){return str_replace('example.invalid','other.invalid',$url);};
    add_filter('home_url',$changed_home); $http_before=count($calls);
    sc_same(false,wphm_sc_cloudflare(false)['known'],'cached Cloudflare evidence bound to exact site');
    sc_same($http_before,count($calls),'changed-site cache mismatch does not initiate an implicit request');
    remove_filter('home_url',$changed_home);
    foreach(['http.request.uri.path contains "wp-login"','not ('.$rule['expression'].')',$rule['expression'].' or ip.src eq 192.0.2.1','http.request.uri.path eq "/other-login"','http.request.uri.path eq "/wp-login.php" and http.host eq "other.invalid"'] as $expression) {
        $bad=$rule; $bad['expression']=$expression;
        sc_same(null,wphm_sc_cloudflare_login_rule($bad),'uncertain Cloudflare target expression remains unconfirmed');
    }
    $bad=$rule; $bad['ratelimit']['counting_expression']='http.response.code eq 500';
    sc_same(null,wphm_sc_cloudflare_login_rule($bad),'conditional rate counting not assumed applicable to login failures');
    foreach([[[], 'unknown'],[[['status'=>'skip']],'unknown'],[[['status'=>'ok']],'low'],[[['status'=>'ok'],['status'=>'pending']],'unknown'],[[['status'=>'pending'],['status'=>'issue']],'high'],[[['status'=>'pending'],['status'=>'attention']],'medium'],[[['status'=>'ok'],['status'=>'error','reference'=>true]],'low']] as $case) sc_same($case[1],wphm_sc_checks_level($case[0]),'risk is derived from required check results');
    sc_same('unknown',wphm_sc_checks_level([['status'=>'ok'],['status'=>'skip','must_confirm'=>true]]),'skipping a required public comparison cannot turn risk green');
    $all_off=['wphm_sc_sources_form'=>'1','server_provider'=>'local','edge_provider'=>'cloudflare'];
    wphm_sc_save_sources($all_off); $http_before=count($calls);
    $before_options=serialize(get_option('wphm_sc_options')); $before_preferences=serialize(get_user_option('wphm_sc_sources',$acq_admin));
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=$_REQUEST=['nonce'=>'invalid','wphm_sc_sources_form'=>'1','source_wordpress'=>'1'];
    ob_start(); try { wphm_sc_ajax(); } catch(SecurityCheckExit $exit) {} ob_end_clean();
    sc_same($before_options,serialize(get_option('wphm_sc_options')),'invalid nonce cannot save connection settings');
    sc_same($before_preferences,serialize(get_user_option('wphm_sc_sources',$acq_admin)),'invalid nonce cannot change per-user source preferences');
    $_POST=$_REQUEST=['nonce'=>wp_create_nonce('wphm_security_check'),'wphm_sc_sources_form'=>'1','source_wordpress'=>['1']];
    ob_start(); try { wphm_sc_ajax(); } catch(SecurityCheckExit $exit) {} $denied=json_decode(ob_get_clean(),true);
    sc_same(false,$denied['success'],'malformed source payload denied by actual AJAX controller');
    sc_same($http_before,count($calls),'rejected source payload cannot initiate HTTP');
    $_SERVER['REQUEST_METHOD']='GET'; $_POST=$_REQUEST=[];
    $disabled_sources=wphm_sc_collect(true);
    sc_same($http_before,count($calls),'all sources and public references off initiate no HTTP');
    sc_same(['unknown'],array_values(array_unique(array_column($disabled_sources['items'],'level'))),'no selected evidence never yields low');
    sc_same(false,$disabled_sources['facts']['telemetry']['available'],'unselected WordPress observation not read');
    sc_same(0,$disabled_sources['facts']['identity']['checked'],'unselected WordPress user metadata not enumerated');
    sc_same(false,$disabled_sources['facts']['files']['scan_available'],'unselected WordPress upload directory not scanned');
    delete_option('wphm_sc_last_recheck');
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=$_REQUEST=$all_off+['nonce'=>wp_create_nonce('wphm_security_check'),'wphm_sc_action'=>'recheck'];
    wphm_sc_handle_request();
    sc_same(true,$GLOBALS['wphm_sc_page_report']['refreshed'],'native source form saves and acquires in one POST');
    sc_same(['wordpress'=>false,'server'=>false,'edge'=>false],$GLOBALS['wphm_sc_page_report']['facts']['acquisition']['selected'],'native source form honors all selections');
    delete_option('wphm_sc_last_recheck');
    $_POST=$_REQUEST=$all_off+['nonce'=>wp_create_nonce('wphm_security_check'),'operation'=>'recheck'];
    ob_start(); try { wphm_sc_ajax(); } catch(SecurityCheckExit $exit) {} $source_reply=json_decode(ob_get_clean(),true);
    sc_same(true,$source_reply['success'],'AJAX source form saves and acquires in one request');
    sc_same(10,substr_count($source_reply['data']['html'],'class="wphm-sc-accordion"'),'AJAX source response includes all disclosures');
    sc_same(false,strpos(wp_json_encode($source_reply),str_repeat('c',32))!==false,'AJAX connection state never includes secret values');
    $_SERVER['REQUEST_METHOD']='GET'; $_POST=$_REQUEST=[];
    $safe_page=$disabled_sources; $safe_page['refreshed']=true;
    ob_start(); wphm_sc_report_html($safe_page); $html=ob_get_clean();
    sc_same(10,substr_count($html,'class="wphm-sc-accordion"'),'all ten items are native disclosures');
    sc_same(10,substr_count($html,'<h3>確認項目</h3>'),'all ten items have checks');
    sc_same(false,strpos($html,'現在の状態')!==false,'removed current-state field absent');
    sc_same(false,strpos($html,'判定理由')!==false,'removed reason field absent');
    sc_same(false,strpos($html,'wphm-sc-coverage')!==false,'only categorical risk bars retained');
    sc_same(true,strpos($html,'（確認先：')!==false,'unread settings explicitly name a target location');
    $safe_page['items'][0]['checks'][0]['label']='<script>label</script>';
    $safe_page['items'][0]['checks'][0]['help']='<img src=x onerror=x>';
    ob_start(); wphm_sc_report_html($safe_page); $html=ob_get_clean();
    sc_same(false,strpos($html,'<script>label')!==false,'check labels escaped');
    sc_same(false,strpos($html,'<img src=x')!==false,'check help escaped');
    sc_same(true,strpos($html,'&lt;script&gt;label')!==false,'escaped label remains readable');
    $sources_input['server_token']=str_repeat('s',32); $sources_input['source_edge']='1'; $sources_input['edge_provider']='fixture_edge';
    wphm_sc_save_sources($sources_input);
    $facts=wphm_sc_collect(true)['facts'];
    $assessed=array_column(wphm_sc_assess($facts),null,'id');
    sc_same(true,in_array('server',$assessed['brute_force']['checks'][0]['sources'],true),'server limiter linked to actual check');
    sc_same(true,in_array('edge',$assessed['brute_force']['checks'][0]['sources'],true),'simultaneous external limiter preserved');
    sc_same(true,count($assessed['brute_force']['checks'][0]['sources'])>=2,'multiple successful source settings coexist');
    foreach($assessed as $item) sc_same($item['level'],wphm_sc_checks_level($item['checks']),'every displayed graph follows its own checks');
    $before_options=serialize(get_option('wphm_sc_options')); $second_user->set_role('subscriber'); wp_set_current_user($second);
    $rejected=false; try { wphm_sc_save_sources($all_off); } catch(WPHM_Security_Check_Exception $error) { $rejected=true; }
    sc_same(true,$rejected,'subscriber cannot save sources or secrets');
    sc_same($before_options,serialize(get_option('wphm_sc_options')),'denied request does not alter options');
} finally {
    wp_set_current_user($acq_admin); update_option('wphm_sc_options',$acq_options,false);
    if(is_array($acq_preferences)) update_user_option($acq_admin,'wphm_sc_sources',$acq_preferences,false); else delete_user_option($acq_admin,'wphm_sc_sources',false);
    remove_filter('wphm_security_readers',$reader_filter);
    delete_option('wphm_sc_reader_server'); delete_option('wphm_sc_reader_edge');
    delete_option('wphm_sc_cf_metadata');
}
