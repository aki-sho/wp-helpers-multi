<?php
if (!defined('ABSPATH')) exit;

/** All outbound requests share one deadline and use fixed HTTPS hosts/paths. */
function wphm_sc_http(string $url, ?array $body = null, array $headers = []): ?array {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) return null;
    $host = $parts['host'] ?? ''; $path = $parts['path'] ?? '';
    $valid = $host === 'api.wordpress.org' && in_array($path, ['/core/stable-check/1.0/','/core/serve-happy/1.0/','/plugins/update-check/1.1/','/themes/update-check/1.1/'], true);
    $valid = $valid || ($host === 'www.wpvulnerability.net' && preg_match('~^/(?:plugin|theme)/[a-z0-9][a-z0-9_-]{0,99}/$|^/core/[0-9][0-9.]{0,19}/$~D', $path));
    $valid = $valid || ($host === 'api.cloudflare.com' && preg_match('~^/client/v4/(?:graphql|zones/[a-f0-9]{32}(?:/rulesets/phases/http_request_firewall_managed/entrypoint)?)$~D', $path));
    if (!$valid) return null;
    if (!isset($GLOBALS['wphm_sc_deadline'])) $GLOBALS['wphm_sc_deadline'] = microtime(true) + 25;
    $remaining = $GLOBALS['wphm_sc_deadline'] - microtime(true);
    if ($remaining < 0.25) return null;
    global $wp_version;
    $args = ['timeout' => min(3, $remaining), 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048577, 'decompress' => false, 'cookies' => [], 'user-agent' => 'WordPress/' . preg_replace('/[^0-9A-Za-z_.-]/', '', (string)$wp_version) . '; WP Helpers Multi/' . WPHM_VERSION, 'headers' => array_merge(['Accept'=>'application/json','Accept-Encoding'=>'identity'], $headers)];
    // A credential is accepted only for the fixed Cloudflare API host.
    if ($host !== 'api.cloudflare.com') unset($args['headers']['Authorization']);
    if ($body !== null) {
        $args['body'] = $host === 'api.cloudflare.com' ? wp_json_encode($body) : $body;
        if ($host === 'api.cloudflare.com') $args['headers']['Content-Type'] = 'application/json';
    }
    $response = $body === null ? wp_safe_remote_get($url, $args) : wp_safe_remote_post($url, $args);
    if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) return null;
    $text = (string)wp_remote_retrieve_body($response);
    $encoding = strtolower(trim((string)wp_remote_retrieve_header($response, 'content-encoding')));
    if (strlen($text) > 1048576 || !in_array($encoding, ['', 'identity'], true)) return null;
    $data = json_decode($text, true, 64);
    return is_array($data) ? $data : null;
}

