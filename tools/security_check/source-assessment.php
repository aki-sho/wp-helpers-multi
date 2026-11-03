<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_checks_level(array $checks): string {
    $required=array_values(array_filter($checks,function($check){ return empty($check['reference']) && (($check['status'] ?? '')!=='skip' || !empty($check['must_confirm'])); }));
    $statuses=array_column($required,'status');
    if(in_array('issue',$statuses,true)) return 'high';
    if(in_array('attention',$statuses,true)) return 'medium';
    if(!$required || array_diff($statuses,['ok'])) return 'unknown';
    return 'low';
}

/** Rendered check rows and risk always use the same, source-specific facts. */
function wphm_sc_source_assessment(array $facts, array $items): array {
    $acquisition=$facts['acquisition']; $selected=$acquisition['selected']; $sources=$acquisition['sources'];
    $identity=$facts['identity']; $files=$facts['files']; $audit=$facts['audit']; $updates=$facts['updates']; $v=$facts['vulnerabilities']; $options=wphm_sc_options();
    $public=$options['updates'] || $options['vulnerabilities'];
    $add=function(string $id,string $label,string $status,string $value,array $locations=['wordpress'],string $help='',bool $reference=false,bool $public_fact=false) use (&$items,$sources,$selected): void {
        $known=false; foreach($locations as $source) if(($sources[$source]['status'] ?? '')==='ok' || ($sources[$source]['status'] ?? '')==='partial') $known=true;
        if(!$public_fact && !array_filter($locations,function($source) use($selected){return !empty($selected[$source]);})) { $status='skip'; $value='今回はこの場所を調査していません。'; $known=false; }
        if($public_fact) $known=!in_array($status,['pending','setup','error','skip'],true);
        $known=$known && !in_array($status,['pending','setup','error','skip'],true);
        $items[$id]['checks'][]=['label'=>$label,'status'=>$status,'value'=>$value,'help'=>$help,'sources'=>$locations,'source_confirmed'=>$known,'reference'=>$reference,'must_confirm'=>$public_fact && !$reference];
    };
    $old=[];
    foreach($items as $id=>&$item) { $old[$id]=$item['checks'] ?? []; $item['checks']=[]; $item['sources']=$sources; }
    unset($item);

    $limits=[]; $origins=[]; $texts=[]; $disabled=false;
    foreach($sources as $source=>$row) foreach($row['login_limits'] ?? [] as $limit) {
        if(empty($limit['applied']) || !in_array('wp_login',$limit['routes'] ?? [],true)) continue;
        $limits[]=$limit; if(!in_array($source,$origins,true)) $origins[]=$source;
        $disabled=$disabled || empty($limit['enabled']);
        $texts[]=wphm_sc_source_names()[$source].'：'.$limit['name'].'／'.(!empty($limit['enabled'])?$limit['tries'].'回'.(!empty($limit['period'])?'・計数'.$limit['period'].'秒':'・計数期間未取得').'／停止'.$limit['seconds'].'秒':'制限は無効');
    }
    $enabled=array_filter($limits,function($limit){return !empty($limit['enabled']);});
    $complete_limits=array_filter($enabled,function($limit){return !empty($limit['period']);});
    $limit_error=!empty($identity['limiter_error']);
    $add('brute_force','ログイン試行制限（標準ログイン）',$complete_limits?'ok':($disabled?'attention':($limit_error?'error':'pending')),$texts?implode(' ／ ',array_slice($texts,0,20)):'設定と適用先を裏付けられる試行制限は、今回取得できていません。',$origins?:array_keys(wphm_sc_source_names()),'回数・計数期間・停止時間・適用先が確認できた設定を採用します。機能名やフックの存在だけで有効とは判定しません。別の認証経路全体を保証する判定ではありません。');
    $items['brute_force']['checks'][0]['source_confirmed']=(bool)$limits;
    if(($sources['edge']['limit_status'] ?? '')==='permission') $items['brute_force']['checks'][0]['help'].=' 外部の回数制限ルールはAPIの読取り権限が不足しています。';
    foreach($old['brute_force'] as $check) if($check['label']!=='ログイン試行制限') {
        if($check['label']==='認証記録' && !empty($facts['telemetry']['available'])) $check['status']='ok';
        $add('brute_force',$check['label'],$check['status'],$check['value'],['wordpress'],$check['help']);
    }
    $discovery=$sources['wordpress']['discovery'] ?? [];
    $owners=[];
    foreach($discovery['callbacks'] ?? [] as $callback) $owners[]=$callback['owner'].'：'.$callback['hook'];
    $add('brute_force','認証処理の発見',!empty($discovery['limited'])?'pending':'ok',$owners?implode(' ／ ',array_slice(array_unique($owners),0,12)):'登録中の追加認証処理の候補は確認されていません。',['wordpress'],'プラグイン・MU・テーマ・独自処理を発見します。未対応の設定は未確認です。発見だけで制限の有効・無効は判断しません。',true);

    foreach($old['credential_stuffing'] as $check) {
        if($check['label']==='管理者の追加認証') { if($check['status']==='setup') $check['status']='pending'; $check['help']='標準ログインの利用者ごとの設定を確認します。取得元：'.implode('・',$identity['mfa_providers']).'。読み取れない設定・独自認証は未確認です。'; }
        if($check['label']==='別の認証経路') { $check['status']=($identity['app_password_users'] ?? 0)>0?'attention':'ok'; }
        $add('credential_stuffing',$check['label'],$check['status'],$check['value'],['wordpress'],$check['help']);
    }

    foreach(['sql_injection'=>'SQL処理','xss'=>'出力処理','csrf'=>'操作受付・nonce・権限・POST制限','directory_traversal'=>'ファイル参照の固定起点・境界'] as $id=>$description) {
        $add($id,'本プラグインの'.$description,!empty($audit['known'])?'ok':'pending',!empty($audit['known'])?'WP Helpers Multi '.$audit['version'].'／確認対象'.$audit['files'].'ファイルが配布時監査と一致。':'配布時監査の対象ファイルとの一致を確認できません。',['wordpress'],'低は本プラグインの確認対象だけの評価です。他の製品・未知の欠陥・侵害の有無を確認した意味ではありません。');
    }
    $add('xss','公開登録者のHTML権限',!empty($facts['registration']) && !empty($facts['raw_html'])?'issue':'ok',!empty($facts['registration']) && !empty($facts['raw_html'])?'公開登録者の初期ロールに強いHTML編集権限があります。':'標準登録設定の範囲では、公開登録者の初期ロールに強いHTML編集権限は確認されていません。');
    $edge=$sources['edge'];
    foreach(['sql_injection'=>'sql_rules','xss'=>'xss_rules','rce'=>'rce_rules','file_upload'=>'upload_rules','directory_traversal'=>'public_boundary'] as $id=>$capability) {
        $known=array_key_exists($capability,$edge['capabilities'] ?? []) && $edge['capabilities'][$capability]!==null;
        $add($id,'関連する外部の防御設定',$known?'ok':'pending',$known?($edge['capabilities'][$capability]?'対象の関連設定が有効です。':'対象の関連設定は無効です。'):'この接続から関連する設定の有効・無効を確認できていません。',['edge'],'参考情報です。WAF等の有無だけでアプリの欠陥の修正済み判定や安全判定は行いません。',true);
    }

    foreach($old['ddos'] as $check) {
        $external=in_array($check['label'],['CDN・WAFの情報','CDN通信量','管理WAFの呼出設定','WAFの遮断記録'],true);
        if($external) continue;
        $add('ddos',$check['label'],$check['status'],$check['value'],['wordpress'],$check['help'],$check['label']==='通常時との比較');
    }
    foreach(['server','edge'] as $source) {
        $row=$sources[$source]; $traffic=$row['traffic'] ?? null;
        $status=$traffic!==null?'ok':(in_array($row['status'],['error','setup'],true)?$row['status']:'pending');
        $value=$traffic!==null?'取得期間'.(int)$traffic['window_seconds'].'秒／通信'.(int)$traffic['requests'].'件':'通信・負荷の統計は、この取得元から取得できていません。';
        if(isset($traffic['peak'],$traffic['errors']) && ($traffic['peak']>=$options['traffic_peak'] || $traffic['errors']>=$options['http_errors'])) $status='attention';
        $add('ddos',$source==='server'?'サーバーの通信・負荷':'外部の通信統計',$status,$value,[$source],'取得できた層と期間の統計です。回線全体のDDoS耐性や攻撃成立を保証しません。',empty($selected[$source]));
    }

    $code_warning=!empty($facts['file_edit_allowed']) || ($identity['code_users'] ?? 0)>0 || !empty($files['code_tools']);
    $add('rce','実行コードの編集・追加権限',$code_warning?'attention':(!empty($identity['limited'])?'pending':'ok'),'確認した利用者'.$identity['checked'].'人／実行コードの編集・追加権限'.$identity['code_users'].'人',['wordpress'],'用途と運用を確認する権限の情報です。権限があることをRCE成立とは判定しません。');
    $php=$updates['php'];
    $add('rce','PHP上流のセキュリティサポート',!$options['updates']?'skip':(!empty($php['known'])?($php['secure']?'ok':'issue'):'pending'),'PHP '.$php['version'].(!empty($php['known'])?($php['secure']?'：上流のサポート対象':'：上流のサポート対象外'):'：サポート情報を取得できません。'),['server'],'公開情報の照合でローカルPHP版をWordPress.orgに照会します。提供元独自パッチは別の情報です。',false,true);
    $patch=$sources['server']['capabilities']['vendor_patches'] ?? null;
    $add('rce','提供元の独自修正',$patch===null?'pending':'ok',$patch===null?'提供元の自動取得情報は未確認です。':($patch?'提供元の修正提供がある設定を取得しました。':'提供元の修正提供はない設定を取得しました。'),['server'],'上流サポートの判定を上書きする情報ではありません。',true);
    $local=$sources['server']['local_php'] ?? null;
    $add('rce','ローカルPHP環境',$local!==null?'ok':'pending',$local!==null?'PHP '.$local['version'].'／PHP標準アップロード受信'.($local['file_uploads']===null?'未確認':($local['file_uploads']?'有効':'無効')).'／最大実行時間'.$local['max_execution_seconds'].'秒':'この取得方法ではローカルPHP環境の補助情報を取得していません。',['server'],'PHP設定の読取りです。実際のコード実行や攻撃試験は行いません。',true);
    $boundary=$sources['server']['capabilities']['public_boundary'] ?? null;
    $add('directory_traversal','サーバーの公開範囲',$boundary===null?'pending':($boundary?'ok':'attention'),$boundary===null?'公開範囲の実効制限は、この読取り連携から取得できていません。':($boundary?'対象サイトの公開範囲の制限を取得しました。':'対象の公開範囲の制限は無効です。'),['server'],'本プラグインのパス処理とサーバーの公開設定は別の根拠です。',empty($selected['server']));

    foreach($old['file_upload'] as $check) if($check['label']!=='サーバーの実行禁止') $add('file_upload',$check['label'],$check['status'],$check['value'],['wordpress'],$check['help']);
    $execution=$sources['server']['capabilities']['uploads_execution'] ?? null;
    $add('file_upload','保存先のスクリプト実行禁止',$execution===null?(($sources['server']['status'] ?? '')==='error'?'error':'pending'):($execution?'ok':'attention'),$execution===null?'保存先の実行禁止を裏付ける自動取得情報は未確認です。':($execution?'対象サイトのアップロード保存先に実行禁止を確認しました。':'対象の保存先に実行禁止がない設定です。'),['server'],'PHPの種類制限や.htaccessの記述だけでは実効設定を推測しません。管理者メモは自動検証とは別に扱います。',empty($selected['server']));
    if(!empty($facts['registration']) && !empty($facts['raw_upload']) && !empty($facts['unfiltered_uploads'])) $add('file_upload','公開登録者の未検査アップロード','issue','公開登録者に未検査アップロードの強い権限がある構成です。');

    foreach($old['vulnerability_exploit'] as $check) {
        if($check['label']==='既知脆弱性の照合' && $check['status']==='attention') $check['status']='pending';
        $reference=$check['label']==='公式更新情報' && !$options['updates'];
        if(($check['label']==='既知脆弱性の照合' || $check['label']==='影響版への該当') && !$options['vulnerabilities']) $check['status']='skip';
        if($check['label']==='公式更新情報' && !$options['updates']) $check['status']='skip';
        if($check['label']==='照合できない製品' && !$options['vulnerabilities']) $check['status']='skip';
        $add('vulnerability_exploit',$check['label'],$check['status'],$check['value'],['wordpress'],$check['help'].' 対象はWordPress内の製品・版です。公開情報との照合はCDN・WAFとは別です。',$reference,true);
    }
    $mapping=['CWE-89'=>'sql_injection','CWE-79'=>'xss','CWE-352'=>'csrf','CWE-78'=>'rce','CWE-94'=>'rce','CWE-434'=>'file_upload','CWE-22'=>'directory_traversal','CWE-23'=>'directory_traversal','CWE-36'=>'directory_traversal'];
    $affected_ids=[]; foreach($v['cwe'] ?? [] as $cwe) if(isset($mapping[$cwe])) $affected_ids[$mapping[$cwe]]=true;
    foreach($v['matches'] ?? [] as $match) foreach($match['cwe'] as $cwe) if(isset($mapping[$cwe])) $affected_ids[$mapping[$cwe]]=true;
    foreach(array_keys($affected_ids) as $id) $add($id,'関連する既知脆弱性の影響版','issue','取得した製品・版が、関連する既知脆弱性の影響条件に一致する候補です。',['wordpress'],'照合元・製品・必要権限・修正版は下部の詳細で確認できます。悪用や侵害の成立は未確認です。',false,true);
    if(($updates['core']['status'] ?? '')==='insecure') $add('vulnerability_exploit','コアの公式セキュリティ情報','issue','WordPress.orgが現在のコアをinsecureと判定しています。',['wordpress'],'公開情報との照合結果です。',false,true);
    foreach($items as &$item) {
        $item['level']=wphm_sc_checks_level($item['checks']);
        $reasons=['low'=>'低は確認項目で取得できた対象だけの評価です。サイト全体の安全や侵害がないことを保証しません。','medium'=>'確認項目に見直しが必要な設定・記録があります。未確認の情報は別に表示します。','high'=>'確認項目に影響版候補または強い危険設定が確認されています。侵害の成立は別です。','unknown'=>'判定に必要な情報を取得できていません。未取得や取得失敗を安全とは扱いません。'];
        $item['reason']=$reasons[$item['level']];
        $item['result_status']='確認項目の状態から判定';
        foreach($item['checks'] as &$check) if($check['status']==='setup' && !empty($check['sources']) && $check['sources']===['wordpress']) $check['status']='pending';
        unset($check);
    }
    unset($item);
    $items['csrf']['sources']['edge']['status']='na';
    return $items;
}
