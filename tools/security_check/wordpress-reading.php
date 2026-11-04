<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_reading_groups(): array {
    return ['settings'=>'WordPressの設定（DB・実行中の値）','config'=>'設定ファイル・.htaccess','themes'=>'テーマ（functions.php等）','plugins'=>'プラグイン・MUプラグイン','snippets'=>'コード追加機能（保存スニペット）'];
}

function wphm_sc_reading_scopes(): array {
    $out=array_fill_keys(array_keys(wphm_sc_reading_groups()),true);
    $saved=get_user_option('wphm_sc_wordpress_reading',get_current_user_id());
    if (is_array($saved)) foreach ($out as $key=>$value) if (isset($saved[$key]) && is_bool($saved[$key])) $out[$key]=$saved[$key];
    return $out;
}

function wphm_sc_reading_key(): string { return 'wphm_sc_reading_'.get_current_blog_id().'_'.get_current_user_id(); }

function wphm_sc_installation_path(): string {
    $path=rtrim(wp_normalize_path(ABSPATH),'/');
    if ($path==='') return '/';
    return preg_match('/^[A-Za-z]:$/D',$path)?$path.'/':$path;
}

/** The form never supplies a filesystem path, SQL identifier, callback or source code. */
function wphm_sc_save_reading_scopes(array $input, bool $save=true): void {
    if (!isset($input['wphm_sc_reading_form'])) return;
    if (!wphm_sc_allowed() || $input['wphm_sc_reading_form']!=='1') throw new WPHM_Security_Check_Exception('WordPressの読取り設定の形式が不正です。');
    $next=[];
    foreach (wphm_sc_reading_groups() as $key=>$label) {
        $field='wp_read_'.$key;
        if (isset($input[$field]) && $input[$field]!=='1') throw new WPHM_Security_Check_Exception('読取り対象の形式が不正です。');
        $next[$key]=isset($input[$field]);
    }
    if ($save && $next!==wphm_sc_reading_scopes()) {
        update_user_option(get_current_user_id(),'wphm_sc_wordpress_reading',$next,false);
        delete_transient(wphm_sc_reading_key());
    }
}

function wphm_sc_path_normal(string $path): string {
    $path=rtrim(wp_normalize_path($path),'/');
    return DIRECTORY_SEPARATOR==='\\'?strtolower($path):$path;
}

function wphm_sc_path_within(string $path, string $root): bool {
    $path=wphm_sc_path_normal($path); $root=wphm_sc_path_normal($root);
    return $root!=='' && ($path===$root || strpos($path,$root.'/')===0);
}

