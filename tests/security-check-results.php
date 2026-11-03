<?php
/** Additional result/continuation security cases, inside the disposable WordPress test only. */
if(PHP_SAPI!=='cli' || !isset($connection,$checks)) exit;
$api_mode='current';
$positive=wphm_sc_collect(false)['facts'];
$positive['telemetry']=array_replace($positive['telemetry'],['available'=>true,'requests'=>100,'failures'=>0,'successes'=>1,'after_failure'=>0,'auth_peak'=>0,'focused_failures'=>0,'peak'=>10,'errors'=>0,'saturated'=>false,'baseline_requests'=>0,'baseline_minutes'=>0,'recent_requests'=>10]);
$positive['identity']=array_replace($positive['identity'],['admins'=>2,'mfa_known'=>2,'mfa_enabled'=>2,'mfa_error'=>false,'limited'=>false,'app_password_users'=>0,'limiter'=>['name'=>'Known limiter','tries'=>4,'seconds'=>1200],'limiter_error'=>false]);
sc_limiter_evidence($positive,$positive['identity']['limiter']);
$positive['files']=array_replace($positive['files'],['types_checked'=>true,'executable_types'=>[],'upload_bytes'=>2048,'scripts'=>0,'scan_available'=>true,'limited'=>false]);
$positive['https']=true; $positive['unfiltered_uploads']=false; $positive['file_uploads']=true;
$positive['updates']['core']['status']='latest'; $positive['updates']['core']['pending']=false;
$positive['updates']['plugins']['pending']=$positive['updates']['themes']['pending']=0;
$positive['vulnerabilities']=['enabled'=>true,'eligible'=>3,'checked'=>3,'total'=>4,'unknown'=>1,'pending'=>0,'failed'=>0,'unsupported'=>1,'conditional'=>0,'closed'=>0,'affected'=>0,'limited'=>false,'matches'=>[],'cwe'=>[]];
$focus=['brute_force','credential_stuffing','ddos','file_upload','vulnerability_exploit'];
$assessed=array_column(wphm_sc_assess($positive),null,'id');
foreach($focus as $id) {
    sc_same($id==='vulnerability_exploit'?'unknown':'low',$assessed[$id]['level'],'checklist status follows confirmed facts, keeping unsupported products unknown: '.$id);
    sc_same(true,count($assessed[$id]['checks'])>=3,'individual observations shown: '.$id);
    sc_same(true,strpos($assessed[$id]['reason'],$id==='vulnerability_exploit'?'取得':'低')!==false,'result limits explicitly explained: '.$id);
}
sc_same('unavailable',$assessed['vulnerability_exploit']['checks'][2]['status'],'private products remain outside the known-vulnerability comparison');
$check=$positive; sc_limiter_evidence($check,null);
sc_same('pending',array_column(wphm_sc_assess($check),null,'id')['brute_force']['checks'][0]['status'],'unread limiter is unknown, not a claim that protection needs setup');
$check['identity']['limiter_error']=true;
sc_same('error',array_column(wphm_sc_assess($check),null,'id')['brute_force']['checks'][0]['status'],'provider read failure distinct from missing setup');
$check=$positive; $check['identity']['mfa_enabled']=1;
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['credential_stuffing'],'administrator without MFA requires attention');
$check=$positive; $check['identity']['limited']=true;
sc_same('unknown',array_column(wphm_sc_assess($check),'level','id')['credential_stuffing'],'partial user scan cannot claim all administrator MFA coverage');
$check=$positive; $check['identity']['mfa_error']=true;
sc_same('error',array_column(wphm_sc_assess($check),null,'id')['credential_stuffing']['checks'][0]['status'],'MFA adapter failure visible');
$check=$positive; $check['identity']['app_password_users']=1;
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['credential_stuffing'],'API credential route distinguished from browser MFA');
$check=$positive; $check['https']=false;
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['credential_stuffing'],'non-HTTPS settings require attention');
$check=$positive; $check['telemetry']['requests']=0;
sc_same('unknown',array_column(wphm_sc_assess($check),'level','id')['ddos'],'empty traffic is measurement pending');
$check=$positive; $check['telemetry']['requests']=100; $check['telemetry']['errors']=5;
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['ddos'],'HTTP error ratio attention threshold');
$check=$positive; $check['telemetry']['baseline_minutes']=60; $check['telemetry']['baseline_requests']=120; $check['telemetry']['recent_requests']=100;
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['ddos'],'measured five-fold surge has attention result');
$check['telemetry']['baseline_minutes']=59;
sc_same('low',array_column(wphm_sc_assess($check),'level','id')['ddos'],'short baseline never used to invent surge');
$check=$positive; $check['files']['types_checked']=false;
sc_same('unknown',array_column(wphm_sc_assess($check),'level','id')['file_upload'],'failed MIME inspection cannot claim standard policy confirmed');
$check=$positive; $check['files']['executable_types']=['php'];
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['file_upload'],'executable MIME allowance needs attention');
$check=$positive; $check['vulnerabilities']['pending']=1; $check['vulnerabilities']['checked']=2;
sc_same('unknown',array_column(wphm_sc_assess($check),'level','id')['vulnerability_exploit'],'incomplete comparison not green');
$check=$positive; $check['vulnerabilities']['conditional']=1;
sc_same('unknown',array_column(wphm_sc_assess($check),'level','id')['vulnerability_exploit'],'unknown version conditions not green');
$check=$positive; $check['vulnerabilities']['limited']=true;
sc_same('unknown',array_column(wphm_sc_assess($check),'level','id')['vulnerability_exploit'],'comparison storage limits not green');
$check=$positive; $check['vulnerabilities']['affected']=101; $check['vulnerabilities']['cwe']=['CWE-434','CWE-94'];
$levels=array_column(wphm_sc_assess($check),'level','id');
foreach(['vulnerability_exploit','file_upload','rce'] as $id) sc_same('high',$levels[$id],'findings beyond displayed matches retain risk classification: '.$id);

