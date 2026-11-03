<?php
if (!defined('ABSPATH')) exit;

function wphm_sc_read_reasons(): array {
    return [
        'http_401'=>'APIの認証に失敗しました。接続設定の読取りキーを確認・更新してください。',
        'http_403'=>'APIの読取り権限が不足しています。対象と必要な読取り権限を確認してください。',
        'http_404'=>'対象情報を取得できません。接続ID・製品の対応関係・非公開情報への権限を確認してください。',
        'http_429'=>'APIの回数制限に達しました。時間を置いて再チェックしてください。',
        'http_5xx'=>'連携先でサーバーエラーが発生しました。連携先の復旧後に再チェックしてください。',
        'network'=>'通信またはTLS証明書を確認できません。接続先とサーバーの外向き通信を確認してください。',
        'timeout'=>'読取り時間の上限に達しました。連携先の応答時間を確認して再チェックしてください。',
        'budget'=>'今回の取得時間の上限に達しました。再チェックで取得をやり直してください。',
        'site'=>'応答の対象サイトが一致しません。接続IDと対象ドメインを確認してください。',
        'stale'=>'応答が有効期限を超えています。提供元の更新・時刻設定を確認して再チェックしてください。',
        'future'=>'応答日時が未来です。提供元とWordPressサーバーの時刻を確認してください。',
        'schema'=>'対応する形式の情報を取得できません。提供元APIの版・対応項目・読取り権限を確認してください。',
        'json'=>'JSON応答を読み取れません。APIの接続先と対応形式を確認してください。',
        'size'=>'応答の件数・サイズ上限を超えました。提供元の絞込みと対応連携を確認してください。',
        'encoding'=>'対応しない圧縮応答です。APIの応答形式を確認してください。',
        'redirect'=>'転送先へは接続していません。接続設定で正しいAPIの接続先を確認してください。',
        'credential'=>'保存キーを使用できません。接続設定で読取りキーを入力し直してください。',
        'missing_id'=>'接続IDが未設定です。接続設定で対象を指定してください。',
        'missing_key'=>'読取りキーが未設定です。接続設定で必要なキーを指定してください。',
        'unregistered'=>'対応する読取り連携が登録されていません。対応サービス・連携の導入を確認してください。',
        'no_data'=>'この応答に対応する確認情報がありません。連携先の対応項目を確認してください。',
        'no_match'=>'今回対応する条件の標準ログイン制限を確認できません。適用先・条件・対応連携を確認してください。',
        'recheck'=>'この接続情報でまだ取得していません。再チェックを実行してください。',
        'exception'=>'連携処理を完了できません。対応連携の版と設定を確認してください。内部の例外情報は表示しません。',
        'target'=>'許可するHTTPS接続先を確認できません。対応サービスの接続設定を確認してください。',
        'db'=>'利用者情報の読取りを完了できません。データベースの状態を確認して再チェックしてください。',
        'transport'=>'この連携にはcURLと公開IPを固定できる直接HTTPS接続が必要です。PHP拡張とプロキシ設定を確認してください。',
    ];
}
function wphm_sc_read_code($code): string {
    if(is_string($code) && preg_match('/^http_5[0-9]{2}$/D',$code)) return 'http_5xx';
    return is_string($code) && isset(wphm_sc_read_reasons()[$code])?$code:'schema';
}
function wphm_sc_read_reason(string $code): string { return wphm_sc_read_reasons()[wphm_sc_read_code($code)]; }

/** Readers may report only a public reason code, never a raw API body or exception text. */
class WPHM_Security_Read_Exception extends RuntimeException {
    public string $reason;
    public function __construct(string $reason) {
        $this->reason=wphm_sc_read_code($reason);
        parent::__construct('読取り情報を確認できませんでした。');
    }
}
