<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_control_badge(string $state): string {
    $labels=wphm_sc_control_states();
    if (!isset($labels[$state])) $state='unknown';
    return '<span class="wphm-sc-state wphm-sc-state-'.esc_attr($state).'">'.esc_html($labels[$state]).'</span>';
}

function wphm_sc_controls_table(array $checks): void {
    echo '<table class="wphm-sc-controls"><thead><tr><th scope="col">確認する対策</th><th scope="col">状態</th><th scope="col">確認先</th></tr></thead><tbody>';
    foreach ($checks as $check) {
        $target='wphm-sc-control-'.sanitize_key($check['id']);
        echo '<tr data-sc-control-row="'.esc_attr($check['id']).'" data-sc-state="'.esc_attr($check['state']).'"><th scope="row">'.esc_html($check['label']);
        foreach ($check['sources'] as $source) if (isset(wphm_sc_source_names()[$source])) echo '<span class="wphm-sc-origin">（'.esc_html(wphm_sc_source_names()[$source]).'）</span>';
        echo '</th><td>'.wphm_sc_control_badge($check['state']).'</td><td><a href="#'.esc_attr($target).'" data-sc-open-control="'.esc_attr($target).'" aria-label="'.esc_attr($check['label'].'の確認先を見る').'">確認先 ›</a></td></tr>';
    }
    echo '</tbody></table>';
    foreach ($checks as $check) wphm_sc_control_detail_html($check);
}

/** Hidden until selected; anchors provide a usable, escaped fallback without JavaScript. */
function wphm_sc_control_detail_html(array $check): void {
    $target='wphm-sc-control-'.sanitize_key($check['id']);
    echo '<article id="'.esc_attr($target).'" class="wphm-sc-control-source"><h3>'.esc_html($check['label']).'</h3>'.wphm_sc_control_badge($check['state']).'<dl>';
    if ($check['value']!=='') echo '<dt>取得した情報</dt><dd>'.esc_html($check['value']).'</dd>';
    if ($check['reason']!=='') echo '<dt>確認できない理由</dt><dd>'.esc_html($check['reason']).'</dd>';
    echo '<dt>設定・処理の確認先の例</dt><dd>'.esc_html(wphm_sc_control_place($check)).'</dd>';
    echo '<dt>次の操作</dt><dd>'.esc_html($check['action']).'</dd>';
    if ($check['help']!=='') echo '<dt>確認する内容</dt><dd>'.esc_html($check['help']).'</dd>';
    if ($check['evidence']) {
        echo '<dt>記述を検出した位置</dt><dd><ul>';
        foreach ($check['evidence'] as $row) echo '<li><code>'.esc_html($row['file']).':'.(int)$row['line'].'</code>'.(!empty($row['conditional'])?'／条件付きの記述':'').'</li>';
        echo '</ul>記述の存在だけで、実際の適用や全経路の保護を確認済みにはしません。</dd>';
    }
    echo '</dl>';
    if ($check['link']!=='') echo '<p><a href="'.esc_url($check['link']).'"'.($check['link']==='#wphm-sc-connect-wordpress'?' data-sc-connect="wordpress"':'').'>'.($check['link']==='#wphm-sc-vulnerabilities'?'照合結果を開く':'該当する取得設定を開く').'</a></p>';
    elseif (in_array('wordpress',$check['sources'],true)) echo '<p><a href="#wphm-sc-connect-wordpress" data-sc-connect="wordpress">WordPressの接続設定を開く</a></p>';
    echo '<p class="wphm-sc-nojs-close"><a href="#wphm-sc-checks">一覧に戻る</a></p></article>';
}

