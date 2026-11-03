<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_identity_facts(bool $enabled=true): array {
    $out = ['checked'=>0,'limited'=>false,'html_users'=>0,'raw_upload_users'=>0,'code_users'=>0,'admins'=>0,'mfa_known'=>0,'mfa_enabled'=>0,'mfa_providers'=>[],'mfa_error'=>false,'app_password_users'=>0,'roles'=>[],'limiter'=>null,'limiters'=>[],'limiter_error'=>false,'xmlrpc_auth'=>false,'application_passwords'=>null];
    if(!$enabled) return $out;
    $roles = wp_roles()->roles;
    foreach (array_slice($roles,0,100,true) as $slug=>$role) {
        $caps = is_array($role['capabilities'] ?? null) ? $role['capabilities'] : [];
        $flags = [];
        foreach (['unfiltered_html','unfiltered_upload','install_plugins','edit_plugins','edit_themes'] as $cap) if (!empty($caps[$cap])) $flags[]=$cap;
        if ($flags) $out['roles'][]=['name'=>substr((string)$slug,0,100),'capabilities'=>$flags];
    }
    $users = get_users(['number'=>201,'orderby'=>'ID','order'=>'ASC','fields'=>'all_with_meta']);
    $out['limited']=count($users)>200; $users=array_slice($users,0,200);
    foreach ($users as $user) {
        $out['checked']++;
        if (user_can($user,'manage_options')) $out['admins']++;
        if (user_can($user,'unfiltered_html') && (user_can($user,'edit_posts') || user_can($user,'edit_pages'))) $out['html_users']++;
        if (user_can($user,'unfiltered_upload') && user_can($user,'upload_files')) $out['raw_upload_users']++;
        if (user_can($user,'edit_plugins') || user_can($user,'edit_themes') || user_can($user,'install_plugins') || user_can($user,'install_themes')) $out['code_users']++;
        if (function_exists('wp_is_application_passwords_available_for_user') && wp_is_application_passwords_available_for_user($user) && class_exists('WP_Application_Passwords') && WP_Application_Passwords::get_user_application_passwords($user->ID)) $out['app_password_users']++;
        if (user_can($user,'manage_options')) {
            $mfa=wphm_sc_mfa_for_user($user);
            if ($mfa['error']) $out['mfa_error']=true;
            if ($mfa['known']) { $out['mfa_known']++; if($mfa['enabled']) $out['mfa_enabled']++; }
            foreach ($mfa['providers'] as $name) if(!in_array($name,$out['mfa_providers'],true)) $out['mfa_providers'][]=$name;
        }
    }
    global $wp_filter;
    if (isset($wp_filter['wp_authenticate_user']->callbacks)) foreach ($wp_filter['wp_authenticate_user']->callbacks as $callbacks) foreach ($callbacks as $callback) {
        $function = $callback['function'] ?? null;
        if (!is_array($function) || !isset($function[0],$function[1]) || !is_object($function[0]) || !is_a($function[0],'Limit_Login_Attempts') || $function[1] !== 'wp_authenticate_user' || !is_callable([$function[0],'get_option'])) continue;
        try {
            $tries=wphm_sc_setting_integer($function[0]->get_option('allowed_retries'),1,100); $duration=wphm_sc_setting_integer($function[0]->get_option('lockout_duration'),1,86400);
            if ($tries!==null && $duration!==null) {
                $out['limiter']=['name'=>'Limit Login Attempts / Reloaded','tries'=>(int)$tries,'seconds'=>(int)$duration];
                $period=wphm_sc_setting_integer($function[0]->get_option('valid_duration'),1,86400);
                $out['limiters'][]=$out['limiter']+['source'=>'wordpress','enabled'=>true,'applied'=>true,'routes'=>['wp_login'],'period'=>$period ?? 0];
            }
            else $out['limiter_error']=true;
        } catch (Throwable $exception) { $out['limiter_error']=true; }
    }
    $out['xmlrpc_auth']=(bool)apply_filters('xmlrpc_enabled',true);
    $out['application_passwords']=function_exists('wp_is_application_passwords_available') ? (bool)wp_is_application_passwords_available() : null;
    $wf_error=false; $wf_limit=wphm_sc_wordfence_limiter($wf_error); $out['limiter_error']=$out['limiter_error'] || $wf_error;
    if($wf_limit!==null) {
        if($out['limiter']===null) $out['limiter']=$wf_limit;
        try { $period=wphm_sc_setting_integer(\wfConfig::get('loginSec_countFailMins'),1,1440); } catch(Throwable $error) { $period=null; }
        $out['limiters'][]=$wf_limit+['source'=>'wordpress','enabled'=>true,'applied'=>true,'routes'=>['wp_login'],'period'=>$period===null?0:$period*60];
    }
    if($wf_limit===null && !$wf_error && is_callable(['wfConfig','get']) && has_filter('authenticate','wordfence::authenticateFilter')!==false) {
        try { if(in_array(\wfConfig::get('loginSecurityEnabled'),[false,0,'0'],true)) $out['limiters'][]=['name'=>'Wordfence','source'=>'wordpress','enabled'=>false,'applied'=>true,'routes'=>['wp_login'],'tries'=>0,'period'=>0,'seconds'=>0]; } catch(Throwable $error) { $out['limiter_error']=true; }
    }
    // Explicit extension point for local, trusted integration code. No remote URL is accepted.
    try { $extension=apply_filters('wphm_security_auth_facts',[]); } catch(Throwable $error) { $extension=[]; $out['limiter_error']=true; }
    if(is_array($extension)) {
        if(isset($extension['limiter'])) {
            $legacy=$extension['limiter'];
            $tries=is_array($legacy)?wphm_sc_setting_integer($legacy['tries'] ?? null,1,100):null;
            $seconds=is_array($legacy)?wphm_sc_setting_integer($legacy['seconds'] ?? null,1,86400):null;
            if(is_array($legacy) && is_string($legacy['name'] ?? null) && $tries!==null && $seconds!==null) {
                $out['limiter']=['name'=>sanitize_text_field(substr($legacy['name'],0,100)),'tries'=>$tries,'seconds'=>$seconds];
                $out['limiters'][]=$out['limiter']+['source'=>'wordpress','enabled'=>true,'applied'=>true,'routes'=>['wp_login'],'period'=>wphm_sc_setting_integer($legacy['period'] ?? null,1,86400) ?? 0];
            } else $out['limiter_error']=true;
        }
        foreach(array_slice(is_array($extension['limiters'] ?? null)?$extension['limiters']:[],0,20) as $limit) {
            $raw=['schema'=>1,'site'=>home_url('/'),'checked_at'=>time(),'login_limits'=>[$limit]];
            $name=is_array($limit) && is_string($limit['name'] ?? null)?sanitize_text_field(substr($limit['name'],0,100)):'ローカル読取り連携';
            $valid=wphm_sc_normalize_reader($raw,'wordpress',$name);
            if($valid!==null) $out['limiters']=array_merge($out['limiters'],$valid['login_limits']); else $out['limiter_error']=true;
        }
    }
    $out['limiters']=array_slice($out['limiters'],0,20);
    return $out;
}

