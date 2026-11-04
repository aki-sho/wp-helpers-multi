<?php
/** Real WordPress rendering regressions, using only the disposable database and fixed API responses. */
if(PHP_SAPI!=='cli' || !isset($connection,$checks)) exit;
$guide_user=get_current_user_id(); $guide_prefs=get_user_option('wphm_sc_sources',$guide_user); $guide_options=get_option('wphm_sc_options',[]);
$guide_original_calls=count($calls); $guide_original_hooks=serialize(array_keys($GLOBALS['wp_filter']['authenticate']->callbacks ?? []));
try {
    update_user_option($guide_user,'wphm_sc_sources',['wordpress'=>false,'server'=>false,'edge'=>false,'updates'=>false,'vulnerabilities'=>false,'vendor'=>false,'public_http'=>false],false);
    $guide_report=wphm_sc_collect(false); $guide_report['refreshed']=false;
    foreach($guide_report['items'] as &$guide_item) foreach($guide_item['checks'] as &$guide_check) if($guide_check['status']==='skip') {
        $guide_check['value']='UNSELECTED_CONFIRMED_VALUE'; $guide_check['help']='UNSELECTED_CONFIRMED_HELP';
    }
    unset($guide_item,$guide_check);
    ob_start(); wphm_sc_report_html($guide_report); $guide_html=ob_get_clean();
    foreach(['UNSELECTED_CONFIRMED_VALUE','UNSELECTED_CONFIRMED_HELP','未確認・計測中','（確認先：','／参考）','設定元は未特定）'] as $forbidden) sc_same(false,strpos($guide_html,$forbidden)!==false,'unchecked rendering must not retain misleading wording: '.$forbidden);
    preg_match_all('/class="wphm-sc-origin">([^<]*)</u',$guide_html,$guide_tags);
    sc_same(true,count($guide_tags[1])>20,'locations rendered throughout all twenty items');
    foreach(array_unique($guide_tags[1]) as $tag) sc_same(true,in_array($tag,['（WordPress内）','（サーバー側）','（外部サービス）'],true),'each location tag uses one exact allowed name');
    sc_same(20,substr_count($guide_html,'class="wphm-sc-accordion"'),'twenty compact disclosures retained');
    sc_same(true,strpos($guide_html,'理由：')!==false && strpos($guide_html,'次の操作：')!==false,'unselected rows give both reason and next action');
    sc_same(true,strpos($guide_html,'この項目で調べる場所が未選択')!==false,'source selection reason shown');
    sc_same(['WordPress内','サーバー側','外部サービス'],wphm_sc_location_tags(['sources'=>['wordpress','server','edge','untrusted','wordpress']]),'only known source tags survive and duplicates are removed');

    update_user_option($guide_user,'wphm_sc_sources',['wordpress'=>true,'server'=>true,'edge'=>false,'updates'=>false,'vulnerabilities'=>false,'vendor'=>false,'public_http'=>false],false);
    $guide_facts=wphm_sc_collect(false)['facts'];
    $guide_facts['acquisition']['sources']['wordpress']['login_limits']=[]; $guide_facts['identity']['limiter']=null; $guide_facts['identity']['limiter_error']=false;
    $guide_facts['acquisition']['sources']['server']=['status'=>'partial','name'=>'PHPのローカル読取り','login_limits'=>[],'capabilities'=>[],'traffic'=>null,'local_php'=>['version'=>PHP_VERSION,'file_uploads'=>true,'max_execution_seconds'=>30]];
    $guide_items=array_column(wphm_sc_assess($guide_facts),null,'id'); $guide_limiter=$guide_items['brute_force']['checks'][0];
    $guide=wphm_sc_check_guidance($guide_limiter,$guide_facts,'brute_force');
    sc_same('未確認',$guide['status_label'],'unread static login policy is not labelled as running measurement');
    sc_same(true,strpos($guide['reason'],'ローカルPHP情報')!==false && strpos($guide['reason'],'待機だけでは')!==false,'actual selected PHP method explains why a limiter is unreadable');
    foreach(['標準ログインへの適用','許可回数','計数期間','停止時間','判定基準'] as $part) sc_same(true,strpos($guide['action'],$part)!==false,'login next action identifies the control rather than installing a limiter: '.$part);
    sc_same('unknown',$guide_items['brute_force']['level'],'adding guidance never upgrades an unread limiter to low risk');
    $guide_facts['identity']['limiter_error']=true; $guide=wphm_sc_check_guidance(array_replace($guide_limiter,['status'=>'error']),$guide_facts,'brute_force');
    sc_same('取得失敗',$guide['status_label'],'read failures stay distinct from missing configuration');
    sc_same(true,strpos($guide['reason'],'読取りに失敗')!==false,'local read failure has an explicit reason');
    $guide_facts['identity']['limiter_error']=false;
    $guide_facts['acquisition']['selected']['edge']=true;
    $guide_facts['acquisition']['sources']['edge']=['status'=>'partial','failures'=>['login_limits'=>'http_403'],'login_limits'=>[]];
    $guide=wphm_sc_check_guidance($guide_limiter,$guide_facts,'brute_force');
    sc_same(true,strpos($guide['reason'],'読取り権限が不足')!==false,'login-specific API permission failure is not a generic waiting state');
    sc_same(true,strpos($guide['action'],'Zone ID')!==false,'Cloudflare guidance names the actual connection fields');

    $guide_facts['acquisition']['sources']['server']=['status'=>'setup','reason'=>'missing_id'];
    $guide=wphm_sc_check_guidance(['label'=>'保存先のスクリプト実行禁止','status'=>'pending','sources'=>['server'],'value'=>'','help'=>''],$guide_facts,'file_upload');
    sc_same(true,strpos($guide['reason'],'接続IDが未設定')!==false,'real missing connection ID is named');
    sc_same('#wphm-sc-connect-server',$guide['link'],'unread server item links directly to its connection panel');
    $guide_php=['label'=>'PHP上流のセキュリティサポート','status'=>'skip','sources'=>['server'],'value'=>'','help'=>''];
    $guide=wphm_sc_check_guidance($guide_php,$guide_facts,'rce');
    sc_same(true,strpos($guide['action'],'公式の更新情報と照合する')!==false,'PHP support opt-in targets public updates, not server connection selection');
    $guide=wphm_sc_check_guidance(array_replace($guide_php,['status'=>'pending']),$guide_facts,'rce');
    sc_same(true,strpos($guide['reason'],'WordPress.org')!==false,'PHP support lookup does not blame an unrelated missing server ID');
    sc_same(false,strpos($guide['reason'],'接続IDが未設定')!==false,'PHP support lookup is independent of server API credentials');
    $guide_facts['acquisition']['sources']['edge']['failures']['traffic']='http_403';
    $guide=wphm_sc_check_guidance(['label'=>'外部の通信統計','status'=>'pending','sources'=>['edge'],'value'=>'','help'=>''],$guide_facts,'ddos');
    sc_same(true,strpos($guide['reason'],'読取り権限が不足')!==false,'traffic guidance uses its own typed API failure');

    $guide_facts['telemetry']=array_replace($guide_facts['telemetry'],['available'=>true,'baseline_minutes'=>12,'baseline_requests'=>8]);
    $guide=wphm_sc_check_guidance(['label'=>'通常時との比較','status'=>'pending','sources'=>['wordpress'],'value'=>'','help'=>''],$guide_facts,'ddos');
    sc_same('記録不足',$guide['status_label'],'only an active aggregate with insufficient evidence is record pending');
    sc_same(true,strpos($guide['reason'],'12分・通信8件')!==false,'traffic reason shows actual missing observation requirements');
    sc_same(true,strpos($guide['action'],'経過時間だけでは')!==false,'waiting does not promise completion without traffic');
    $guide_facts['telemetry']['available']=false;
    $guide=wphm_sc_check_guidance(['label'=>'認証記録','status'=>'pending','sources'=>['wordpress'],'value'=>'','help'=>''],$guide_facts,'brute_force');
    sc_same('未確認',$guide['status_label'],'unavailable counter is not labelled measuring');
    sc_same(true,strpos($guide['action'],'DBの読取り')!==false,'counter read failure has an actionable local check');

    $guide_public=['label'=>'ログインページ：セッション用Cookie候補の属性','status'=>'pending','sources'=>['wordpress','server','edge'],'public_response'=>true,'value'=>'今回の未ログイン応答ではセッション用Cookie候補が発行されていません。','help'=>''];
    $guide_facts['public_response']['responses']['login']=['status'=>'ok'];
    $guide=wphm_sc_check_guidance($guide_public,$guide_facts,'session');
    sc_same(true,strpos($guide['action'],'待っても認証後の属性は取得されません')!==false,'absent anonymous Cookie does not claim waiting will issue authenticated cookies');
    sc_same(true,strpos($guide['reason'],'Cookie候補が発行されていません')!==false,'public Cookie observation keeps its actual reason despite unrelated missing server ID');
    sc_same(false,strpos($guide['action'],'接続ID')!==false,'known public response does not ask for unrelated API credentials');
    $guide_facts['public_response']['responses']['login']=['status'=>'error','reason'=>'network','raw'=>'RAW_PRIVATE_API_RESPONSE'];
    $guide=wphm_sc_check_guidance(array_replace($guide_public,['status'=>'error']),$guide_facts,'session');
    sc_same(true,strpos($guide['reason'],'通信')!==false,'public transport failures report fixed public reason');
    sc_same(false,strpos(wp_json_encode($guide),'RAW_PRIVATE_API_RESPONSE')!==false,'raw private API text never enters guidance');

    $guide_mfa=wphm_sc_check_guidance(['label'=>'管理者の追加認証','status'=>'pending','sources'=>['wordpress'],'value'=>'','help'=>''],$guide_facts,'credential_stuffing');
    sc_same(true,strpos($guide_mfa['action'],'プロフィール')!==false,'MFA identifies actual user profile setting location');
    foreach(['他製品のAPI・所有者確認','他製品・外部スクリプトの整合性','認証後のキャッシュ分離','TLSの全許可構成'] as $label) {
        $guide=wphm_sc_check_guidance(['label'=>$label,'status'=>'pending','sources'=>['wordpress','server','edge'],'value'=>'','help'=>''],$guide_facts,'session');
        sc_same(true,strpos($guide['reason'],'自動読取りの範囲では判定できません')!==false,'manual scope is not portrayed as a missing key: '.$label);
        sc_same(true,$guide['action']!=='','out-of-scope check describes the concrete next inspection');
    }
    $guide_report['items'][0]['checks'][0]=array_replace($guide_limiter,['label'=>'<script>private-label</script>','status'=>'pending','value'=>'<img src=x onerror=x>','help'=>'PRIVATE_SUCCESS_HELP']);
    $guide_report['facts']=$guide_facts;
    ob_start(); wphm_sc_report_html($guide_report); $guide_html=ob_get_clean();
    sc_same(false,strpos($guide_html,'<script>private-label')!==false,'new guidance rendering preserves label escaping');
    sc_same(false,strpos($guide_html,'<img src=x')!==false,'unread rows never output the former successful value');
    sc_same(false,strpos($guide_html,'PRIVATE_SUCCESS_HELP')!==false,'unread rows never output the former successful help');
    sc_same($guide_original_calls,count($calls),'new guidance and location rendering do not send network requests');
    sc_same($guide_original_hooks,serialize(array_keys($GLOBALS['wp_filter']['authenticate']->callbacks ?? [])),'guidance does not register login restriction hooks');
    require_once $root.'/tools/link_inspector.php';
    sc_same('',wphm_li_defaults_settings()['bad_patterns'],'public distribution has no site-specific broken-link patterns');
    sc_same((string)wp_parse_url(home_url('/'),PHP_URL_HOST),wphm_li_defaults_settings()['target_host'],'default link host comes from the installed site');

    $guide_calls=[];
    $guide_transport=function($pre,$args,$url) use(&$guide_calls) { if($url!==WPHM_UPDATE_JSON_URL) return $pre; $guide_calls[]=$args; return ['response'=>['code'=>200],'headers'=>[],'body'=>wp_json_encode(['version'=>WPHM_VERSION,'download_url'=>WPHM_UPDATE_URI.'wp-helpers-multi.zip'])]; };
    add_filter('pre_http_request',$guide_transport,40,3);
    try { wphm_get_update_metadata(true); } finally { remove_filter('pre_http_request',$guide_transport,40); }
    sc_same(1,count($guide_calls),'privacy regression checks actual update metadata transport');
    sc_same('WP-Helpers-Multi/'.WPHM_VERSION,$guide_calls[0]['user-agent'],'update metadata does not use WordPress default site-URL user agent');
    sc_same(false,strpos($guide_calls[0]['user-agent'],home_url('/'))!==false,'site URL absent from custom metadata user agent');
} finally {
    update_option('wphm_sc_options',$guide_options,false); update_user_option($guide_user,'wphm_sc_sources',$guide_prefs,false);
}
echo "PASS guidance: exact location tags, per-item reasons/actions, unavailable versus waiting, privacy and unchanged authentication.\n";
