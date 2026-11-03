<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_source_names(): array { return ['wordpress'=>'WordPress内','server'=>'サーバー側','edge'=>'外部サービス']; }
function wphm_sc_source_labels(): array { return ['ok'=>'取得済み','partial'=>'一部取得','skip'=>'未選択','setup'=>'接続情報が必要','pending'=>'再チェック待ち','unsupported'=>'未対応','error'=>'取得失敗','na'=>'この項目では使用しない']; }

/** Preferences are isolated by administrator and WordPress site. Credentials remain site options. */
function wphm_sc_source_selection(): array {
    $options=wphm_sc_options();
    $out=['wordpress'=>true,'server'=>false,'edge'=>$options['cf_zone']!=='' && $options['cf_secret']!==''];
    $saved=get_user_option('wphm_sc_sources',get_current_user_id());
    if(is_array($saved)) foreach($out as $key=>$default) if(isset($saved[$key]) && is_bool($saved[$key])) $out[$key]=$saved[$key];
    return $out;
}

/** Trusted installed code registers readers. No URL, path, PHP name or callback comes from a form. */
function wphm_sc_readers(): array {
    $out=[];
    try { $registered=apply_filters('wphm_security_readers',wphm_sc_builtin_readers()); } catch(Throwable $error) { return $out; }
    if(!is_array($registered)) return $out;
    foreach(array_slice($registered,0,16,true) as $id=>$reader) {
        if(!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$id) || in_array($id,['local','cloudflare'],true) || !is_array($reader) || !in_array($reader['source'] ?? '',['server','edge'],true) || !is_string($reader['name'] ?? null) || !is_callable($reader['read'] ?? null)) continue;
        $out[$id]=['source'=>$reader['source'],'name'=>sanitize_text_field(substr($reader['name'],0,100)),'read'=>$reader['read'],'needs_id'=>!empty($reader['needs_id']),'needs_token'=>!empty($reader['needs_token']),'help'=>is_string($reader['help'] ?? null)?sanitize_text_field(substr($reader['help'],0,800)):'' ];
    }
    return $out;
}

function wphm_sc_site_key(string $url): string {
    $p=wp_parse_url($url);
    if(!is_array($p) || !in_array($p['scheme'] ?? '',['http','https'],true) || !is_string($p['host'] ?? null) || isset($p['user'],$p['pass']) || isset($p['query']) || isset($p['fragment'])) return '';
    if(isset($p['user']) || isset($p['pass'])) return '';
    return strtolower($p['scheme'].'://'.$p['host']).(isset($p['port'])?':'.(int)$p['port']:'').'/'.trim($p['path'] ?? '','/');
}

function wphm_sc_valid_count($value, int $max): bool { return is_int($value) && $value>=0 && $value<=$max; }
function wphm_sc_setting_integer($value, int $min, int $max): ?int {
    if(!is_int($value) && !(is_string($value) && preg_match('/^[0-9]{1,8}$/D',$value))) return null;
    $value=(int)$value;
    return $value>=$min && $value<=$max?$value:null;
}

