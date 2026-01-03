<?php
if (!defined('ABSPATH')) exit;

/**
 * 記録するかどうか
 * - UAがボットっぽいなら除外（必要ならフィルタで上書き）
 */
function wphm_access_log_should_log(): bool {
    // GET/POST以外はスキップしたいならここで制御
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET','POST'], true)) {
        return (bool) apply_filters('wphm_access_log_should_log', false, $method);
    }

    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ua_l = strtolower($ua);

    $bot = false;
    if ($ua_l !== '') {
        $bot = (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|pingdom|uptimerobot/i', $ua_l);
    }

    // 管理者のアクセスを除外したい場合（デフォルトは「記録する」）
    $skip_admin = (bool) apply_filters('wphm_access_log_skip_admin_user', false);
    if ($skip_admin && is_user_logged_in() && current_user_can('manage_options')) {
        return (bool) apply_filters('wphm_access_log_should_log', false, $method);
    }

    if ($bot) {
        return (bool) apply_filters('wphm_access_log_should_log', false, $method);
    }

    return (bool) apply_filters('wphm_access_log_should_log', true, $method);
}

/**
 * ログ1行を組み立て
 */
function wphm_access_log_build_row(): ?array {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    $uri    = (string)($_SERVER['REQUEST_URI'] ?? '');

    if ($host === '' || $uri === '') return null;

    $url = $scheme . '://' . $host . $uri;

    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $ua  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip  = wphm_access_log_get_client_ip();

    // path は query なしの形に寄せる
    $parts = wp_parse_url($url);
    $path = (string)($parts['path'] ?? '/');

    $status = 200; // template_redirect 時点では確定しづらいので、まず 200 として保存（必要なら改修）
    $user_id = get_current_user_id();

    return [
        'created_at' => time(),
        'method'     => $method,
        'status'     => $status,
        'url'        => $url,
        'path'       => $path,
        'referrer'   => $ref,
        'ip'         => $ip,
        'user_id'    => (int)$user_id,
        'user_agent' => $ua,
    ];
}

/**
 * IP取得（信頼できるプロキシ環境ならフィルタで差し替え推奨）
 */
function wphm_access_log_get_client_ip(): string {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    // X-Forwarded-For を使う場合はフィルタで上書き（無条件採用は危険）
    return (string) apply_filters('wphm_access_log_ip', $ip);
}

/**
 * 日付入力（YYYY-MM-DD）→ unix秒（その日の開始/終了）
 */
function wphm_access_log_date_to_ts(string $ymd, bool $end_of_day = false): int {
    $ymd = trim($ymd);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return 0;

    $t = strtotime($ymd . ($end_of_day ? ' 23:59:59' : ' 00:00:00'));
    return $t ? (int)$t : 0;
}
