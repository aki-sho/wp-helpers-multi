<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_reading_fields_html(): void {
    $scopes=wphm_sc_reading_scopes();
    echo '<details class="wphm-sc-connection" id="wphm-sc-connect-wordpress" data-sc-connection="wordpress" open><summary>WordPressの接続設定</summary><div class="wphm-sc-connection-body">';
    echo '<p class="description">このWordPressの設定とローカルファイルを読み取ります。FTP・APIキーの入力は不要です。</p>';
    echo '<label>WordPressの設置フォルダ<input class="wphm-sc-root" type="text" readonly aria-readonly="true" autocomplete="off" value="'.esc_attr(wphm_sc_installation_path()).'"></label>';
    echo '<p class="description">利用者ごとの設置先を自動取得しています。テーマ・プラグイン等が別の場所にある場合も、WordPressが使用する実際の配置を参照します。</p>';
    echo '<input type="hidden" name="wphm_sc_reading_form" value="1"><fieldset class="wphm-sc-reading-choices"><legend>読取り対象</legend>';
    foreach (wphm_sc_reading_groups() as $key=>$label) echo '<label><input type="checkbox" name="wp_read_'.esc_attr($key).'" value="1" '.checked($scopes[$key],true,false).'>'.esc_html($label).'</label>';
    echo '</fieldset><div class="wphm-sc-local-actions"><button type="submit" class="button" data-sc-read-wordpress>読取りを確認</button><a href="#wphm-sc-reading-results" data-sc-reading-results>直近の読取り結果を見る</a></div>';
    echo '<p class="description">「読取りを確認」は再チェックと同じ更新操作です。選択中の取得元・公開照合も更新します。コードは実行せずに解析し、記述を見つけただけでは対策を有効と判定しません。</p></div></details>';
}

function wphm_sc_reading_results_html(array $facts): void {
    $reading=$facts['reading'] ?? [];
    $labels=['skip'=>'未選択','pending'=>'未実行','running'=>'読取り中','done'=>'参照済み','partial'=>'一部未取得'];
    echo '<details class="wphm-panel wphm-sc-details" id="wphm-sc-reading-results"><summary>WordPressの読取り結果 <span class="wphm-sc-detail-count">'.(int)($reading['files'] ?? 0).'ファイル・保存コード</span></summary>';
    if (!empty($reading['checked_at'])) echo '<p class="description">取得：'.esc_html(wphm_format_timestamp($reading['checked_at'])).'。記述の検出と対策の有効性は区別します。</p>';
    foreach (wphm_sc_reading_groups() as $key=>$label) {
        $row=$reading['rows'][$key] ?? ['status'=>'pending','files'=>0,'bytes'=>0,'issues'=>[]];
        $status=$row['status'];
        echo '<details class="wphm-sc-reading-row" id="wphm-sc-reading-row-'.esc_attr($key).'"><summary><span>'.esc_html($label).'</span><span class="wphm-sc-read-badge">'.esc_html($labels[$status] ?? '未確認').'</span></summary><div>';
        if ($status==='skip') echo '<p>今回はこの範囲を読み取っていません。対策がないという意味ではありません。</p>';
        elseif ($status==='pending') echo '<p>「再チェック」または「読取りを確認」で取得します。</p>';
        else {
            echo '<p>'.($key==='settings'?'WordPressの設定値・権限等を参照しました。各対策の取得可否は確認項目で表示します。':esc_html(number_format((int)$row['files']).'ファイル・保存コード／'.number_format((int)$row['bytes']).'バイトを解析しました。')).'</p>';
            if ($status==='running') echo '<p>分割して読取り中です。続行・一時停止の操作を使えます。</p>';
        }
        foreach ($row['issues'] as $issue) echo '<p class="description">'.esc_html(wphm_sc_reading_reason($issue)).'</p>';
        foreach ($reading['problems'] ?? [] as $problem) if ($problem['group']===$key) echo '<p><code>'.esc_html($problem['file']).'</code>：'.esc_html(wphm_sc_reading_reason($problem['code'])).'</p>';
        $locations=array_filter($reading['locations'] ?? [],function($location)use($key){return $location['group']===$key;});
        if ($locations) { echo '<ul class="wphm-sc-paths">'; foreach ($locations as $location) echo '<li>'.esc_html($location['label']).'<code>'.esc_html($location['path']).'</code></li>'; echo '</ul>'; }
        if ($key==='snippets') echo '<p class="description">Code Snippetsの対応する保存形式から、有効なPHPスニペットを上限付きで参照します。別形式・ネットワーク共有の適用条件など、取得できない範囲は理由を表示します。</p>';
        echo '</div></details>';
    }
    echo '<p class="description">1処理最大100対象・約2秒、1ファイル256KiB、全体1,000ファイル・保存コード／16MiB、深さ12、分割処理は最長10分です。対象外のリンク・上限・未対応形式は確認済みにしません。検出位置は各対策で最大8件表示します。</p></details>';
    $env=$facts['environment'] ?? [];
    echo '<details class="wphm-panel wphm-sc-details" id="wphm-sc-environment"><summary>PHP環境の参照情報</summary><p>PHP '.esc_html($env['php'] ?? PHP_VERSION).'／最大実行時間 '.(int)($env['max_execution_seconds'] ?? 0).'秒。</p><p class="description">このWordPressを動かすPHPから直接取得する情報です。サーバー会社のAPI接続情報は不要です。Webサーバー全体の設定とは取得範囲が異なります。</p></details>';
}