/** Only bounded numeric/boolean facts survive. Raw API bodies, errors and identifiers are discarded. */
function wphm_sc_normalize_reader($raw, string $source, string $name, ?string &$failure=null): ?array {
    $failure='schema';
    if(!is_array($raw) || ($raw['schema'] ?? null)!==1 || !is_string($raw['site'] ?? null) || !is_int($raw['checked_at'] ?? null)) return null;
    if(wphm_sc_site_key($raw['site'])==='' || wphm_sc_site_key($raw['site'])!==wphm_sc_site_key(home_url('/'))) { $failure='site'; return null; }
    if($raw['checked_at']<time()-300) { $failure='stale'; return null; }
    if($raw['checked_at']>time()+60) { $failure='future'; return null; }
    $out=['status'=>'ok','name'=>$name,'checked_at'=>$raw['checked_at'],'login_limits'=>[],'capabilities'=>[],'traffic'=>null,'partial'=>false,'reason'=>''];
    if(isset($raw['login_limits'])) {
        if(!is_array($raw['login_limits'])) return null;
        if(count($raw['login_limits'])>20) { $failure='size'; return null; }
        foreach($raw['login_limits'] as $limit) {
            if(!is_array($limit) || !is_bool($limit['enabled'] ?? null) || !is_bool($limit['applied'] ?? null) || !is_array($limit['routes'] ?? null) || count($limit['routes'])>4 || !$limit['routes']) return null;
            foreach($limit['routes'] as $route) if(!in_array($route,['wp_login','xmlrpc','rest','custom'],true)) return null;
            if(!wphm_sc_valid_count($limit['tries'] ?? null,1000000) || $limit['tries']<1 || !wphm_sc_valid_count($limit['period'] ?? null,86400) || $limit['period']<1 || !wphm_sc_valid_count($limit['seconds'] ?? null,86400)) return null;
            $out['login_limits'][]=['name'=>$name,'source'=>$source,'enabled'=>$limit['enabled'],'applied'=>$limit['applied'],'tries'=>$limit['tries'],'period'=>$limit['period'],'seconds'=>$limit['seconds'],'routes'=>array_values(array_unique($limit['routes']))];
        }
    }
    if(isset($raw['capabilities'])) {
        if(!is_array($raw['capabilities']) || count($raw['capabilities'])>8) return null;
        foreach(['uploads_execution','vendor_patches','public_boundary','waf','sql_rules','xss_rules','rce_rules','upload_rules'] as $key) if(array_key_exists($key,$raw['capabilities'])) {
            if(!in_array($raw['capabilities'][$key],[true,false,null],true)) return null;
            $out['capabilities'][$key]=$raw['capabilities'][$key];
        }
    }
    if(isset($raw['traffic'])) {
        if(!is_array($raw['traffic'])) return null;
        $traffic=[];
        foreach(['requests'=>PHP_INT_MAX,'peak'=>60000000,'errors'=>PHP_INT_MAX,'window_seconds'=>86400] as $key=>$max) {
            if(!wphm_sc_valid_count($raw['traffic'][$key] ?? null,$max)) return null;
            $traffic[$key]=$raw['traffic'][$key];
        }
        if($traffic['window_seconds']<60 || $traffic['errors']>$traffic['requests']) return null;
        if(isset($raw['traffic']['from']) || isset($raw['traffic']['to'])) {
            if(!is_int($raw['traffic']['from'] ?? null) || !is_int($raw['traffic']['to'] ?? null) || $raw['traffic']['from']<time()-DAY_IN_SECONDS || $raw['traffic']['to']>time()+60 || $raw['traffic']['to']-$raw['traffic']['from']!==$traffic['window_seconds']) return null;
            $traffic['from']=$raw['traffic']['from']; $traffic['to']=$raw['traffic']['to'];
        }
        $out['traffic']=$traffic;
    }
    if(!$out['login_limits'] && !$out['capabilities'] && $out['traffic']===null) { $out['status']='unsupported'; $out['reason']='no_data'; }
    $failure=null;
    return $out;
}