function wphm_sc_range(string $version, array $operator): ?bool {
    if (!preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D',$version)) return null;
    $ops = ['lt'=>'<','le'=>'<=','gt'=>'>','ge'=>'>=','eq'=>'==','ne'=>'!=']; $bounded = false;
    foreach (['min','max'] as $side) {
        $bound = $operator[$side . '_version'] ?? null; $op = $operator[$side . '_operator'] ?? null;
        if ($bound === null && $op === null) continue;
        if (!is_string($bound) || !preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D', $bound) || !is_string($op) || !isset($ops[$op])) return null;
        $bounded = true;
        if (!version_compare($version, $bound, $ops[$op])) return false;
    }
    return $bounded ? true : (isset($operator['unfixed']) && (string)$operator['unfixed'] === '1' ? true : null);
}

function wphm_sc_vulnerability_record(array $data, string $kind, string $slug, string $version): ?array {
    if (!isset($data['error']) || (string)$data['error'] !== '0' || !isset($data['data']) || !is_array($data['data'])) return null;
    $entry = $data['data'];
    if (($entry[$kind] ?? null) !== $slug || !array_key_exists('vulnerability', $entry) || !is_array($entry['vulnerability']) || count($entry['vulnerability']) > 1000) return null;
    $out = ['checked_at'=>time(),'version'=>$version,'kind'=>$kind,'slug'=>$slug,'matches'=>[],'unresolved'=>0];
    foreach ($entry['vulnerability'] as $item) {
        if (!is_array($item)) { $out['unresolved']++; continue; }
        $affected = $kind === 'core' ? true : (isset($item['operator']) && is_array($item['operator']) ? wphm_sc_range($version, $item['operator']) : null);
        if ($affected === null) { $out['unresolved']++; continue; }
        if (!$affected) continue;
        if (count($out['matches']) >= 20) { $out['unresolved']++; continue; }
        $sources = [];
        foreach (array_slice(is_array($item['source'] ?? null) ? $item['source'] : [], 0, 2) as $source) {
            if (!is_array($source)) continue;
            $link = isset($source['link']) && is_string($source['link']) ? esc_url_raw($source['link'], ['https']) : '';
            if ($link !== '' && strlen($link)<=512) $sources[] = ['name'=>sanitize_text_field(substr(is_string($source['name'] ?? null) ? $source['name'] : '情報源',0,200)), 'url'=>$link];
        }
        $impact = is_array($item['impact'] ?? null) ? $item['impact'] : [];
        $conditions = []; $cwes = [];
        foreach (array_slice(is_array($impact['cwe'] ?? null) ? $impact['cwe'] : [],0,10) as $cwe) if (is_array($cwe) && is_string($cwe['cwe'] ?? null) && preg_match('/^CWE-[0-9]{1,5}$/D',$cwe['cwe'])) $cwes[] = $cwe['cwe'];
        $cvss = is_array($impact['cvss3'] ?? null) ? $impact['cvss3'] : (is_array($impact['cvss'] ?? null) ? $impact['cvss'] : []);
        foreach (['pr'=>'必要権限','ui'=>'利用者操作','av'=>'到達経路'] as $key=>$label) if (isset($cvss[$key]) && is_scalar($cvss[$key])) $conditions[] = $label . ':' . substr((string)$cvss[$key],0,40);
        $operator = is_array($item['operator'] ?? null) ? $item['operator'] : [];
        $out['matches'][] = ['name'=>sanitize_text_field(substr(is_string($item['name'] ?? null) ? $item['name'] : '既知脆弱性',0,300)), 'sources'=>$sources,'cwe'=>$cwes,'conditions'=>implode(' / ',$conditions),'range'=>$operator,'fix'=>isset($operator['max_operator'],$operator['max_version']) && $operator['max_operator']==='lt' && is_string($operator['max_version']) ? $operator['max_version'] : '情報源を確認'];
    }
    while (strlen(serialize($out))>32768 && $out['matches']) { array_pop($out['matches']); $out['unresolved']++; }
    return $out;
}

function wphm_sc_vulnerabilities(array $inventory, bool $refresh): array {
    global $wp_version;
    $out = ['enabled'=>(bool)wphm_sc_options()['vulnerabilities'],'checked'=>0,'unknown'=>0,'total'=>1,'matches'=>[],'components'=>[]];
    $targets = [['kind'=>'core','slug'=>(string)$wp_version,'version'=>(string)$wp_version,'name'=>'WordPress']];
    foreach (['plugins'=>'plugin','themes'=>'theme'] as $group=>$kind) foreach ($inventory[$group] as $id=>$item) {
        $slug = $kind === 'plugin' ? (strpos($id,'/') === false ? basename($id,'.php') : dirname($id)) : $id;
        $targets[] = ['kind'=>$kind,'slug'=>$slug,'version'=>$item['Version'],'name'=>$item['Name'],'eligible'=>wphm_sc_wporg_item($item) && (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D',$slug)];
    }
    $out['total'] = count($targets) + count($inventory['mu_plugins'] ?? []) + count($inventory['dropins'] ?? []);
    $cache = get_option('wphm_sc_vulnerability_metadata', []); $cache = is_array($cache) ? $cache : []; $next = []; $next_bytes=0; $calls = 0;
    // Missing/expired products go first; a cursor prevents more than eight products blocking the rest.
    $cursor = max(0,(int)get_option('wphm_sc_vulnerability_cursor',0)); $count = count($targets);
    for ($i=0;$i<$count;$i++) {
        $target = $targets[($cursor+$i)%$count]; $key = hash('sha256',$target['kind'] . ':' . $target['slug'] . ':' . $target['version']);
        $record = $cache[$key] ?? null;
        $fresh = is_array($record) && isset($record['checked_at']) && $record['checked_at'] <= time()+60 && $record['checked_at'] >= time()-DAY_IN_SECONDS;
        if ($out['enabled'] && ($target['eligible'] ?? true) && $refresh && !$fresh && $calls < 8) {
            $calls++;
            $data = wphm_sc_http('https://www.wpvulnerability.net/' . $target['kind'] . '/' . rawurlencode($target['slug']) . '/');
            $record = $data !== null ? wphm_sc_vulnerability_record($data,$target['kind'],$target['slug'],$target['version']) : null;
            $fresh = is_array($record);
            update_option('wphm_sc_vulnerability_cursor',($cursor+$i+1)%$count,false);
        }
        if (!$out['enabled'] || !($target['eligible'] ?? true) || !$fresh) { $out['unknown']++; continue; }
        $record_bytes=strlen(serialize($record))+80;
        if (count($next)<500 && $next_bytes+$record_bytes<=2097000) { $next[$key]=$record; $next_bytes+=$record_bytes; }
        $out['checked']++;
        if ($record['unresolved']) $out['unknown']++;
        $out['components'][] = ['name'=>$target['name'],'version'=>$target['version'],'checked_at'=>$record['checked_at'],'unresolved'=>$record['unresolved']];
        foreach ($record['matches'] as $item) {
            if (count($out['matches']) >= 100) break;
            $out['matches'][] = ['component'=>$target['name'],'version'=>$target['version'],'checked_at'=>$record['checked_at']] + $item;
        }
    }
    $out['unknown'] += count($inventory['mu_plugins'] ?? []) + count($inventory['dropins'] ?? []);
    if ($refresh && $out['enabled']) update_option('wphm_sc_vulnerability_metadata',$next,false);
    return $out;
}

function wphm_sc_cloudflare(bool $refresh): array {
    $options = wphm_sc_options();
    $out = ['configured'=>false,'known'=>false,'checked_at'=>0,'requests'=>null,'waf_rules'=>null,'state'=>'未設定'];
    if (!$options['cf_secret'] || !preg_match('/^[a-f0-9]{32}$/D',$options['cf_zone'])) return $out;
    $out['configured'] = true; $out['state'] = '情報を取得できませんでした';
    $key = hash('sha256',$options['cf_zone'] . ':' . $options['cf_secret']);
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
    if ($domain === '' || !($site === $domain || substr($site,-strlen('.'.$domain)) === '.'.$domain)) { $out['state']='ゾーンのドメイン一致を確認できませんでした'; return $out; }
    $query = 'query($zone: string, $start: Time, $end: Time) { viewer { zones(filter: {zoneTag: $zone}) { httpRequests1hGroups(limit: 24, filter: {datetime_geq: $start, datetime_lt: $end}) { sum { requests } } } } }';
    $data = wphm_sc_http('https://api.cloudflare.com/client/v4/graphql',['query'=>$query,'variables'=>['zone'=>$options['cf_zone'],'start'=>gmdate('Y-m-d\TH:00:00\Z',time()-DAY_IN_SECONDS),'end'=>gmdate('Y-m-d\TH:00:00\Z')]],$headers);
    $groups = $data['data']['viewer']['zones'][0]['httpRequests1hGroups'] ?? null;
    if (empty($data['errors']) && is_array($groups) && count($groups)<=24) {
        $total=0; $valid=true;
        foreach ($groups as $group) { $n=$group['sum']['requests'] ?? null; if (!is_numeric($n) || $n<0 || $n>PHP_INT_MAX-$total) { $valid=false; break; } $total+=(int)$n; }
        if ($valid) $out['requests']=$total;
    }
    $rules = wphm_sc_http($base . '/rulesets/phases/http_request_firewall_managed/entrypoint',null,$headers);
    if (is_array($rules) && ($rules['success'] ?? false) === true && is_array($rules['result']['rules'] ?? null)) {
        $out['waf_rules']=0;
        foreach ($rules['result']['rules'] as $rule) if (is_array($rule) && ($rule['enabled'] ?? true) === true && ($rule['action'] ?? '')==='execute') $out['waf_rules']++;
    }
    $out['known']=$out['requests']!==null || $out['waf_rules']!==null;
    $out['checked_at']=$out['known'] ? time() : 0; $out['key']=$key;
    $out['state']=$out['known'] ? 'ドメイン一致を確認したゾーンの読取り情報' : '権限・プラン・応答を確認してください';
    // No token, response body, host or visitor data in the stored report.
    update_option('wphm_sc_cf_metadata',$out,false);
    return $out;
}
