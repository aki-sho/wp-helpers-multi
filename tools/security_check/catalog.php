<?php
if (!defined('ABSPATH')) exit;

/** Stable IDs and numbering are shared by the report, filters and regression tests. */
function wphm_sc_catalog(): array {
    return [
        'brute_force'=>['name'=>'ブルートフォース攻撃','group'=>'identity'],
        'credential_stuffing'=>['name'=>'クレデンシャルスタッフィング攻撃','group'=>'identity'],
        'sql_injection'=>['name'=>'SQLインジェクション','group'=>'processing'],
        'xss'=>['name'=>'クロスサイトスクリプティング（XSS）','group'=>'processing'],
        'ddos'=>['name'=>'DDoS攻撃','group'=>'delivery'],
        'csrf'=>['name'=>'CSRF攻撃','group'=>'processing'],
        'rce'=>['name'=>'RCE（リモートコード実行）攻撃','group'=>'processing'],
        'file_upload'=>['name'=>'ファイルアップロード攻撃','group'=>'processing'],
        'vulnerability_exploit'=>['name'=>'既知脆弱性の悪用','group'=>'information'],
        'directory_traversal'=>['name'=>'ディレクトリトラバーサル攻撃','group'=>'processing'],
        'access_control'=>['name'=>'権限昇格・アクセス制御の回避','group'=>'identity'],
        'session'=>['name'=>'セッション乗っ取り・固定化','group'=>'identity'],
        'ssrf'=>['name'=>'SSRF','group'=>'processing'],
        'clickjacking'=>['name'=>'クリックジャッキング','group'=>'delivery'],
        'transport'=>['name'=>'通信の盗聴・改ざん／中間者攻撃','group'=>'delivery'],
        'information_exposure'=>['name'=>'公開ファイルからの機密情報窃取','group'=>'information'],
        'supply_chain'=>['name'=>'サプライチェーン攻撃','group'=>'information'],
        'redirect'=>['name'=>'オープンリダイレクトの悪用','group'=>'processing'],
        'cache'=>['name'=>'Webキャッシュの悪用','group'=>'delivery'],
        'cors'=>['name'=>'CORS設定を悪用した情報窃取','group'=>'delivery'],
    ];
}

function wphm_sc_groups(): array { return ['identity'=>'認証と権限','processing'=>'入力と処理','delivery'=>'通信と配信','information'=>'情報と更新']; }

function wphm_sc_cwe_mapping(): array {
    return ['CWE-89'=>'sql_injection','CWE-79'=>'xss','CWE-352'=>'csrf','CWE-78'=>'rce','CWE-94'=>'rce','CWE-434'=>'file_upload','CWE-22'=>'directory_traversal','CWE-23'=>'directory_traversal','CWE-36'=>'directory_traversal','CWE-269'=>'access_control','CWE-284'=>'access_control','CWE-285'=>'access_control','CWE-639'=>'access_control','CWE-862'=>'access_control','CWE-863'=>'access_control','CWE-384'=>'session','CWE-613'=>'session','CWE-918'=>'ssrf','CWE-1021'=>'clickjacking','CWE-295'=>'transport','CWE-319'=>'transport','CWE-532'=>'information_exposure','CWE-538'=>'information_exposure','CWE-494'=>'supply_chain','CWE-601'=>'redirect','CWE-524'=>'cache','CWE-525'=>'cache','CWE-942'=>'cors'];
}