function wphm_sc_control_place(array $check): string {
    $places=[
        'ip_allow'=>'WordPressの独自PHPコード／.htaccess・Webサーバー／外部WAFのIP許可設定',
        'ip_deny'=>'WordPressの独自PHPコード／.htaccess・Webサーバー／外部WAFのIP拒否設定',
        'login_ip_limit'=>'WordPressの認証処理／Webサーバー／外部WAFのIP単位の制限',
        'login_account_limit'=>'WordPress・認証サービスのアカウント単位の制限処理',
        'ip_autoblock'=>'WordPressの認証処理／サーバー・外部WAFの自動遮断設定',
        'login_extra_access'=>'WordPressの独自コード／.htaccess・Webサーバーの認証・アクセス制限／外部の認証サービス',
        'file_edit_disabled'=>'wp-config.php等のDISALLOW_FILE_EDIT・DISALLOW_FILE_MODS',
        'debug_hidden'=>'wp-config.php等のWP_DEBUG・WP_DEBUG_DISPLAY',
        'db_least_privilege'=>'現在のWordPress DBユーザーに付与された権限',
        'registration_closed'=>'WordPress「設定 → 一般 → メンバーシップ」。マルチサイト・独自登録処理は別に確認',
        'registration_roles'=>'WordPress「設定 → 一般 → 新規ユーザーのデフォルト権限グループ」',
        'csp'=>'WordPressのヘッダー出力処理／.htaccess・Webサーバー／外部CDNのContent-Security-Policy',
        'frame_options'=>'WordPressのヘッダー出力処理／.htaccess・Webサーバー／外部CDNのX-Frame-Options',
        'frame_ancestors'=>'WordPressのヘッダー出力処理／.htaccess・Webサーバー／外部CDNのCSP frame-ancestors',
        'hsts'=>'WordPressのヘッダー出力処理／.htaccess・Webサーバー／外部CDNのStrict-Transport-Security',
    ];
    return $places[$check['feature']] ?? $check['place'];
}

