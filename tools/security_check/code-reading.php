<?php
if (!defined('ABSPATH')) exit;

/** Local lexical observations only. Never execute inspected code or retain literal values. */
function wphm_sc_php_candidates(string $code, bool $snippet = false): array {
    $out = ['features'=>[], 'limited'=>false];
    if (!function_exists('token_get_all') || strlen($code)>262144) return ['features'=>[], 'limited'=>true];
    if ($snippet) $code = '<?php ' . $code;
    try { $tokens = @token_get_all($code); } catch (Throwable $error) { return ['features'=>[], 'limited'=>true]; }
    if (count($tokens)>50000) return ['features'=>[], 'limited'=>true];
    $clean=[]; $line=1;
    foreach ($tokens as $token) {
        if (is_array($token)) {
            $line=$token[2];
            if (in_array($token[0],[T_COMMENT,T_DOC_COMMENT,T_WHITESPACE,T_INLINE_HTML,T_OPEN_TAG,T_CLOSE_TAG],true)) continue;
            $clean[]=['id'=>$token[0],'text'=>$token[1],'line'=>$line];
        } else $clean[]=['id'=>0,'text'=>$token,'line'=>$line];
    }
    unset($tokens,$code);
    $calls = [
        'wp_verify_nonce'=>'nonce_check','check_admin_referer'=>'nonce_check','check_ajax_referer'=>'nonce_check',
        'current_user_can'=>'cap_check','user_can'=>'cap_check','esc_html'=>'output_escape','esc_attr'=>'output_escape',
        'esc_url'=>'output_escape','esc_textarea'=>'output_escape','wp_kses'=>'output_escape','wp_kses_post'=>'output_escape',
        'wp_safe_remote_get'=>'safe_http','wp_safe_remote_post'=>'safe_http','wp_safe_remote_request'=>'safe_http',
        'wp_safe_redirect'=>'safe_redirect','validate_file'=>'file_path_check','wp_check_filetype_and_ext'=>'upload_type_check',
        'wp_unique_filename'=>'upload_path_check','wp_destroy_all_sessions'=>'session_revoke','wp_destroy_other_sessions'=>'session_limit',
    ];
    $record = function (string $key, int $at) use (&$out): void {
        if (!isset($out['features'][$key])) $out['features'][$key]=['line'=>max(1,$at)];
    };
    $found=[]; $remote=false; $count=count($clean);
    for ($n=0;$n<$count;$n++) {
        $t=$clean[$n]; $name=strtolower(ltrim($t['text'],'\\'));
        $next=$clean[$n+1]['text'] ?? ''; $prev=$clean[$n-1] ?? ['id'=>0,'text'=>''];
        $identifier=$t['id']===T_STRING || (defined('T_NAME_FULLY_QUALIFIED') && $t['id']===constant('T_NAME_FULLY_QUALIFIED'));
        $declaration=$prev['id']===T_FUNCTION || ($prev['text']==='&' && ($clean[$n-2]['id'] ?? 0)===T_FUNCTION);
        $qualified=$prev['text']==='\\' && ($clean[$n-2]['id'] ?? 0)===T_STRING;
        $global=$identifier && $next==='(' && !$declaration && !$qualified && !in_array($prev['id'],[T_NEW,T_OBJECT_OPERATOR,T_DOUBLE_COLON],true);
        if ($global) {
            $found[$name]=$t['line'];
            if (isset($calls[$name])) $record($calls[$name],$t['line']);
            if (in_array($name,['add_action','add_filter'],true) && ($clean[$n+2]['id'] ?? 0)===T_CONSTANT_ENCAPSED_STRING) {
                $hook=strtolower(substr($clean[$n+2]['text'],1,-1));
                if (in_array($hook,['wp_login_failed','authenticate','wp_authenticate_user','login_init','login_form'],true)) $record('auth_hook',$t['line']);
                if (in_array($hook,['validate_password_reset','user_profile_update_errors','registration_errors'],true)) $record('password_policy',$t['line']);
                if ($hook==='xmlrpc_enabled') $record('xmlrpc_setting',$t['line']);
                if (strpos($hook,'two_factor')!==false) $record('mfa_code',$t['line']);
            }
            if (in_array($name,['header','header_remove'],true) && ($clean[$n+2]['id'] ?? 0)===T_CONSTANT_ENCAPSED_STRING) {
                $header=strtolower(ltrim(substr($clean[$n+2]['text'],1,-1)));
                foreach (['content-security-policy:'=>'csp','x-frame-options:'=>'frame_options','strict-transport-security:'=>'hsts','access-control-allow-origin:'=>'cors','cache-control:'=>'cache_policy','x-content-type-options:'=>'nosniff','referrer-policy:'=>'referrer_policy'] as $prefix=>$feature) {
                    if (strpos($header,$prefix)===0) $record($feature,$t['line']);
                }
            }
        }
        if ($name==='prepare' && $next==='(' && $prev['id']===T_OBJECT_OPERATOR && ($clean[$n-2]['text'] ?? '')==='$wpdb') $record('sql_prepared',$t['line']);
        if ($t['text']==='$_SERVER' && $next==='[' && ($clean[$n+2]['id'] ?? 0)===T_CONSTANT_ENCAPSED_STRING) {
            $key=substr($clean[$n+2]['text'],1,-1);
            if (in_array($key,['REMOTE_ADDR','HTTP_X_FORWARDED_FOR','HTTP_CF_CONNECTING_IP'],true)) $remote=$t['line'];
        }
    }
    if ($remote && (isset($found['wp_die']) || isset($found['status_header']) || isset($found['http_response_code']))) $record('ip_access',$remote);
    if (isset($out['features']['auth_hook']) && (isset($found['set_transient']) || isset($found['wp_cache_set']))) $record('login_limit_code',$out['features']['auth_hook']['line']);
    if (isset($found['setcookie']) || isset($found['setrawcookie'])) $record('cookie_code',$found['setcookie'] ?? $found['setrawcookie']);
    return $out;
}

