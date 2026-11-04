<?php
// Included inside the disposable native WordPress integration test, never a web endpoint.
if (PHP_SAPI !== 'cli' || !isset($connection,$checks)) exit;
$api_mode='current';
sc_same(true,wphm_sc_telemetry_schema(),'independent counters schema available');
$counts_table=wphm_sc_telemetry_table();
$wpdb->query("TRUNCATE TABLE {$counts_table}");
$classified=wphm_sc_classify_request(['search'=>'union select','file'=>'%252e%252e%252fprivate'],['text'=>'<svg onload=x>'],'/normal');
foreach (['sql_hits','xss_hits','traversal_hits'] as $key) sc_same(1,$classified[$key],'bounded input classification '.$key);
$private=wphm_sc_classify_request(['password'=>'union select','access_token'=>'../','email'=>'<svg>'],['pwd'=>'<script>'],'/normal');
foreach (['sql_hits','xss_hits','traversal_hits'] as $key) sc_same(0,$private[$key],'secret field excluded '.$key);
sc_same(1,wphm_sc_classify_request(['a'=>str_repeat('x',9000)],[],'/')['truncated'],'oversized value is truncated before classification');
sc_same(1,wphm_sc_classify_request(array_fill(0,100,'x'),[],'/')['truncated'],'node count bounded');
$_SERVER['HTTP_USER_AGENT']='fixturebot';
$_GET=['search'=>'union select']; $_POST=[];
$previous_status=http_response_code(); wphm_sc_observe_request(); http_response_code(503); wphm_sc_flush_request(); http_response_code($previous_status ?: 200);
sc_same(1,wphm_sc_telemetry_facts()['requests'],'bot-identifying requests counted independently');
sc_same(1,wphm_sc_telemetry_facts()['sql_hits'],'only category persisted');
sc_same(1,wphm_sc_telemetry_facts()['errors'],'final HTTP error status counted');
$auth_window=intdiv(time(),300); $auth_minute=intdiv(time(),60);
for($i=0;$i<20;$i++) wphm_sc_auth_event('private-user-not-to-be-stored',false);
wphm_sc_auth_event('private-user-not-to-be-stored',true);
$observed=wphm_sc_telemetry_facts();
sc_same(20,$observed['failures'],'authentication failures counted');
sc_same(1,$observed['successes'],'password-stage success counted');
sc_same(true,$observed['after_failure']===1 || $auth_window!==intdiv(time(),300),'success following failures in the same five-minute window observed');
sc_same(true,$observed['auth_peak']===20 || $auth_minute!==intdiv(time(),60),'minute-level failed authentication peak');
$persisted=wp_json_encode($wpdb->get_results("SELECT * FROM {$counts_table}",ARRAY_A));
foreach(['private-user-not-to-be-stored','fixturebot','REMOTE_ADDR','union select'] as $secret) sc_same(false,strpos($persisted,$secret)!==false,'raw private data never stored: '.$secret);
sc_same(true,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$counts_table}")<=1568,'fixed counter storage bound');
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$counts_table}");
wphm_sc_counter(9999,intdiv(time(),60),['requests'=>1]);
sc_same($before,(int)$wpdb->get_var("SELECT COUNT(*) FROM {$counts_table}"),'invalid ring slots rejected');
$epoch=intdiv(time(),60); $slot=$epoch%1440;
$wpdb->query("TRUNCATE TABLE {$counts_table}");
wphm_sc_counter($slot,$epoch,['requests'=>1]);
$wpdb->query($wpdb->prepare("UPDATE {$counts_table} SET requests=60000 WHERE slot=%d",$slot));
wphm_sc_counter($slot,$epoch,['requests'=>1]);
sc_same(60000,(int)$wpdb->get_var($wpdb->prepare("SELECT requests FROM {$counts_table} WHERE slot=%d",$slot)),'counter saturation is bounded');
sc_same(true,wphm_sc_telemetry_facts()['saturated'],'saturation disclosed');
wphm_sc_counter($slot,$epoch-1440,['requests'=>1]);
sc_same(0,wphm_sc_telemetry_facts()['requests'],'expired/reused ring bucket excluded');
$base_facts=wphm_sc_collect()['facts']; $base_facts['telemetry']=$observed;
$new_items=array_column(wphm_sc_assess($base_facts),null,'id');
sc_same('medium',$new_items['brute_force']['level'],'observed concentration produces scoped warning');
if($observed['after_failure']>0) sc_same('medium',$new_items['credential_stuffing']['level'],'post-failure success is attention, not claimed breach');
$base_facts['telemetry']['peak']=601;
sc_same('medium',array_column(wphm_sc_assess($base_facts),'level','id')['ddos'],'high application volume is attention, not confirmed DDoS');
sc_limiter_evidence($base_facts,['name'=>'fixture limiter','tries'=>4,'seconds'=>1200]);
$base_facts['telemetry']['auth_peak']=0; $base_facts['telemetry']['focused_failures']=0;
sc_same('low',array_column(wphm_sc_assess($base_facts),'level','id')['brute_force'],'known limiter setting has explicitly limited low scope');

