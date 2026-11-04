<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_public_row(): array {
    return ['status'=>'pending','reason'=>'recheck','code'=>0,'https'=>false,'tls_verified'=>false,'cert_expires'=>0,'redirects'=>0,'external_redirect'=>false,'query_redirect'=>false,'html'=>false,'frame'=>'missing','csp'=>'absent','hsts'=>0,'hsts_present'=>false,'nosniff'=>false,'cors'=>'none','cors_credentials'=>false,'vary_origin'=>false,'cache'=>'unspecified','cache_age'=>0,'vary_cookie'=>false,'cookies'=>[],'cookies_limited'=>false,'referrer'=>'missing','server_disclosure'=>false];
}

/** Only configured, credential-free HTTP origins on standard ports are eligible. */
function wphm_sc_public_origin(string $url): string {
    if(strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$url)) return '';
    $p=wp_parse_url($url);
    if(!is_array($p) || !in_array($p['scheme'] ?? '',['http','https'],true) || !is_string($p['host'] ?? null) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return '';
    $host=strtolower($p['host']);
    if(!preg_match('/^[a-z0-9][a-z0-9.-]{1,251}[a-z0-9]$/D',$host) || strpos($host,'.')===false || filter_var($host,FILTER_VALIDATE_IP)) return '';
    foreach(explode('.',$host) as $label) if(strlen($label)>63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D',$label)) return '';
    $port=$p['scheme']==='https'?443:80;
    if(isset($p['port']) && $p['port']!==$port) return '';
    return $p['scheme'].'://'.$host;
}

/** Headers are consumed locally; only an explicit set of non-secret summaries is retained. */
function wphm_sc_public_headers($headers): array {
    if(is_object($headers) && $headers instanceof Traversable) $headers=iterator_to_array($headers);
    if(!is_array($headers) || count($headers)>64) throw new WPHM_Security_Read_Exception('size');
    $wanted=['content-type','x-frame-options','content-security-policy','strict-transport-security','x-content-type-options','access-control-allow-origin','access-control-allow-credentials','vary','cache-control','age','set-cookie','referrer-policy','x-powered-by'];
    $out=[]; $bytes=0;
    foreach($headers as $key=>$values) {
        if(!is_string($key) || !preg_match('/^[A-Za-z0-9-]{1,80}$/D',$key)) throw new WPHM_Security_Read_Exception('schema');
        $values=is_array($values)?$values:[$values]; if(count($values)>20) throw new WPHM_Security_Read_Exception('size');
        foreach($values as $value) {
            if(!is_string($value) || strlen($value)>8192 || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/',$value)) throw new WPHM_Security_Read_Exception('schema');
            $bytes+=strlen($key)+strlen($value); if($bytes>32768) throw new WPHM_Security_Read_Exception('size');
            if(in_array(strtolower($key),$wanted,true)) $out[strtolower($key)][]=trim($value);
        }
    }
    return $out;
}

function wphm_sc_public_summary($headers, string $url): array {
    $h=wphm_sc_public_headers($headers); $out=wphm_sc_public_row();
    $one=function(string $key) use($h): string { return isset($h[$key]) && count($h[$key])===1?$h[$key][0]:''; };
    $out['html']=(bool)preg_match('~^(?:text/html|application/xhtml\+xml)(?:\s*;|$)~i',$one('content-type'));
    $xfo=strtolower($one('x-frame-options'));
    $out['frame']=$xfo==='deny'?'deny':($xfo==='sameorigin'?'self':(isset($h['x-frame-options'])?'invalid':'missing'));
    if(isset($h['content-security-policy'])) {
        $out['csp']='restricted'; $frames=[]; $script_policy=false;
        foreach($h['content-security-policy'] as $policy) {
            $directives=explode(';',$policy); if(count($directives)>40) { $out['csp']='invalid'; $frames[]='invalid'; break; }
            $seen=[];
            foreach($directives as $directive) {
                $tokens=preg_split('/\s+/',trim($directive)); $name=strtolower(array_shift($tokens) ?? '');
                if($name==='') continue;
                if(isset($seen[$name])) { $out['csp']='invalid'; if($name==='frame-ancestors') $frames[]='invalid'; continue; }
                $seen[$name]=true;
                if(in_array($name,['script-src','default-src'],true)) $script_policy=true;
                if(in_array($name,['script-src','default-src'],true) && array_intersect($tokens,["'unsafe-inline'","'unsafe-eval'",'*','data:','http:'])) $out['csp']='permissive';
                if($name==='frame-ancestors') {
                    if($tokens===["'none'"]) $frames[]='deny'; elseif($tokens===["'self'"]) $frames[]='self';
                    elseif(!$tokens || count($tokens)>20 || in_array("'none'",$tokens,true)) $frames[]='invalid';
                    else $frames[]='external';
                }
            }
        }
        // Enforced policies intersect; a restrictive policy still applies beside another policy.
        if(in_array('deny',$frames,true)) $out['frame']='deny'; elseif(in_array('self',$frames,true)) $out['frame']='self';
        elseif($frames) $out['frame']=in_array('invalid',$frames,true)?'invalid':'external';
        if(!$script_policy && $out['csp']==='restricted') $out['csp']='absent';
    }
    if(isset($h['strict-transport-security'])) {
        $out['hsts_present']=true;
        if(count($h['strict-transport-security'])===1 && preg_match('/(?:^|;)\s*max-age\s*=\s*([0-9]{1,10})\s*(?:;|$)/i',$one('strict-transport-security'),$m) && preg_match_all('/(?:^|;)\s*max-age\s*=/i',$one('strict-transport-security'))===1) $out['hsts']=min(2147483647,(int)$m[1]);
    }
    $out['nosniff']=strtolower($one('x-content-type-options'))==='nosniff';
    $origin=$one('access-control-allow-origin'); $credentials=$one('access-control-allow-credentials');
    $out['cors_credentials']=$credentials==='true';
    if(isset($h['access-control-allow-origin'])) {
        if($origin==='*') $out['cors']='wildcard'; elseif($origin==='null') $out['cors']='null';
        elseif($origin!=='' && wphm_sc_public_origin($origin)!=='' && rtrim($origin,'/')===wphm_sc_public_origin($origin)) $out['cors']=wphm_sc_public_origin($origin)===wphm_sc_public_origin($url)?'same-site':'external';
        else $out['cors']='invalid';
    }
    if(isset($h['access-control-allow-credentials']) && $credentials!=='true') $out['cors']='invalid';
    $vary=strtolower(implode(',',$h['vary'] ?? [])); $out['vary_origin']=(bool)preg_match('/(?:^|,)\s*(?:origin|\*)\s*(?:,|$)/',$vary); $out['vary_cookie']=(bool)preg_match('/(?:^|,)\s*(?:cookie|\*)\s*(?:,|$)/',$vary);
    $cache=strtolower(implode(',',$h['cache-control'] ?? []));
    if(preg_match('/(?:^|,)\s*(?:private|no-store)(?:\s*(?:=|,)|$)/',$cache)) $out['cache']='private';
    elseif(preg_match('/(?:^|,)\s*no-cache(?:\s*(?:=|,)|$)/',$cache)) $out['cache']='revalidate';
    elseif(preg_match('/(?:^|,)\s*public\s*(?:,|$)/',$cache) || preg_match('/(?:^|,)\s*s-maxage\s*=\s*[1-9][0-9]{0,9}\s*(?:,|$)/',$cache)) $out['cache']='shared';
    if(isset($h['age']) && preg_match('/^[0-9]{1,10}$/D',$one('age'))) $out['cache_age']=min(2147483647,(int)$one('age'));
    foreach($h['set-cookie'] ?? [] as $cookie) {
        if(preg_match('/,\s*[A-Za-z0-9_!#$%&\x27*+.^`|~-]+=/',$cookie)) { $out['cookies_limited']=true; continue; }
        $parts=explode(';',$cookie); $pair=array_shift($parts); $equals=strpos($pair,'=');
        if($equals===false || !preg_match('/^[A-Za-z0-9_!#$%&\x27*+.^`|~-]{1,128}$/D',substr($pair,0,$equals))) { $out['cookies_limited']=true; continue; }
        $name=substr($pair,0,$equals); $kind=preg_match('/^wordpress(?:_sec|_logged_in)?_[a-f0-9]{32}$/i',$name)?'wp_auth':(strcasecmp($name,'PHPSESSID')===0?'php_session':'other');
        $row=['kind'=>$kind,'secure'=>false,'http_only'=>false,'same_site'=>'missing','domain_wide'=>false]; $seen=[];
        foreach($parts as $part) {
            $attribute=explode('=',trim($part),2); $key=strtolower($attribute[0]); $value=trim($attribute[1] ?? '');
            if(isset($seen[$key]) && in_array($key,['secure','httponly','samesite','domain'],true)) $out['cookies_limited']=true;
            $seen[$key]=true;
            if($key==='secure') $row['secure']=count($attribute)===1;
            if($key==='httponly') $row['http_only']=count($attribute)===1;
            if($key==='samesite') $row['same_site']=in_array(strtolower($value),['lax','strict','none'],true)?strtolower($value):'invalid';
            if($key==='domain') $row['domain_wide']=$value!=='';
        }
        $out['cookies'][]=$row;
    }
    $ref=strtolower($one('referrer-policy'));
    if($ref!=='') $out['referrer']=in_array($ref,['no-referrer','same-origin','strict-origin','strict-origin-when-cross-origin'],true)?'strict':(in_array($ref,['unsafe-url','origin','origin-when-cross-origin','no-referrer-when-downgrade'],true)?'loose':'invalid');
    $out['server_disclosure']=isset($h['x-powered-by']);
    return $out;
}

function wphm_sc_valid_public_row($row): bool {
    $shape=wphm_sc_public_row();
    if(!is_array($row) || array_diff_key($row,$shape) || array_diff_key($shape,$row)) return false;
    foreach($shape as $key=>$value) if(gettype($row[$key])!==gettype($value)) return false;
    foreach(['code'=>599,'cert_expires'=>time()+20*YEAR_IN_SECONDS,'redirects'=>2,'hsts'=>2147483647,'cache_age'=>2147483647] as $key=>$max) if($row[$key]<0 || $row[$key]>$max) return false;
    foreach(['status'=>['pending','skip','ok','error'],'frame'=>['missing','invalid','deny','self','external'],'csp'=>['absent','restricted','permissive','invalid'],'cors'=>['none','wildcard','same-site','external','null','invalid'],'cache'=>['unspecified','private','revalidate','shared'],'referrer'=>['missing','strict','loose','invalid']] as $key=>$allowed) if(!in_array($row[$key],$allowed,true)) return false;
    if($row['reason']!=='' && wphm_sc_read_code($row['reason'])!==$row['reason']) return false;
    if($row['status']==='ok' && ($row['code']!==200 || $row['reason']!=='' || ($row['https'] && !$row['tls_verified']))) return false;
    if(count($row['cookies'])>20) return false;
    foreach($row['cookies'] as $cookie) {
        if(!is_array($cookie) || array_keys($cookie)!==['kind','secure','http_only','same_site','domain_wide'] || !in_array($cookie['kind'],['wp_auth','php_session','other'],true) || !in_array($cookie['same_site'],['missing','invalid','lax','strict','none'],true)) return false;
        foreach(['secure','http_only','domain_wide'] as $key) if(!is_bool($cookie[$key])) return false;
    }
    return strlen(serialize($row))<16384;
}

function wphm_sc_public_reason(string $reason): string {
    $messages=[
        'http_401'=>'公開応答に認証が必要です。Cookieや認証キーは送らないため、この方法では確認できません。',
        'http_403'=>'公開応答が拒否されました。アクセス制限・WAF・自己接続の許可を確認してください。',
        'http_404'=>'設定された公開トップ・標準ログインページが404です。URL設定やログインURLの変更を確認してください。',
        'http_5xx'=>'サイトの公開応答がサーバーエラーです。復旧後に再チェックしてください。',
        'network'=>'公開ページへの通信・TLS証明書を確認できません。名前解決・外向き通信・証明書を確認してください。',
        'target'=>'設定URLの公開IP・ドメイン・標準ポートを確認できません。内部IPや認証情報付きURLは取得しません。',
        'redirect'=>'通常転送の追跡を停止しました。サイト外・クエリ付き・HTTPSからHTTPへの転送、または2段階の上限を確認してください。',
        'transport'=>'公開応答の確認にはcURL・SSLと、接続先IPを固定できる直接接続が必要です。PHP拡張とプロキシ設定を確認してください。',
        'schema'=>'公開応答のステータス・ヘッダーを対応する形式で確認できません。ページや配信設定を確認してください。',
    ];
    return $messages[$reason] ?? wphm_sc_read_reason($reason);
}

/** The pin and metadata hooks exist only during this request and are removed on every exit. */
function wphm_sc_public_get(string $url, float $deadline, array &$tls, array &$resolved=[]): array {
    $origin=wphm_sc_public_origin($url); if($origin==='') throw new WPHM_Security_Read_Exception('target');
    $configured=array_filter([wphm_sc_public_origin(home_url('/')),wphm_sc_public_origin(site_url('wp-login.php','login'))]);
    foreach($configured as $allowed) if(strpos($allowed,'http://')===0) $configured[]='https://'.substr($allowed,7);
    if(!in_array($origin,$configured,true)) throw new WPHM_Security_Read_Exception('target');
    $remaining=min($deadline, $GLOBALS['wphm_sc_deadline'] ?? $deadline)-microtime(true);
    if($remaining<0.25) throw new WPHM_Security_Read_Exception('budget');
    $host=(string)wp_parse_url($url,PHP_URL_HOST);
    if(!array_key_exists($host,$resolved)) $resolved[$host]=wphm_sc_panel_addresses($host);
    $addresses=$resolved[$host];
    if(!is_array($addresses) || !$addresses || count($addresses)>32) throw new WPHM_Security_Read_Exception('target');
    foreach($addresses as $address) if(!is_string($address) || !wphm_sc_public_address($address)) throw new WPHM_Security_Read_Exception('target');
    if(!function_exists('curl_init') || !defined('CURLOPT_RESOLVE') || !(curl_version()['features'] & CURL_VERSION_SSL) || (defined('WP_PROXY_HOST') && WP_PROXY_HOST)) throw new WPHM_Security_Read_Exception('transport');
    $remaining=min($deadline, $GLOBALS['wphm_sc_deadline'] ?? $deadline)-microtime(true);
    if($remaining<0.25) throw new WPHM_Security_Read_Exception('budget');
    $tls=['pinned'=>false,'verified'=>false,'expires'=>0]; $https=strpos($origin,'https://')===0; $port=$https?443:80;
    $pin=function($handle,$args,$request_url) use($url,$host,$addresses,$port,&$tls) {
        if($request_url!==$url) return;
        $ip=strpos($addresses[0],':')!==false?'['.$addresses[0].']':$addresses[0];
        if(!curl_setopt_array($handle,[CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':'.$port.':'.$ip],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CERTINFO=>true,CURLOPT_COOKIE=>'',CURLOPT_REFERER=>''])) throw new WPHM_Security_Read_Exception('transport');
        $tls['pinned']=true;
    };
    $capture=function($headers,$info=[]) use($url,$addresses,$https,&$tls) {
        if(!is_array($info) || ($info['url'] ?? '')!==$url || !in_array($info['primary_ip'] ?? '',$addresses,true) || ($info['redirect_count'] ?? 0)!==0) return;
        $tls['verified']=$https && ($info['ssl_verify_result'] ?? null)===0;
        $expiry=$info['certinfo'][0]['Expire date'] ?? null;
        if(is_string($expiry) && strlen($expiry)<100) {
            try { $time=(new DateTimeImmutable($expiry,new DateTimeZone('UTC')))->getTimestamp(); if($time>0 && $time<time()+20*YEAR_IN_SECONDS) $tls['expires']=$time; } catch(Throwable $error) { /* No raw certificate/error is retained. */ }
        }
    };
    add_action('http_api_curl',$pin,PHP_INT_MAX,3); add_action('requests-curl.after_request',$capture,PHP_INT_MAX,2);
    try { $reply=wp_safe_remote_get($url,['method'=>'GET','timeout'=>min(2.5,$remaining),'redirection'=>0,'sslverify'=>true,'headers'=>['Accept'=>'text/html,application/xhtml+xml','Accept-Encoding'=>'identity'],'cookies'=>[],'decompress'=>false,'limit_response_size'=>1024,'user-agent'=>'WP-Helpers-Multi/Security-Check']); }
    finally { remove_action('http_api_curl',$pin,PHP_INT_MAX); remove_action('requests-curl.after_request',$capture,PHP_INT_MAX); }
    if(is_wp_error($reply)) throw new WPHM_Security_Read_Exception('network');
    if(!$tls['pinned']) throw new WPHM_Security_Read_Exception('transport');
    if($https && !$tls['verified']) throw new WPHM_Security_Read_Exception('network');
    return $reply;
}

function wphm_sc_public_fetch(string $url, array $origins, float $deadline, array &$resolved=[]): array {
    $row=wphm_sc_public_row();
    for($step=0;$step<=2;$step++) {
        try {
            $tls=[]; $reply=wphm_sc_public_get($url,$deadline,$tls,$resolved); $code=wp_remote_retrieve_response_code($reply);
            if(!is_int($code) || $code<200 || $code>599) throw new WPHM_Security_Read_Exception('schema');
            $row['code']=$code; $row['redirects']=$step;
            if(in_array($code,[301,302,303,307,308],true)) {
                $location=wp_remote_retrieve_header($reply,'location');
                if(!is_string($location) || strlen($location)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$location)) throw new WPHM_Security_Read_Exception('redirect');
                $next=WP_Http::make_absolute_url($location,$url); $p=wp_parse_url($next);
                $row['query_redirect']=is_array($p) && (isset($p['query']) || isset($p['fragment']));
                $origin=wphm_sc_public_origin(preg_replace('/[?#].*$/','',$next)); $row['external_redirect']=$origin==='' || !in_array($origin,$origins,true);
                if($row['query_redirect'] || $row['external_redirect'] || $step===2 || (strpos($url,'https://')===0 && strpos($next,'https://')!==0)) throw new WPHM_Security_Read_Exception('redirect');
                $url=$next; continue;
            }
            if($code!==200) throw new WPHM_Security_Read_Exception($code>=500?'http_5xx':($code===401?'http_401':($code===403?'http_403':($code===404?'http_404':'schema'))));
            $summary=wphm_sc_public_summary(wp_remote_retrieve_headers($reply),$url);
            $row=array_replace($summary,['status'=>'ok','reason'=>'','code'=>$code,'https'=>strpos($url,'https://')===0,'tls_verified'=>$tls['verified'],'cert_expires'=>$tls['expires'],'redirects'=>$step]);
            return $row;
        } catch(Throwable $failure) { $row['status']='error'; $row['reason']=$failure instanceof WPHM_Security_Read_Exception?$failure->reason:'exception'; return $row; }
    }
    return $row;
}