// Malformed later bounds must not be skipped after a valid but nonmatching first bound.
sc_same(null,wphm_sc_range('1.0',['min_version'=>'2.0','min_operator'=>'ge','max_version'=>['bad'],'max_operator'=>'lt']),'every range bound validated before unaffected result');
sc_same(null,wphm_sc_range('1.0',['unfixed'=>['bad']]),'array unfixed value rejected without warning');
sc_same(null,wphm_sc_range('1.0',['min_version'=>'3.0','min_operator'=>'ge','max_version'=>'2.0','max_operator'=>'lt']),'inverted interval unverified');
$clean=wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'fixture','vulnerability'=>[]]],'plugin','fixture','1.0');
$target=['kind'=>'plugin','slug'=>'fixture','version'=>'1.0'];
sc_same(true,wphm_sc_cached_lookup($clean,$target),'well-formed fresh product evidence accepted');
foreach(['checked_at'=>time()+120,'unresolved'=>-1,'matches'=>['bad'],'closed'=>[],'cwe'=>['javascript:bad']] as $field=>$bad) {
    $broken=$clean; $broken[$field]=$bad;
    sc_same(false,wphm_sc_cached_lookup($broken,$target),'malformed cached evidence rejected: '.$field);
}
$nested=wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'fixture','vulnerability'=>[$vuln]]],'plugin','fixture','1.0');
$nested['matches'][0]['sources'][0]['url']='https://name:secret@example.invalid/';
sc_same(false,wphm_sc_cached_lookup($nested,$target),'cached credential-bearing source URL rejected');
$nested['matches'][0]['sources'][0]['url']='javascript:bad';
sc_same(false,wphm_sc_cached_lookup($nested,$target),'cached executable link rejected');
$omitted=$vuln; $omitted['impact']['cwe']=array_merge(array_fill(0,10,['cwe'=>'CWE-999']),[['cwe'=>'CWE-434']]);
$omitted_record=wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'fixture','vulnerability'=>[$omitted]]],'plugin','fixture','1.0');
sc_same(true,in_array('CWE-434',$omitted_record['cwe'],true),'risk classifications retained beyond per-finding display limit');
sc_same(10,count($omitted_record['matches'][0]['cwe']),'display classification limit still bounded');