foreach (['lt'=>['1.0',true],'le'=>['2.0',true],'gt'=>['3.0',true],'ge'=>['2.0',true],'eq'=>['2.0',true],'ne'=>['1.0',true]] as $op=>$test) sc_same($test[1],wphm_sc_range($test[0],['min_version'=>null,'min_operator'=>null,'max_version'=>'2.0','max_operator'=>$op]),'supported range operator '.$op);
sc_same(false,wphm_sc_range('2.0',['max_version'=>'2.0','max_operator'=>'lt']),'fixed boundary not affected');
sc_same(null,wphm_sc_range('1.0',['max_version'=>'2.0','max_operator'=>'unknown']),'unknown comparison never guessed');
sc_same(null,wphm_sc_range('1.0',[]),'missing affected range is unknown');
$vuln=['name'=>'Fixture SQL issue <img src=x>','operator'=>['min_version'=>'0.1','min_operator'=>'ge','max_version'=>'2.0','max_operator'=>'lt','unfixed'=>'0'],'source'=>[['name'=>'CVE fixture','link'=>'https://www.cve.org/CVERecord?id=CVE-2099-0001'],['name'=>'bad','link'=>'javascript:bad']],'impact'=>['cwe'=>[['cwe'=>'CWE-89']],'cvss3'=>['pr'=>'low','ui'=>'none','av'=>'network']]];
$record=wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'sc-fixture','vulnerability'=>[$vuln]]],'plugin','sc-fixture','1.0');
sc_same(1,count($record['matches']),'affected product version matched');
sc_same(1,count($record['matches'][0]['sources']),'unsafe source URL discarded');
sc_same('2.0',$record['matches'][0]['fix'],'exclusive upper bound provides fixed-version candidate');
sc_same(null,wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'other','vulnerability'=>[]]],'plugin','sc-fixture','1.0'),'wrong product response rejected');
sc_same(null,wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'sc-fixture']],'plugin','sc-fixture','1.0'),'missing vulnerability list is unknown');
$large_record=wphm_sc_vulnerability_record(['error'=>0,'data'=>['plugin'=>'sc-fixture','vulnerability'=>array_fill(0,100,$vuln)]],'plugin','sc-fixture','1.0');
sc_same(true,count($large_record['matches'])<=20,'per-product findings bounded');
sc_same(true,strlen(serialize($large_record))<=32768,'per-product evidence storage bounded');
sc_same(true,$large_record['unresolved']>=80,'omitted findings disclosed as unresolved');
$base_facts['vulnerabilities']=['checked'=>1,'unknown'=>0,'total'=>1,'matches'=>[['cwe'=>['CWE-89']]+$record['matches'][0]]];
$vitems=array_column(wphm_sc_assess($base_facts),null,'id');
sc_same('high',$vitems['vulnerability_exploit']['level'],'known affected range warning');
sc_same('high',$vitems['sql_injection']['level'],'CWE classification maps to corresponding attack row');

$api_mode='insecure'; wphm_sc_collect(true);
delete_transient('wphm_security_check_'.WPHM_VERSION.'_'.get_current_user_id());
sc_same('high',array_column(wphm_sc_collect(false)['items'],'level','id')['vulnerability_exploit'],'official high survives display cache expiry');
$changed_inventory=wphm_sc_inventory(); $changed_inventory['plugins']['new/fixture.php']=['Name'=>'New fixture','Version'=>'1.0','PluginURI'=>'','UpdateURI'=>''];
sc_same('insecure',wphm_sc_update_facts($changed_inventory,false)['core']['status'],'plugin inventory changes do not erase unchanged core evidence');
$meta=get_option('wphm_sc_update_metadata'); $meta['checked_at']=time()-2*DAY_IN_SECONDS; update_option('wphm_sc_update_metadata',$meta,false);
$expired=wphm_sc_collect(false);
sc_same(false,$expired['facts']['updates']['core']['known'],'expired official evidence becomes unknown');
sc_same(true,strpos($expired['facts']['updates']['core']['source'],'期限切れ')!==false,'expiry explicitly visible');
$api_mode='current'; wphm_sc_collect(true);
$GLOBALS['wphm_sc_deadline']=microtime(true)-1;
$before=count($calls); sc_same(null,wphm_sc_http('https://api.wordpress.org/core/stable-check/1.0/'),'overall external deadline enforced');
sc_same($before,count($calls),'expired budget starts no request');
sc_same(null,wphm_sc_http('https://127.0.0.1/private'),'arbitrary remote target rejected');
sc_same(null,wphm_sc_http('https://api.cloudflare.com:444/client/v4/graphql'),'nonstandard port rejected');
unset($GLOBALS['wphm_sc_deadline']);