function wphm_sc_file_facts(array $inventory, bool $enabled=true): array {
    if(!$enabled) return ['mime_count'=>0,'types_checked'=>false,'executable_types'=>[],'upload_bytes'=>0,'public_ajax'=>0,'scripts'=>0,'scanned'=>0,'limited'=>false,'scan_available'=>false,'rule_hint'=>false,'writable'=>null,'code_tools'=>[]];
    $types=get_allowed_mime_types(); $dangerous=[];
    $types_valid=is_array($types) && count($types)>0 && count($types)<=200;
    if ($types_valid) foreach($types as $pattern=>$mime) if(!is_string($pattern) || strlen($pattern)>200 || !is_string($mime)){ $types_valid=false; break; }
    if($types_valid) foreach(['php','php3','php4','php5','php7','php8','phtml','pht','phar','cgi','pl','py','asp','aspx','jsp','jspx','sh','htaccess'] as $extension) {
        $type=wp_check_filetype('wphm-check.' . $extension,$types);
        if(!empty($type['ext'])) $dangerous[]=$extension;
    }
    $out=['mime_count'=>is_array($types)?count($types):0,'types_checked'=>$types_valid,'executable_types'=>$dangerous,'upload_bytes'=>(int)wp_max_upload_size(),'public_ajax'=>0,'scripts'=>0,'scanned'=>0,'limited'=>false,'scan_available'=>false,'rule_hint'=>false,'writable'=>null,'code_tools'=>[]];
    global $wp_filter;
    foreach (array_keys($wp_filter) as $hook) if (strpos($hook,'wp_ajax_nopriv_')===0) $out['public_ajax']++;
    $active=array_merge((array)get_option('active_plugins',[]),array_keys((array)get_site_option('active_sitewide_plugins',[])));
    foreach (['code-snippets/code-snippets.php','insert-headers-and-footers/ihaf.php','wpcode-lite/wpcode.php'] as $file) if (in_array($file,$active,true)) $out['code_tools'][]=$inventory['plugins'][$file]['Name'] ?? $file;
    $uploads=wp_upload_dir(null,false); $base=$uploads['basedir'] ?? '';
    if (!empty($uploads['error']) || !is_string($base) || !is_dir($base) || is_link($base)) return $out;
    $resolved=realpath($base); if ($resolved===false) return $out;
    $out['writable']=is_writable($resolved); $out['scan_available']=true; $queue=[$resolved]; $directories=0;
    while ($queue && $directories++<40 && $out['scanned']<500) {
        $dir=array_shift($queue); $actual=realpath($dir);
        if ($actual===false || is_link($dir) || ($actual!==$resolved && strpos($actual,$resolved . DIRECTORY_SEPARATOR)!==0)) { $out['limited']=true; continue; }
        $dir=$actual; $handle=@opendir($dir); if ($handle===false) { $out['limited']=true; continue; }
        try {
            while (($name=readdir($handle))!==false) {
                if ($name==='.' || $name==='..') continue;
                if ($out['scanned']>=500) { $out['limited']=true; break; }
                $out['scanned']++;
                $path=$dir . DIRECTORY_SEPARATOR . $name;
                if (is_link($path)) { $out['limited']=true; continue; }
                if (is_dir($path)) { if(count($queue)<40) $queue[]=$path; else $out['limited']=true; }
                elseif (preg_match('/\.(?:php[0-9]?|phtml|phar|cgi|pl|py|asp|aspx|jsp|sh)$/i',$name)) $out['scripts']++;
            }
        } finally { closedir($handle); }
    }
    if ($queue) $out['limited']=true;
    $rule=$resolved . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_file($rule) && !is_link($rule) && is_readable($rule) && filesize($rule)<=65536) {
        $text=file_get_contents($rule,false,null,0,65536);
        $out['rule_hint']=is_string($text) && (bool)preg_match('/php_flag\s+engine\s+off|RemoveHandler[^\r\n]*\.php|Require\s+all\s+denied/i',$text);
    }
    return $out;
}