function wphm_sc_report_html(array $report): void {
    $facts=$report['facts']; $items=wphm_sc_controls($facts); $scan=$report['scan'] ?? [];
    echo '<div class="wphm-sc-meta"><p><strong>確認日時：</strong>'.esc_html(wphm_format_timestamp($report['checked_at'])).'</p><p>横棒は対策の確認状況です。安全性の点数ではありません。</p></div>';
    if (!empty($scan['pending']) || !empty($scan['users_pending']) || !empty($scan['reading_pending'])) {
        echo '<div class="wphm-sc-scan" data-run="'.esc_attr($scan['run'] ?? '').'" role="status"><span>未照合 '.(int)($scan['pending'] ?? 0).'製品。';
        if (!empty($scan['users_pending'])) echo '利用者の設定を分割確認中。';
        if (!empty($scan['reading_pending'])) echo 'WordPress内のファイルを分割読取り中。';
        echo !empty($scan['stopped'])?'処理上限で停止しました。残りは未確認です。':'画面を離れると自動続行を停止します。';
        echo '</span>';
        if (!empty($scan['run'])) { echo '<form method="post" class="wphm-sc-continue-form" action="'.esc_url(admin_url('admin.php?page=wphm-security-check')).'">';echo str_replace('id="nonce"','id="wphm-sc-continue-nonce"',wp_nonce_field('wphm_security_check','nonce',false,false));echo '<input type="hidden" name="wphm_sc_action" value="continue"><input type="hidden" name="run" value="'.esc_attr($scan['run']).'"><button class="button" type="submit">照合を続ける</button></form>'; }
        echo '</div>';
    }
    wphm_sc_reading_results_html($facts);
    wphm_sc_api_results_html($facts);
    echo '<div class="wphm-sc-filters" hidden><label>表示<select data-sc-filter="group" aria-label="項目の分類"><option value="all">すべての項目</option>';
    foreach (wphm_sc_groups() as $key=>$label) echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>';
    echo '</select></label><label><select data-sc-filter="status" aria-label="確認結果の絞り込み"><option value="all">すべての結果</option><option value="off">設定なし・無効を含む項目</option><option value="unknown">未確認を含む項目</option></select></label><span data-sc-filter-count role="status" aria-live="polite"></span><button type="button" class="button-link" data-sc-collapse>すべて閉じる</button></div>';
    echo '<div class="wphm-sc-legend">'; foreach (wphm_sc_control_states() as $state=>$label) echo '<span><i class="wphm-sc-dot wphm-sc-dot-'.esc_attr($state).'" aria-hidden="true"></i>'.esc_html($label).'</span>'; echo '</div>';
    echo '<ol class="wphm-sc-results" id="wphm-sc-checks" aria-label="20項目のセキュリティチェック結果">';
    $legacy=array_column($report['items'] ?? [],null,'id');
    foreach ($items as $index=>$item) {
        $counts=$item['counts']; $description=$item['name'].'。設定あり・有効'.$counts['on'].'件、設定なし・無効'.$counts['off'].'件、未確認'.$counts['unknown'].'件。';
        echo '<li class="wphm-sc-item" data-check="'.esc_attr($item['id']).'" data-sc-group="'.esc_attr($item['group']).'" data-sc-off="'.(int)$counts['off'].'" data-sc-unknown="'.(int)$counts['unknown'].'"><details class="wphm-sc-accordion"><summary><div class="wphm-sc-item-title"><h2><span class="wphm-sc-number">'.($index+1).'</span>'.esc_html($item['name']).'</h2><span class="wphm-sc-counts">あり '.(int)$counts['on'].' ／ なし '.(int)$counts['off'].' ／ 未確認 '.(int)$counts['unknown'].'</span></div><div class="wphm-sc-distribution" role="img" aria-label="'.esc_attr($description).'">';
        foreach ($counts as $state=>$count) if ($count>0) echo '<span class="wphm-sc-segment-'.esc_attr($state).'" style="flex:'.(int)$count.'" aria-hidden="true"></span>';
        echo '</div></summary><div class="wphm-sc-item-body"><h3>確認項目</h3>';
        wphm_sc_controls_table(array_values(array_filter($item['checks'],function($check){return $check['kind']==='defense';})));
        $recording=array_values(array_filter($item['checks'],function($check){return $check['kind']==='detection';}));
        $observed=array_values(array_filter($legacy[$item['id']]['checks'] ?? [],function($check){return (bool)preg_match('/記録|集中|通信量|通常時|HTTPエラー|計測/u',$check['label']);}));
        if ($recording || $observed) {
            echo '<details class="wphm-sc-recording" id="wphm-sc-recording-'.esc_attr($item['id']).'"><summary>記録・通知 <span>横棒の対象外</span></summary>';
            if ($recording) wphm_sc_controls_table($recording);
            if ($observed) {
                echo '<details class="wphm-sc-observations" id="wphm-sc-observations-'.esc_attr($item['id']).'"><summary>取得した記録・集計</summary>';
                foreach (array_slice($observed,0,8) as $record) {
                    $guidance=wphm_sc_check_guidance($record,$facts,$item['id']);
                    echo '<p><strong>'.esc_html($record['label']).'</strong>：'.esc_html($guidance['unread']?$guidance['reason']:$record['value']).'</p>';
                }
                echo '</details>';
            }
            echo '</details>';
        }
        echo '</div></details></li>';
    }
    echo '</ol>';
    wphm_sc_evidence_html($facts);
    echo '<details class="wphm-panel wphm-sc-details" id="wphm-sc-report-scope"><summary>確認方法と判定の範囲</summary><p>設定値と適用先を確認できたものだけ「設定あり・有効」「設定なし・無効」と表示します。コード・設定記述の検出だけでは有効としません。場所の括弧は取得できた根拠に合わせて表示し、公開応答だけで設定元を推測しません。</p><p>通常表示は保存済みの結果を参照します。「再チェック」で選択した取得元・読取り対象・公開照合を更新します。未確認の理由と次の操作は各項目の「確認先」に表示します。</p><p>ログイン回数の制限・IP遮断・自動修正は追加しません。実際の攻撃・認証試行・ファイル送信・取得コードの実行は行いません。確認できた範囲の結果であり、未知の脆弱性やサイト全体の安全を保証する表示ではありません。</p></details>';
}
