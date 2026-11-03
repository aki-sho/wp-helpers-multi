<?php
if (!defined('ABSPATH')) exit;

/** A product is matched by installed identity, never by a user-supplied filesystem path. */
function wphm_sc_vendor_products(array $inventory): array {
    $out=[];
    foreach(['plugins'=>'plugin','themes'=>'theme','mu_plugins'=>'mu','dropins'=>'dropin'] as $group=>$kind) foreach($inventory[$group] ?? [] as $file=>$item) {
        if(in_array($kind,['plugin','theme'],true) && wphm_sc_wporg_item($item)) continue;
        $key=hash('sha256',$kind.':'.$file);
        $out[$key]=['kind'=>$kind,'file'=>$file,'name'=>$item['Name'],'version'=>$item['Version']];
    }
    return $out;
}

function wphm_sc_vendor_mapping(string $key, array $product): ?array {
    $saved=wphm_sc_options()['vendor_sources'];
    if(array_key_exists($key,$saved)) $map=$saved[$key];
    elseif($product['kind']==='plugin' && $product['file']===plugin_basename(WPHM_PLUGIN_FILE)) $map=['repository'=>'aki-sho/wp-helpers-multi','package'=>'wp-helpers-multi','ecosystem'=>'other'];
    else return null;
    if(!is_array($map) || !is_string($map['repository'] ?? null) || !preg_match('~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}$~D',$map['repository']) || in_array(basename($map['repository']),['.','..'],true) || !is_string($map['package'] ?? null) || !preg_match('~^[A-Za-z0-9@][A-Za-z0-9@/_.-]{0,149}$~D',$map['package']) || !in_array($map['ecosystem'] ?? '',['other','composer','npm'],true)) return null;
    return $map;
}

function wphm_sc_vendor_save(array $input, array &$next): void {
    if(isset($input['vendor_sources'])) {
        if(!is_array($input['vendor_sources']) || count($input['vendor_sources'])>20) throw new WPHM_Security_Check_Exception('配布元の登録は20製品までです。');
        $products=wphm_sc_vendor_products(wphm_sc_inventory()); $maps=[];
        foreach($input['vendor_sources'] as $key=>$map) {
            if(!is_string($key) || !isset($products[$key]) || !is_array($map) || array_diff_key($map,array_flip(['repository','package','ecosystem']))) throw new WPHM_Security_Check_Exception('配布元の対象製品が不正です。');
            foreach(['repository','package','ecosystem'] as $field) if(!is_string($map[$field] ?? null)) throw new WPHM_Security_Check_Exception('配布元の入力形式が不正です。');
            $repo=trim(wp_unslash($map['repository'])); $package=trim(wp_unslash($map['package']));
            if($repo==='') { $maps[$key]=null; continue; }
            if(!preg_match('~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}$~D',$repo) || in_array(basename($repo),['.','..'],true) || !preg_match('~^[A-Za-z0-9@][A-Za-z0-9@/_.-]{0,149}$~D',$package) || !in_array($map['ecosystem'],['other','composer','npm'],true)) throw new WPHM_Security_Check_Exception('GitHubはowner/repository形式と、配布元が公表するパッケージ名で指定してください。');
            $maps[$key]=['repository'=>$repo,'package'=>$package,'ecosystem'=>$map['ecosystem']];
        }
        $next['vendor_sources']=$maps;
    }
    foreach(['gh_token','clear_gh'] as $key) if(isset($input[$key]) && !is_string($input[$key])) throw new WPHM_Security_Check_Exception('GitHubキーの入力形式が不正です。');
    $token=isset($input['gh_token'])?trim(wp_unslash($input['gh_token'])):'';
    if($token!=='' && !preg_match('/^[A-Za-z0-9_]{20,256}$/D',$token)) throw new WPHM_Security_Check_Exception('GitHub読取りトークンの形式を確認してください。');
    if(isset($input['clear_gh']) && $input['clear_gh']!=='1') throw new WPHM_Security_Check_Exception('GitHubキーの削除指定が不正です。');
    if(!empty($input['clear_gh'])) $next['gh_secret']=''; elseif($token!=='') $next['gh_secret']=wphm_sc_secret($token);
}