/** Directive evidence, not an Apache configuration interpreter or proof of AllowOverride. */
function wphm_sc_htaccess_candidates(string $text): array {
    $out=['features'=>[], 'limited'=>false];
    if (strlen($text)>65536) return ['features'=>[], 'limited'=>true];
    $lines=preg_split('/\r\n|\r|\n/',$text);
    if (count($lines)>4096) return ['features'=>[], 'limited'=>true];
    $depth=0; $continued='';
    foreach ($lines as $number=>$raw) {
        $line=trim($continued.$raw); $continued='';
        if ($line==='' || $line[0]==='#') continue;
        if (substr($line,-1)==='\\') { $continued=substr($line,0,-1).' '; continue; }
        if (preg_match('/^<\//',$line)) { $depth=max(0,$depth-1); continue; }
        if (preg_match('/^<[^\/]/',$line)) { $depth++; continue; }
        $keys=[];
        if (preg_match('/^(?:Require\s+ip\s+|Allow\s+from\s+(?!all\s*$))/i',$line)) $keys[]='ip_allow';
        if (preg_match('/^(?:Require\s+not\s+ip\s+|Deny\s+from\s+(?!all\s*$))/i',$line)) $keys[]='ip_deny';
        if (preg_match('/^(?:AuthType\s+Basic|Require\s+(?:valid-user|user)\b)/i',$line)) $keys[]='basic_auth';
        if (preg_match('/^Options\s+.*(?:^|\s)-?Indexes\b/i',$line)) $keys[]='directory_index';
        if (preg_match('/^Options\s+-Indexes\b/i',$line)) $keys[]='directory_index';
        if (preg_match('/^(?:php_flag\s+engine\s+off|RemoveHandler\s+.*\.php|SetHandler\s+(?:none|default-handler))/i',$line)) $keys[]='script_execution';
        if (preg_match('/^(?:Require\s+all\s+denied|Deny\s+from\s+all)\s*$/i',$line)) $keys[]='file_access';
        if (preg_match('/^(?:RewriteRule|Redirect(?:Match)?)\s+.*https:\/\//i',$line)) $keys[]='https_redirect';
        if (preg_match('/^(?:RewriteRule|Redirect(?:Match)?)\s+/i',$line)) $keys[]='redirect_code';
        if (preg_match('/^Header\s+(?:always\s+|onsuccess\s+)?(?:set|add|append|merge|unset)\s+["\']?([a-z-]+)/i',$line,$match)) {
            $headers=['content-security-policy'=>'csp','x-frame-options'=>'frame_options','strict-transport-security'=>'hsts','access-control-allow-origin'=>'cors','cache-control'=>'cache_policy','x-content-type-options'=>'nosniff','referrer-policy'=>'referrer_policy'];
            if (isset($headers[strtolower($match[1])])) $keys[]=$headers[strtolower($match[1])];
        }
        foreach ($keys as $key) if (!isset($out['features'][$key])) $out['features'][$key]=['line'=>$number+1,'conditional'=>$depth>0];
    }
    if ($depth!==0 || $continued!=='') $out['limited']=true;
    return $out;
}
