<?php
if (!defined('ABSPATH')) exit;

/** Session metadata is read only for the current administrator, never authentication cookies. */
function wphm_sc_session_facts(): array {
    global $wpdb;
    $out=['status'=>'pending','active'=>0,'expired'=>0,'latest_expiry'=>0];
    if(get_current_user_id()<1) return $out;
    $previous=$wpdb->suppress_errors(true);
    try { $sizes=$wpdb->get_col($wpdb->prepare("SELECT LENGTH(meta_value) FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s LIMIT 2",get_current_user_id(),'session_tokens')); }
    finally { $wpdb->suppress_errors($previous); }
    if($wpdb->last_error || !is_array($sizes) || count($sizes)>1 || ($sizes && (!ctype_digit((string)$sizes[0]) || (int)$sizes[0]>65536))) return $out;
    if(!$sizes) { $out['status']='empty'; return $out; }
    $sessions=get_user_meta(get_current_user_id(),'session_tokens',true);
    if(!is_array($sessions) || count($sessions)>100 || strlen(serialize($sessions))>65536) return $out;
    foreach($sessions as $session) {
        if(!is_array($session) || !is_int($session['expiration'] ?? null) || $session['expiration']<0 || $session['expiration']>time()+10*YEAR_IN_SECONDS) return ['status'=>'pending','active'=>0,'expired'=>0,'latest_expiry'=>0];
        if($session['expiration']<=time()) $out['expired']++; else { $out['active']++; $out['latest_expiry']=max($out['latest_expiry'],$session['expiration']); }
    }
    $out['status']='ok';
    return $out;
}

/** Read the standard implementation's policy without issuing or collecting a login cookie. */
function wphm_sc_cookie_policy(): array {
    $out=['known'=>false,'secure_auth'=>false,'secure_logged_in'=>false,'native_tokens'=>false];
    if(!function_exists('wp_set_auth_cookie')) return $out;
    try { $file=(new ReflectionFunction('wp_set_auth_cookie'))->getFileName(); }
    catch(Throwable $failure) { return $out; }
    if(!is_string($file) || realpath($file)!==realpath(ABSPATH.WPINC.'/pluggable.php')) return $out;
    foreach(['secure_auth_cookie','secure_logged_in_cookie','send_auth_cookies','session_token_manager'] as $hook) if(has_filter($hook)!==false) return $out;
    $out['known']=true; $out['secure_auth']=is_ssl();
    $out['secure_logged_in']=$out['secure_auth'] && wp_parse_url(home_url('/'),PHP_URL_SCHEME)==='https';
    $out['native_tokens']=true; return $out;
}

/** Inspect metadata of fixed locations only. No file contents, traversal, directory scan or symlink following. */
function wphm_sc_public_file_facts(): array {
    $out=['known'=>false,'checked'=>0,'candidates'=>0,'links'=>0];
    $base=realpath(ABSPATH); if(!is_string($base) || !is_dir($base)) return $out;
    $paths=['.env','.git','wp-config.php.bak','wp-config.php.old','wp-config.php~','debug.log','backup.zip','database.sql'];
    $content=realpath(WP_CONTENT_DIR);
    if(is_string($content) && $content!==$base && strpos(str_replace('\\','/',$content).'/',str_replace('\\','/',$base).'/')===0) $paths[]=substr($content,strlen($base)+1).DIRECTORY_SEPARATOR.'debug.log';
    foreach($paths as $relative) {
        $path=$base.DIRECTORY_SEPARATOR.$relative; $out['checked']++;
        if(is_link($path)) { $out['links']++; continue; }
        // A linked intermediate directory must not turn a metadata check into a check outside the root.
        $parent=dirname($path);
        if($parent!==$base && (is_link($parent) || realpath($parent)!==$parent)) { $out['links']++; continue; }
        if(@lstat($path)!==false) $out['candidates']++;
    }
    $out['known']=true; return $out;
}

