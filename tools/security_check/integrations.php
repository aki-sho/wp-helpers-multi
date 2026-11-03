<?php
if (!defined('ABSPATH')) exit;

/** All outbound requests share one deadline and use fixed HTTPS hosts/paths. */
function wphm_sc_http(string $url, ?array $body = null, array $headers = [], ?string &$failure = null): ?array {
    $failure='target';
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) return null;
    $host = $parts['host'] ?? ''; $path = $parts['path'] ?? '';
    $valid = $host === 'api.wordpress.org' && in_array($path, ['/core/stable-check/1.0/','/core/serve-happy/1.0/','/plugins/update-check/1.1/','/themes/update-check/1.1/'], true);
    $valid = $valid || ($host === 'www.wpvulnerability.net' && preg_match('~^/(?:plugin|theme)/[a-z0-9][a-z0-9_-]{0,99}/$|^/core/[0-9][0-9.]{0,19}/$~D', $path));
    $valid = $valid || ($host === 'api.cloudflare.com' && preg_match('~^/client/v4/(?:graphql|zones/[a-f0-9]{32}(?:/rulesets/phases/(?:http_request_firewall_managed|http_ratelimit)/entrypoint)?)$~D', $path));
    if (!$valid) return null;
    if (!isset($GLOBALS['wphm_sc_deadline'])) $GLOBALS['wphm_sc_deadline'] = microtime(true) + 25;
    $remaining = $GLOBALS['wphm_sc_deadline'] - microtime(true);
    if ($remaining < 0.25) { $failure='budget'; return null; }
    global $wp_version;
    $args = ['timeout' => min(3, $remaining), 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048577, 'decompress' => false, 'cookies' => [], 'user-agent' => 'WordPress/' . preg_replace('/[^0-9A-Za-z_.-]/', '', (string)$wp_version) . '; WP Helpers Multi/' . WPHM_VERSION, 'headers' => array_merge(['Accept'=>'application/json','Accept-Encoding'=>'identity'], $headers)];
    // A credential is accepted only for the fixed Cloudflare API host.
    if ($host !== 'api.cloudflare.com') unset($args['headers']['Authorization']);
    if ($body !== null) {
        $args['body'] = $host === 'api.cloudflare.com' ? wp_json_encode($body) : $body;
        if ($host === 'api.cloudflare.com') $args['headers']['Content-Type'] = 'application/json';
    }
    $response = $body === null ? wp_safe_remote_get($url, $args) : wp_safe_remote_post($url, $args);
    if (is_wp_error($response)) { $failure='network'; return null; }
    $status=(int)wp_remote_retrieve_response_code($response);
    if ($status!==200) { $failure=$status>=300 && $status<400 ? 'redirect' : 'http_'.$status; return null; }
    $text = (string)wp_remote_retrieve_body($response);
    $encoding = strtolower(trim((string)wp_remote_retrieve_header($response, 'content-encoding')));
    if (strlen($text) > 1048576) { $failure='size'; return null; }
    if (!in_array($encoding, ['', 'identity'], true)) { $failure='encoding'; return null; }
    $data = json_decode($text, true, 64);
    if(!is_array($data)) { $failure='json'; return null; }
    $failure=null;
    return $data;
}