// Enforce total storage and product count, even when a previous cache is larger.
$dense_inventory=['plugins'=>[],'themes'=>[],'mu_plugins'=>[],'dropins'=>[]]; $dense_cache=[];
$dense_vuln=$vuln; $dense_vuln['name']=str_repeat('x',300);
$dense_vuln['source']=array_fill(0,2,['name'=>str_repeat('n',200),'link'=>'https://example.invalid/'.str_repeat('a',450)]);
for($i=0;$i<499;$i++) {
    $slug='storage-fixture-'.$i; $dense_inventory['plugins'][$slug.'/main.php']=['Name'=>$slug,'Version'=>'1.0','UpdateURI'=>''];
    $dense_cache[hash('sha256','plugin:'.$slug.':1.0')]=wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>$slug,'vulnerability'=>[$dense_vuln,$dense_vuln]]],'plugin',$slug,'1.0');
}
$dense_cache[hash('sha256','core:'.$wp_version.':'.$wp_version)]=wphm_sc_vulnerability_record(['error'=>0,'data'=>['core'=>$wp_version,'vulnerability'=>[]]],'core',$wp_version,$wp_version);
$legacy_key=hash('sha256','plugin:storage-fixture-498:1.0');
unset($dense_cache[$legacy_key]['cwe'],$dense_cache[$legacy_key]['affected']);
$dense_cache[$legacy_key]['matches'][0]['cwe']=['CWE-434'];
delete_option('wphm_sc_vulnerability_cursor');
update_option('wphm_sc_vulnerability_metadata',$dense_cache,false); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
$before=count($calls); $dense_result=wphm_sc_vulnerabilities($dense_inventory,true,'dense-fixture');
sc_same(500,$dense_result['checked'],'full supported product capacity read');
sc_same($before,count($calls),'valid dense cache does not initiate extra HTTP');
sc_same(true,in_array('CWE-434',$dense_result['cwe'],true),'legacy cached match beyond global display limit retains classification');
sc_same(false,in_array('CWE-434',array_merge(...array_column($dense_result['matches'],'cwe')),true),'legacy risk fixture is outside displayed first hundred findings');
sc_same(true,strlen(serialize(get_option('wphm_sc_vulnerability_metadata')))<=2097152,'large previous cache reduced to total storage bound');
$dense_inventory['plugins']['extra/main.php']=['Name'=>'Extra product','Version'=>'1.0','UpdateURI'=>''];
$dense_result=wphm_sc_vulnerabilities($dense_inventory,false);
sc_same(true,$dense_result['limited'],'products beyond 500 explicitly flagged');
sc_same(500,count($dense_result['products']),'product display count bounded');
unset($dense_cache,$dense_inventory,$dense_result); delete_option('wphm_sc_vulnerability_metadata');

// Saving an unrelated setting cannot renew a stale server declaration.
$saved=wphm_sc_options(); $old=$saved; $old['attested_at']=time()-8*DAY_IN_SECONDS;
$old['uploads_execution']='yes'; $old['vendor_patches']='yes'; $old['evidence']='Read provider instructions';
update_option('wphm_sc_options',$old,false);
$input=['vulnerabilities'=>1,'cf_zone'=>$old['cf_zone'],'uploads_execution'=>'yes','vendor_patches'=>'yes','evidence'=>$old['evidence']];
wphm_sc_save_options($input);
sc_same($old['attested_at'],wphm_sc_options()['attested_at'],'unrelated save does not renew server evidence');
$input['confirm_server']='1'; wphm_sc_save_options($input);
sc_same(true,wphm_sc_options()['attested_at']>=time()-2,'explicit server reconfirmation renews timestamp');
$saved_options=serialize(get_option('wphm_sc_options'));
foreach(['auth_failures'=>4,'auth_focus'=>61,'traffic_peak'=>'1e6','http_errors'=>[],'error_percent'=>51,'evidence'=>[],'cf_token'=>[]] as $key=>$value) {
    $invalid=$input; $invalid[$key]=$value; $rejected=false;
    try { wphm_sc_save_options($invalid); } catch(WPHM_Security_Check_Exception $failure) { $rejected=true; }
    sc_same(true,$rejected,'invalid setting rejected: '.$key);
    sc_same($saved_options,serialize(get_option('wphm_sc_options')),'invalid setting save is atomic: '.$key);
}
$input['traffic_peak']='100'; wphm_sc_save_options($input);
$check=$positive; $check['telemetry']['peak']=100;
sc_same('medium',array_column(wphm_sc_assess($check),'level','id')['ddos'],'administrator observation threshold used by assessment');
update_option('wphm_sc_options',$saved,false);