/** Only WordPress-provided locations, not the server account's other sites or its ancestors. */
function wphm_sc_reading_context(): array {
    $scopes=wphm_sc_reading_scopes(); $roots=[]; $locations=[]; $limited=[];
    $add=function (string $group,string $path,string $label,bool $directory) use (&$roots,&$locations,&$limited,$scopes): void {
        if (!$scopes[$group] || $path==='' || strpos($path,"\0")!==false) return;
        if (count($roots)>=250) { $limited[$group]=true; return; }
        $id=hash('sha256',$group.':'.wp_normalize_path($path));
        $resolved=@realpath($path);
        $blocked=$directory && $resolved!==false && (bool)preg_match('~^(?:[A-Za-z]:)?/+\z~',wp_normalize_path($resolved));
        $roots[$id]=['group'=>$group,'path'=>$directory && $resolved!==false?$resolved:wp_normalize_path($path),'directory'=>$directory,'exists'=>$resolved!==false,'link'=>@is_link($path),'blocked'=>$blocked,'label'=>$label];
        if (count($locations)<40) $locations[$id]=['group'=>$group,'path'=>wp_normalize_path($path),'label'=>$label];
    };
    $base=wphm_sc_installation_path();
    if ($scopes['config']) {
        $homes=[$base=>'WordPress設置先の.htaccess'];
        if (function_exists('get_home_path')) { $home=rtrim(get_home_path(),'/\\'); if (!isset($homes[$home])) $homes[$home]='公開先の.htaccess'; }
        $uploads=wp_upload_dir(null,false);
        if (empty($uploads['error']) && is_string($uploads['basedir'] ?? null) && !isset($homes[$uploads['basedir']])) $homes[$uploads['basedir']]='アップロード先の.htaccess';
        foreach ($homes as $home=>$label) $add('config',$home.'/.htaccess',$label,false);
        $config=$base.'/wp-config.php';
        // WordPress supports wp-config.php one directory above the installation.
        if (!@is_file($config) && @is_file(dirname($base).'/wp-config.php') && !@is_file(dirname($base).'/wp-settings.php')) $config=dirname($base).'/wp-config.php';
        $add('config',$config,'WordPress設定ファイル',false);
    }
    if ($scopes['themes']) {
        foreach (array_unique([get_stylesheet_directory(),get_template_directory()]) as $path) $add('themes',$path,'使用中のテーマ',true);
    }
    if ($scopes['plugins']) {
        $active=array_unique(array_merge((array)get_option('active_plugins',[]),array_keys((array)get_site_option('active_sitewide_plugins',[]))));
        if (count($active)>200) $limited['plugins']=true;
        foreach (array_slice($active,0,200) as $file) {
            if (!is_string($file) || validate_file($file)!==0 || !preg_match('~^[A-Za-z0-9_./-]+\.php$~D',$file)) continue;
            $path=WP_PLUGIN_DIR.'/'.$file;
            $own=@realpath(WPHM_PLUGIN_DIR); $actual=@realpath($path);
            if ($actual!==false && $own!==false && wphm_sc_path_within($actual,$own)) continue;
            $directory=dirname($file)!=='.';
            $add('plugins',$directory?dirname($path):$path,'有効なプラグイン：'.sanitize_text_field(substr(explode('/',$file)[0],0,100)),$directory);
        }
        if (defined('WPMU_PLUGIN_DIR') && @is_dir(WPMU_PLUGIN_DIR)) $add('plugins',WPMU_PLUGIN_DIR,'MUプラグイン',true);
        if (function_exists('get_dropins')) foreach (array_keys(get_dropins()) as $file) if (is_string($file) && preg_match('/^[A-Za-z0-9_.-]+\.php$/D',$file)) $add('plugins',WP_CONTENT_DIR.'/'.$file,'ドロップイン',false);
    }
    $signature=hash('sha256',wp_json_encode([WPHM_VERSION,get_current_blog_id(),get_current_user_id(),home_url('/'),$scopes,$roots,$limited]));
    return ['root'=>wp_normalize_path($base),'roots'=>$roots,'locations'=>array_values($locations),'scopes'=>$scopes,'limited_groups'=>array_keys($limited),'signature'=>$signature];
}

function wphm_sc_reading_blank(array $context, string $status): array {
    $rows=[];
    foreach ($context['scopes'] as $key=>$selected) $rows[$key]=['status'=>$selected?$status:'skip','files'=>0,'bytes'=>0,'issues'=>[]];
    return ['schema'=>1,'owner'=>get_current_user_id(),'site'=>get_current_blog_id(),'signature'=>$context['signature'],'started'=>time(),'checked_at'=>0,'root'=>$context['root'],'locations'=>$context['locations'],'rows'=>$rows,'features'=>[],'problems'=>[],'pending'=>false,'remaining'=>0,'entries'=>0,'bytes'=>0,'files'=>0];
}

function wphm_sc_reading_issue(array &$state, string $group, string $code,string $file=''): void {
    if (!in_array($code,$state['rows'][$group]['issues'],true)) $state['rows'][$group]['issues'][]=$code;
    if ($file!=='' && count($state['problems'])<40) $state['problems'][]=['group'=>$group,'code'=>$code,'file'=>sanitize_text_field(substr($file,0,240))];
}

