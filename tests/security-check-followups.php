<?php
/** Follow-up regressions run inside disposable native WordPress, never against the live site. */
if(PHP_SAPI!=='cli' || !isset($connection,$checks)) exit;
$route_base=['app_password_users'=>0,'limited'=>false,'xmlrpc_auth'=>false,'application_passwords'=>false];
$route_discovery=['unknown'=>0,'limited'=>false,'callbacks'=>[]];
sc_same('ok',wphm_sc_alternate_auth_status($route_base,$route_discovery),'explicitly disabled standard alternate routes can be confirmed within scope');
foreach([['xmlrpc_auth'=>true],['application_passwords'=>true],['application_passwords'=>null],['limited'=>true]] as $change) {
    sc_same('pending',wphm_sc_alternate_auth_status(array_replace($route_base,$change),$route_discovery),'zero API registrations cannot erase incomplete route evidence');
}
foreach([['unknown'=>1],['limited'=>true]] as $change) sc_same('pending',wphm_sc_alternate_auth_status($route_base,array_replace($route_discovery,$change)),'unread custom authentication remains unconfirmed');
sc_same('attention',wphm_sc_alternate_auth_status(array_replace($route_base,['app_password_users'=>1]),$route_discovery),'observed API credentials retain attention');
$route_facts=$positive;
$route_facts['identity']=array_replace($route_facts['identity'],$route_base,['xmlrpc_auth'=>true]);
$route_items=array_column(wphm_sc_assess($route_facts),null,'id');
$route_checks=array_column($route_items['credential_stuffing']['checks'],null,'label');
sc_same('pending',$route_checks['別の認証経路']['status'],'actual assessment preserves the unknown XML-RPC route');
sc_same('unknown',$route_items['credential_stuffing']['level'],'actual graph cannot turn low from zero API registrations');
echo "PASS stage 1: alternate authentication route regression.\n";
$policy=wphm_sc_options();
$strong_limit=['enabled'=>true,'applied'=>true,'tries'=>5,'period'=>300,'seconds'=>600,'routes'=>['wp_login']];
sc_same(true,wphm_sc_limiter_meets_policy($strong_limit,$policy),'bounded active limiter meets configured policy');
foreach([['tries'=>1000000],['period'=>1],['seconds'=>0],['enabled'=>false],['applied'=>false],['routes'=>['xmlrpc']]] as $change) sc_same(false,wphm_sc_limiter_meets_policy(array_replace($strong_limit,$change),$policy),'weak or inapplicable limiter cannot satisfy policy');
$weak_facts=$positive; sc_limiter_evidence($weak_facts,['name'=>'Weak fixture','tries'=>100,'seconds'=>1]);
$weak_items=array_column(wphm_sc_assess($weak_facts),null,'id');
sc_same('attention',$weak_items['brute_force']['checks'][0]['status'],'actual check flags an overly permissive limiter');
sc_same('medium',$weak_items['brute_force']['level'],'actual graph reflects weak limiter configuration');
$saved_policy=get_option('wphm_sc_options',[]);
try {
    wphm_sc_save_options(['login_max_tries'=>'3','login_min_period'=>'120','login_min_lockout'=>'300']);
    sc_same(3,wphm_sc_options()['login_max_tries'],'policy saves via validated settings');
    sc_same(false,wphm_sc_limiter_meets_policy($strong_limit,wphm_sc_options()),'stricter policy is applied');
    foreach([['login_max_tries'=>['3']],['login_min_period'=>'0'],['login_min_lockout'=>'86401']] as $bad) {
        $rejected=false; try { wphm_sc_save_options($bad); } catch(WPHM_Security_Check_Exception $error) { $rejected=true; }
        sc_same(true,$rejected,'invalid policy values rejected');
    }
} finally { update_option('wphm_sc_options',$saved_policy,false); }
echo "PASS stage 2: limiter policy settings and graph.\n";
$vendor_options=get_option('wphm_sc_options',[]); $vendor_prefs=get_user_option('wphm_sc_sources',get_current_user_id());
$vendor_file=WP_CONTENT_DIR.'/plugins/vendor-followup.php';
file_put_contents($vendor_file,"<?php\n/* Plugin Name: Vendor follow-up\nVersion: 1.2.0\nUpdate URI: https://vendor.invalid/\n*/\n"); wp_cache_delete('plugins','plugins');
$vendor_key=hash('sha256','plugin:vendor-followup.php');
$vendor_map=['repository'=>'publisher/product','package'=>'vendor-followup','ecosystem'=>'other'];
$vendor_input=['source_wordpress'=>'1','source_vulnerabilities'=>'1','source_vendor'=>'1','vendor_sources'=>[$vendor_key=>$vendor_map],'gh_token'=>str_repeat('g',32)];
$vendor_mode='affected'; $vendor_http=0;
$vendor_response=function($pre,$args,$url) use (&$vendor_mode,&$vendor_http) {
    if(strpos($url,'https://api.github.com/repos/')!==0) return $pre;
    $vendor_http++;
    sc_same('Bearer '.str_repeat('g',32),$args['headers']['Authorization'],'GitHub credential sent only to fixed API');
    sc_same(0,$args['redirection'],'vendor requests never follow redirects');
    sc_same(true,$args['sslverify'],'vendor request verifies TLS');
    $advisory=['state'=>'published','ghsa_id'=>'GHSA-abcd-1234-efgh','html_url'=>'https://github.com/publisher/product/security/advisories/GHSA-abcd-1234-efgh','summary'=>'Vendor SQL issue <script>bad</script>','cwe_ids'=>['CWE-89'],'vulnerabilities'=>[['package'=>['name'=>'vendor-followup','ecosystem'=>'other'],'vulnerable_version_range'=>$vendor_mode==='unaffected'?'< 1.0.0':'< 2.0.0','patched_versions'=>'2.0.0']]];
    if($vendor_mode==='unsupported_range') $advisory['vulnerabilities'][0]['vulnerable_version_range']='1.x || 2.x';
    if($vendor_mode==='wrong_repository') $advisory['html_url']='https://github.com/other/repository/security/advisories/GHSA-abcd-1234-efgh';
    if($vendor_mode==='missing_ranges') $advisory['vulnerabilities']=[];
    if($vendor_mode==='permission') return ['response'=>['code'=>403],'headers'=>[],'body'=>'private-api-message'];
    return ['response'=>['code'=>200],'headers'=>[],'body'=>$vendor_mode==='empty_object'?'{}':wp_json_encode($vendor_mode==='empty'?[]:[$advisory])];
};
add_filter('pre_http_request',$vendor_response,20,3);
try {
    wphm_sc_save_sources($vendor_input);
    sc_same(true,wphm_sc_options()['vendor'],'vendor lookup is explicit per-administrator opt-in');
    sc_same(false,strpos(wphm_sc_options()['gh_secret'],str_repeat('g',32))!==false,'GitHub key encrypted at rest');
    $vi=['plugins'=>['vendor-followup.php'=>['Name'=>'Vendor follow-up','Version'=>'1.2.0','UpdateURI'=>'https://vendor.invalid/']],'themes'=>[],'mu_plugins'=>[],'dropins'=>[]];
    $vt=wphm_sc_lookup_targets($vi)[1];
    sc_same(true,$vt['eligible'],'registered private product has a real advisory reader');
    sc_same('publisher/product',$vt['vendor']['repository'],'repository binding retained');
    sc_same('aki-sho/wp-helpers-multi',wphm_sc_vendor_mapping(hash('sha256','plugin:'.plugin_basename(WPHM_PLUGIN_FILE)),['kind'=>'plugin','file'=>plugin_basename(WPHM_PLUGIN_FILE)])['repository'],'this plugin has its own publisher mapping');
    foreach(['affected','unaffected','empty','unsupported_range','wrong_repository','permission','empty_object','missing_ranges'] as $vendor_mode) {
        delete_option('wphm_sc_vulnerability_metadata'); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
        $vr=wphm_sc_vulnerabilities($vi,true,'vendor-fixture');
        if($vendor_mode==='affected') {
            sc_same(1,$vr['affected'],'publisher affected range reaches the vulnerability result');
            sc_same(true,in_array('CWE-89',$vr['cwe'],true),'publisher CWE reaches attack classification');
            sc_same(false,strpos(wp_json_encode($vr),'<script>')!==false,'publisher title sanitized');
        } elseif(in_array($vendor_mode,['unaffected','empty'],true)) {
            sc_same(0,$vr['affected'],'complete publisher comparison contains no affected version');
            sc_same(0,$vr['unknown'],'empty published advisory list is distinguished from failed response');
        } else sc_same(true,$vr['unknown']>0,'incomplete publisher data cannot become safe: '.$vendor_mode);
        sc_same(false,strpos(wp_json_encode($vr),str_repeat('g',32))!==false,'publisher result never contains key');
    }
    $stable_options=serialize(get_option('wphm_sc_options'));
    foreach([['vendor_sources'=>[$vendor_key=>array_replace($vendor_map,['repository'=>'https://127.0.0.1/private'])]],['vendor_sources'=>[$vendor_key=>array_replace($vendor_map,['repository'=>'publisher/..'])]],['vendor_sources'=>['../../path'=>$vendor_map]],['gh_token'=>['bad']]] as $bad) {
        $rejected=false; try { wphm_sc_save_sources(array_replace($vendor_input,$bad)); } catch(WPHM_Security_Check_Exception $error) { $rejected=true; }
        sc_same(true,$rejected,'unsafe publisher input rejected'); sc_same($stable_options,serialize(get_option('wphm_sc_options')),'unsafe publisher input is atomic');
    }
    sc_same(null,wphm_sc_github_range('>= 3.0, = 1.0'),'ambiguous two-bound equality remains unresolved');
    sc_same(null,wphm_sc_github_range('*'),'wildcard is never guessed safe');
    $vendor_input['gh_token']=''; unset($vendor_input['source_vendor']); wphm_sc_save_sources($vendor_input);
    $before=$vendor_http; delete_option('wphm_sc_vulnerability_metadata'); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
    $vr=wphm_sc_vulnerabilities($vi,true);
    sc_same($before,$vendor_http,'unselected publisher lookup makes no GitHub request');
    sc_same(1,$vr['unsupported'],'unselected private product remains explicitly outside comparison');
} finally {
    remove_filter('pre_http_request',$vendor_response,20); unlink($vendor_file); wp_cache_delete('plugins','plugins');
    update_option('wphm_sc_options',$vendor_options,false); update_user_option(get_current_user_id(),'wphm_sc_sources',$vendor_prefs,false);
    delete_option('wphm_sc_vulnerability_metadata');
}
echo "PASS stage 3: publisher advisory reader, mapping, safety and opt-in.\n";
$users_created=[]; $user_options_before=get_user_option('wphm_sc_sources',get_current_user_id());
$user_key='wphm_sc_users_'.get_current_user_id();
try {
    // Populate disposable native WP tables without spending time hashing passwords that will never be used.
    $wpdb->query('START TRANSACTION');
    for($i=0;$i<211;$i++) {
        $login='batchfixture'.$i;
        $wpdb->insert($wpdb->users,['user_login'=>$login,'user_pass'=>'!unusable-fixture-password','user_nicename'=>$login,'user_email'=>$login.'@example.invalid','user_registered'=>gmdate('Y-m-d H:i:s'),'display_name'=>$login]);
        $id=(int)$wpdb->insert_id; $users_created[]=$id;
        update_user_meta($id,$wpdb->get_blog_prefix().'capabilities',[$i===210?'administrator':'subscriber'=>true]);
    }
    $wpdb->query('COMMIT');
    delete_transient($user_key); delete_option('wphm_sc_last_recheck');
    update_user_option(get_current_user_id(),'wphm_sc_sources',['wordpress'=>true,'server'=>false,'edge'=>false,'updates'=>false,'vulnerabilities'=>false,'vendor'=>false],false);
    $total=count(get_users(['fields'=>'ID','number'=>-1]));
    $admin_total=count(get_users(['fields'=>'ID','capability'=>'manage_options','number'=>-1]));
    $users_step=wphm_sc_report(true);
    sc_same(200,$users_step['facts']['identity']['checked'],'one request reads at most 200 users');
    sc_same($admin_total,$users_step['facts']['identity']['admins'],'administrator with ID beyond first 200 is prioritized');
    sc_same(true,$users_step['scan']['users_pending'],'remaining users are explicitly pending');
    sc_same(0,$users_step['scan']['pending'],'user-only continuation works without pending products');
    sc_same(true,$users_step['scan']['auto'],'authorized report advertises automatic user continuation');
    $cached_users=get_transient($user_key);
    sc_same(false,strpos(serialize($cached_users),'batchfixture')!==false,'user scan cache contains no usernames');
    sc_same(false,strpos(serialize($cached_users),'unusable-fixture-password')!==false,'user scan cache contains no credentials');
    sc_same(200,wphm_sc_identity_facts(true,'view')['checked'],'view reuses bounded aggregate without advancing the cursor');
    delete_option('wphm_sc_last_step');
    $users_done=wphm_sc_report(true,'continue',$users_step['scan']['run']);
    sc_same($total,$users_done['facts']['identity']['checked'],'continuation reaches all users without duplication');
    sc_same(false,$users_done['facts']['identity']['limited'],'completed user enumeration clears partial state');
    sc_same(false,$users_done['scan']['users_pending'],'finished user-only scan has no pending batch');
    sc_same(false,$users_done['scan']['auto'],'completed user-only scan stops automatically');
    $bad_state=get_transient($user_key); $bad_state['owner']++;
    sc_same(false,wphm_sc_valid_user_scan($bad_state,wphm_sc_users_signature()),'another owner cannot reuse the scan');
    $bad_state=get_transient($user_key); $bad_state['site']++;
    sc_same(false,wphm_sc_valid_user_scan($bad_state,wphm_sc_users_signature()),'another site cannot reuse the scan');
    delete_option('wphm_sc_last_recheck'); $users_step=wphm_sc_report(true);
    update_user_meta(end($users_created),$wpdb->get_blog_prefix().'capabilities',['subscriber'=>true]);
    delete_option('wphm_sc_last_step');
    $rejected=false; try { wphm_sc_report(true,'continue',$users_step['scan']['run']); } catch(WPHM_Security_Check_Exception $error) { $rejected=strpos($error->getMessage(),'変更')!==false; }
    sc_same(true,$rejected,'role change invalidates in-progress user evidence');
    $fresh_users=wphm_sc_identity_facts(true,'view');
    sc_same(true,$fresh_users['limited'],'invalidated partial scan never masquerades as complete');
} finally {
    $wpdb->query('ROLLBACK');
    $wpdb->query('START TRANSACTION');
    foreach($users_created as $id) wp_delete_user($id);
    $wpdb->query('COMMIT');
    update_user_option(get_current_user_id(),'wphm_sc_sources',$user_options_before,false);
    delete_transient($user_key); delete_transient('wphm_sc_job_'.get_current_user_id()); delete_option('wphm_sc_last_recheck');
}
echo "PASS stage 5: administrator-first batches, continuation and cache isolation.\n";
$reason_options=get_option('wphm_sc_options',[]); $reason_prefs=get_user_option('wphm_sc_sources',get_current_user_id());
$reason_mode='site';
$reason_reader=function($readers) use (&$reason_mode) {
    $readers['reason_fixture']=['source'=>'server','name'=>'Reason fixture','read'=>function($context) use (&$reason_mode) {
        if($reason_mode==='http_403') throw new WPHM_Security_Read_Exception('http_403');
        if($reason_mode==='exception') throw new RuntimeException('private-host-key-error-marker');
        return ['schema'=>1,'site'=>$reason_mode==='site'?'https://other.invalid/':$context['site'],'checked_at'=>time()+($reason_mode==='future'?120:($reason_mode==='stale'?-301:0)),'capabilities'=>['waf'=>true]];
    }]; return $readers;
};
$reason_cf=function($pre,$args,$url) {
    if(strpos($url,'https://api.cloudflare.com/')!==0) return $pre;
    $body=['success'=>true,'result'=>['id'=>str_repeat('a',32),'name'=>'example.invalid']]; $status=200;
    if(strpos($url,'/graphql')!==false) $body=['data'=>['viewer'=>['zones'=>[['httpRequests1hGroups'=>[['sum'=>['requests'=>100]]],'firewallEventsAdaptive'=>[]]]]]];
    if(strpos($url,'http_request_firewall_managed')!==false) { $status=403; $body=['private'=>'private-api-error-marker']; }
    if(strpos($url,'http_ratelimit')!==false) { $status=401; $body=['private'=>'private-api-error-marker']; }
    return ['response'=>['code'=>$status],'headers'=>[],'body'=>wp_json_encode($body)];
};
add_filter('wphm_security_readers',$reason_reader); add_filter('pre_http_request',$reason_cf,30,3);
try {
    wphm_sc_save_sources(['source_wordpress'=>'1','source_server'=>'1','server_provider'=>'reason_fixture']);
    foreach(['site','stale','future','http_403','exception'] as $reason_mode) {
        $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
        $reason_result=wphm_sc_reader_facts('server',true);
        sc_same('error',$reason_result['status'],'failed reader remains failed');
        sc_same($reason_mode,$reason_result['reason'],'reader exposes only the specific public reason code');
        sc_same(wphm_sc_read_reason($reason_mode),wphm_sc_source_reason($reason_result),'reader reason includes an appropriate next step');
        sc_same(false,strpos(wp_json_encode($reason_result),'private-host-key-error-marker')!==false,'internal exception text never escapes');
    }
    $GLOBALS['wphm_sc_deadline']=microtime(true)-1;
    sc_same('budget',wphm_sc_reader_facts('server',true)['reason'],'request budget exhaustion has its own reason');
    sc_same('schema',(new WPHM_Security_Read_Exception('private-unknown-error'))->reason,'unknown provider codes cannot become raw output');
    wphm_sc_save_sources(['source_wordpress'=>'1','source_edge'=>'1','cf_zone'=>str_repeat('a',32),'cf_token'=>str_repeat('c',32)]);
    $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
    $partial_cf=wphm_sc_cloudflare(true);
    sc_same(true,$partial_cf['known'],'successful Cloudflare statistics survive unrelated API errors');
    sc_same('http_403',$partial_cf['failures']['waf'],'Cloudflare WAF permission failure distinguished');
    sc_same('http_401',$partial_cf['failures']['login_limits'],'Cloudflare rate-limit authentication failure distinguished');
    $partial_sources=wphm_sc_acquisition_facts(['identity'=>['limiters'=>[]],'cloudflare'=>$partial_cf],false);
    $partial_reason=wphm_sc_source_reason($partial_sources['sources']['edge']);
    sc_same(true,strpos($partial_reason,'管理WAF')!==false && strpos($partial_reason,'ログイン制限')!==false,'partial source names each failed resource');
    sc_same(false,strpos(wp_json_encode($partial_cf),'private-api-error-marker')!==false,'API response bodies are never saved as error details');
    sc_same(false,strpos(wp_json_encode($partial_cf),str_repeat('c',32))!==false,'error metadata contains no token');
    sc_same(true,strpos(wphm_sc_lookup_reason('http_401'),'認証')!==false,'publisher authentication failure has actionable text');
} finally {
    remove_filter('wphm_security_readers',$reason_reader); remove_filter('pre_http_request',$reason_cf,30);
    update_option('wphm_sc_options',$reason_options,false); update_user_option(get_current_user_id(),'wphm_sc_sources',$reason_prefs,false);
    delete_option('wphm_sc_reader_server'); delete_option('wphm_sc_cf_metadata');
}
echo "PASS stage 6: specific acquisition failures, recovery advice and secret protection.\n";
$service_options=get_option('wphm_sc_options',[]); $service_prefs=get_user_option('wphm_sc_sources',get_current_user_id());
$service_mode='valid'; $service_calls=[]; $panel_answers=['93.184.216.34'];
$panel_dns=function() use (&$panel_answers){return $panel_answers;};
$service_fixture=function($pre,$args,$url) use (&$service_mode,&$service_calls) {
    if(strpos($url,'https://panel.example.com:2083/')!==0 && strpos($url,'https://api.fastly.com/')!==0) return $pre;
    $service_calls[]=$url;
    sc_same(true,$args['sslverify'],'service reader verifies TLS'); sc_same(0,$args['redirection'],'service reader blocks redirects');
    sc_same(true,$args['reject_unsafe_urls'],'service reader uses the safe HTTP API');
    sc_same(65537,$args['limit_response_size'],'service response size bounded');
    if(strpos($url,'panel.example.com')!==false) {
        sc_same('cpanel account:'.str_repeat('p',32),$args['headers']['Authorization'],'cPanel key goes to selected panel only');
        sc_same(false,isset($args['headers']['Fastly-Key']),'Fastly key absent from panel request');
        $data=['module'=>'ModSecurity','func'=>'list_domains','result'=>['status'=>1,'data'=>[['domain'=>$service_mode==='wrong_panel_site'?'other.invalid':'example.invalid','enabled'=>1]],'errors'=>null]];
    } else {
        sc_same(str_repeat('f',32),$args['headers']['Fastly-Key'],'Fastly key goes to fixed Fastly API only');
        sc_same(false,isset($args['headers']['Authorization']),'panel credential absent from Fastly request');
        if(strpos($url,'/version/active')!==false) $data=['number'=>3,'active'=>true,'service_id'=>'ABCDEF1234567890abcdef'];
        elseif(strpos($url,'/domain')!==false) {
            $data=[['name'=>'example.invalid','service_id'=>'ABCDEF1234567890abcdef']];
            if($service_mode==='shared_fastly_service') $data[]=['name'=>'other.invalid'];
            if($service_mode==='deleted_fastly_domain') $data[0]['deleted_at']='2026-01-01T00:00:00Z';
        } else {
            parse_str((string)wp_parse_url($url,PHP_URL_QUERY),$query); $rows=[];
            for($i=0;$i<5;$i++) $rows[]=['start_time'=>(int)$query['from']+$i*60,'requests'=>10,'status_5xx'=>0];
            if($service_mode==='bad_fastly_counter') $rows[0]['requests']='10';
            if($service_mode==='wrong_fastly_stats') $rows[0]['service_id']='OTHER1234567890';
            $data=['status'=>'success','data'=>$rows];
        }
    }
    if($service_mode==='redirect') return ['response'=>['code'=>302],'headers'=>['location'=>'http://127.0.0.1/private'],'body'=>'private-provider-body'];
    if($service_mode==='forbidden') return ['response'=>['code'=>403],'headers'=>[],'body'=>'private-provider-body'];
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode($data)];
};
add_filter('wphm_security_panel_addresses',$panel_dns); add_filter('pre_http_request',$service_fixture,40,3);
try {
    $service_input=['source_wordpress'=>'1','source_server'=>'1','source_edge'=>'1','server_provider'=>'cpanel','server_connection'=>'account@panel.example.com','server_token'=>str_repeat('p',32),'edge_provider'=>'fastly','edge_connection'=>'ABCDEF1234567890abcdef','edge_token'=>str_repeat('f',32)];
    wphm_sc_save_sources($service_input);
    sc_same(true,isset(wphm_sc_readers()['cpanel'],wphm_sc_readers()['fastly']),'service connectors available without extra PHP');
    $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
    $panel=wphm_sc_reader_facts('server',true);
    sc_same('ok',$panel['status'],'panel reader fetches the domain configuration');
    sc_same(true,$panel['capabilities']['waf'],'panel WAF setting normalized');
    sc_same(false,isset($panel['capabilities']['uploads_execution']),'WAF setting never claims upload execution prevention');
    $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
    $fastly=wphm_sc_reader_facts('edge',true);
    sc_same('ok',$fastly['status'],'Fastly reader verifies active service domain');
    sc_same(50,$fastly['traffic']['requests'],'Fastly bounded minute samples aggregated');
    sc_same(10,$fastly['traffic']['peak'],'Fastly per-minute peak preserves threshold unit');
    sc_same(300,$fastly['traffic']['to']-$fastly['traffic']['from'],'actual observation interval retained');
    foreach(['wrong_panel_site','shared_fastly_service','deleted_fastly_domain','bad_fastly_counter','wrong_fastly_stats','redirect','forbidden'] as $service_mode) {
        $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
        $source=$service_mode==='wrong_panel_site'?'server':'edge';
        $bad=wphm_sc_reader_facts($source,true);
        sc_same('error',$bad['status'],'unsafe or failed service data stays unconfirmed: '.$service_mode);
        sc_same(false,strpos(wp_json_encode($bad),'private-provider-body')!==false,'service response body never leaks into failure');
    }
    $service_mode='valid';
    foreach(['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','224.0.0.1','198.18.0.1','::1','::ffff:127.0.0.1','2001:db8::1','2002:7f00:1::1'] as $address) {
        $panel_answers=[$address]; $before=count($service_calls); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
        sc_same('target',wphm_sc_reader_facts('server',true)['reason'],'private address blocked before panel connection');
        sc_same($before,count($service_calls),'private address sends no credential request');
    }
    $panel_answers=['93.184.216.34'];
    foreach(['https://panel.example.com/','account@127.0.0.1','account@panel.example.com/../private','account@localhost'] as $bad) sc_same(null,wphm_sc_cpanel_connection($bad),'panel connection rejects URL/path/internal literal');
    $before=count($service_calls); $rejected=false;
    try { wphm_sc_service_get('https://panel.example.com:2083/execute/ModSecurity/disable_all_domains',[],['deadline'=>microtime(true)+3],'panel.example.com'); } catch(WPHM_Security_Read_Exception $error) { $rejected=true; }
    sc_same(true,$rejected,'panel mutation endpoint cannot be called'); sc_same($before,count($service_calls),'blocked endpoint sends no request');
    sc_same([80,443],apply_filters('http_allowed_safe_ports',[80,443],'panel.example.com','https://panel.example.com:2083/execute/ModSecurity/list_domains'),'panel port exception does not persist after request');
    $changed=$service_input; $changed['server_connection']='account@different.example.com'; $changed['server_token']=''; $changed['edge_token']=''; wphm_sc_save_sources($changed);
    sc_same('',wphm_sc_options()['server_secret'],'changed connection cannot reuse a key for the old host');
    sc_same('setup',wphm_sc_reader_facts('server',false)['status'],'changed target requires a new key before connecting');
    $local_switch=$changed; $local_switch['server_provider']='local'; $local_switch['edge_provider']='cloudflare';
    wphm_sc_save_sources($local_switch);
    sc_same('',wphm_sc_options()['server_connection'],'switching to local PHP ignores an old hidden panel connection');
    sc_same('',wphm_sc_options()['server_secret'],'switching to local PHP removes unused provider credential');
    sc_same('partial',wphm_sc_reader_facts('server',true)['status'],'local PHP can be read after switching from a panel');
} finally {
    remove_filter('wphm_security_panel_addresses',$panel_dns); remove_filter('pre_http_request',$service_fixture,40);
    update_option('wphm_sc_options',$service_options,false); update_user_option(get_current_user_id(),'wphm_sc_sources',$service_prefs,false);
    delete_option('wphm_sc_reader_server'); delete_option('wphm_sc_reader_edge');
}
echo "PASS stage 4: user-configured cPanel/Fastly readers, site binding, network guards and credential isolation.\n";
foreach([['1'],true,false,'1suffix','1e2','-1','1.5',PHP_INT_MAX.'0'] as $bad_id) sc_same(0,wphm_positive_id($bad_id),'malformed mutation ID rejected');
sc_same([1,2],wphm_positive_ids([1,'2','1',['3'],'4bad',true]),'bulk IDs cannot coerce nested input into another record');
$mutations_table=wphm_access_log_table_name(); $rows_before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$mutations_table}");
sc_same(0,wphm_access_log_repo_delete_ids([['1'],true,'1suffix']),'malformed repository input never deletes record 1');
sc_same($rows_before,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$mutations_table}"),'malformed bulk mutation preserves log rows');
$mutation_post=$_POST; $mutation_request=$_REQUEST; $mutation_method=$_SERVER['REQUEST_METHOD'];
try {
    $_SERVER['REQUEST_METHOD']='POST';
    foreach([['1'],'1suffix',true] as $bad_id) {
        $_POST=$_REQUEST=['nonce'=>wp_create_nonce('wphm_access_log_ajax'),'id'=>$bad_id];
        ob_start(); try { wphm_access_log_ajax_delete(); } catch(SecurityCheckExit $exit) {} $mutation_reply=json_decode(ob_get_clean(),true);
        sc_same(false,$mutation_reply['success'],'actual log deletion endpoint rejects malformed ID');
    }
    sc_same($rows_before,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$mutations_table}"),'malformed AJAX requests cannot mutate log rows');
} finally { $_POST=$mutation_post; $_REQUEST=$mutation_request; $_SERVER['REQUEST_METHOD']=$mutation_method; }
echo "PASS whole-plugin hardening: strict mutation IDs and actual endpoint preservation.\n";