function wphm_sc_vendor_fields_html(): void {
    $options=wphm_sc_options(); $products=wphm_sc_vendor_products(wphm_sc_inventory());
    echo '<label><input type="checkbox" name="source_vendor" value="1" '.checked($options['vendor'],true,false).'>独自配布元（GitHub）の公開済み安全情報も照合する</label>';
    echo '<details class="wphm-sc-connection"><summary>独自配布元の照合設定</summary><div class="wphm-sc-connection-body"><p>登録した製品とGitHubリポジトリの対応に基づいて、公開済みアドバイザリを読み取ります。GitHubへ送るのはリポジトリ名です。インストール版の比較はサイト内で行います。未対応の版条件・非公開/権限不足・処理上限は未確認を維持します。</p>';
    foreach(array_slice($products,0,20,true) as $key=>$product) {
        $map=wphm_sc_vendor_mapping($key,$product) ?? ['repository'=>'','package'=>'','ecosystem'=>'other'];
        echo '<fieldset><legend>'.esc_html($product['name'].' '.$product['version']).'</legend>';
        foreach(['repository'=>'GitHubリポジトリ（owner/repository）','package'=>'配布元のパッケージ名'] as $field=>$label) echo '<label>'.esc_html($label).'<input type="text" name="vendor_sources['.esc_attr($key).']['.esc_attr($field).']" maxlength="150" value="'.esc_attr($map[$field]).'"></label>';
        echo '<label>パッケージの種類<select name="vendor_sources['.esc_attr($key).'][ecosystem]">';
        foreach(['other'=>'その他（WordPress独自配布）','composer'=>'Composer','npm'=>'npm'] as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($map['ecosystem'],$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label></fieldset>';
    }
    if(count($products)>20) echo '<p>登録欄は先頭20製品です。残りは対象外として表示し、安全とは扱いません。</p>';
    if(!$products) echo '<p>現在、独自配布・MU等の対象製品はありません。</p>';
    echo '<label>GitHub読取りトークン（公開リポジトリは通常不要）<input type="password" name="gh_token" value="" maxlength="256" autocomplete="new-password" placeholder="'.($options['gh_secret']!==''?'保存済み（空欄で保持）':'未設定').'"></label><label><input type="checkbox" name="clear_gh" value="1">保存したGitHubキーを削除する</label><p>非公開リポジトリは対象リポジトリのRepository security advisories読取り権限が必要です。未公開の報告・本文は取得しません。選択と設定後は上部の再チェックで保存・照合します。</p></div></details>';
}

/** Deliberately small comparison grammar; unsupported ranges remain unresolved. */
function wphm_sc_github_range(string $text): ?array {
    if(strlen($text)>160) return null;
    $parts=explode(',',$text); if(count($parts)>2) return null;
    $range=[];
    foreach($parts as $part) {
        if(!preg_match('/^\s*(<=|>=|<|>|=|==)\s*([0-9][0-9A-Za-z.+_-]{0,39})\s*$/D',$part,$m)) return null;
        $side=in_array($m[1],['>','>='],true)?'min':'max';
        if(isset($range[$side.'_version'])) return null;
        $range[$side.'_version']=$m[2]; $range[$side.'_operator']=['<'=>'lt','<='=>'le','>'=>'gt','>='=>'ge','='=>'eq','=='=>'eq'][$m[1]];
    }
    if(count($parts)===2 && in_array($range['max_operator'] ?? '',['eq'],true)) return null;
    return $range;
}

function wphm_sc_vendor_record($data, array $target): ?array {
    if(!is_array($data) || count($data)>20 || array_keys($data)!==($data?range(0,count($data)-1):[])) return null;
    $out=['kind'=>$target['kind'],'slug'=>$target['slug'],'version'=>$target['version'],'provider'=>$target['provider_key'],'checked_at'=>time(),'unresolved'=>count($data)===20?1:0,'closed'=>false,'matches'=>[],'affected'=>0,'cwe'=>[]];
    foreach($data as $advisory) {
        if(!is_array($advisory) || ($advisory['state'] ?? '')!=='published' || !is_string($advisory['ghsa_id'] ?? null) || !preg_match('/^GHSA-[a-z0-9]{4}-[a-z0-9]{4}-[a-z0-9]{4}$/D',$advisory['ghsa_id']) || !is_array($advisory['vulnerabilities'] ?? null) || count($advisory['vulnerabilities'])>50) return null;
        if(($advisory['html_url'] ?? '')!=='https://github.com/'.$target['vendor']['repository'].'/security/advisories/'.$advisory['ghsa_id']) return null;
        if(!$advisory['vulnerabilities']) { $out['unresolved']++; continue; }
        if(!empty($advisory['withdrawn_at'])) { $out['unresolved']++; continue; }
        $cwes=[]; foreach(array_slice(is_array($advisory['cwe_ids'] ?? null)?$advisory['cwe_ids']:[],0,10) as $cwe) if(is_string($cwe) && preg_match('/^CWE-[0-9]{1,5}$/D',$cwe)) $cwes[]=$cwe;
        foreach($advisory['vulnerabilities'] as $vuln) {
            if(!is_array($vuln) || !is_array($vuln['package'] ?? null) || !is_string($vuln['package']['name'] ?? null) || !is_string($vuln['package']['ecosystem'] ?? null)) return null;
            if($vuln['package']['name']!==$target['vendor']['package'] || $vuln['package']['ecosystem']!==$target['vendor']['ecosystem']) continue;
            $range=is_string($vuln['vulnerable_version_range'] ?? null)?wphm_sc_github_range($vuln['vulnerable_version_range']):null;
            $affected=$range===null?null:wphm_sc_range($target['version'],$range);
            if($affected===null) { $out['unresolved']++; continue; }
            if(!$affected) continue;
            $out['affected']++; $out['cwe']=array_values(array_unique(array_merge($out['cwe'],array_intersect($cwes,wphm_sc_risk_cwes()))));
            if(count($out['matches'])<20) $out['matches'][]=['name'=>sanitize_text_field(substr(is_string($advisory['summary'] ?? null)?$advisory['summary']:$advisory['ghsa_id'],0,300)),'sources'=>[['name'=>$advisory['ghsa_id'],'url'=>'https://github.com/'.$target['vendor']['repository'].'/security/advisories/'.$advisory['ghsa_id']]],'cwe'=>$cwes,'conditions'=>'配布元が公開した版条件。登録した製品対応に基づく照合。','fix'=>sanitize_text_field(substr(is_string($vuln['patched_versions'] ?? null)?$vuln['patched_versions']:'配布元を確認',0,100)),'range'=>$range];
            else $out['unresolved']++;
        }
    }
    while(strlen(serialize($out))>32768 && count($out['matches'])>1) { array_pop($out['matches']); $out['unresolved']++; }
    return strlen(serialize($out))<=32768?$out:null;
}

function wphm_sc_vendor_fetch(array $target, ?string &$error): ?array {
    $headers=['Accept'=>'application/vnd.github+json','X-GitHub-Api-Version'=>'2022-11-28'];
    try { $secret=wphm_sc_options()['gh_secret']; $token=$secret!==''?wphm_sc_secret($secret,true):''; } catch(Throwable $failure) { $error='credential'; return null; }
    if($secret!=='' && $token==='') { $error='credential'; return null; }
    if($token!=='') $headers['Authorization']='Bearer '.$token;
    $data=wphm_sc_http('https://api.github.com/repos/'.$target['vendor']['repository'].'/security-advisories?per_page=20&state=published',null,$headers,$error);
    return $data!==null?wphm_sc_vendor_record($data,$target):null;
}