function wphm_sc_valid_reading_state($state,array $context): bool {
    if (!is_array($state) || ($state['schema'] ?? null)!==1 || ($state['owner'] ?? null)!==get_current_user_id() || ($state['site'] ?? null)!==get_current_blog_id() || ($state['signature'] ?? '')!==$context['signature'] || !is_int($state['started'] ?? null) || $state['started']<time()-600 || $state['started']>time()) return false;
    foreach (['entries'=>10001,'files'=>1001,'bytes'=>17039360] as $key=>$max) if (!is_int($state[$key] ?? null) || $state[$key]<0 || $state[$key]>$max) return false;
    if (!is_array($state['queue'] ?? null) || count($state['queue'])>2000 || !is_array($state['seen'] ?? null) || count($state['seen'])>10001 || !is_array($state['features'] ?? null) || count($state['features'])>60 || !is_array($state['problems'] ?? null) || count($state['problems'])>40 || !is_array($state['rows'] ?? null) || array_keys($state['rows'])!==array_keys(wphm_sc_reading_groups())) return false;
    if (!is_bool($state['pending'] ?? null) || !is_int($state['remaining'] ?? null) || $state['remaining']<0 || $state['remaining']>2000 || !is_int($state['checked_at'] ?? null)) return false;
    foreach ($state['rows'] as $row) {
        if (!is_array($row) || !in_array($row['status'] ?? '',['skip','pending','running','done','partial'],true) || !is_int($row['files'] ?? null) || $row['files']<0 || $row['files']>1000 || !is_int($row['bytes'] ?? null) || $row['bytes']<0 || $row['bytes']>17039360 || !is_array($row['issues'] ?? null) || count($row['issues'])>20) return false;
        foreach ($row['issues'] as $issue) if (!is_string($issue) || !preg_match('/^[a-z]{1,20}$/D',$issue)) return false;
    }
    foreach ($state['problems'] as $problem) if (!is_array($problem) || !isset($context['scopes'][$problem['group'] ?? '']) || !is_string($problem['file'] ?? null) || strlen($problem['file'])>1000 || !is_string($problem['code'] ?? null) || !preg_match('/^[a-z]{1,20}$/D',$problem['code'])) return false;
    foreach ($state['features'] as $feature=>$rows) {
        if (!is_string($feature) || !preg_match('/^[a-z_]{1,40}$/D',$feature) || !is_array($rows) || count($rows)>8) return false;
        foreach ($rows as $row) if (!is_array($row) || !in_array($row['source'] ?? '',['wordpress','server'],true) || !is_string($row['file'] ?? null) || strlen($row['file'])>1000 || !is_int($row['line'] ?? null) || $row['line']<1 || !is_bool($row['conditional'] ?? null) || !isset($context['scopes'][$row['group'] ?? ''])) return false;
    }
    foreach ($state['queue'] as $entry) if (!is_array($entry) || !is_string($entry['id'] ?? null) || !isset($context['roots'][$entry['id']]) || !is_string($entry['relative'] ?? null) || strlen($entry['relative'])>1024 || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\\\\]~',$entry['relative']) || strpos($entry['relative'],'/')===0 || !is_int($entry['depth'] ?? null) || $entry['depth']<0 || $entry['depth']>12) return false;
    return strlen(serialize($state))<=2500000;
}

function wphm_sc_reading_record(array &$state,array $parsed,string $group,string $label,string $source): void {
    foreach ($parsed['features'] as $feature=>$row) {
        if (count($state['features'])>=60 && !isset($state['features'][$feature])) { wphm_sc_reading_issue($state,$group,'limit'); break; }
        if (count($state['features'][$feature] ?? [])>=8) continue;
        $state['features'][$feature][]=['source'=>$source,'group'=>$group,'file'=>sanitize_text_field(substr($label,0,240)),'line'=>max(1,(int)$row['line']),'conditional'=>!empty($row['conditional'])];
    }
    if (!empty($parsed['limited'])) wphm_sc_reading_issue($state,$group,'parse');
}