// Verify Wordfence documented read methods without performing any authentication.
if(!class_exists('wfConfig')) {
    class wfConfig { public static function get($key) { if(!empty($GLOBALS['sc_wf_throw'])) throw new RuntimeException('sensitive-provider-error'); return $GLOBALS['sc_wf_values'][$key] ?? null; } }
    class wordfence { public static function authenticateFilter($user) { throw new RuntimeException('authentication must not execute'); } }
}
$GLOBALS['sc_wf_values']=['loginSecurityEnabled'=>true,'loginSec_maxFailures'=>6,'loginSec_lockoutMins'=>30];
sc_same(null,wphm_sc_wordfence_limiter(),'unregistered Wordfence callback not considered active');
add_filter('authenticate','wordfence::authenticateFilter',99,3);
sc_same(['name'=>'Wordfence','tries'=>6,'seconds'=>1800],wphm_sc_wordfence_limiter(),'verified Wordfence keys and minute conversion');
$GLOBALS['sc_wf_values']['loginSecurityEnabled']=false;
sc_same(null,wphm_sc_wordfence_limiter(),'disabled Wordfence brute-force protection not enabled by presence');
$GLOBALS['sc_wf_values']['loginSecurityEnabled']=true; $GLOBALS['sc_wf_throw']=true; $provider_error=false;
sc_same(null,wphm_sc_wordfence_limiter($provider_error),'failing Wordfence API isolated');
sc_same(true,$provider_error,'Wordfence read error distinguishable from no configuration');
$GLOBALS['sc_wf_throw']=false; remove_filter('authenticate','wordfence::authenticateFilter',99);
if(!class_exists('WordfenceLS\\Controller_Users')) class_alias('SecurityCheckWordfenceUsers','WordfenceLS\\Controller_Users');
$GLOBALS['fixture_mfa_enabled']=false;
$GLOBALS['sc_wf_mfa']=true;
sc_same(true,wphm_sc_mfa_for_user(wp_get_current_user())['enabled'],'Wordfence enrolled MFA observed through documented API');
$GLOBALS['sc_wf_mfa']=false;
sc_same(false,wphm_sc_mfa_for_user(wp_get_current_user())['enabled'],'Wordfence inactive MFA not assumed active');
$GLOBALS['sc_wf_mfa']='malformed';
sc_same(true,wphm_sc_mfa_for_user(wp_get_current_user())['error'],'malformed MFA provider result explicitly failed');
$GLOBALS['sc_wf_mfa']=false;

// Cloudflare query reads bounded action-only events; partial failures retain only valid fields.
wphm_sc_save_options(['vulnerabilities'=>1,'cf_zone'=>str_repeat('a',32),'cf_token'=>$token]);
$events_mode='partial'; $event_queries=[];
$event_filter=function($pre,$args,$url) use (&$events_mode,&$event_queries) {
    if(wp_parse_url($url,PHP_URL_HOST)!=='api.cloudflare.com' || strpos($url,'/graphql')===false) return $pre;
    $event_queries[]=json_decode($args['body'],true)['query'];
    $events=[['action'=>'block'],['action'=>'skip'],['action'=>'managed_challenge']];
    if($events_mode==='oversized') $events=array_fill(0,101,['action'=>'block']);
    $response=['data'=>['viewer'=>['zones'=>[['httpRequests1hGroups'=>null,'firewallEventsAdaptive'=>$events]]]],'errors'=>[['message'=>'Statistics unavailable']]];
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode($response)];
};
add_filter('pre_http_request',$event_filter,30,3); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
$cf_mode='valid'; $cf=wphm_sc_cloudflare(true);
sc_same(1,$cf['blocked'],'explicit block actions counted without treating challenge as block');
sc_same(3,$cf['events'],'sample count shown');
sc_same(null,$cf['requests'],'failed GraphQL field is not interpreted as zero');
sc_same(true,$cf['known'],'valid partial fields retained');
foreach(['clientIP','clientRequestPath','clientRequestQuery','userAgent','source'] as $field) sc_same(false,strpos($event_queries[0],$field)!==false,'PII field not requested: '.$field);
$events_mode='oversized'; $cf=wphm_sc_cloudflare(true);
sc_same(null,$cf['blocked'],'over-limit event array rejected');
remove_filter('pre_http_request',$event_filter,30);
wphm_sc_save_options(['vulnerabilities'=>1,'clear_cf'=>1]);