$token='fixture-token-abcdefghijklmnopqrstuvwxyz';
$cipher=wphm_sc_secret($token);
sc_same(false,strpos($cipher,$token)!==false,'stored credential encrypted');
sc_same($token,wphm_sc_secret($cipher,true),'encrypted credential round trip');
sc_same('',wphm_sc_secret(base64_encode('bad'),true),'corrupt credential rejected');
wphm_sc_save_options(['vulnerabilities'=>1,'cf_zone'=>str_repeat('a',32),'cf_token'=>$token,'uploads_execution'=>'yes','vendor_patches'=>'yes','evidence'=>'fixture evidence']);
$options=wphm_sc_options();
sc_same(false,strpos(wp_json_encode($options),$token)!==false,'settings do not retain plaintext token');
sc_same('yes',$options['uploads_execution'],'administrator server attestation saved separately');
$cf_mode='valid';
add_filter('pre_http_request',function($pre,$args,$url) use (&$cf_mode) {
    if (wp_parse_url($url,PHP_URL_HOST)!=='api.cloudflare.com') return $pre;
    if ($cf_mode==='failure') return new WP_Error('fixture','connection failed');
    if (strpos($url,'/graphql')!==false) $data=['data'=>['viewer'=>['zones'=>[['httpRequests1hGroups'=>[['sum'=>['requests'=>123]]]]]]]];
    elseif (strpos($url,'/rulesets/')!==false) $data=['success'=>true,'result'=>['rules'=>[['enabled'=>true,'action'=>'execute'],['enabled'=>false,'action'=>'execute']]]];
    else $data=['success'=>true,'result'=>['name'=>$cf_mode==='wrong-zone' ? 'other.invalid' : 'example.invalid']];
    return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode($data)];
},20,3);
$cf=wphm_sc_cloudflare(true);
sc_same(true,$cf['known'],'fixed-host Cloudflare integration reads valid matching zone');
sc_same(123,$cf['requests'],'Cloudflare aggregate parsed');
sc_same(1,$cf['waf_rules'],'only enabled managed WAF execute rules counted');
sc_same(false,strpos(wp_json_encode($cf),$token)!==false,'credential absent from result');
$cf_mode='wrong-zone'; $before=count($calls); $cf=wphm_sc_cloudflare(true);
sc_same(false,$cf['known'],'wrong-zone data rejected');
sc_same(1,count($calls)-$before,'wrong zone stops further analytics requests');
$cf_mode='failure'; sc_same(false,wphm_sc_cloudflare(true)['known'],'API failure is unknown, not cached successful result');
$cf_mode='valid'; wphm_sc_cloudflare(true);
foreach($calls as $call) if(wp_parse_url($call['url'],PHP_URL_HOST)!=='api.cloudflare.com') sc_same(false,isset($call['args']['headers']['Authorization']),'Cloudflare credential never sent to another host');
ob_start(); wphm_sc_acquisition_fields_html(); $settings_html=ob_get_clean();
sc_same(false,strpos($settings_html,$token)!==false,'saved token not rendered into form');
sc_same(true,strpos($settings_html,'保存済み')!==false,'blank credential field explains retention');
wphm_sc_save_options(['vulnerabilities'=>1,'cf_zone'=>'','clear_cf'=>1]);
sc_same('',wphm_sc_options()['cf_secret'],'credential deletion supported');

$lease=wphm_sc_acquire_lease();
$blocked=false; try { wphm_sc_acquire_lease(); } catch(RuntimeException $error){$blocked=true;}
sc_same(true,$blocked,'atomic lease excludes concurrent owners');
wphm_sc_release_lease('incorrect-owner');
sc_same($lease,get_option('wphm_sc_check_lock'),'non-owner cannot release lease');
wphm_sc_release_lease($lease);
add_option('wphm_sc_check_lock',wp_json_encode(['token'=>'expired','expires'=>time()-1]),'',false);
$lease=wphm_sc_acquire_lease(); sc_same(true,strpos($lease,'expired')===false,'expired lease recovered'); wphm_sc_release_lease($lease);
delete_option('wphm_sc_last_recheck'); wphm_sc_report(true);
$blocked=false; try { wphm_sc_report(true); } catch(RuntimeException $error){$blocked=strpos($error->getMessage(),'20秒')!==false;}
sc_same(true,$blocked,'sequential rechecks throttled');
sc_same(false,get_option('wphm_sc_check_lock',false),'cooldown error releases its lease');