/** Bound both bytes and token memory. Verify the opened object without following a link outside its root. */
function wphm_sc_read_local(string $path,string $root,bool $fixed=false): array {
    clearstatcache(true,$path);
    $real=@realpath($path); $bound=@realpath($root);
    if ($real===false || $bound===false) return ['error'=>'missing'];
    if (is_link($path) || (!$fixed && !wphm_sc_path_within($real,$bound)) || ($fixed && wphm_sc_path_normal($real)!==wphm_sc_path_normal($bound))) return ['error'=>'boundary'];
    $before=@lstat($path);
    if (!is_array($before) || (($before['mode'] & 0170000)!==0100000)) return ['error'=>'type'];
    if ($before['size']>262144) return ['error'=>'size'];
    $stream=@fopen($path,'rb');
    if ($stream===false) return ['error'=>'permission'];
    try {
        $opened=fstat($stream); clearstatcache(true,$path); $now=@lstat($path); $after=@realpath($path);
        if (!is_array($opened) || !is_array($now) || $after===false || is_link($path) || $opened['ino']!==$now['ino'] || $opened['dev']!==$now['dev'] || $opened['size']>262144 || wphm_sc_path_normal($after)!==wphm_sc_path_normal($real)) return ['error'=>'changed'];
        $data=stream_get_contents($stream,262145);
        if (!is_string($data) || strlen($data)>262144) return ['error'=>'size'];
        return ['code'=>$data];
    } finally { fclose($stream); }
}

/** Known Code Snippets tables only. The current site's table is not inferred from user input. */
function wphm_sc_read_snippets(array &$state): void {
    global $wpdb;
    $active=array_merge((array)get_option('active_plugins',[]),array_keys((array)get_site_option('active_sitewide_plugins',[])));
    if (!in_array('code-snippets/code-snippets.php',$active,true)) { wphm_sc_reading_issue($state,'snippets','format'); return; }
    $tables=[$wpdb->prefix.'snippets'];
    if (is_multisite()) {
        if (is_super_admin()) $tables[]=$wpdb->base_prefix.'ms_snippets';
        wphm_sc_reading_issue($state,'snippets','network');
    }
    $old=$wpdb->suppress_errors(true);
    try {
        foreach (array_unique($tables) as $table) {
            if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) { wphm_sc_reading_issue($state,'snippets','format'); continue; }
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)))!==$table) { wphm_sc_reading_issue($state,'snippets','missing'); continue; }
            $rows=$wpdb->get_results("SELECT id,LENGTH(code) AS bytes FROM `{$table}` WHERE active=1 AND scope IN ('global','front-end','admin') ORDER BY id LIMIT 65",ARRAY_A);
            if ($wpdb->last_error || !is_array($rows)) { wphm_sc_reading_issue($state,'snippets','format'); continue; }
            if (count($rows)>64) wphm_sc_reading_issue($state,'snippets','limit');
            foreach (array_slice($rows,0,64) as $row) {
                if (!ctype_digit((string)$row['id']) || strlen((string)$row['id'])>18 || (int)$row['id']<1 || (int)$row['bytes']>262144 || $state['bytes']+(int)$row['bytes']>2097152) { wphm_sc_reading_issue($state,'snippets','size'); continue; }
                $code=$wpdb->get_var($wpdb->prepare("SELECT code FROM `{$table}` WHERE id=%d AND active=1 AND scope IN ('global','front-end','admin') AND LENGTH(code)<=262144 LIMIT 1",(int)$row['id']));
                if (!is_string($code)) { wphm_sc_reading_issue($state,'snippets','permission'); continue; }
                wphm_sc_reading_record($state,wphm_sc_php_candidates($code,true),'snippets','保存スニペット #'.(int)$row['id'],'wordpress');
                $bytes=strlen($code); unset($code);
                $state['rows']['snippets']['files']++; $state['rows']['snippets']['bytes']+=$bytes; $state['files']++; $state['bytes']+=$bytes;
            }
        }
    } finally { $wpdb->suppress_errors($old); }
}

