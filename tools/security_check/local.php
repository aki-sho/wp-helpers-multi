<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_identity_facts(): array {
    $out = ['checked'=>0,'limited'=>false,'html_users'=>0,'raw_upload_users'=>0,'code_users'=>0,'admins'=>0,'mfa_known'=>0,'mfa_enabled'=>0,'app_password_users'=>0,'roles'=>[],'limiter'=>null];
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
        if (user_can($user,'manage_options') && is_callable(['Two_Factor_Core','is_user_using_two_factor']) && is_callable(['Two_Factor_Core','get_primary_provider_for_user'])) {
            try {
                $provider = Two_Factor_Core::get_primary_provider_for_user($user->ID);
                if (is_wp_error($provider)) continue;
                $enabled=$provider && !is_a($provider,'Two_Factor_Dummy') && Two_Factor_Core::is_user_using_two_factor($user->ID);
                $out['mfa_known']++;
                if ($enabled) $out['mfa_enabled']++;
            } catch (Throwable $exception) { /* An incompatible adapter supplies no evidence. */ }
        }
    }
    global $wp_filter;
    if (isset($wp_filter['wp_authenticate_user']->callbacks)) foreach ($wp_filter['wp_authenticate_user']->callbacks as $callbacks) foreach ($callbacks as $callback) {
        $function = $callback['function'] ?? null;
        if (!is_array($function) || !isset($function[0],$function[1]) || !is_object($function[0]) || !is_a($function[0],'Limit_Login_Attempts') || $function[1] !== 'wp_authenticate_user' || !is_callable([$function[0],'get_option'])) continue;
        try {
            $tries=$function[0]->get_option('allowed_retries'); $duration=$function[0]->get_option('lockout_duration');
            if (is_numeric($tries) && is_numeric($duration) && $tries>=1 && $tries<=100 && $duration>=1 && $duration<=86400) $out['limiter']=['name'=>'Limit Login Attempts / Reloaded','tries'=>(int)$tries,'seconds'=>(int)$duration];
        } catch (Throwable $exception) { /* Do not change authentication when an adapter fails. */ }
    }
    $out['xmlrpc_auth']=(bool)apply_filters('xmlrpc_enabled',true);
    $out['application_passwords']=function_exists('wp_is_application_passwords_available') ? (bool)wp_is_application_passwords_available() : null;
    // Explicit extension point for local, trusted integration code. No remote URL is accepted.
    $extension=apply_filters('wphm_security_auth_facts',[]);
    if (is_array($extension) && isset($extension['limiter']['name'],$extension['limiter']['tries'],$extension['limiter']['seconds']) && is_string($extension['limiter']['name']) && is_numeric($extension['limiter']['tries']) && is_numeric($extension['limiter']['seconds']) && $extension['limiter']['tries']>=1 && $extension['limiter']['tries']<=100 && $extension['limiter']['seconds']>=1 && $extension['limiter']['seconds']<=86400) $out['limiter']=['name'=>sanitize_text_field(substr($extension['limiter']['name'],0,100)),'tries'=>(int)$extension['limiter']['tries'],'seconds'=>(int)$extension['limiter']['seconds']];
    return $out;
}

function wphm_sc_file_facts(array $inventory): array {
    $types=get_allowed_mime_types(); $dangerous=[];
    foreach (array_keys($types) as $extensions) if (preg_match('/(?:^|\|)(?:php[0-9]?|phtml|phar|cgi|pl|py|asp|aspx|jsp|sh)(?:\||$)/i',$extensions)) $dangerous[]=$extensions;
    $out=['mime_count'=>count($types),'executable_types'=>array_slice($dangerous,0,20),'upload_bytes'=>(int)wp_max_upload_size(),'public_ajax'=>0,'scripts'=>0,'scanned'=>0,'limited'=>false,'rule_hint'=>false,'writable'=>null,'code_tools'=>[]];
    global $wp_filter;
    foreach (array_keys($wp_filter) as $hook) if (strpos($hook,'wp_ajax_nopriv_')===0) $out['public_ajax']++;
    $active=array_merge((array)get_option('active_plugins',[]),array_keys((array)get_site_option('active_sitewide_plugins',[])));
    foreach (['code-snippets/code-snippets.php','insert-headers-and-footers/ihaf.php','wpcode-lite/wpcode.php'] as $file) if (in_array($file,$active,true)) $out['code_tools'][]=$inventory['plugins'][$file]['Name'] ?? $file;
    $uploads=wp_upload_dir(null,false); $base=$uploads['basedir'] ?? '';
    if (!empty($uploads['error']) || !is_string($base) || !is_dir($base) || is_link($base)) return $out;
    $resolved=realpath($base); if ($resolved===false) return $out;
    $out['writable']=is_writable($resolved); $queue=[$resolved]; $directories=0;
    while ($queue && $directories++<40 && $out['scanned']<500) {
        $dir=array_shift($queue); $actual=realpath($dir);
        if ($actual===false || is_link($dir) || ($actual!==$resolved && strpos($actual,$resolved . DIRECTORY_SEPARATOR)!==0)) { $out['limited']=true; continue; }
        $dir=$actual; $handle=@opendir($dir); if ($handle===false) { $out['limited']=true; continue; }
        try {
            while (($name=readdir($handle))!==false) {
                if ($name==='.' || $name==='..') continue;
                if (++$out['scanned']>500) { $out['limited']=true; break; }
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
