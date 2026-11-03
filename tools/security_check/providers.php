<?php
if (!defined('ABSPATH')) exit;

/** Read documented provider methods; never change enrollment or authentication filters. */
function wphm_sc_mfa_for_user(WP_User $user): array {
    $out=['known'=>false,'enabled'=>false,'error'=>false,'providers'=>[]];
    if(is_callable(['Two_Factor_Core','is_user_using_two_factor']) && is_callable(['Two_Factor_Core','get_primary_provider_for_user'])) {
        try {
            $provider=Two_Factor_Core::get_primary_provider_for_user($user->ID);
            $using=Two_Factor_Core::is_user_using_two_factor($user->ID);
            if(is_wp_error($provider) || !is_bool($using) || ($provider!==false && $provider!==null && !is_object($provider))) $out['error']=true;
            else {
                $out['enabled']=$provider && !is_a($provider,'Two_Factor_Dummy') && $using;
                $out['known']=true; $out['providers'][]='Two-Factor';
            }
        } catch(Throwable $error) { $out['error']=true; }
    }
    if(is_callable(['WordfenceLS\\Controller_Users','shared'])) {
        try {
            $controller=\WordfenceLS\Controller_Users::shared();
            if(is_callable([$controller,'has_2fa_active'])) {
                $enabled=$controller->has_2fa_active($user);
                if(is_bool($enabled)) { $out['known']=true; $out['enabled']=$out['enabled'] || $enabled; $out['providers'][]='Wordfence Login Security'; }
                else $out['error']=true;
            } else $out['error']=true;
        } catch(Throwable $error) { $out['error']=true; }
    }
    try {
        $custom=apply_filters('wphm_security_mfa_facts',[],$user);
        if(is_array($custom) && ($custom['known'] ?? null)===true && is_bool($custom['enabled'] ?? null) && is_string($custom['name'] ?? null)) {
            $out['known']=true; $out['enabled']=$out['enabled'] || $custom['enabled'];
            $out['providers'][]=sanitize_text_field(substr($custom['name'],0,100));
        }
    } catch(Throwable $error) { $out['error']=true; }
    return $out;
}

function wphm_sc_wordfence_limiter(?bool &$failed=null): ?array {
    $failed=false;
    if(!is_callable(['wfConfig','get']) || has_filter('authenticate','wordfence::authenticateFilter')===false) return null;
    try {
        if(!\wfConfig::get('loginSecurityEnabled')) return null;
        $tries=wphm_sc_setting_integer(\wfConfig::get('loginSec_maxFailures'),1,100);
        $minutes=wphm_sc_setting_integer(\wfConfig::get('loginSec_lockoutMins'),1,1440);
        if($tries!==null && $minutes!==null) return ['name'=>'Wordfence','tries'=>$tries,'seconds'=>$minutes*60];
        $failed=true;
    } catch(Throwable $error) { $failed=true; return null; }
    return null;
}