wp_using_ext_object_cache(true);
wp_cache_set('php_check_'.md5(PHP_VERSION),['is_secure'=>true],'site-transient',60);
sc_same(['is_secure'=>true],wphm_sc_saved_site_transient('php_check_'.md5(PHP_VERSION)),'external object-cache site-transient read path');
wp_using_ext_object_cache(false);
sc_same(true,wphm_sc_audit_facts()['known'],'reviewed distribution files match manifest');
$unavailable=$base_facts; $unavailable['logs']['available']=false; $unavailable['telemetry']=['available'=>false];
sc_same(true,strpos(array_column(wphm_sc_assess($unavailable),'state','id')['sql_injection'],'未計測')!==false,'unavailable observation is not displayed as zero');

// Adapter fixtures expose the documented vendor methods; no login attempt is made.
if (!class_exists('Two_Factor_Core')) {
    class Two_Factor_Core {
        public static function get_primary_provider_for_user($user) { if(!empty($GLOBALS['fixture_mfa_throw'])) throw new RuntimeException('adapter failure'); return new stdClass(); }
        public static function is_user_using_two_factor($user) { return !empty($GLOBALS['fixture_mfa_enabled']); }
    }
}
$GLOBALS['fixture_mfa_enabled']=true;
$id=wphm_sc_identity_facts();
sc_same(true,$id['admins']>0,'administrator subset observed for MFA');
sc_same($id['admins'],$id['mfa_known'],'supported MFA adapter covers observed administrators');
sc_same($id['admins'],$id['mfa_enabled'],'MFA demand read from vendor adapter');
$GLOBALS['fixture_mfa_enabled']=false;
sc_same(0,wphm_sc_identity_facts()['mfa_enabled'],'disabled MFA not inferred from plugin presence');
$GLOBALS['fixture_mfa_throw']=true;
sc_same(0,wphm_sc_identity_facts()['mfa_known'],'incompatible MFA adapter remains unknown');
$GLOBALS['fixture_mfa_throw']=false;
if (!class_exists('Limit_Login_Attempts')) {
    class Limit_Login_Attempts {
        public function get_option($name) { return $name==='allowed_retries' ? 4 : 1200; }
        public function wp_authenticate_user($user,$password) { return $user; }
    }
}
$limiter=new Limit_Login_Attempts(); add_filter('wp_authenticate_user',[$limiter,'wp_authenticate_user'],99999,2);
sc_same(4,wphm_sc_identity_facts()['limiter']['tries'],'active limiter callback and actual retry setting read');
sc_same(1200,wphm_sc_identity_facts()['limiter']['seconds'],'actual lockout duration read');
remove_filter('wp_authenticate_user',[$limiter,'wp_authenticate_user'],99999);
sc_same(null,wphm_sc_identity_facts()['limiter'],'installed class without callback is not protection');

$many=['plugins'=>[],'themes'=>[],'mu_plugins'=>[],'dropins'=>[]];
for($i=0;$i<12;$i++) $many['plugins']['fixture-'.$i.'/main.php']=['Name'=>'Fixture '.$i,'Version'=>'1.0','UpdateURI'=>''];
delete_option('wphm_sc_vulnerability_metadata'); delete_option('wphm_sc_vulnerability_cursor');
$GLOBALS['wphm_sc_deadline']=microtime(true)+25;
$before=count($calls); $batch=wphm_sc_vulnerabilities($many,true);
sc_same(8,count($calls)-$before,'vulnerability requests bounded to eight per refresh');
sc_same(8,$batch['checked'],'first bounded batch coverage');
$before=count($calls); $batch=wphm_sc_vulnerabilities($many,true);
sc_same(5,count($calls)-$before,'next refresh continues remaining products');
sc_same(13,$batch['checked'],'completed products retained across batches');
$many['plugins']['fixture-0/main.php']['Version']='2.0';
$before=count($calls); wphm_sc_vulnerabilities($many,true);
sc_same(1,count($calls)-$before,'installed version change invalidates cached comparison');