function wphm_sc_reader_facts(string $source, bool $refresh): array {
    $options=wphm_sc_options(); $id=$options[$source.'_provider'];
    if($source==='server' && $id==='local') return ['status'=>'partial','name'=>'PHPのローカル読取り','checked_at'=>time(),'login_limits'=>[],'capabilities'=>[],'traffic'=>null,'partial'=>true,'local_php'=>['version'=>PHP_VERSION,'file_uploads'=>filter_var(ini_get('file_uploads'),FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE),'max_execution_seconds'=>max(0,(int)ini_get('max_execution_time'))]];
    $readers=wphm_sc_readers();
    $empty=['status'=>'unsupported','name'=>'登録済みの読取り連携','checked_at'=>0,'login_limits'=>[],'capabilities'=>[],'traffic'=>null,'reason'=>'unregistered'];
    if(!isset($readers[$id]) || $readers[$id]['source']!==$source) return $empty;
    $reader=$readers[$id]; $empty['name']=$reader['name'];
    if(($reader['needs_id'] && $options[$source.'_connection']==='') || ($reader['needs_token'] && $options[$source.'_secret']==='')) { $empty['status']='setup'; $empty['reason']=$reader['needs_id'] && $options[$source.'_connection']===''?'missing_id':'missing_key'; return $empty; }
    $cache_key='wphm_sc_reader_'.$source;
    $signature=hash('sha256',wp_json_encode([$id,$options[$source.'_connection'],$options[$source.'_secret'],wphm_sc_site_key(home_url('/'))]));
    if(!$refresh) {
        $saved=get_option($cache_key,[]);
        if(is_array($saved) && ($saved['signature'] ?? '')===$signature && is_array($saved['facts'] ?? null)) {
            $cache_failure=null; $valid=wphm_sc_normalize_reader($saved['facts']+['schema'=>1,'site'=>home_url('/')],$source,$reader['name'],$cache_failure);
            if($valid!==null) return $valid;
        }
        $empty['status']='pending'; $empty['reason']=$cache_failure ?? 'recheck'; return $empty;
    }
    delete_option($cache_key);
    $budget=isset($GLOBALS['wphm_sc_deadline'])?min(3.0,$GLOBALS['wphm_sc_deadline']-microtime(true)):3.0;
    if($budget<0.25) { $empty['status']='pending'; $empty['reason']='budget'; return $empty; }
    $failure='schema';
    try {
        $token=$options[$source.'_secret']!=='' ? wphm_sc_secret($options[$source.'_secret'],true) : '';
        if($reader['needs_token'] && $token==='') { $empty['status']='error'; $empty['reason']='credential'; return $empty; }
        $started=microtime(true);
        $raw=call_user_func($reader['read'],['site'=>home_url('/'),'connection_id'=>$options[$source.'_connection'],'token'=>$token,'refresh'=>true,'timeout'=>$budget,'max_bytes'=>65536,'max_records'=>20]);
        if(microtime(true)-$started>$budget+0.1) { $empty['status']='error'; $empty['reason']='timeout'; return $empty; }
        $facts=wphm_sc_normalize_reader($raw,$source,$reader['name'],$failure);
    } catch(Throwable $error) { $facts=null; $failure=$error instanceof WPHM_Security_Read_Exception?$error->reason:'exception'; }
    if($facts===null) { $empty['status']='error'; $empty['reason']=wphm_sc_read_code($failure); return $empty; }
    update_option($cache_key,['signature'=>$signature,'facts'=>$facts],false);
    return $facts;
}

function wphm_sc_acquisition_facts(array $facts, bool $refresh): array {
    $selected=wphm_sc_source_selection(); $out=['selected'=>$selected,'sources'=>[]];
    foreach(wphm_sc_source_names() as $source=>$name) {
        $row=['status'=>'skip','name'=>$name,'checked_at'=>0,'login_limits'=>[],'capabilities'=>[],'traffic'=>null];
        if(!$selected[$source]) { $out['sources'][$source]=$row; continue; }
        if($source==='wordpress') {
            $row['status']='ok'; $row['checked_at']=time();
            if(!empty($facts['identity']['limited'])) $row['status']='partial';
            $row['login_limits']=$facts['identity']['limiters'] ?? [];
            $row['discovery']=wphm_sc_discover_auth();
        } elseif($source==='edge' && wphm_sc_options()['edge_provider']==='cloudflare') {
            $cf=$facts['cloudflare']; $row['name']='Cloudflare';
            $row['status']=empty($cf['configured'])?'setup':(!empty($cf['known'])?'partial':(($cf['reason'] ?? '')==='recheck'?'pending':'error'));
            $row['reason']=$cf['reason'] ?? ''; $row['failures']=$cf['failures'] ?? [];
            $row['checked_at']=$cf['checked_at']; $row['login_limits']=$cf['login_limits'] ?? [];
            $row['limit_status']=$cf['limit_status'] ?? 'unknown';
            if($cf['waf_rules']!==null) $row['capabilities']['waf']=$cf['waf_rules']>0;
            if($cf['requests']!==null) $row['traffic']=['requests'=>$cf['requests'],'window_seconds'=>86400];
        } else $row=wphm_sc_reader_facts($source,$refresh);
        $out['sources'][$source]=$row;
    }
    return $out;
}

