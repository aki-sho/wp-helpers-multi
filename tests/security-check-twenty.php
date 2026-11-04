<?php
/** Native, disposable WordPress tests. Every public HTTP response is fixture data, never a live site. */
if(PHP_SAPI!=='cli' || !isset($connection,$checks)) exit;
$twenty_user=get_current_user_id(); $twenty_preferences=get_user_option('wphm_sc_sources',$twenty_user);
$twenty_meta_exists=metadata_exists('user',$twenty_user,'session_tokens'); $twenty_meta=get_user_meta($twenty_user,'session_tokens',true);
$twenty_update=wphm_sc_saved_site_transient('update_plugins');
$twenty_env=ABSPATH.'.env'; $twenty_env_created=false;
$twenty_http=[]; $twenty_mode='good'; $twenty_addresses=['8.8.8.8'];
$twenty_dns=function($old,$host) use(&$twenty_addresses) { return $host==='example.invalid'?$twenty_addresses:$old; };
$twenty_transport=function($pre,$args,$url) use(&$twenty_http,&$twenty_mode) {
    if(wp_parse_url($url,PHP_URL_HOST)!=='example.invalid') return $pre;
    $twenty_http[]=['url'=>$url,'args'=>$args];
    if($twenty_mode==='failure') return new WP_Error('private_network_error','secret-error-body-must-not-appear');
    if($twenty_mode!=='no_pin') {
        $handle=curl_init(); do_action('http_api_curl',$handle,$args,$url); curl_close($handle);
        $info=['url'=>$url,'primary_ip'=>$twenty_mode==='wrong_ip'?'127.0.0.1':'8.8.8.8','redirect_count'=>0,'ssl_verify_result'=>$twenty_mode==='bad_certificate'?18:0,'certinfo'=>$twenty_mode==='no_expiry'?[]:[['Expire date'=>gmdate('M d H:i:s Y \G\M\T',time()+30*DAY_IN_SECONDS)]]];
        do_action('requests-curl.after_request','',$info);
    }
    if($twenty_mode==='external') return ['response'=>['code'=>302],'headers'=>['location'=>'https://external.invalid/secret-location-token'],'body'=>'secret-body-must-not-appear'];
    if($twenty_mode==='query') return ['response'=>['code'=>302],'headers'=>['location'=>'/?token=secret-location-token'],'body'=>''];
    if($twenty_mode==='loop') return ['response'=>['code'=>301],'headers'=>['location'=>'/loop/'],'body'=>''];
    if($twenty_mode==='not_found') return ['response'=>['code'=>404],'headers'=>[],'body'=>'secret-body-must-not-appear'];
    $headers=['content-type'=>'text/html; charset=UTF-8','x-frame-options'=>'SAMEORIGIN','content-security-policy'=>"default-src 'self'; frame-ancestors 'self'; style-src 'nonce-secret-csp-value'",'strict-transport-security'=>'max-age=31536000; includeSubDomains','x-content-type-options'=>'nosniff','cache-control'=>'private, no-store','referrer-policy'=>'strict-origin-when-cross-origin','set-cookie'=>'PHPSESSID=secret-cookie-value; Path=/; Secure; HttpOnly; SameSite=Lax'];
    if($twenty_mode==='report_only') { unset($headers['x-frame-options'],$headers['content-security-policy']); $headers['content-security-policy-report-only']="frame-ancestors 'none'"; }
    if($twenty_mode==='external_frame') $headers['content-security-policy']="frame-ancestors *";
    if($twenty_mode==='wildcard_credentials') { $headers['access-control-allow-origin']='*'; $headers['access-control-allow-credentials']='true'; }
    if($twenty_mode==='shared_cache') { $headers['cache-control']='public, s-maxage=600'; $headers['age']='10'; }
    if($twenty_mode==='unsafe_auth_cookie') $headers['set-cookie']='wordpress_logged_in_'.str_repeat('a',32).'=secret-cookie-value; Path=/; Secure';
    if($twenty_mode==='bad_header') $headers['x-powered-by']=['nested'=>['secret-header-value']];
    return ['response'=>['code'=>200],'headers'=>$headers,'body'=>'secret-body-must-not-appear'];
};
try {
    update_user_option($twenty_user,'wphm_sc_sources',['wordpress'=>true,'server'=>false,'edge'=>false,'updates'=>false,'vulnerabilities'=>false,'vendor'=>false,'public_http'=>false],false);
    $before=count($twenty_http); $off=wphm_sc_public_facts(true);
    sc_same(false,$off['enabled'],'public response acquisition is opt-in');
    sc_same('skip',$off['responses']['home']['status'],'unchecked public acquisition is explicitly skipped');
    sc_same($before,count($twenty_http),'unchecked public acquisition makes no request');
    $facts20=wphm_sc_collect(false)['facts'];
    sc_same(20,count(wphm_sc_assess($facts20)),'all twenty distinct checks represented');
    sc_same(array_keys(wphm_sc_catalog()),array_column(wphm_sc_assess($facts20),'id'),'numbering and IDs remain stable');
    foreach(wphm_sc_assess($facts20) as $item) sc_same(true,count($item['checks'])>=2,'each item has real observations: '.$item['id']);

    // 11: check actual role state, then verify risk cannot hide privileged public registration.
    sc_same(true,$facts20['posture']['roles_known'],'native roles inspected');
    $changed=$facts20; $changed['posture']['public_privileged_role']=true;
    sc_same('high',array_column(wphm_sc_assess($changed),'level','id')['access_control'],'privileged public registration is high');
    $changed=$facts20; $changed['posture']['privileged_basic_roles']=1;
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['access_control'],'elevated basic role asks for review');
    $changed=$facts20; $changed['posture']['roles_limited']=true;
    sc_same('unknown',array_column(wphm_sc_assess($changed),'level','id')['access_control'],'bounded role inspection remains incomplete');
    echo "PASS item 11: role scope, public privileges and incomplete inspection.\n";

    // 12: current user only; no identifiers, cookie values, IP or user-agent in aggregates.
    update_user_meta($twenty_user,'session_tokens',['fixture-session-hash'=>['expiration'=>time()+3600,'ip'=>'192.0.2.99','ua'=>'secret-device-name'],'expired-session-hash'=>['expiration'=>time()-1]]);
    $session=wphm_sc_session_facts();
    sc_same('ok',$session['status'],'bounded native session metadata read'); sc_same(1,$session['active'],'active sessions counted'); sc_same(1,$session['expired'],'expired sessions counted');
    foreach(['fixture-session-hash','192.0.2.99','secret-device-name'] as $secret) sc_same(false,strpos(wp_json_encode($session),$secret)!==false,'session metadata never exposes '.$secret);
    update_user_meta($twenty_user,'session_tokens',['bad'=>['expiration'=>'tomorrow']]);
    sc_same('pending',wphm_sc_session_facts()['status'],'malformed session expiry stays unknown');
    update_user_meta($twenty_user,'session_tokens',['large'=>str_repeat('x',65537)]);
    sc_same('pending',wphm_sc_session_facts()['status'],'oversized session data not read as policy');
    if($twenty_meta_exists) update_user_meta($twenty_user,'session_tokens',$twenty_meta); else delete_user_meta($twenty_user,'session_tokens');
    $cookie_policy=wphm_sc_cookie_policy(); sc_same(true,$cookie_policy['known'],'standard core authentication policy identified');
    $custom_cookie=function($secure){return $secure;}; add_filter('secure_auth_cookie',$custom_cookie);
    sc_same(false,wphm_sc_cookie_policy()['known'],'custom authentication cookie callback remains unknown'); remove_filter('secure_auth_cookie',$custom_cookie);
    $changed=$facts20; $changed['posture']['cookie_policy']=['known'=>true,'secure_auth'=>false,'secure_logged_in'=>false,'native_tokens'=>true];
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['session'],'unprotected native cookie policy requires review');
    echo "PASS item 12: standard policy, custom overrides and private session aggregation.\n";

    // 13: distribution audit cannot stand in for third-party HTTP code.
    $changed=$facts20; $changed['audit']=['known'=>false];
    sc_same('unknown',array_column(wphm_sc_assess($changed),'level','id')['ssrf'],'unmatched HTTP audit is not green');
    sc_same(true,strpos(wp_json_encode(array_column(wphm_sc_assess($facts20),null,'id')['ssrf'],JSON_UNESCAPED_UNICODE),'他のテーマ')!==false,'SSRF inspection scope is explicit');
    echo "PASS item 13: reviewed HTTP boundary and explicit third-party scope.\n";

    // 16: metadata only, fixed paths only; a candidate is not a confirmed public disclosure.
    if(file_exists($twenty_env) || is_link($twenty_env)) throw new RuntimeException('Unexpected .env in disposable WordPress fixture');
    file_put_contents($twenty_env,'secret-file-contents-must-not-appear'); $twenty_env_created=true;
    $file_facts=wphm_sc_public_file_facts(); sc_same(true,$file_facts['candidates']>=1,'fixed file metadata detects a candidate');
    sc_same(false,strpos(wp_json_encode($file_facts),'secret-file-contents')!==false,'file content is never retained');
    $changed=$facts20; $changed['posture']['public_files']=$file_facts;
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['information_exposure'],'candidate needs review, not a claim of confirmed theft');
    unlink($twenty_env); $twenty_env_created=false;
    $changed=$facts20; $changed['posture']['debug_display']=true;
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['information_exposure'],'debug output is independently visible');
    echo "PASS item 16: fixed metadata inspection and no file-content collection.\n";

    // 17: stale, wrong-version or signed URL data is not shown as a verified update chain.
    set_site_transient('update_plugins',(object)['last_checked'=>time(),'checked'=>['sc-fixture.php'=>'1.0'],'response'=>['sc-fixture.php'=>(object)['package'=>'http://vendor.invalid/update.zip?token=secret-package-query']]]);
    $distribution=wphm_sc_distribution_facts(wphm_sc_inventory()); sc_same(1,$distribution['insecure_offers'],'insecure package destination counted');
    sc_same(false,strpos(wp_json_encode($distribution),'secret-package-query')!==false,'signed package URL never retained');
    $changed=$facts20; $changed['posture']['distribution']=$distribution;
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['supply_chain'],'insecure saved update destination requires review');
    $changed=$facts20; $changed['audit']=['known'=>false,'changed'=>1];
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['supply_chain'],'file mismatch is reviewable, not automatically called malware');
    if($twenty_update===false) delete_site_transient('update_plugins'); else set_site_transient('update_plugins',$twenty_update);
    echo "PASS item 17: distribution metadata, safe URL summaries and integrity mismatch.\n";

    add_filter('wphm_security_panel_addresses',$twenty_dns,95,2); add_filter('pre_http_request',$twenty_transport,95,3);
    $prefs=get_user_option('wphm_sc_sources',$twenty_user); $prefs['public_http']=true; update_user_option($twenty_user,'wphm_sc_sources',$prefs,false);
    $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $public20=wphm_sc_public_facts(true);
    sc_same(2,count($twenty_http),'only public home and standard login page are fetched');
    foreach($public20['responses'] as $kind=>$row) {
        sc_same('ok',$row['status'],'normal public response collected: '.$kind);
        sc_same(true,wphm_sc_valid_public_row($row),'only validated bounded summary stored: '.$kind);
        sc_same(true,$row['tls_verified'],'TLS verification captured from pinned cURL request: '.$kind);
        sc_same(true,$row['cert_expires']>time(),'certificate expiry extracted without retaining certificate: '.$kind);
    }
    foreach($twenty_http as $call) {
        sc_same('GET',$call['args']['method'],'normal GET only'); sc_same(0,$call['args']['redirection'],'automatic redirects disabled'); sc_same(true,$call['args']['sslverify'],'certificate verification enabled');
        sc_same([],$call['args']['cookies'],'no authentication cookie forwarded'); sc_same(false,isset($call['args']['headers']['Authorization']),'no provider key forwarded'); sc_same(1024,$call['args']['limit_response_size'],'body reads bounded and discarded');
    }
    $serialized=wp_json_encode([$public20,get_transient('wphm_sc_public_'.$twenty_user)]);
    foreach(['secret-cookie-value','secret-body-must-not-appear','secret-csp-value','Expire date'] as $secret) sc_same(false,strpos($serialized,$secret)!==false,'raw public response is not persisted: '.$secret);
    $facts20['public_response']=$public20;
    foreach(['clickjacking','transport','cache','cors'] as $id) sc_same('low',array_column(wphm_sc_assess($facts20),'level','id')[$id],'good observed response classified within its scope: '.$id);
    $twenty_mode='unsafe_auth_cookie'; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $changed=$facts20; $changed['public_response']=wphm_sc_public_facts(true);
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['session'],'observed unsafe WordPress auth cookie cannot be hidden by standard policy');
    $changed=$facts20; $changed['https']=false;
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['transport'],'HTTPS URL configuration is checked separately from one successful HTTPS response');

    // 14: a report-only policy cannot protect framing, and enforced CSP overrides X-Frame-Options.
    foreach(['report_only','external_frame'] as $mode) {
        $twenty_mode=$mode; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $changed=$facts20; $changed['public_response']=wphm_sc_public_facts(true);
        sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['clickjacking'],'framing policy accurately classified: '.$mode);
    }
    echo "PASS item 14: enforced framing policy, report-only and CSP precedence.\n";

    // 15: no manufactured certificate success when metadata or the pinned transport is missing.
    foreach(['wrong_ip','bad_certificate','no_pin'] as $mode) {
        $twenty_mode=$mode; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $failed=wphm_sc_public_facts(true);
        sc_same('error',$failed['responses']['home']['status'],'unverified transport rejected: '.$mode);
        $changed=$facts20; $changed['public_response']=$failed;
        sc_same('unknown',array_column(wphm_sc_assess($changed),'level','id')['transport'],'failed transport does not produce low risk: '.$mode);
    }
    $twenty_mode='no_expiry'; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $changed=$facts20; $changed['public_response']=wphm_sc_public_facts(true);
    sc_same('unknown',array_column(wphm_sc_assess($changed),'level','id')['transport'],'missing certificate expiry remains visible');
    echo "PASS item 15: verified TLS, public IP pin and missing certificate metadata.\n";

    // 18: no external destination, query-bearing redirect or unbounded loop is followed.
    foreach(['external','query','loop'] as $mode) {
        $twenty_mode=$mode; $twenty_http=[]; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $redirected=wphm_sc_public_facts(true);
        sc_same($mode==='loop'?6:2,count($twenty_http),'bounded redirect requests: '.$mode);
        sc_same('redirect',$redirected['responses']['home']['reason'],'redirect boundary reason retained: '.$mode);
        sc_same(false,strpos(wp_json_encode($redirected),'secret-location-token')!==false,'redirect token discarded: '.$mode);
        foreach($twenty_http as $call) sc_same(true,strpos($call['url'],'https://example.invalid/')===0 && strpos($call['url'],'?')===false,'only credential-free first-party URL requested');
    }
    echo "PASS item 18: normal redirect observations, bounded loops and no outside/query requests.\n";

    // 19: public cache plus a session candidate deserves review; no authenticated content is fetched.
    $twenty_mode='shared_cache'; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $changed=$facts20; $changed['public_response']=wphm_sc_public_facts(true);
    sc_same('medium',array_column(wphm_sc_assess($changed),'level','id')['cache'],'shared login/session response cache flagged for review');
    sc_same(true,in_array('認証後のキャッシュ分離',array_column(array_column(wphm_sc_assess($changed),null,'id')['cache']['checks'],'label'),true),'authenticated separation remains explicitly outside anonymous observation');
    echo "PASS item 19: login cache metadata and explicit authenticated-scope boundary.\n";

    // 20: wildcard plus credentials is browser-invalid, not a proven credential leak.
    $twenty_mode='wildcard_credentials'; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $changed=$facts20; $changed['public_response']=wphm_sc_public_facts(true);
    $cors_item=array_column(wphm_sc_assess($changed),null,'id')['cors'];
    sc_same('medium',$cors_item['level'],'wildcard plus credentials asks for review'); sc_same(true,strpos(wp_json_encode($cors_item,JSON_UNESCAPED_UNICODE),'ブラウザが拒否')!==false,'browser rejection is explained rather than claimed as data theft');
    foreach([['access-control-allow-origin'=>'*'],['access-control-allow-origin'=>'null','access-control-allow-credentials'=>'true'],['access-control-allow-origin'=>'https://partner.invalid','access-control-allow-credentials'=>'true']] as $headers) {
        $row=wphm_sc_public_summary($headers,'https://example.invalid/'); sc_same(true,wphm_sc_valid_public_row($row),'bounded CORS summary accepted without storing domain');
        sc_same(false,strpos(wp_json_encode($row),'partner.invalid')!==false,'external CORS domain not retained');
    }
    echo "PASS item 20: CORS origin classes and credential/wildcard semantics.\n";

    // Security boundaries for the new observation pipeline.
    foreach(['https://other.invalid/','https://user:secret@example.invalid/','https://example.invalid/?token=secret','http://127.0.0.1/','https://example.invalid:8443/'] as $url) {
        $before=count($twenty_http); $blocked=false; $tls=[];
        try { wphm_sc_public_get($url,microtime(true)+3,$tls); } catch(WPHM_Security_Read_Exception $failure) { $blocked=true; }
        sc_same(true,$blocked,'unapproved target rejected'); sc_same($before,count($twenty_http),'rejected target starts no request');
    }
    foreach(['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','::1','::ffff:127.0.0.1'] as $address) {
        $twenty_addresses=[$address]; $before=count($twenty_http); $GLOBALS['wphm_sc_deadline']=microtime(true)+25;
        sc_same('target',wphm_sc_public_facts(true)['responses']['home']['reason'],'private DNS target rejected'); sc_same($before,count($twenty_http),'private DNS starts no HTTP request');
    }
    $twenty_addresses=['8.8.8.8']; $twenty_mode='good'; $twenty_http=[]; $GLOBALS['wphm_sc_deadline']=microtime(true)-1;
    sc_same('budget',wphm_sc_public_facts(true)['responses']['home']['reason'],'shared deadline blocks late public requests'); sc_same(0,count($twenty_http),'expired budget starts no request');
    foreach(['failure','bad_header','not_found'] as $mode) {
        $twenty_mode=$mode; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $failed=wphm_sc_public_facts(true);
        sc_same('error',$failed['responses']['home']['status'],'invalid/failing response not substituted with old success: '.$mode);
        sc_same(false,strpos(wp_json_encode($failed),'secret-')!==false,'raw failure content discarded: '.$mode);
    }
    foreach([['x-frame-options'=>"SAMEORIGIN\r\nInjected: secret"],['set-cookie'=>array_fill(0,21,'x=y')],['content-security-policy'=>str_repeat('x',8193)]] as $headers) {
        $blocked=false; try { wphm_sc_public_summary($headers,'https://example.invalid/'); } catch(WPHM_Security_Read_Exception $failure) { $blocked=true; }
        sc_same(true,$blocked,'header injection/count/length bound enforced');
    }
    $collapsed=wphm_sc_public_summary(['set-cookie'=>'PHPSESSID=one, other=two; Secure; HttpOnly; SameSite=Lax'],'https://example.invalid/');
    sc_same(true,$collapsed['cookies_limited'],'collapsed ambiguous cookie header remains incomplete');
    sc_same('absent',wphm_sc_public_summary(['content-security-policy'=>"img-src 'self'"],'https://example.invalid/')['csp'],'image-only CSP does not claim script restriction');

    $twenty_mode='good'; $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $cached=wphm_sc_public_facts(true); $before=count($twenty_http);
    sc_same($cached,wphm_sc_public_facts(false),'fresh user/site-bound public cache read'); sc_same($before,count($twenty_http),'view and continuation start no implicit public request');
    $cache=get_transient('wphm_sc_public_'.$twenty_user); $cache['responses']['home']['cookies'][0]['http_only']='secret-cache-value'; set_transient('wphm_sc_public_'.$twenty_user,$cache,300);
    sc_same(0,wphm_sc_public_facts(false)['checked_at'],'malformed nested public cache rejected');
    sc_same($before,count($twenty_http),'invalid cache does not silently reconnect');
    $GLOBALS['wphm_sc_deadline']=microtime(true)+25; $cached=wphm_sc_public_facts(true); $valid_cache=get_transient('wphm_sc_public_'.$twenty_user); $before=count($twenty_http);
    foreach([time()-301,time()+60] as $bad_time) {
        $cache=$valid_cache; $cache['checked_at']=$bad_time; set_transient('wphm_sc_public_'.$twenty_user,$cache,300);
        sc_same(0,wphm_sc_public_facts(false)['checked_at'],'stale or future public metadata stays unknown');
    }
    set_transient('wphm_sc_public_'.$twenty_user,$valid_cache,300);
    $different_home=function($url){return str_replace('example.invalid','changed.invalid',$url);}; add_filter('home_url',$different_home);
    sc_same(0,wphm_sc_public_facts(false)['checked_at'],'public metadata cannot be reused for a changed site URL'); remove_filter('home_url',$different_home);
    $other_owner=wp_insert_user(['user_login'=>'twenty-other-owner','user_email'=>'twenty-other@example.invalid','user_pass'=>bin2hex(random_bytes(12)),'role'=>'administrator']);
    if(is_wp_error($other_owner)) throw new RuntimeException('Could not create disposable cache-owner fixture');
    wp_set_current_user($other_owner); update_user_option($other_owner,'wphm_sc_sources',['public_http'=>true],false); set_transient('wphm_sc_public_'.$other_owner,$valid_cache,300);
    sc_same(0,wphm_sc_public_facts(false)['checked_at'],'public metadata cache signature is bound to its administrator');
    wp_set_current_user($twenty_user); sc_same($before,count($twenty_http),'owner/site/time cache rejection never initiates HTTP');
    $forged=$valid_cache['responses']['home']; $forged['tls_verified']=false;
    sc_same(false,wphm_sc_valid_public_row($forged),'successful HTTPS summary without verified TLS rejected');
    $resolved=['example.invalid'=>['127.0.0.1']]; $tls=[]; $blocked=false;
    try { wphm_sc_public_get('https://example.invalid/',microtime(true)+3,$tls,$resolved); } catch(WPHM_Security_Read_Exception $failure) { $blocked=true; }
    sc_same(true,$blocked,'prepopulated DNS cache cannot bypass public IP validation');
    sc_same($before,count($twenty_http),'untrusted cached address starts no request');
    $prefs=get_user_option('wphm_sc_sources',$twenty_user); $prefs['wordpress']=false; update_user_option($twenty_user,'wphm_sc_sources',$prefs,false);
    $changed=$facts20; $changed['vulnerabilities']['cwe']=['CWE-918']; $changed['vulnerabilities']['affected']=1;
    sc_same('high',array_column(wphm_sc_assess($changed),'level','id')['ssrf'],'public known-vulnerability evidence remains high when local WordPress source is unchecked');
    $prefs['wordpress']=true; update_user_option($twenty_user,'wphm_sc_sources',$prefs,false);
    foreach(array_slice(wphm_sc_cwe_mapping(),9,null,true) as $cwe=>$id) {
        $changed=$facts20; $changed['vulnerabilities']['cwe']=[$cwe]; $changed['vulnerabilities']['affected']=1;
        sc_same('high',array_column(wphm_sc_assess($changed),'level','id')[$id],'new CWE mapping affects its item: '.$cwe);
    }
    $bad=false; try { wphm_sc_save_sources(['source_public_http'=>['1']]); } catch(WPHM_Security_Check_Exception $failure) { $bad=true; }
    sc_same(true,$bad,'nested public observation selection rejected');

    // Current PHP-generated markup for manual browser verification; transport is still fixture-only.
    $facts20['public_response']=$public20;
    $report20=['schema'=>2,'checked_at'=>time(),'refreshed'=>false,'facts'=>$facts20,'items'=>wphm_sc_assess($facts20),'scan'=>['run'=>'','pending'=>0,'users_pending'=>false,'auto'=>false,'stopped'=>false]];
    $GLOBALS['wphm_sc_page_report']=$report20; ob_start(); wphm_sc_render(); $page20=ob_get_clean();
    sc_same(20,substr_count($page20,'class="wphm-sc-accordion"'),'twenty compact disclosures rendered');
    sc_same(true,strpos($page20,'data-sc-filter="group"')!==false,'group filter rendered'); sc_same(true,strpos($page20,'data-sc-filter="status"')!==false,'risk filter rendered');
    sc_same(false,strpos($page20,'現在の状態')!==false,'removed state section remains absent'); sc_same(false,strpos($page20,'判定理由')!==false,'removed reason section remains absent');
    sc_same(false,strpos($page20,'secret-')!==false,'HTML excludes all synthetic secrets');
    file_put_contents($root.'/.wphm-test/security-check-page.html',$page20);
    ob_start(); wphm_sc_report_html($report20); $html20=ob_get_clean();
    file_put_contents($root.'/.wphm-test/security-check-ajax.json',wp_json_encode(['success'=>true,'data'=>['html'=>$html20,'scan'=>$report20['scan']]]));
    echo "PASS twenty-item pipeline: opt-in, scoped cache, private IPs, TLS, headers, secrets and rendering.\n";
} finally {
    remove_filter('wphm_security_panel_addresses',$twenty_dns,95); remove_filter('pre_http_request',$twenty_transport,95);
    if($twenty_env_created && is_file($twenty_env)) unlink($twenty_env);
    wp_set_current_user($twenty_user);
    if($twenty_meta_exists) update_user_meta($twenty_user,'session_tokens',$twenty_meta); else delete_user_meta($twenty_user,'session_tokens');
    if($twenty_update===false) delete_site_transient('update_plugins'); else set_site_transient('update_plugins',$twenty_update);
    if($twenty_preferences===false) delete_user_option($twenty_user,'wphm_sc_sources',false); else update_user_option($twenty_user,'wphm_sc_sources',$twenty_preferences,false);
    unset($GLOBALS['wphm_sc_page_report']);
}