function wphm_sc_api_results_html(array $facts): void {
    $selection=$facts['acquisition']['selected'] ?? [];
    if (empty($selection['server']) && empty($selection['edge'])) return;
    $statuses=wphm_sc_source_labels();
    $capabilities=['waf'=>'管理WAFの有効設定','uploads_execution'=>'アップロード先の実行禁止','public_boundary'=>'公開範囲の制限','sql_rules'=>'SQL用ルール','xss_rules'=>'XSS用ルール','rce_rules'=>'RCE用ルール','upload_rules'=>'アップロード用ルール','vendor_patches'=>'提供元の修正情報'];
    echo '<details class="wphm-panel wphm-sc-details" id="wphm-sc-api-results"><summary>API連携の取得結果</summary>';
    foreach (['server','edge'] as $source) {
        if (empty($selection[$source])) continue;
        echo '<h3>'.esc_html(wphm_sc_source_names()[$source]).'</h3>';
        if ($source==='server' && wphm_sc_options()['server_provider']==='local') { echo '<p>サーバー管理APIは使用していません。PHP環境は追加接続なしで別に参照します。</p>'; continue; }
        $row=$facts['acquisition']['sources'][$source] ?? ['status'=>'pending'];
        echo '<p>'.esc_html($row['name'] ?? '').'：'.esc_html($statuses[$row['status']] ?? '未確認').'</p><p>'.esc_html(wphm_sc_source_reason($row)).'</p>';
        foreach ($capabilities as $key=>$label) if (is_bool($row['capabilities'][$key] ?? null)) echo '<p>'.esc_html($label).'：'.($row['capabilities'][$key]?'有効の設定値を取得':'無効の設定値を取得').'</p>';
        if (is_array($row['traffic'] ?? null)) echo '<p>取得した通信統計：'.number_format((int)$row['traffic']['requests']).'件／'.(int)$row['traffic']['window_seconds'].'秒の範囲。</p>';
        echo '<p><a href="#wphm-sc-connect-'.esc_attr($source).'" data-sc-connect="'.esc_attr($source).'">接続設定を開く</a></p>';
    }
    echo '<p class="description">管理WAF全体の有効設定や通信統計だけでは、攻撃種類ごとの防御やサイト全体の安全を確認済みにしません。接続ID・キー・API応答の原文は表示しません。</p></details>';
}