/** All active authentication callbacks are inspected as metadata, never invoked as a probe. */
function wphm_sc_discover_auth(): array {
    global $wp_filter;
    $out=['callbacks'=>[],'limited'=>false,'unknown'=>0,'plugins'=>0,'mu_plugins'=>0,'themes'=>0,'custom'=>0]; $seen=[]; $count=0;
    $roots=['mu_plugins'=>defined('WPMU_PLUGIN_DIR')?WPMU_PLUGIN_DIR:'','plugins'=>WP_PLUGIN_DIR,'themes'=>get_theme_root(),'core'=>ABSPATH];
    foreach(['authenticate','wp_authenticate_user','login_init','login_form','wp_login_failed','wp_login'] as $hook) {
        $groups=isset($wp_filter[$hook]->callbacks) ? $wp_filter[$hook]->callbacks : [];
        foreach($groups as $callbacks) foreach($callbacks as $entry) {
            if(++$count>250) { $out['limited']=true; break 3; }
            $callback=$entry['function'] ?? null;
            try {
                if(is_array($callback) && count($callback)===2) { if(is_string($callback[0]) && !class_exists($callback[0],false)) continue; $reflection=new ReflectionMethod($callback[0],$callback[1]); }
                elseif(is_string($callback) && strpos($callback,'::')!==false) { $method=explode('::',$callback,2); if(!class_exists($method[0],false)) continue; $reflection=new ReflectionMethod($method[0],$method[1]); }
                elseif(is_string($callback) || $callback instanceof Closure) $reflection=new ReflectionFunction($callback);
                elseif(is_object($callback) && method_exists($callback,'__invoke')) $reflection=new ReflectionMethod($callback,'__invoke');
                else continue;
                $file=$reflection->getFileName(); if(!is_string($file)) continue;
                $function=$reflection instanceof ReflectionMethod ? $reflection->getDeclaringClass()->getName().'::'.$reflection->getName() : $reflection->getName();
                if(strpos($function,'wphm_sc_')===0) continue;
                $location='custom'; $owner='独自処理'; $normalized=wp_normalize_path($file);
                foreach($roots as $kind=>$root) if($root!=='' && strpos($normalized,trailingslashit(wp_normalize_path($root)))===0) {
                    $location=$kind; $relative=substr($normalized,strlen(trailingslashit(wp_normalize_path($root))));
                    $owner=$kind==='core'?'WordPress本体':sanitize_text_field(substr(explode('/',$relative)[0],0,100)); break;
                }
                $key=$location.':'.$owner.':'.$hook.':'.$function; if(isset($seen[$key])) continue; $seen[$key]=true;
                if($location==='core') continue;
                $supported=strpos($function,'Limit_Login_Attempts::')===0 || stripos($function,'wordfence')!==false || strpos($function,'Two_Factor_')===0;
                $out[$location]++;
                if(!$supported && in_array($hook,['authenticate','wp_authenticate_user','login_init','login_form'],true)) $out['unknown']++;
                if(count($out['callbacks'])<60) $out['callbacks'][]=['location'=>$location,'owner'=>$owner,'hook'=>$hook,'supported'=>$supported]; else $out['limited']=true;
            } catch(Throwable $error) { $out['limited']=true; }
        }
    }
    return $out;
}

function wphm_sc_source_reason(array $source): string {
    $messages=['ok'=>'選択した場所の情報を取得しました。','partial'=>'一部の情報を取得しました。各確認項目に未取得の理由を表示します。','skip'=>'今回は調べていません。対策がないという意味ではありません。','setup'=>'読取り連携の接続情報が不足しています。防御設定の無効とは区別します。','pending'=>'この接続情報でまだ取得できていません。処理上限の場合も含みます。再チェックしてください。','unsupported'=>'この場所の必要な読取り情報に対応する連携が確認できません。','error'=>'権限・応答形式・対象サイト・通信状態を確認できませんでした。'];
    if(($source['status'] ?? '')==='na') return 'この項目はアプリ側の操作受付が確認対象です。一般のWAF設定で判定しません。';
    if(!empty($source['reason'])) return wphm_sc_read_reason($source['reason']);
    if(!empty($source['failures']) && is_array($source['failures'])) {
        $reasons=[]; foreach(['traffic'=>'通信統計','events'=>'遮断記録','waf'=>'管理WAF','login_limits'=>'ログイン制限'] as $key=>$label) if(isset($source['failures'][$key]) && is_string($source['failures'][$key])) $reasons[]=$label.'：'.wphm_sc_read_reason($source['failures'][$key]);
        if($reasons) return '一部取得。'.implode(' ',$reasons);
    }
    return $messages[$source['status']] ?? '情報を確認できませんでした。';
}