function wphm_sc_range(string $version, array $operator): ?bool {
    if (!preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D',$version)) return null;
    $ops = ['lt'=>'<','le'=>'<=','gt'=>'>','ge'=>'>=','eq'=>'==','ne'=>'!=']; $bounds = [];
    if(isset($operator['unfixed']) && !in_array($operator['unfixed'],[0,1,'0','1',false,true],true)) return null;
    foreach (['min','max'] as $side) {
        $bound = $operator[$side . '_version'] ?? null; $op = $operator[$side . '_operator'] ?? null;
        if ($bound === null && $op === null) continue;
        if (!is_string($bound) || !preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D', $bound) || !is_string($op) || !isset($ops[$op])) return null;
        $bounds[$side]=[$bound,$op];
    }
    // Validate every bound before considering a product unaffected.
    if(isset($bounds['min'],$bounds['max']) && in_array($bounds['min'][1],['ge','gt'],true) && in_array($bounds['max'][1],['le','lt'],true) && (version_compare($bounds['min'][0],$bounds['max'][0],'>') || ($bounds['min'][0]===$bounds['max'][0] && ($bounds['min'][1]==='gt' || $bounds['max'][1]==='lt')))) return null;
    foreach($bounds as $bound) if(!version_compare($version,$bound[0],$ops[$bound[1]])) return false;
    return $bounds ? true : (isset($operator['unfixed']) && (string)$operator['unfixed'] === '1' ? true : null);
}

function wphm_sc_cloudflare(bool $refresh): array {
    $options = wphm_sc_options();
    $out = ['configured'=>false,'known'=>false,'checked_at'=>0,'requests'=>null,'waf_rules'=>null,'blocked'=>null,'events'=>null,'login_limits'=>[],'limit_status'=>'unknown','state'=>'未設定'];
    if (!$options['cf_secret'] || !preg_match('/^[a-f0-9]{32}$/D',$options['cf_zone'])) return $out;
    $out['configured'] = true; $out['state'] = '情報を取得できませんでした';
    $key = hash('sha256',wp_json_encode([$options['cf_zone'],$options['cf_secret'],home_url('/'),site_url('wp-login.php'),WPHM_VERSION]));
    if (!$refresh) {
        $cached = get_option('wphm_sc_cf_metadata',[]);
        if (is_array($cached) && ($cached['key'] ?? '') === $key && ($cached['checked_at'] ?? 0) >= time()-HOUR_IN_SECONDS && $cached['checked_at'] <= time()+60) return $cached;
        return $out;
    }
    delete_option('wphm_sc_cf_metadata');
    try { $token = wphm_sc_secret($options['cf_secret'],true); } catch (RuntimeException $exception) { return $out; }
    if ($token === '') return $out;
    $headers = ['Authorization'=>'Bearer ' . $token]; $base = 'https://api.cloudflare.com/client/v4/zones/' . $options['cf_zone'];
    $zone = wphm_sc_http($base,null,$headers);
    $domain = is_array($zone) && ($zone['success'] ?? false) === true && is_string($zone['result']['name'] ?? null) ? strtolower($zone['result']['name']) : '';
    $site = strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
    if ($domain === '' || (isset($zone['result']['id']) && $zone['result']['id']!==$options['cf_zone']) || !($site === $domain || substr($site,-strlen('.'.$domain)) === '.'.$domain)) { $out['state']='ゾーンのドメイン一致を確認できませんでした'; return $out; }
    $query = 'query($zone: string, $start: Time, $end: Time) { viewer { zones(filter: {zoneTag: $zone}) { httpRequests1hGroups(limit: 24, filter: {datetime_geq: $start, datetime_lt: $end}) { sum { requests } } firewallEventsAdaptive(limit: 100, filter: {datetime_geq: $start, datetime_leq: $end}, orderBy: [datetime_DESC]) { action } } } }';
    $data = wphm_sc_http('https://api.cloudflare.com/client/v4/graphql',['query'=>$query,'variables'=>['zone'=>$options['cf_zone'],'start'=>gmdate('Y-m-d\TH:00:00\Z',time()-DAY_IN_SECONDS),'end'=>gmdate('Y-m-d\TH:00:00\Z')]],$headers);
    $groups = $data['data']['viewer']['zones'][0]['httpRequests1hGroups'] ?? null;
    if (is_array($data['data']['viewer']['zones'] ?? null) && count($data['data']['viewer']['zones'])===1 && is_array($groups) && count($groups)<=24) {
        $total=0; $valid=true;
        foreach ($groups as $group) { $n=$group['sum']['requests'] ?? null; if (!is_numeric($n) || $n<0 || $n>PHP_INT_MAX-$total) { $valid=false; break; } $total+=(int)$n; }
        if ($valid) $out['requests']=$total;
    }
    $events=$data['data']['viewer']['zones'][0]['firewallEventsAdaptive'] ?? null;
    if(is_array($data['data']['viewer']['zones'] ?? null) && count($data['data']['viewer']['zones'])===1 && is_array($events) && count($events)<=100) {
        $blocked=0; $valid=true;
        foreach($events as $event) { if(!is_array($event) || !is_string($event['action'] ?? null) || strlen($event['action'])>40){$valid=false;break;} if($event['action']==='block') $blocked++; }
        if($valid){ $out['events']=count($events); $out['blocked']=$blocked; }
    }
    $rules = wphm_sc_http($base . '/rulesets/phases/http_request_firewall_managed/entrypoint',null,$headers);
    if (is_array($rules) && ($rules['success'] ?? false) === true && is_array($rules['result']['rules'] ?? null)) {
        $out['waf_rules']=0;
        foreach ($rules['result']['rules'] as $rule) if (is_array($rule) && ($rule['enabled'] ?? true) === true && ($rule['action'] ?? '')==='execute') $out['waf_rules']++;
    }
    $failure=null;
    $rate=wphm_sc_http($base.'/rulesets/phases/http_ratelimit/entrypoint',null,$headers,$failure);
    $out['limit_status']=in_array($failure,['http_401','http_403'],true)?'permission':'error';
    if(is_array($rate) && ($rate['success'] ?? null)===true && is_array($rate['result']['rules'] ?? null) && count($rate['result']['rules'])<=100) {
        $out['limit_status']='no_match';
        foreach($rate['result']['rules'] as $rule) {
            $limit=wphm_sc_cloudflare_login_rule($rule);
            if($limit!==null) { $out['login_limits'][]=$limit; $out['limit_status']='confirmed'; }
            if(count($out['login_limits'])>=20) break;
        }
    }
    $out['known']=$out['requests']!==null || $out['waf_rules']!==null || $out['events']!==null || $out['login_limits'];
    $out['known']=(bool)$out['known'];
    $out['checked_at']=$out['known'] ? time() : 0; $out['key']=$key;
    $out['state']=$out['known'] ? 'ドメイン一致を確認したゾーンの読取り情報'.($out['requests']===null || $out['waf_rules']===null || $out['events']===null ? '（一部の情報は取得失敗）':'') : '権限・プラン・応答を確認してください';
    // No token, response body, host or visitor data in the stored report.
    update_option('wphm_sc_cf_metadata',$out,false);
    return $out;
}

/** Parse only a small conjunction grammar; complex or conditional expressions stay unknown. */
function wphm_sc_cloudflare_login_rule($rule): ?array {
    if(!is_array($rule) || !is_string($rule['expression'] ?? null) || strlen($rule['expression'])>2048 || !in_array($rule['action'] ?? '',['block','managed_challenge','js_challenge'],true) || (isset($rule['enabled']) && !is_bool($rule['enabled']))) return null;
    $parts=preg_split('/\s+and\s+/i',trim($rule['expression']));
    if(!$parts || count($parts)>3) return null;
    $conditions=[];
    foreach($parts as $part) {
        $part=trim($part,"() \t\r\n");
        if(!preg_match('/^(http\.request\.uri\.path|http\.request\.method|http\.host)\s+eq\s+"([^"\\\\]{1,256})"$/D',$part,$m) || isset($conditions[$m[1]])) return null;
        $conditions[$m[1]]=$m[2];
    }
    $login=(string)wp_parse_url(site_url('wp-login.php'),PHP_URL_PATH);
    $host=strtolower((string)wp_parse_url(home_url('/'),PHP_URL_HOST));
    if(($conditions['http.request.uri.path'] ?? '')!==$login || (isset($conditions['http.request.method']) && $conditions['http.request.method']!=='POST') || (isset($conditions['http.host']) && strtolower($conditions['http.host'])!==$host)) return null;
    $rate=$rule['ratelimit'] ?? null;
    if(!is_array($rate) || !wphm_sc_valid_count($rate['requests_per_period'] ?? null,1000000) || $rate['requests_per_period']<1 || !wphm_sc_valid_count($rate['period'] ?? null,86400) || $rate['period']<1 || !wphm_sc_valid_count($rate['mitigation_timeout'] ?? null,86400) || (!empty($rate['counting_expression']) && $rate['counting_expression']!==$rule['expression'])) return null;
    return ['name'=>'Cloudflareの回数制限ルール','source'=>'edge','enabled'=>$rule['enabled'] ?? true,'applied'=>true,'tries'=>$rate['requests_per_period'],'period'=>$rate['period'],'seconds'=>$rate['mitigation_timeout'],'routes'=>['wp_login']];
}