// One authorized run traverses multiple bounded batches and never retries its failed product.
$test_files=[]; $run_filter=null; $vuln_calls=0; $fail_product='result-fixture-0';
try {
    for($i=0;$i<20;$i++) {
        $path=WP_CONTENT_DIR.'/plugins/result-fixture-'.$i.'.php';
        file_put_contents($path,"<?php\n/* Plugin Name: Result Fixture {$i}\nVersion: 1.0\n*/\n"); $test_files[]=$path;
    }
    wp_clean_plugins_cache(false);
    $run_filter=function($pre,$args,$url) use (&$vuln_calls,&$fail_product) {
        if(wp_parse_url($url,PHP_URL_HOST)!=='www.wpvulnerability.net') return $pre;
        $vuln_calls++;
        if(strpos($url,'/'.$fail_product.'/')!==false) return new WP_Error('fixture','do-not-leak-file-path-or-token');
        return $pre;
    };
    add_filter('pre_http_request',$run_filter,40,3);
    delete_option('wphm_sc_vulnerability_metadata'); delete_option('wphm_sc_vulnerability_cursor'); delete_option('wphm_sc_last_recheck');
    $initial=wphm_sc_report(true); $scan=$initial['scan'];
    sc_same(true,$scan['auto'],'multi-batch report advertises authorized continuation');
    sc_same(true,(bool)preg_match('/^[a-f0-9]{32}$/D',$scan['run']),'run token unpredictable and fixed format');
    sc_same(8,$vuln_calls,'initial batch limited to eight vulnerability calls');
    $before=count($calls); $rejected=false;
    try { wphm_sc_report(true,'continue','bad'); } catch(WPHM_Security_Check_Exception $failure) { $rejected=true; }
    sc_same(true,$rejected,'malformed continuation token rejected'); sc_same($before,count($calls),'invalid continuation sends no request');
    update_option('wphm_sc_last_step',time(),false);
    $rejected=false; try { wphm_sc_report(true,'continue',$scan['run']); } catch(WPHM_Security_Check_Exception $failure) { $rejected=strpos($failure->getMessage(),'2秒')!==false; }
    sc_same(true,$rejected,'continuation interval enforced');
    $job_key='wphm_sc_job_'.get_current_user_id(); $job=get_transient($job_key);
    $invalid_job=$job; $invalid_job['owner']++;
    set_transient($job_key,$invalid_job,600); update_option('wphm_sc_last_step',time()-3,false);
    $rejected=false; try { wphm_sc_report(true,'continue',$scan['run']); } catch(WPHM_Security_Check_Exception $failure) { $rejected=true; }
    sc_same(true,$rejected,'run owned by another administrator rejected');
    foreach(['created'=>time()-601,'steps'=>64] as $key=>$bad) {
        $invalid_job=$job; $invalid_job[$key]=$bad; set_transient($job_key,$invalid_job,600);
        $rejected=false; try { wphm_sc_report(true,'continue',$scan['run']); } catch(WPHM_Security_Check_Exception $failure) { $rejected=true; }
        sc_same(true,$rejected,'bounded continuation rejected: '.$key);
    }
    $invalid_job=$job; $invalid_job['fingerprint']=str_repeat('0',64); set_transient($job_key,$invalid_job,600);
    $before=count($calls); $rejected=false;
    try { wphm_sc_report(true,'continue',$scan['run']); } catch(WPHM_Security_Check_Exception $failure) { $rejected=strpos($failure->getMessage(),'変更')!==false; }
    sc_same(true,$rejected,'stale inventory/config fingerprint rejected'); sc_same($before,count($calls),'changed fingerprint sends no metadata requests');
    set_transient($job_key,$job,600); update_option('wphm_sc_last_step',time()-3,false);
    $original=file_get_contents($test_files[0]); file_put_contents($test_files[0],str_replace('Version: 1.0','Version: 1.1',$original)); wp_clean_plugins_cache(false);
    $before=count($calls); $rejected=false;
    try { wphm_sc_report(true,'continue',$scan['run']); } catch(WPHM_Security_Check_Exception $failure) { $rejected=true; }
    sc_same(true,$rejected,'actual installed product change invalidates continuation'); sc_same($before,count($calls),'changed inventory sends no HTTP');
    file_put_contents($test_files[0],$original); wp_clean_plugins_cache(false); update_option('wphm_sc_last_step',time()-3,false);
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=$_REQUEST=['nonce'=>wp_create_nonce('wphm_security_check'),'operation'=>'continue','run'=>$scan['run']];
    ob_start(); try { wphm_sc_ajax(); } catch(SecurityCheckExit $exit) {} $continued_reply=json_decode(ob_get_clean(),true);
    sc_same(true,$continued_reply['success'],'authorized AJAX continuation succeeds');
    sc_same(true,$continued_reply['data']['scan']['auto'],'AJAX continuation advertises third batch');
    sc_same(16,$vuln_calls,'each AJAX continuation has its own eight-call bound');
    update_option('wphm_sc_last_step',time()-3,false);
    $_POST=$_REQUEST=['nonce'=>wp_create_nonce('wphm_security_check'),'wphm_sc_action'=>'continue','run'=>$scan['run']];
    wphm_sc_handle_request(); $continued=$GLOBALS['wphm_sc_page_report'];
    sc_same(false,$continued['scan']['auto'],'native POST continuation completes all remaining products');
    sc_same(0,$continued['scan']['pending'],'no products stuck behind failed request');
    sc_same(1,$continued['facts']['vulnerabilities']['failed'],'failed product disclosed separately');
    sc_same(23,$vuln_calls,'twenty-three eligible products include one failure tried only once');
    sc_same(false,get_transient($job_key),'completed job token discarded');
    sc_same(false,strpos(wp_json_encode($continued),'do-not-leak-file-path-or-token')!==false,'raw provider error never included in report');
    $stored=serialize(get_option('wphm_sc_vulnerability_metadata'));
    sc_same(true,strlen($stored)<=2097152,'bounded persisted vulnerability evidence');
    $before=count($calls); wphm_sc_collect(false);
    sc_same($before,count($calls),'read-only report sends no continuation request');
    $failed_read=wphm_sc_vulnerabilities(wphm_sc_inventory(),false);
    sc_same(1,$failed_read['failed'],'failure reason survives read-only report rebuild');
    delete_option('wphm_sc_last_recheck'); $fail_product='none';
    $retry=wphm_sc_report(true);
    sc_same(0,$retry['facts']['vulnerabilities']['failed'],'new explicit recheck can retry prior run failure');
    sc_same(24,$vuln_calls,'successful cached products not fetched again');
    sc_same('unknown',array_column($retry['items'],'level','id')['vulnerability_exploit'],'finished supported comparison does not clear unsupported products');
} finally {
    if($run_filter!==null) remove_filter('pre_http_request',$run_filter,40);
    foreach($test_files as $path) if(is_file($path)) unlink($path);
    wp_clean_plugins_cache(false);
}

