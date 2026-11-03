<?php
if (!defined('ABSPATH')) exit;

/** Built-in readers are configured by the site's administrator, not by the developer. */
function wphm_sc_builtin_readers(): array {
    return [
        'cpanel'=>['source'=>'server','name'=>'cPanel：ドメインのModSecurity設定','needs_id'=>true,'needs_token'=>true,'read'=>'wphm_sc_read_cpanel','help'=>'接続IDはアカウント名@管理パネルのホスト名です（例：account@panel.example.com）。HTTPS 2083番のModSecurity読取りだけを行います。キーは指定した管理パネルだけに送ります。制限値・PHP修正・実行禁止はこのAPIで確認したことにはしません。'],
        'fastly'=>['source'=>'edge','name'=>'Fastly：対象サービスの通信統計','needs_id'=>true,'needs_token'=>true,'read'=>'wphm_sc_read_fastly','help'=>'接続IDはFastly Service IDです。対象サービスのドメインがこのサイトだけであることを確認し、約30分前までの完了した5分間の通信・5xx統計を読みます。Fastly-Keyはapi.fastly.comだけに送ります。WAFやログイン制限の有効判定ではありません。'],
    ];
}

function wphm_sc_cpanel_connection(string $value): ?array {
    if(strlen($value)>128 || !preg_match('/^([a-z][a-z0-9_]{0,15})@([a-z0-9][a-z0-9.-]{1,110}[a-z0-9])$/D',$value,$m) || strpos($m[2],'.')===false || strpos($m[2],'..')!==false || filter_var($m[2],FILTER_VALIDATE_IP)) return null;
    foreach(explode('.',$m[2]) as $label) if(strlen($label)>63 || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D',$label)) return null;
    return ['account'=>$m[1],'host'=>$m[2]];
}

function wphm_sc_public_address(string $ip): bool {
    $packed=@inet_pton($ip);
    if($packed===false) return false;
    if(strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") $ip=inet_ntop(substr($packed,12));
    if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false) return false;
    $packed=inet_pton($ip);
    if(strlen($packed)===16) return (ord($packed[0]) & 0xe0)===0x20 && !in_array(substr($packed,0,4),[hex2bin('20010db8'),hex2bin('20010000')],true) && substr($packed,0,2)!==hex2bin('2002');
    $bytes=array_values(unpack('C4',$packed));
    if($bytes[0]===0 || $bytes[0]>=224 || ($bytes[0]===100 && $bytes[1]>=64 && $bytes[1]<=127)) return false;
    if($bytes[0]===192 && (($bytes[1]===0 && in_array($bytes[2],[0,2],true)) || ($bytes[1]===88 && $bytes[2]===99))) return false;
    if($bytes[0]===198 && (in_array($bytes[1],[18,19],true) || ($bytes[1]===51 && $bytes[2]===100))) return false;
    return !($bytes[0]===203 && $bytes[1]===0 && $bytes[2]===113);
}

function wphm_sc_panel_addresses(string $host): array {
    // Trusted installed test/integration code may provide DNS answers; every address is still checked.
    $addresses=apply_filters('wphm_security_panel_addresses',null,$host);
    if($addresses===null) {
        $addresses=@gethostbynamel($host);
        $ipv6=function_exists('dns_get_record')?@dns_get_record($host,DNS_AAAA):[];
        if(is_array($ipv6)) foreach($ipv6 as $record) if(isset($record['ipv6'])) $addresses=is_array($addresses)?array_merge($addresses,[$record['ipv6']]):[$record['ipv6']];
    }
    if(!is_array($addresses) || !$addresses || count($addresses)>32) return [];
    foreach($addresses as $ip) if(!is_string($ip) || !wphm_sc_public_address($ip)) return [];
    return array_values(array_unique($addresses));
}

/** Only GET, fixed provider paths, TLS verification, no redirects/cookies, and a shared deadline. */
function wphm_sc_service_get(string $url, array $headers, array $context, ?string $panel_host=null): array {
    $p=wp_parse_url($url); $host=$p['host'] ?? ''; $path=$p['path'] ?? '';
    if(!is_array($p) || ($p['scheme'] ?? '')!=='https' || isset($p['user']) || isset($p['pass']) || isset($p['fragment'])) throw new WPHM_Security_Read_Exception('target');
    $cpanel=$panel_host!==null && $host===$panel_host && ($p['port'] ?? 0)===2083 && $path==='/execute/ModSecurity/list_domains' && !isset($p['query']);
    $fastly=$panel_host===null && $host==='api.fastly.com' && !isset($p['port']) && (preg_match('~^/service/[A-Za-z0-9]{6,64}/version/(?:active|[0-9]{1,8}/domain)$~D',$path) || (preg_match('~^/stats/service/[A-Za-z0-9]{6,64}$~D',$path) && preg_match('/^from=[0-9]{10}&to=[0-9]{10}&by=minute$/D',$p['query'] ?? '')));
    if(!$cpanel && !$fastly) throw new WPHM_Security_Read_Exception('target');
    $addresses=$cpanel?wphm_sc_panel_addresses($host):[];
    if($cpanel && !$addresses) throw new WPHM_Security_Read_Exception('target');
    if($cpanel && (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE') || !(curl_version()['features'] & CURL_VERSION_SSL) || (defined('WP_PROXY_HOST') && WP_PROXY_HOST))) throw new WPHM_Security_Read_Exception('transport');
    $remaining=min($context['deadline']-microtime(true),($GLOBALS['wphm_sc_deadline'] ?? microtime(true)+3)-microtime(true));
    if($remaining<0.25) throw new WPHM_Security_Read_Exception('budget');
    $allowed=$cpanel?['Authorization']:['Fastly-Key']; $safe=['Accept'=>'application/json','Accept-Encoding'=>'identity'];
    foreach($allowed as $key) if(isset($headers[$key])) $safe[$key]=$headers[$key];
    $ports=function($ports,$request_host,$request_url) use($url,$cpanel) { if($cpanel && $request_url===$url) $ports[]=2083; return $ports; };
    $pin=function($handle,$args,$request_url) use($url,$cpanel,$addresses,$host) {
        if($cpanel && $request_url===$url) {
            $ip=strpos($addresses[0],':')!==false?'['.$addresses[0].']':$addresses[0];
            curl_setopt($handle,CURLOPT_PROXY,'');
            if(!curl_setopt($handle,CURLOPT_RESOLVE,[$host.':2083:'.$ip])) throw new WPHM_Security_Read_Exception('transport');
        }
    };
    add_filter('http_allowed_safe_ports',$ports,10,3);
    if($cpanel) add_action('http_api_curl',$pin,10,3);
    try { $response=wp_safe_remote_get($url,['headers'=>$safe,'timeout'=>min(3,$remaining),'sslverify'=>true,'redirection'=>0,'cookies'=>[],'decompress'=>false,'limit_response_size'=>65537]); }
    finally { remove_filter('http_allowed_safe_ports',$ports,10); if($cpanel) remove_action('http_api_curl',$pin,10); }
    if(is_wp_error($response)) throw new WPHM_Security_Read_Exception(strpos((string)$response->get_error_code(),'timeout')!==false?'timeout':'network');
    $status=(int)wp_remote_retrieve_response_code($response);
    if($status!==200) throw new WPHM_Security_Read_Exception($status>=300 && $status<400?'redirect':'http_'.$status);
    $body=(string)wp_remote_retrieve_body($response);
    if(strlen($body)>65536) throw new WPHM_Security_Read_Exception('size');
    if(!in_array(strtolower(trim((string)wp_remote_retrieve_header($response,'content-encoding'))),['','identity'],true)) throw new WPHM_Security_Read_Exception('encoding');
    $json=json_decode($body,true,32);
    if(!is_array($json)) throw new WPHM_Security_Read_Exception('json');
    return $json;
}

function wphm_sc_read_cpanel(array $context): array {
    $connection=wphm_sc_cpanel_connection($context['connection_id']);
    if($connection===null) throw new WPHM_Security_Read_Exception('target');
    $context['deadline']=microtime(true)+$context['timeout'];
    $data=wphm_sc_service_get('https://'.$connection['host'].':2083/execute/ModSecurity/list_domains',['Authorization'=>'cpanel '.$connection['account'].':'.$context['token']],$context,$connection['host']);
    if(($data['module'] ?? '')!=='ModSecurity' || ($data['func'] ?? '')!=='list_domains' || !in_array($data['result']['status'] ?? null,[1,'1'],true) || !is_array($data['result']['data'] ?? null) || count($data['result']['data'])>200) throw new WPHM_Security_Read_Exception('schema');
    $host=strtolower((string)wp_parse_url($context['site'],PHP_URL_HOST)); $matched=[];
    foreach($data['result']['data'] as $row) if(is_array($row) && is_string($row['domain'] ?? null) && strtolower($row['domain'])===$host) $matched[]=$row;
    if(count($matched)!==1) throw new WPHM_Security_Read_Exception('site');
    $value=$matched[0]['enabled'] ?? null;
    if(!in_array($value,[true,false,0,1,'0','1'],true)) throw new WPHM_Security_Read_Exception('schema');
    return ['schema'=>1,'site'=>$context['site'],'checked_at'=>time(),'capabilities'=>['waf'=>in_array($value,[true,1,'1'],true)]];
}

function wphm_sc_read_fastly(array $context): array {
    $id=$context['connection_id'];
    if(!preg_match('/^[A-Za-z0-9]{6,64}$/D',$id)) throw new WPHM_Security_Read_Exception('target');
    $context['deadline']=microtime(true)+$context['timeout']; $headers=['Fastly-Key'=>$context['token']];
    $active=wphm_sc_service_get('https://api.fastly.com/service/'.$id.'/version/active',$headers,$context);
    $version=wphm_sc_setting_integer($active['number'] ?? null,1,99999999);
    if($version===null || ($active['active'] ?? null)!==true || (isset($active['service_id']) && $active['service_id']!==$id)) throw new WPHM_Security_Read_Exception('schema');
    $domains=wphm_sc_service_get('https://api.fastly.com/service/'.$id.'/version/'.$version.'/domain',$headers,$context);
    $host=strtolower((string)wp_parse_url($context['site'],PHP_URL_HOST));
    if(count($domains)!==1 || !is_array($domains[0] ?? null) || !empty($domains[0]['deleted_at']) || !is_string($domains[0]['name'] ?? null) || strtolower($domains[0]['name'])!==$host || (isset($domains[0]['service_id']) && $domains[0]['service_id']!==$id)) throw new WPHM_Security_Read_Exception('site');
    $end=intdiv(time(),60)*60-1800; $start=$end-300;
    $stats=wphm_sc_service_get('https://api.fastly.com/stats/service/'.$id.'?from='.$start.'&to='.$end.'&by=minute',$headers,$context);
    if(($stats['status'] ?? '')!=='success' || !is_array($stats['data'] ?? null) || count($stats['data'])!==5) throw new WPHM_Security_Read_Exception('schema');
    $traffic=['requests'=>0,'errors'=>0,'peak'=>0,'window_seconds'=>300,'from'=>$start,'to'=>$end]; $seen=[];
    foreach($stats['data'] as $row) {
        $time=$row['start_time'] ?? null; $requests=$row['requests'] ?? null; $errors=$row['status_5xx'] ?? null;
        if((isset($row['service_id']) && $row['service_id']!==$id) || !is_int($time) || $time<$start || $time>=$end || ($time-$start)%60!==0 || isset($seen[$time]) || !wphm_sc_valid_count($requests,min(60000000,PHP_INT_MAX-$traffic['requests'])) || !wphm_sc_valid_count($errors,$requests)) throw new WPHM_Security_Read_Exception('schema');
        $seen[$time]=true; $traffic['requests']+=$requests; $traffic['errors']+=$errors; $traffic['peak']=max($traffic['peak'],$requests);
    }
    return ['schema'=>1,'site'=>$context['site'],'checked_at'=>time(),'traffic'=>$traffic];
}
