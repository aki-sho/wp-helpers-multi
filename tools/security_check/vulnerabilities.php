<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_lookup_reason(string $code): string {
    $messages=['network'=>'通信・証明書の確認に失敗しました','budget'=>'今回の通信予算に達しました','size'=>'応答のサイズ上限を超えました','encoding'=>'対応しない圧縮応答です','json'=>'JSON応答を読み取れませんでした','schema'=>'製品名・版条件などの応答形式を確認できませんでした','http_404'=>'データベースに製品情報がありません','http_403'=>'照会先からアクセスを拒否されました','http_429'=>'照会先の回数制限に達しました','redirect'=>'転送応答のため取得を停止しました'];
    return isset(wphm_sc_read_reasons()[$code]) || preg_match('/^http_5[0-9]{2}$/D',$code)?wphm_sc_read_reason($code):($messages[$code] ?? '照会先から正常な情報を取得できませんでした');
}

function wphm_sc_vulnerability_record(array $data,string $kind,string $slug,string $version): ?array {
    if(!isset($data['error']) || !in_array($data['error'],[0,'0'],true) || !is_array($data['data'] ?? null)) return null;
    $entry=$data['data'];
    if(($entry[$kind] ?? null)!==$slug || !is_array($entry['vulnerability'] ?? null) || count($entry['vulnerability'])>1000) return null;
    $out=['status'=>'checked','checked_at'=>time(),'version'=>$version,'kind'=>$kind,'slug'=>$slug,'matches'=>[],'affected'=>0,'unresolved'=>0,'closed'=>isset($entry['closed']) && in_array($entry['closed'],[1,'1'],true),'cwe'=>[]];
    foreach($entry['vulnerability'] as $item) {
        if(!is_array($item)) { $out['unresolved']++; continue; }
        $operator=$kind==='core' ? [] : (is_array($item['operator'] ?? null) ? array_intersect_key($item['operator'],array_flip(['min_version','min_operator','max_version','max_operator','unfixed'])) : []);
        $affected=$kind==='core' ? true : wphm_sc_range($version,$operator);
        if($affected===null) { $out['unresolved']++; continue; }
        if(!$affected) continue;
        $sources=[];
        foreach(array_slice(is_array($item['source'] ?? null)?$item['source']:[],0,2) as $source) {
            if(!is_array($source) || !is_string($source['link'] ?? null)) continue;
            $url=esc_url_raw($source['link'],['https']); $parts=wp_parse_url($url);
            if($url==='' || strlen($url)>512 || !is_array($parts) || ($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) continue;
            $sources[]=['name'=>sanitize_text_field(substr(is_string($source['name'] ?? null)?$source['name']:'情報源',0,200)),'url'=>$url];
        }
        if(!$sources || !is_string($item['name'] ?? null)) { $out['unresolved']++; continue; }
        $out['affected']++;
        $impact=is_array($item['impact'] ?? null)?$item['impact']:[]; $cwes=[];
        $raw_cwes=is_array($impact['cwe'] ?? null)?$impact['cwe']:[];
        if(count($raw_cwes)>1000) $out['unresolved']++;
        foreach(array_slice($raw_cwes,0,1000) as $cwe) {
            if(is_array($cwe) && is_string($cwe['cwe'] ?? null) && preg_match('/^CWE-[0-9]{1,5}$/D',$cwe['cwe'])) { if(count($cwes)<10) $cwes[]=$cwe['cwe']; if(in_array($cwe['cwe'],wphm_sc_risk_cwes(),true) && !in_array($cwe['cwe'],$out['cwe'],true)) $out['cwe'][]=$cwe['cwe']; }
        }
        if(count($out['matches'])>=20) { $out['unresolved']++; continue; }
        $cvss=is_array($impact['cvss3'] ?? null)?$impact['cvss3']:(is_array($impact['cvss'] ?? null)?$impact['cvss']:[]); $conditions=[];
        foreach(['pr'=>'必要権限','ui'=>'利用者操作','av'=>'到達経路'] as $key=>$label) if(isset($cvss[$key]) && is_scalar($cvss[$key])) $conditions[]=$label.':'.substr((string)$cvss[$key],0,40);
        $out['matches'][]=['name'=>sanitize_text_field(substr($item['name'],0,300)),'sources'=>$sources,'cwe'=>$cwes,'conditions'=>implode(' / ',$conditions),'range'=>$operator,'fix'=>($operator['max_operator'] ?? '')==='lt' && is_string($operator['max_version'] ?? null)?$operator['max_version']:'情報源を確認'];
    }
    while(strlen(serialize($out))>32768 && count($out['matches'])>1) { array_pop($out['matches']); $out['unresolved']++; }
    return strlen(serialize($out))<=32768 ? $out : null;
}

function wphm_sc_lookup_targets(array $inventory): array {
    global $wp_version;
    $targets=[['kind'=>'core','slug'=>(string)$wp_version,'version'=>(string)$wp_version,'name'=>'WordPress','eligible'=>(bool)preg_match('/^[0-9][0-9.]{0,19}$/D',(string)$wp_version),'reason'=>'開発版など、版の識別形式が照合対象外です']];
    foreach(['plugins'=>'plugin','themes'=>'theme','mu_plugins'=>'mu','dropins'=>'dropin'] as $group=>$kind) foreach($inventory[$group] ?? [] as $id=>$item) {
        $slug=$kind==='plugin' ? (strpos($id,'/')===false?basename($id,'.php'):dirname($id)):$id;
        $public=in_array($kind,['plugin','theme'],true) && wphm_sc_wporg_item($item) && (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D',$slug) && (bool)preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D',$item['Version']);
        $vendor_key=hash('sha256',$kind.':'.$id);
        $vendor=!$public && wphm_sc_options()['vendor']?wphm_sc_vendor_mapping($vendor_key,['kind'=>$kind,'file'=>$id]):null;
        $vendor_valid=$vendor!==null && (bool)preg_match('/^[0-9][0-9A-Za-z.+_-]{0,39}$/D',$item['Version']);
        $target=['kind'=>$kind,'slug'=>$slug,'version'=>$item['Version'],'name'=>$item['Name'],'eligible'=>$public || $vendor_valid,'reason'=>$vendor_valid?'登録したGitHub配布元の公開済みアドバイザリとの照合':(in_array($kind,['mu','dropin'],true)?'MU・ドロップインの配布元照合が未登録・未選択です':'独自配布元の照合が未登録・未選択、または版情報が対象外です')];
        if($vendor_valid) { $target['vendor']=$vendor; $target['provider_key']=hash('sha256',wp_json_encode($vendor)); }
        $targets[]=$target;
    }
    return $targets;
}

function wphm_sc_cached_lookup($record,array $target): bool {
    if(isset($target['provider_key']) && (!is_array($record) || ($record['provider'] ?? '')!==$target['provider_key'])) return false;
    if(!is_array($record) || ($record['status'] ?? 'checked')!=='checked' || ($record['kind'] ?? '')!==$target['kind'] || ($record['slug'] ?? '')!==$target['slug'] || ($record['version'] ?? '')!==$target['version'] || !is_int($record['checked_at'] ?? null) || $record['checked_at']<time()-DAY_IN_SECONDS || $record['checked_at']>time()+60 || !is_array($record['matches'] ?? null) || count($record['matches'])>20 || !is_int($record['unresolved'] ?? null) || $record['unresolved']<0 || !is_bool($record['closed'] ?? null) || strlen(serialize($record))>32768) return false;
    if(isset($record['affected']) && (!is_int($record['affected']) || $record['affected']<count($record['matches']) || $record['affected']>1000)) return false;
    if(isset($record['cwe']) && (!is_array($record['cwe']) || count($record['cwe'])>64)) return false;
    foreach($record['cwe'] ?? [] as $cwe) if(!is_string($cwe) || !preg_match('/^CWE-[0-9]{1,5}$/D',$cwe)) return false;
    foreach($record['matches'] as $match) {
        if(!is_array($match)) return false;
        foreach(['name'=>300,'conditions'=>250,'fix'=>100] as $key=>$length) if(!is_string($match[$key] ?? null) || strlen($match[$key])>$length) return false;
        if(!is_array($match['sources'] ?? null) || count($match['sources'])<1 || count($match['sources'])>2 || !is_array($match['cwe'] ?? null) || count($match['cwe'])>10 || !is_array($match['range'] ?? null) || count($match['range'])>5 || array_diff_key($match['range'],array_flip(['min_version','min_operator','max_version','max_operator','unfixed']))) return false;
        if($target['kind']!=='core' && wphm_sc_range($target['version'],$match['range'])!==true) return false;
        foreach($match['range'] as $value) if($value!==null && !is_scalar($value)) return false;
        foreach($match['cwe'] as $cwe) if(!is_string($cwe) || !preg_match('/^CWE-[0-9]{1,5}$/D',$cwe)) return false;
        foreach($match['sources'] as $source) {
            if(!is_array($source) || !is_string($source['name'] ?? null) || strlen($source['name'])>200 || !is_string($source['url'] ?? null) || strlen($source['url'])>512) return false;
            $parts=wp_parse_url($source['url']);
            if(!is_array($parts) || ($parts['scheme'] ?? '')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return false;
        }
    }
    return true;
}

function wphm_sc_risk_cwes(): array { return array_keys(wphm_sc_cwe_mapping()); }

/** Bounded batches; failed requests are remembered for this run, so continuation always advances. */
function wphm_sc_vulnerabilities(array $inventory,bool $refresh,string $run=''): array {
    $out=['enabled'=>(bool)wphm_sc_options()['vulnerabilities'],'checked'=>0,'unknown'=>0,'total'=>0,'eligible'=>0,'pending'=>0,'failed'=>0,'unsupported'=>0,'conditional'=>0,'closed'=>0,'affected'=>0,'limited'=>false,'matches'=>[],'components'=>[],'products'=>[],'cwe'=>[]];
    $all=wphm_sc_lookup_targets($inventory); $out['total']=count($all); $out['limited']=count($all)>500; $targets=array_slice($all,0,500);
    $out['unsupported']=max(0,count($all)-500);
    $saved=get_option('wphm_sc_vulnerability_metadata',[]); $cache=is_array($saved)?$saved:[]; $next=[]; $calls=0;
    $capacity=min(32768,max(3000,intdiv(1900000,max(1,count($targets)))-80));
    $cursor=max(0,(int)get_option('wphm_sc_vulnerability_cursor',0)); $count=count($targets);
    for($i=0;$i<$count;$i++) {
        $target=$targets[($cursor+$i)%$count]; $row=['name'=>$target['name'],'version'=>$target['version'],'kind'=>$target['kind'],'status'=>'unsupported','reason'=>$target['reason'],'checked_at'=>0];
        if(!$target['eligible']) { $out['unsupported']++; $out['products'][]=$row; continue; }
        $out['eligible']++; $key=hash('sha256',$target['kind'].':'.$target['slug'].':'.$target['version'].(isset($target['provider_key'])?':'.$target['provider_key']:'')); $record=$cache[$key] ?? null;
        $valid=wphm_sc_cached_lookup($record,$target);
        $failed=is_array($record) && (!$refresh || ($run!=='' && ($record['run'] ?? '')===$run)) && ($record['status'] ?? '')==='failed' && is_int($record['checked_at'] ?? null) && $record['checked_at']>=time()-900 && $record['checked_at']<=time()+60 && is_string($record['code'] ?? null) && strlen($record['code'])<=30;
        $budget=($GLOBALS['wphm_sc_deadline'] ?? microtime(true)+1)-microtime(true);
        if($out['enabled'] && !$valid && !$failed && $refresh && $calls<8 && $budget>=0.25) {
            $calls++; $error=null;
            if(isset($target['vendor'])) $record=wphm_sc_vendor_fetch($target,$error);
            else {
                $data=wphm_sc_http('https://www.wpvulnerability.net/'.$target['kind'].'/'.rawurlencode($target['slug']).'/',null,[],$error);
                $record=$data!==null ? wphm_sc_vulnerability_record($data,$target['kind'],$target['slug'],$target['version']) : null;
            }
            $valid=$record!==null;
            if(!$valid) { $record=['status'=>'failed','checked_at'=>time(),'run'=>$run,'code'=>$error ?? 'schema']; $failed=true; }
            update_option('wphm_sc_vulnerability_cursor',($cursor+$i+1)%max(1,$count),false);
        }
        if(!$out['enabled']) { $row['status']='disabled'; $row['reason']='既知脆弱性照合が設定で無効です'; }
        elseif($valid) {
            while(strlen(serialize($record))>$capacity && count($record['matches'])>1) { array_pop($record['matches']); $record['unresolved']++; }
            if(strlen(serialize($record))<=$capacity) $next[$key]=$record;
            else $out['limited']=true;
            $row['status']=$record['unresolved']>0?'conditional':'checked'; $row['reason']=$record['unresolved']>0?'版条件や表示上限により確認できない情報があります':'取得した情報とインストール版を照合しました'; $row['checked_at']=$record['checked_at'];
            $out['checked']++; if($record['unresolved']>0) $out['conditional']++; if(!empty($record['closed'])) $out['closed']++;
            $out['affected']+=(int)($record['affected'] ?? count($record['matches']));
            $out['components'][]=['name'=>$target['name'],'version'=>$target['version'],'checked_at'=>$record['checked_at'],'unresolved'=>$record['unresolved']];
            foreach($record['matches'] as $match) {
                // Previous releases have no aggregate field; include every cached match before the UI limit.
                foreach($match['cwe'] as $cwe) if(in_array($cwe,wphm_sc_risk_cwes(),true) && !in_array($cwe,$out['cwe'],true)) $out['cwe'][]=$cwe;
                if(count($out['matches'])<100) $out['matches'][]=['component'=>$target['name'],'version'=>$target['version'],'checked_at'=>$record['checked_at']]+$match;
            }
            foreach($record['cwe'] ?? [] as $cwe) if(in_array($cwe,wphm_sc_risk_cwes(),true) && !in_array($cwe,$out['cwe'],true)) $out['cwe'][]=$cwe;
        } elseif($failed) {
            $out['failed']++; $row['status']='failed'; $row['reason']=wphm_sc_lookup_reason($record['code']); $row['checked_at']=$record['checked_at'];
            $next[$key]=$record;
        } else { $out['pending']++; $row['status']='pending'; $row['reason']='残りの照合を待っています'; }
        $out['products'][]=$row;
    }
    if(!$out['enabled']) $out['pending']=0;
    $out['unknown']=$out['pending']+$out['failed']+$out['unsupported']+$out['conditional']+(!$out['enabled']?$out['eligible']:0);
    if($refresh && $out['enabled']) update_option('wphm_sc_vulnerability_metadata',$next,false);
    return $out;
}