// Controller abuse: deny before any outbound call and keep unexpected exception details private.
$nonce=wp_create_nonce('wphm_security_check');
$ajax_input=function(array $input) {
    $_SERVER['REQUEST_METHOD']='POST'; $_POST=$_REQUEST=$input;
    ob_start(); try { wphm_sc_ajax(); } catch(SecurityCheckExit $exit) {} return json_decode(ob_get_clean(),true);
};
foreach([['nonce'=>['bad']],['nonce'=>$nonce,'operation'=>['continue']],['nonce'=>$nonce,'operation'=>'continue','run'=>['bad']],['nonce'=>$nonce,'operation'=>'unknown']] as $input) {
    $before=count($calls); sc_same(false,$ajax_input($input)['success'],'malformed AJAX parameters denied');
    sc_same($before,count($calls),'malformed parameters cannot trigger HTTP');
}
sc_same(false,strpos(wphm_sc_failure_message(new RuntimeException('secret/path/token')),'secret/path/token')!==false,'unexpected exception hidden by controller formatter');
delete_option('wphm_sc_last_recheck');
$throwing=function() { throw new RuntimeException('secret/path/token'); }; add_filter('wphm_security_auth_facts',$throwing,99);
$reply=$ajax_input(['nonce'=>$nonce]); remove_filter('wphm_security_auth_facts',$throwing,99);
sc_same(true,$reply['success'],'failed local adapter is isolated and other result collection continues');
sc_same(false,strpos(wp_json_encode($reply),'secret/path/token')!==false,'unexpected adapter exception text never exposed');
sc_same(false,get_option('wphm_sc_check_lock',false),'unexpected exception releases owner lease');
$_SERVER['REQUEST_METHOD']='GET'; $_POST=$_REQUEST=[];

class SecurityCheckWordfenceUsers {
    public static function shared() { return new self(); }
    public function has_2fa_active($user) { return $GLOBALS['sc_wf_mfa'] ?? false; }
}
