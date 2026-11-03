<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_acquisition_fields_html(): void {
    $selection=wphm_sc_source_selection(); $options=wphm_sc_options(); $readers=wphm_sc_readers();
    echo '<input type="hidden" name="wphm_sc_sources_form" value="1"><div class="wphm-sc-source-options">';
    $descriptions=['wordpress'=>'本体・プラグイン・MU・テーマ・独自の認証処理と記録','server'=>'PHP環境・登録済みのホスティング読取り連携','edge'=>'Cloudflare・登録済みのCDN/WAF等の読取り連携'];
    foreach(wphm_sc_source_names() as $key=>$label) {
        echo '<div class="wphm-sc-source-option"><label><input type="checkbox" name="source_'.esc_attr($key).'" value="1" data-sc-source="'.esc_attr($key).'" '.checked($selection[$key],true,false).'><strong>'.esc_html($label).'</strong></label><p>'.esc_html($descriptions[$key]).'</p>';
        if($key!=='wordpress') echo '<a class="wphm-sc-source-link" href="#wphm-sc-connect-'.esc_attr($key).'" data-sc-connect="'.esc_attr($key).'">接続設定</a>';
        else echo '<small>通常は接続設定不要</small>';
        echo '</div>';
    }
    echo '</div>';
    foreach(['server','edge'] as $source) {
        echo '<details class="wphm-sc-connection" id="wphm-sc-connect-'.esc_attr($source).'" data-sc-connection="'.esc_attr($source).'"'.($selection[$source]?' open':'').'><summary>'.esc_html(wphm_sc_source_names()[$source]).'の接続設定</summary><div class="wphm-sc-connection-body">';
        echo '<label>取得方法<select name="'.esc_attr($source).'_provider" data-sc-provider="'.esc_attr($source).'">';
        $choices=$source==='server'?['local'=>'ローカルPHP情報（追加接続不要）']:['cloudflare'=>'Cloudflare'];
        foreach($readers as $id=>$reader) if($reader['source']===$source) $choices[$id]=$reader['name'];
        if(!isset($choices[$options[$source.'_provider']])) $choices[$options[$source.'_provider']]='以前の連携（現在は未登録）';
        foreach($choices as $value=>$label) echo '<option value="'.esc_attr($value).'" '.selected($options[$source.'_provider'],$value,false).'>'.esc_html($label).'</option>';
        echo '</select></label>';
        if($source==='edge') {
            echo '<div data-sc-provider-fields="cloudflare"><label>Cloudflare Zone ID<input type="text" name="cf_zone" maxlength="32" autocomplete="off" value="'.esc_attr($options['cf_zone']).'"></label><label>Cloudflare 読取りトークン<input type="password" name="cf_token" value="" maxlength="256" autocomplete="new-password" placeholder="'.($options['cf_secret']!==''?'保存済み（空欄で保持）':'未設定').'"></label><label><input type="checkbox" name="clear_cf" value="1">保存したCloudflareトークンを削除する</label><p class="description">対象ゾーンのZone Read・Analytics Read・Zone WAF Readを使用します。ドメインの一致を確認し、取得できた統計・WAF・ログイン回数制限を別々に表示します。設定変更は行いません。</p></div>';
        }
        echo '<div data-sc-reader-fields="'.esc_attr($source).'"><label>登録済み連携の接続ID<input type="text" name="'.esc_attr($source).'_connection" value="'.esc_attr($options[$source.'_connection']).'" maxlength="128" autocomplete="off"></label><label>読取りキー<input type="password" name="'.esc_attr($source).'_token" value="" maxlength="512" autocomplete="new-password" placeholder="'.($options[$source.'_secret']!==''?'保存済み（空欄で保持）':'未設定').'"></label><label><input type="checkbox" name="clear_'.esc_attr($source).'" value="1">保存した連携キーを削除する</label></div>';
        echo '<p class="description">'.($source==='server'?'ローカルで確認できるPHP情報と、ホスティング側の非公開設定は区別します。非公開設定には提供元に対応する読取り連携の登録が必要です。':'Cloudflare以外は、利用中サービスに対応する読取り連携の登録が必要です。').'URLやファイルパスを入力して取得する機能ではありません。未登録・権限不足・未取得は「未確認」と表示します。</p></div></details>';
    }
    echo '<div class="wphm-sc-reference-options"><strong>公開情報との照合</strong>';
    foreach(['updates'=>'公式の更新情報と照合する','vulnerabilities'=>'既知脆弱性の公開情報と照合する'] as $key=>$label) echo '<label><input type="checkbox" name="source_'.esc_attr($key).'" value="1" '.checked($options[$key],true,false).'>'.esc_html($label).'</label>';
    echo '<p class="description">WordPress.org・WPVulnerabilityへの製品名・版等の照会です。外部の防御サービスとは別です。WordPress内の調査を外しても、照合に必要な製品構成は取得します。接続情報や認証記録は送信しません。チェックや設定欄の開閉だけでは通信しません。</p></div>';
}

function wphm_sc_item_sources_html(array $item): void {
    if(empty($item['sources'])) return;
    $labels=wphm_sc_source_labels();
    echo '<div class="wphm-sc-source-results" aria-label="取得元ごとの状況">';
    foreach($item['sources'] as $source=>$info) {
        $status=$info['status'];
        echo '<div><strong>'.esc_html(wphm_sc_source_names()[$source]).'</strong><span class="wphm-sc-source-status">'.esc_html($labels[$status] ?? '未確認').'</span><p>'.esc_html(wphm_sc_source_reason($info)).'</p>';
        if(!empty($info['name']) && $info['name']!==wphm_sc_source_names()[$source]) echo '<small>'.esc_html($info['name']).'</small>';
        if(is_int($info['checked_at'] ?? null) && $info['checked_at']>0 && $info['checked_at']<=time()+60) echo '<small>取得 '.esc_html(wphm_format_timestamp($info['checked_at'])).'</small>';
        if($source!=='wordpress' && in_array($status,['setup','pending','unsupported','error','skip'],true)) echo '<a href="#wphm-sc-connect-'.esc_attr($source).'" class="wphm-sc-source-link" data-sc-connect="'.esc_attr($source).'">接続設定を確認</a>';
        echo '</div>';
    }
    echo '</div>';
}