/** Check release-reviewed files against the bundled inventory; this is not a third-party scanner. */
function wphm_sc_audit_facts(): array {
    $out=['known'=>false,'files'=>0,'changed'=>0,'version'=>'','groups'=>[]];
    $path=__DIR__ . '/audit.json';
    if (!is_file($path) || filesize($path)>131072) return $out;
    $manifest=json_decode((string)file_get_contents($path),true);
    if (!is_array($manifest) || ($manifest['version'] ?? '')!==WPHM_VERSION || !is_array($manifest['files'] ?? null) || count($manifest['files'])>200) return $out;
    foreach ($manifest['files'] as $file=>$hash) {
        if (!is_string($file) || !preg_match('~^(?:(?:includes|tools|assets/js)/[A-Za-z0-9_./-]+\.(?:php|js)|wp-helpers-multi\.php)$~D',$file) || strpos($file,'..')!==false || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash)) return $out;
        $local=WPHM_PLUGIN_DIR . $file; $out['files']++;
        $bytes=is_file($local) && !is_link($local) && filesize($local)<=2097152 ? @file_get_contents($local) : false;
        if (!is_string($bytes) || !hash_equals($hash,hash('sha256',str_replace("\r\n","\n",$bytes)))) $out['changed']++;
    }
    $out['known']=$out['files']>0 && $out['changed']===0; $out['version']=$manifest['version'];
    $out['groups']=is_array($manifest['groups'] ?? null) ? $manifest['groups'] : [];
    return $out;
}
