<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_users_signature(): string {
    return hash('sha256',wp_json_encode([get_current_blog_id(),wp_roles()->roles,get_site_option('wphm_sc_users_revision',''),get_option('active_plugins',[]),get_site_option('active_sitewide_plugins',[]),WPHM_VERSION]));
}

function wphm_sc_user_totals(): array {
    return ['checked'=>0,'html_users'=>0,'raw_upload_users'=>0,'code_users'=>0,'admins'=>0,'mfa_known'=>0,'mfa_enabled'=>0,'mfa_providers'=>[],'mfa_error'=>false,'app_password_users'=>0];
}

function wphm_sc_valid_user_scan($state, string $signature): bool {
    if(!is_array($state) || ($state['schema'] ?? null)!==1 || ($state['owner'] ?? null)!==get_current_user_id() || ($state['site'] ?? null)!==get_current_blog_id() || ($state['signature'] ?? '')!==$signature || !is_int($state['started'] ?? null) || $state['started']<time()-DAY_IN_SECONDS || $state['started']>time()+60 || !is_int($state['checked_at'] ?? null) || $state['checked_at']>time()+60 || $state['checked_at']<time()-(($state['phase'] ?? '')==='done'?300:DAY_IN_SECONDS) || !in_array($state['phase'] ?? '',['admins','others','done'],true) || !is_int($state['offset'] ?? null) || $state['offset']<0 || $state['offset']>1000000 || !is_array($state['totals'] ?? null)) return false;
    foreach(['checked','html_users','raw_upload_users','code_users','admins','mfa_known','mfa_enabled','app_password_users'] as $field) if(!wphm_sc_valid_count($state['totals'][$field] ?? null,1000000)) return false;
    $t=$state['totals'];
    if($t['mfa_enabled']>$t['mfa_known'] || $t['mfa_known']>$t['admins'] || $t['admins']>$t['checked'] || $t['app_password_users']>$t['checked'] || !is_bool($t['mfa_error'] ?? null) || !is_array($t['mfa_providers'] ?? null) || count($t['mfa_providers'])>30) return false;
    foreach($t['mfa_providers'] as $name) if(!is_string($name) || strlen($name)>100) return false;
    return strlen(serialize($state))<8192;
}

/** Admins first; at most 200 user records per step. No username, IP, key or list of user IDs is cached. */
function wphm_sc_scan_users(string $operation='fresh'): array {
    global $wpdb;
    $signature=wphm_sc_users_signature(); $key='wphm_sc_users_'.get_current_user_id();
    $state=$operation==='fresh'?null:get_transient($key);
    $valid=wphm_sc_valid_user_scan($state,$signature);
    if($operation==='continue' && !$valid && $state!==false) throw new WPHM_Security_Check_Exception('利用者の確認状態が変更・期限切れです。再チェックしてください。');
    if($valid && $operation==='view') return $state['totals']+['limited'=>$state['phase']!=='done','pending'=>$state['phase']!=='done','user_error'=>false,'user_checked_at'=>$state['checked_at']];
    if(!$valid || ($operation==='recheck' && $state['phase']==='done')) $state=['schema'=>1,'owner'=>get_current_user_id(),'site'=>get_current_blog_id(),'signature'=>$signature,'started'=>time(),'checked_at'=>time(),'phase'=>'admins','offset'=>0,'totals'=>wphm_sc_user_totals()];
    $budget=200; $failed=false;
    while($budget>0 && $state['phase']!=='done') {
        if($state['offset']>=1000000 || $state['totals']['checked']>=1000000) { $failed=true; break; }
        $args=['number'=>$budget+1,'offset'=>$state['offset'],'orderby'=>'ID','order'=>'ASC','fields'=>'all_with_meta','count_total'=>false,'cache_results'=>false];
        $args[$state['phase']==='admins'?'capability':'capability__not_in']=$state['phase']==='admins'?'manage_options':['manage_options'];
        $users=get_users($args);
        if(!is_array($users) || $wpdb->last_error!=='') { $failed=true; break; }
        $more=count($users)>$budget; $users=array_slice($users,0,$budget);
        foreach($users as $user) {
            $t=&$state['totals']; $t['checked']++;
            if(user_can($user,'manage_options')) $t['admins']++;
            if(user_can($user,'unfiltered_html') && (user_can($user,'edit_posts') || user_can($user,'edit_pages'))) $t['html_users']++;
            if(user_can($user,'unfiltered_upload') && user_can($user,'upload_files')) $t['raw_upload_users']++;
            if(user_can($user,'edit_plugins') || user_can($user,'edit_themes') || user_can($user,'install_plugins') || user_can($user,'install_themes')) $t['code_users']++;
            if(function_exists('wp_is_application_passwords_available_for_user') && wp_is_application_passwords_available_for_user($user) && class_exists('WP_Application_Passwords') && WP_Application_Passwords::get_user_application_passwords($user->ID)) $t['app_password_users']++;
            if(user_can($user,'manage_options')) {
                $mfa=wphm_sc_mfa_for_user($user); $t['mfa_error']=$t['mfa_error'] || $mfa['error'];
                if($mfa['known']) { $t['mfa_known']++; if($mfa['enabled']) $t['mfa_enabled']++; }
                foreach($mfa['providers'] as $name) if(!in_array($name,$t['mfa_providers'],true) && count($t['mfa_providers'])<30) $t['mfa_providers'][]=substr($name,0,100);
            }
            unset($t);
        }
        $budget-=count($users); $state['offset']+=count($users);
        if(!$more) { $state['phase']=$state['phase']==='admins'?'others':'done'; $state['offset']=0; }
    }
    $state['checked_at']=time();
    if(in_array($operation,['recheck','continue'],true)) {
        if($failed) delete_transient($key); else set_transient($key,$state,$state['phase']==='done'?300:max(1,DAY_IN_SECONDS-(time()-$state['started'])));
    }
    return $state['totals']+['limited'=>$failed || $state['phase']!=='done','pending'=>!$failed && $state['phase']!=='done','user_error'=>$failed,'user_checked_at'=>$state['checked_at']];
}
