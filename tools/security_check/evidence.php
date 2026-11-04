<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_evidence_html(array $facts): void {
    $telemetry=$facts['telemetry'] ?? []; $identity=$facts['identity'] ?? []; $files=$facts['files'] ?? []; $audit=$facts['audit'] ?? []; $vulns=$facts['vulnerabilities'] ?? []; $cf=$facts['cloudflare'] ?? []; $server=$facts['server'] ?? [];
    echo '<details class="wphm-panel wphm-sc-details wphm-sc-evidence"><summary>取得した情報・確認できなかった範囲</summary>';
    $lines=[];
    if (!empty($telemetry['available'])) {
        $lines[]='独立集計：開始 ' . ($telemetry['started'] ? wphm_format_timestamp($telemetry['started']) : '未確認') . '。観測された分数 ' . $telemetry['minutes'] . '分、最初 ' . ($telemetry['from'] ? wphm_format_timestamp($telemetry['from']) : '未計測') . '、最後 ' . ($telemetry['to'] ? wphm_format_timestamp($telemetry['to']) : '未計測') . '。';
        $lines[]='保存枠は最大1,568行、各計数は1分60,000まで。上限到達：' . ($telemetry['saturated'] ? 'あり' : 'なし') . '。入力分類の省略・切詰め：' . $telemetry['truncated'] . '件。認証集中は64共有枠の概数です。WordPress到達前の通信と停止期間は観測できません。';
    } else $lines[]='独立集計：未計測。初回の画面表示後、以後のイベントを集計します。';
    $logs=$facts['logs'];
    $lines[]='従来ログの抽出：' . ($logs['available'] ? $logs['sampled'] . '件、最初 ' . (!empty($logs['from']) ? wphm_format_timestamp($logs['from']) : '未計測') . '、最後 ' . (!empty($logs['to']) ? wphm_format_timestamp($logs['to']) : '未計測') : '未計測') . '。ボット除外・記録頻度制限・抽出上限のあるログです。';
    if ($identity) {
        $lines[]='利用者は管理者を優先し、1処理200人まで分割確認します。画面を閉じると続行を止め、未完了は未確認を維持します。役割・関連する認証メタ情報の変更で過去の集計を無効化します。';
        $lines[]='認証イベントは標準の失敗・wp_login・アプリケーションパスワードのフックから集計します。XML-RPCの成功・SSO・独自認証など、全経路の最終ログイン成否は未確認です。';
        $lines[]='既存利用者 ' . $identity['checked'] . '人を確認。人数上限：' . ($identity['limited'] ? '到達（残りは未確認）' : '未到達') . '。XML-RPC認証設定 ' . ($identity['xmlrpc_auth'] ? '有効' : '無効') . '、アプリケーションパスワード受付 ' . ($identity['application_passwords']===null ? '未確認' : ($identity['application_passwords'] ? '有効' : '無効')) . '、確認範囲の登録利用者 ' . $identity['app_password_users'] . '人。2FAの全認証経路への強制は未確認です。';
        foreach ($identity['roles'] as $role) $lines[]='役割 ' . $role['name'] . '：' . implode(', ',$role['capabilities']) . '。役割の設定値と利用者の実効権限は区別しています。';
    }
    if ($files) {
        $lines[]='アップロード先の読取り：' . min(500,$files['scanned']) . '項目、スクリプト形式 ' . $files['scripts'] . '件、上限・読取り不能・リンク除外 ' . ($files['limited'] ? 'あり' : 'なし') . '。最大40ディレクトリ・500項目で停止し、ファイル内容は読みません。';
        $lines[]='現在の利用者に許可された実行形式：' . (empty($files['types_checked']) ? '取得失敗' : ($files['executable_types'] ? implode(', ',$files['executable_types']) : '確認した形式に該当なし')) . '。.htaccessの禁止記述候補 ' . ($files['rule_hint'] ? 'あり' : '未確認') . '。記述の存在からサーバーの実効設定は推測しません。';
        $lines[]='登録された公開AJAX ' . $files['public_ajax'] . '件（アップロード機能かは未監査）。有効な既知のコード追加機能：' . ($files['code_tools'] ? implode(', ',$files['code_tools']) : '検出なし・独自機能は未確認') . '。';
    }
    if ($audit) {
        $lines[]='本プラグインの配布時監査：' . ($audit['known'] ? '対象ファイル一致' : '未確認・不一致') . '。対象 ' . $audit['files'] . '、不一致 ' . $audit['changed'] . 'ファイル。';
        foreach ($audit['groups'] ?? [] as $group) if (is_string($group)) $lines[]=$group;
    }
    if ($server) {
        $valid=$server['attested_at']>0 && $server['attested_at']<=time()+60 && $server['attested_at']>=time()-7*DAY_IN_SECONDS;
        $labels=['unknown'=>'未確認','yes'=>'はい','no'=>'いいえ'];
        $lines[]='管理者申告：アップロード先の実行禁止 ' . ($labels[$server['uploads_execution']] ?? '未確認') . '、提供元PHP修正 ' . ($labels[$server['vendor_patches']] ?? '未確認') . '。' . ($valid ? '申告日時 ' . wphm_format_timestamp($server['attested_at']) : '未申告または期限切れ') . '。自動検証の判定には置き換えません。';
        if ($server['evidence']) $lines[]='申告の根拠：' . $server['evidence'];
    }
    $php=$facts['updates']['php'];
    $lines[]='PHPサポートの参照元：' . ($php['source'] ?? '未確認') . '。取得日時 ' . (!empty($php['checked_at']) ? wphm_format_timestamp($php['checked_at']) : '未確認') . '。';
    if ($cf) {
        $lines[]='Cloudflare：' . $cf['state'] . '。取得日時 ' . ($cf['checked_at'] ? wphm_format_timestamp($cf['checked_at']) : '未確認') . '。統計が取得できてもDDoS耐性全体は未確認です。';
        $lines[]='WAF遮断記録：' . (($cf['blocked'] ?? null)===null ? '取得失敗・未設定' : '最大100件の直近サンプル中 '.$cf['blocked'].'件／取得 '.$cf['events'].'件') . '。取得期間は直近の完了した24時間です。個々のIP・URL・利用者情報は取得・保存しません。';
    }
    foreach ($lines as $line) echo '<p>' . esc_html($line) . '</p>';
    echo '</details>';
    echo '<details class="wphm-panel wphm-sc-details wphm-sc-vulnerabilities" id="wphm-sc-vulnerabilities"><summary>既知脆弱性の照合結果・対象製品</summary>';
    echo '<p>WPVulnerabilityの公開情報を識別名と版で照合します。独自配布・同名製品・改名・未知の欠陥は確認できません。0件は安全の証明ではありません。MUプラグイン・ドロップインは一覧に含め、照合未対応として数えます。</p>';
    foreach ($vulns['matches'] ?? [] as $match) {
        echo '<article class="wphm-sc-vulnerability"><h3>' . esc_html($match['component'] . ' ' . $match['version'] . '：' . $match['name']) . '</h3>';
        $range=$match['range']; $terms=[];
        foreach (['min','max'] as $side) if (isset($range[$side.'_version'],$range[$side.'_operator']) && is_scalar($range[$side.'_version']) && is_scalar($range[$side.'_operator'])) $terms[]=$range[$side.'_operator'] . ' ' . $range[$side.'_version'];
        echo '<p>' . esc_html('影響条件：' . ($terms ? implode(' / ',$terms) : '照会したコア版に対する情報') . '。修正版の目安：' . $match['fix'] . '。' . $match['conditions'] . '。取得 ' . wphm_format_timestamp($match['checked_at'])) . '</p>';
        foreach ($match['sources'] as $source) echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url($source['url'],['https']) . '">' . esc_html($source['name']) . '</a></p>';
        echo '</article>';
    }
    if (empty($vulns['matches'])) echo '<p>表示できる影響版候補はありません。未照合の製品・通信失敗・情報不足は別に確認してください。</p>';
    $statuses=['checked'=>'照合済み','conditional'=>'条件・表示範囲に未確認あり','failed'=>'取得失敗','pending'=>'照合待ち','unsupported'=>'確認範囲外','disabled'=>'要設定'];
    echo '<ul class="wphm-sc-products">';
    foreach ($vulns['products'] ?? [] as $product) echo '<li><strong>' . esc_html($product['name'].' '.$product['version']) . '</strong><span>' . esc_html(($statuses[$product['status']] ?? '未確認').'：'.$product['reason']) . '</span><small>' . esc_html($product['checked_at'] ? '情報取得 '.wphm_format_timestamp($product['checked_at']) : '情報取得日時なし') . '</small></li>';
    echo '</ul>';
    if(!empty($vulns['limited'])) echo '<p>製品数または保存容量の上限に達しています。上限外の製品・省略した情報を安全とは判定しません。</p>';
    foreach ($facts['inventory'] ?? [] as $group=>$items) {
        $labels=['plugins'=>'プラグイン','themes'=>'テーマ','mu_plugins'=>'MUプラグイン','dropins'=>'ドロップイン'];
        echo '<h3>' . esc_html($labels[$group] ?? $group) . '</h3><ul>';
        foreach ($items as $item) echo '<li>' . esc_html($item['name'] . ' ' . $item['version']) . '</li>';
        echo '</ul>';
    }
    echo '<p><a href="https://docs.wpvulnerability.com/" target="_blank" rel="noopener noreferrer">WPVulnerability API・データの出典</a></p></details>';
}
