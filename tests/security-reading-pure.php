<?php
/** Passive parsing and filesystem boundaries; no WordPress/site/HTTP access. */
if (PHP_SAPI!=='cli') exit;
define('ABSPATH',dirname(__DIR__).'/.wphm-test/');
function wp_normalize_path($path) { return str_replace('\\','/',$path); }
require dirname(__DIR__).'/tools/security_check/code-reading.php';
require dirname(__DIR__).'/tools/security_check/wordpress-reading.php';
$checks=0;
function reading_same($expected,$actual,string $message): void {
    global $checks; $checks++;
    if ($expected!==$actual) throw new RuntimeException($message);
}
$empty=wphm_sc_php_candidates("<?php // wp_verify_nonce('x');\n/* current_user_can('x'); */\n\$text=\"wp_safe_remote_get('x')\";");
reading_same([], $empty['features'],'comments and quoted examples are not executable calls');
reading_same([],wphm_sc_php_candidates('<?php function wp_verify_nonce($x) {}')['features'],'function declarations are not calls');
reading_same([],wphm_sc_php_candidates('<?php function &wp_verify_nonce($x) {}')['features'],'reference-returning declarations are not calls');
reading_same([],wphm_sc_php_candidates('<?php Fixture\\wp_verify_nonce($x);')['features'],'a qualified custom function is not assumed to be a WordPress API');
$features=wphm_sc_php_candidates('<?php check_ajax_referer("fixture"); current_user_can("manage_options"); $wpdb->prepare("SELECT %s",$x); esc_html($x); wp_safe_remote_get($u); wp_safe_redirect($u); validate_file($x); wp_check_filetype_and_ext($a,$b);');
foreach (['nonce_check','cap_check','sql_prepared','output_escape','safe_http','safe_redirect','file_path_check','upload_type_check'] as $key) reading_same(true,isset($features['features'][$key]),$key.' real-call candidate recorded');
reading_same(false,isset(wphm_sc_php_candidates('<?php $other->prepare("x");')['features']['sql_prepared']),'other objects do not impersonate wpdb');
reading_same([],wphm_sc_php_candidates('<p>wp_verify_nonce($x)</p>')['features'],'inline HTML does not impersonate PHP');
$features=wphm_sc_php_candidates('<?php if (in_array($_SERVER["REMOTE_ADDR"], $ips)) wp_die("blocked");');
reading_same(true,isset($features['features']['ip_access']),'IP access code is recorded without claiming enabled');
reading_same(false,isset($features['features']['ip_allow']),'unknown polarity is not assumed to be an allow list');
reading_same([],wphm_sc_php_candidates('<?php $key="REMOTE_ADDR"; $x="wp_die";')['features'],'unrelated literals do not imply IP protection');
$features=wphm_sc_php_candidates('<?php add_action("wp_login_failed", $cb); set_transient("fixture",1,60);');
reading_same(true,isset($features['features']['login_limit_code']),'auth counter implementation candidate');
reading_same(true,isset(wphm_sc_php_candidates('wp_verify_nonce($x);',true)['features']['nonce_check']),'DB snippets are lexed without execution');
reading_same(true,wphm_sc_php_candidates(str_repeat('a',262145))['limited'],'large code is rejected before token allocation');
$secret='FIXTURE_PRIVATE_KEY_DO_NOT_DISPLAY';
$parsed=wphm_sc_php_candidates('<?php define("DB_PASSWORD","'.$secret.'"); header("X-Frame-Options: DENY");');
reading_same(false,strpos(serialize($parsed),$secret)!==false,'source literal values do not enter evidence');
reading_same(true,isset($parsed['features']['frame_options']),'header code location can be recorded');
reading_same([],wphm_sc_htaccess_candidates("# Require ip 192.0.2.1\n# Header set X-Frame-Options DENY")['features'],'commented directives are ignored');
$ht=wphm_sc_htaccess_candidates("<Files wp-login.php>\nRequire ip 192.0.2.1\nRequire not ip 198.51.100.1\nAuthType Basic\nRequire valid-user\n</Files>\nOptions -Indexes\nHeader always set X-Frame-Options DENY\nRewriteRule ^ https://example.invalid/ [R=301,L]\nphp_flag engine off\nRequire all denied\n");
foreach (['ip_allow','ip_deny','basic_auth','directory_index','frame_options','https_redirect','redirect_code','script_execution','file_access'] as $key) reading_same(true,isset($ht['features'][$key]),'htaccess '.$key.' candidate');
reading_same(true,$ht['features']['ip_allow']['conditional'],'Files block scope remains conditional');
reading_same(false,$ht['features']['directory_index']['conditional'],'unconditional scope remains distinct');
reading_same(false,strpos(serialize($ht),'192.0.2.1')!==false,'actual IP values are not saved');
reading_same(false,array_key_exists('enabled',$ht['features']['ip_allow']),'directives never prove server enforcement');
reading_same(true,wphm_sc_htaccess_candidates("<IfModule mod_authz_core.c>\nRequire all denied")['limited'],'unclosed contexts remain partial');
reading_same(true,wphm_sc_htaccess_candidates(str_repeat('a',65537))['limited'],'huge htaccess is bounded');
$parent=realpath(dirname(__DIR__).'/.wphm-test');
if ($parent===false) throw new RuntimeException('Disposable workspace missing');
$dir=$parent.'/reading-pure-'.bin2hex(random_bytes(5)); mkdir($dir);
$other=$dir.'-other'; mkdir($other);
try {
    file_put_contents($dir.'/normal.php','<?php // local inert fixture');
    file_put_contents($other.'/private.php','PRIVATE_OUTSIDE_TARGET');
    file_put_contents($dir.'/oversize.php',str_repeat('a',262145));
    $read=wphm_sc_read_local($dir.'/normal.php',$dir);
    reading_same('<?php // local inert fixture',$read['code'],'permitted local file can be read');
    reading_same('boundary',wphm_sc_read_local($other.'/private.php',$dir)['error'],'same-prefix sibling directory is outside scope');
    reading_same('boundary',wphm_sc_read_local($dir.'/../'.basename($other).'/private.php',$dir)['error'],'parent traversal cannot expand scope');
    reading_same('size',wphm_sc_read_local($dir.'/oversize.php',$dir)['error'],'oversized file cannot be read');
    reading_same('type',wphm_sc_read_local($dir,$dir)['error'],'directories are not read as files');
    reading_same('missing',wphm_sc_read_local('php://memory',$dir)['error'],'stream wrappers are not file roots');
    reading_same('boundary',wphm_sc_read_local($dir.'/normal.php',$dir.'/oversize.php',true)['error'],'fixed-file target cannot be replaced by a sibling');
    $link=$dir.'/link.php';
    if (@symlink($other.'/private.php',$link)) reading_same('boundary',wphm_sc_read_local($link,$dir)['error'],'symlink escape rejected');
    $never=$dir.'/must-not-exist';
    wphm_sc_php_candidates('<?php file_put_contents('.var_export($never,true).',"executed");');
    reading_same(false,file_exists($never),'inspected PHP code is never executed');
    reading_same(false,wphm_sc_path_within($other,$dir),'prefix collision is not within root');
    reading_same(true,wphm_sc_path_within($dir.'/normal.php',$dir),'child path is within root');
    echo "PASS: {$checks} passive parser and filesystem boundary assertions.\n";
} finally {
    // Delete only the exact disposable paths created above, never recurse through links.
    foreach ([$dir,$other] as $target) {
        if (strpos(wphm_sc_path_normal($target),wphm_sc_path_normal($parent).'/reading-pure-')!==0) throw new RuntimeException('Cleanup boundary');
        foreach (['normal.php','oversize.php','link.php','must-not-exist','private.php'] as $name) if (is_file($target.'/'.$name) || is_link($target.'/'.$name)) unlink($target.'/'.$name);
        rmdir($target);
    }
}