/** A bounded resumable local scan. Caches contain metadata/evidence only, never inspected source. */
function wphm_sc_wordpress_reading(bool $refresh=false,bool $continuing=false,bool $enabled=true): array {
    if (!$enabled || !wphm_sc_allowed()) return wphm_sc_reading_blank(['signature'=>'','root'=>'','locations'=>[],'scopes'=>wphm_sc_reading_scopes()],'skip');
    $context=wphm_sc_reading_context(); $out=wphm_sc_reading_blank($context,$enabled?'pending':'skip');
    $key=wphm_sc_reading_key(); $saved=get_transient($key);
    $valid=wphm_sc_valid_reading_state($saved,$context);
    if (!$refresh) { if ($valid) { unset($saved['queue'],$saved['seen']); return $saved; } return $out; }
    if ($continuing && !$valid) { foreach ($out['rows'] as &$row) if ($row['status']!=='skip') { $row['status']='partial'; $row['issues'][]='expired'; } unset($row); return $out; }
    if (!$continuing) {
        $state=$out; $state['queue']=[]; $state['seen']=[];
        foreach ($context['limited_groups'] as $group) wphm_sc_reading_issue($state,$group,'limit');
        foreach ($context['roots'] as $id=>$record) {
            if ($record['blocked'] || ($record['link'] && !$record['directory'])) wphm_sc_reading_issue($state,$record['group'],'boundary',$record['label']);
            elseif ($record['exists']) $state['queue'][]=['id'=>$id,'relative'=>'','depth'=>0];
            else wphm_sc_reading_issue($state,$record['group'],'missing',$record['label']);
        }
        if ($context['scopes']['snippets']) wphm_sc_read_snippets($state);
        $state['rows']['settings']['status']=$context['scopes']['settings']?'done':'skip';
    } else $state=$saved;
    $deadline=min(microtime(true)+2.0,($GLOBALS['wphm_sc_deadline'] ?? microtime(true)+3)-0.1); $steps=0; $batch_bytes=0;
    while ($state['queue'] && $steps++<100 && microtime(true)<$deadline && $batch_bytes<2097152) {
        $entry=array_shift($state['queue']);
        if (!is_array($entry) || !is_string($entry['id'] ?? null) || !isset($context['roots'][$entry['id']]) || !is_string($entry['relative'] ?? null) || strlen($entry['relative'])>1024 || preg_match('~(?:^|/)\.\.(?:/|$)|[\x00-\x1f\\\\]~',$entry['relative']) || strpos($entry['relative'],'/')===0 || !is_int($entry['depth'] ?? null) || $entry['depth']<0 || $entry['depth']>12) continue;
        $spec=$context['roots'][$entry['id']]; $group=$spec['group'];
        $label=$spec['label'].($entry['relative']!==''?' / '.$entry['relative']:'');
        if (!$spec['directory'] && $entry['relative']!=='') { wphm_sc_reading_issue($state,$group,'boundary'); continue; }
        $path=$spec['path'].($entry['relative']!==''?'/'.$entry['relative']:'');
        $real=@realpath($path);
        if ($real===false || is_link($path) || ($spec['directory'] && !wphm_sc_path_within($real,$spec['path']))) { wphm_sc_reading_issue($state,$group,'boundary',$label); continue; }
        $seen=hash('sha256',$group.':'.wphm_sc_path_normal($real));
        if (isset($state['seen'][$seen])) continue;
        $state['seen'][$seen]=true;
        if ($state['entries']++>=10000 || $state['files']>=1000 || $state['bytes']>=16777216) { foreach ($state['queue'] as $left) if (isset($context['roots'][$left['id']])) wphm_sc_reading_issue($state,$context['roots'][$left['id']]['group'],'limit'); wphm_sc_reading_issue($state,$group,'limit'); $state['queue']=[]; break; }
        if (is_dir($real)) {
            if (!$spec['directory'] || $entry['depth']>=12) { wphm_sc_reading_issue($state,$group,'limit'); continue; }
            $handle=@opendir($real);
            if ($handle===false) { wphm_sc_reading_issue($state,$group,'permission',$label); continue; }
            try {
                $children=0;
                while (($name=readdir($handle))!==false) {
                    if ($name==='.' || $name==='..') continue;
                    if (++$children>512 || count($state['queue'])>=2000) { wphm_sc_reading_issue($state,$group,'limit'); break; }
                    $child=$real.'/'.$name;
                    if (is_link($child)) { wphm_sc_reading_issue($state,$group,'boundary'); continue; }
                    if (!is_dir($child) && strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='php' && $name!=='.htaccess') continue;
                    // Distribution fixtures and hidden VCS folders are not application code.
                    if (is_dir($child) && ($name[0]==='.' || in_array($name,['node_modules','tests','vendor-bin'],true))) continue;
                    $relative=$entry['relative']!==''?$entry['relative'].'/'.$name:$name;
                    $state['queue'][]=['id'=>$entry['id'],'relative'=>$relative,'depth'=>$entry['depth']+1];
                }
            } finally { closedir($handle); }
            continue;
        }
        $read=wphm_sc_read_local($path,$spec['path'],!$spec['directory']);
        if (isset($read['error'])) { wphm_sc_reading_issue($state,$group,$read['error'],$label); continue; }
        $code=$read['code']; unset($read); $bytes=strlen($code); $ht=basename($path)==='.htaccess';
        wphm_sc_reading_record($state,$ht?wphm_sc_htaccess_candidates($code):wphm_sc_php_candidates($code),$group,$label,$ht?'server':'wordpress');
        unset($code); $batch_bytes+=$bytes; $state['bytes']+=$bytes; $state['files']++; $state['rows'][$group]['files']++; $state['rows'][$group]['bytes']+=$bytes;
    }
    $pending=[];
    foreach ($state['queue'] as $entry) if (isset($context['roots'][$entry['id']])) $pending[$context['roots'][$entry['id']]['group']]=true;
    foreach ($state['rows'] as $group=>&$row) {
        if (!$context['scopes'][$group]) $row['status']='skip';
        elseif (isset($pending[$group])) $row['status']='running';
        else $row['status']=$row['issues']?'partial':'done';
    }
    unset($row);
    $state['checked_at']=time(); $state['pending']=(bool)$state['queue']; $state['remaining']=count($state['queue']);
    set_transient($key,$state,max(1,600-(time()-$state['started'])));
    unset($state['queue'],$state['seen']); return $state;
}

function wphm_sc_reading_reason(string $code): string {
    $messages=['missing'=>'対象ファイル・保存先が見つかりません。','permission'=>'PHPの読取り権限がありません。','boundary'=>'リンクまたは対象外の配置を除外しました。','size'=>'ファイル・保存コードのサイズ上限に達しました。','limit'=>'件数・深さの上限に達し、残りは未確認です。','parse'=>'字句解析の上限または未対応の形式があります。','format'=>'保存形式に対応する読取り処理を確認できません。','network'=>'ネットワーク共有スニペットの適用条件は確認範囲外です。','expired'=>'分割読取りの保存期限が切れました。再チェックしてください。','changed'=>'読取り中にファイルの状態が変わったため除外しました。','type'=>'通常のファイル以外を除外しました。'];
    return $messages[$code] ?? 'この範囲の読取りを完了できませんでした。';
}