/** Opt-in, at most two fixed pages and two redirects each, one shared six-second HTTP budget. */
function wphm_sc_public_facts(bool $refresh): array {
    $out=['enabled'=>wphm_sc_options()['public_http'],'checked_at'=>0,'responses'=>['home'=>wphm_sc_public_row(),'login'=>wphm_sc_public_row()]];
    if(!$out['enabled']) { foreach($out['responses'] as &$row) { $row['status']='skip'; $row['reason']=''; } unset($row); return $out; }
    $targets=['home'=>home_url('/'),'login'=>site_url('wp-login.php','login')];
    $signature=hash('sha256',wp_json_encode([WPHM_VERSION,get_current_blog_id(),get_current_user_id(),$targets])); $key='wphm_sc_public_'.get_current_user_id();
    if(!$refresh) {
        $cache=get_transient($key);
        if(is_array($cache) && ($cache['signature'] ?? '')===$signature && is_int($cache['checked_at'] ?? null) && $cache['checked_at']>=time()-300 && $cache['checked_at']<=time() && is_array($cache['responses'] ?? null) && array_keys($cache['responses'])===['home','login']) {
            foreach($cache['responses'] as $row) if(!wphm_sc_valid_public_row($row)) return $out;
            $out['checked_at']=$cache['checked_at']; $out['responses']=$cache['responses'];
        }
        return $out;
    }
    $origins=array_values(array_unique(array_filter(array_map('wphm_sc_public_origin',$targets))));
    foreach($origins as $origin) if(strpos($origin,'http://')===0) $origins[]='https://'.substr($origin,7);
    $deadline=microtime(true)+6; $resolved=[];
    foreach($targets as $kind=>$url) $out['responses'][$kind]=wphm_sc_public_fetch($url,$origins,$deadline,$resolved);
    $out['checked_at']=time(); set_transient($key,['signature'=>$signature,'checked_at'=>$out['checked_at'],'responses'=>$out['responses']],300);
    return $out;
}