/** Count update destinations; raw URLs, signed queries, product paths and tokens never leave this function. */
function wphm_sc_distribution_facts(array $inventory): array {
    $out=['products'=>0,'private'=>0,'insecure_sources'=>0,'offers'=>0,'insecure_offers'=>0,'limited'=>false,'offers_known'=>false];
    foreach(['plugins','themes'] as $group) {
        $items=$inventory[$group] ?? []; if(count($items)>200) $out['limited']=true;
        foreach(array_slice($items,0,200,true) as $item) {
            $out['products']++; $uri=$item['UpdateURI'] ?? '';
            if(!is_string($uri) || strlen($uri)>2048) { $out['private']++; continue; }
            if(!wphm_sc_wporg_item($item)) { $out['private']++; if(preg_match('~^http://~i',$uri)) $out['insecure_sources']++; }
        }
        $saved=wphm_sc_saved_site_transient('update_'.$group); $saved=is_object($saved)?get_object_vars($saved):$saved;
        if(!is_array($saved) || !is_int($saved['last_checked'] ?? null) || $saved['last_checked']<time()-DAY_IN_SECONDS || $saved['last_checked']>time()+60 || !is_array($saved['checked'] ?? null)) continue;
        $offers=$saved['response'] ?? []; $offers=is_object($offers)?get_object_vars($offers):$offers;
        if(!is_array($offers) || count($offers)>200) { $out['limited']=true; continue; }
        $out['offers_known']=true;
        foreach($offers as $id=>$offer) {
            $offer=is_object($offer)?get_object_vars($offer):$offer;
            if(!isset($items[$id]) || ($saved['checked'][$id] ?? null)!==$items[$id]['Version'] || !is_array($offer) || !is_string($offer['package'] ?? null) || $offer['package']==='' || strlen($offer['package'])>2048) continue;
            $out['offers']++; $p=wp_parse_url($offer['package']);
            if(!is_array($p) || ($p['scheme'] ?? '')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) $out['insecure_offers']++;
        }
    }
    foreach(['mu_plugins','dropins'] as $group) { $out['private']+=min(200,count($inventory[$group] ?? [])); if(count($inventory[$group] ?? [])>200) $out['limited']=true; }
    return $out;
}

function wphm_sc_posture_facts(array $inventory, bool $enabled, bool $inspect_files=true): array {
    $out=['enabled'=>$enabled,'roles_known'=>false,'roles_limited'=>false,'privileged_basic_roles'=>0,'public_privileged_role'=>false,'session'=>['status'=>'pending','active'=>0,'expired'=>0,'latest_expiry'=>0],'cookie_policy'=>['known'=>false,'secure_auth'=>false,'secure_logged_in'=>false,'native_tokens'=>false],'public_files'=>['known'=>false,'checked'=>0,'candidates'=>0,'links'=>0],'debug_display'=>false,'http_blocked'=>null,'distribution'=>[]];
    if(!$enabled) return $out;
    $roles=wp_roles()->roles; $out['roles_known']=is_array($roles); $out['roles_limited']=count($roles)>100;
    $danger=['manage_options','promote_users','edit_users','delete_users','install_plugins','edit_plugins','edit_themes','unfiltered_html'];
    foreach(array_slice($roles,0,100,true) as $slug=>$role) {
        $caps=is_array($role['capabilities'] ?? null)?$role['capabilities']:[]; $strong=false;
        foreach($danger as $cap) if(!empty($caps[$cap])) $strong=true;
        if($strong && in_array($slug,['subscriber','contributor','author'],true)) $out['privileged_basic_roles']++;
        if($strong && !is_multisite() && get_option('users_can_register') && $slug===get_option('default_role')) $out['public_privileged_role']=true;
    }
    $out['session']=wphm_sc_session_facts();
    $out['cookie_policy']=wphm_sc_cookie_policy();
    if ($inspect_files) $out['public_files']=wphm_sc_public_file_facts();
    $out['debug_display']=defined('WP_DEBUG') && WP_DEBUG && (!defined('WP_DEBUG_DISPLAY') || WP_DEBUG_DISPLAY);
    $out['http_blocked']=defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL;
    $out['distribution']=wphm_sc_distribution_facts($inventory);
    return $out;
}