$mu_file=WP_CONTENT_DIR.'/mu-plugins/sc-inventory-fixture.php'; $dropin_file=WP_CONTENT_DIR.'/advanced-cache.php';
try {
    file_put_contents($mu_file,"<?php\n/* Plugin Name: Must-use inventory fixture\nVersion: 1.0\n*/\n");
    file_put_contents($dropin_file,"<?php\n/* Plugin Name: Drop-in inventory fixture\nVersion: 1.0\n*/\n");
    $inventory=wphm_sc_inventory();
    sc_same(true,isset($inventory['mu_plugins']['sc-inventory-fixture.php']),'must-use components included');
    sc_same(true,isset($inventory['dropins']['advanced-cache.php']),'drop-in components included');
    $extra=wphm_sc_vulnerabilities($inventory,false);
    sc_same(true,$extra['unknown']>=2,'unmapped MU/drop-in products explicitly unverified');
} finally { if(is_file($mu_file)) unlink($mu_file); if(is_file($dropin_file)) unlink($dropin_file); }
wphm_sc_cleanup_counts();
sc_same(0,(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$counts_table} WHERE slot<1440 AND epoch<=%d",intdiv(time(),60)-1440)),'expired counter rows cleaned incrementally');
$fields=$base_facts; $fields['audit']=['known'=>false]; $fields['vulnerabilities']=['matches'=>[]]; $fields['registration']=false;
sc_same('unknown',array_column(wphm_sc_assess($fields),'level','id')['csrf'],'unmatched audit files never claim reviewed low risk');

$range_record=$record['matches'][0]; $range_record['component']='Fixture <script>'; $range_record['version']='1.0'; $range_record['checked_at']=time();
$escape_facts=wphm_sc_collect(false)['facts']; $escape_facts['vulnerabilities']['matches']=[$range_record];
ob_start(); wphm_sc_evidence_html($escape_facts); $evidence_html=ob_get_clean();
sc_same(false,strpos($evidence_html,'<script>')!==false,'vulnerability details escape product names');
sc_same(true,strpos($evidence_html,'&lt;script&gt;')!==false,'escaped evidence remains readable');

foreach(['wphm_su_ajax','wphm_sr_ajax','wphm_access_log_ajax_delete'] as $handler) {
    $_SERVER['REQUEST_METHOD']='GET'; $_POST=$_REQUEST=[];
    ob_start(); try{$handler();}catch(SecurityCheckExit $error){} $reply=json_decode(ob_get_clean(),true);
    sc_same(false,$reply['success'],'state-changing AJAX requires POST: '.$handler);
    sc_same(true,strpos($reply['data']['message'],'POST')!==false,'method rejection precedes action processing: '.$handler);
}
$_SERVER['REQUEST_METHOD']='POST';
$_POST=$_REQUEST=['nonce'=>wp_create_nonce('wphm_security_check'),'wphm_sc_action'=>'settings','vulnerabilities'=>'1'];
$before=count($calls); wphm_sc_handle_request();
sc_same($before,count($calls),'native settings save sends no external requests');
sc_same(true,isset($GLOBALS['wphm_sc_page_message']),'native settings save acknowledged');

// Generate browser fixtures with all risk labels; these remain explicitly simulated test data.
require __DIR__.'/security-check-results.php';
$display=wphm_sc_collect(false); $display['facts']['registration']=true; $display['facts']['raw_html']=true; $display['facts']['file_uploads']=false;
$display['facts']['telemetry']=$observed;
// Explicitly simulate traffic waiting so all four legacy evidence levels remain covered after richer assessments.
foreach(['requests','peak','errors','recent_requests'] as $key) $display['facts']['telemetry'][$key]=0;
$display['items']=wphm_sc_assess($display['facts']);
foreach(array_keys(wphm_sc_levels()) as $level) sc_same(true,in_array($level,array_column($display['items'],'level'),true),'legacy observation metadata covers result level '.$level);
$GLOBALS['wphm_sc_page_report']=$display; unset($GLOBALS['wphm_sc_page_error']);
ob_start(); wphm_sc_render(); $page=ob_get_clean();
file_put_contents($root.'/.wphm-test/security-check-page.html',$page);
ob_start(); wphm_sc_report_html($display); $report_html=ob_get_clean();
file_put_contents($root.'/.wphm-test/security-check-ajax.json',wp_json_encode(['success'=>true,'data'=>['html'=>$report_html,'scan'=>['run'=>'','pending'=>0,'auto'=>false,'stopped'=>false]]]));
sc_same(false,strpos($page,$token)!==false,'full rendered page contains no API credential');
