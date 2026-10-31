<?php
if (!defined('ABSPATH')) exit;

const WPHM_LI_MAX_BODY = 1048576;
const WPHM_LI_MAX_FETCHES = 20;
const WPHM_LI_MAX_SECONDS = 20;
const WPHM_LI_MAX_LOCS = 20000;

function wphm_li_public_ip(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    $packed = inet_pton($ip);
    if (strlen($packed) === 16) return (ord($packed[0]) & 0xe0) === 0x20
        && !in_array(substr($packed, 0, 4), [hex2bin('20010db8'), hex2bin('20010000')], true)
        && substr($packed, 0, 2) !== hex2bin('2002');
    // Byte comparisons work on 32-bit PHP too; avoid unsigned IPv4 integer masks.
    $b = array_values(unpack('C4', $packed));
    if ($b[0] === 0 || $b[0] >= 224 || ($b[0] === 100 && $b[1] >= 64 && $b[1] <= 127)) return false;
    if ($b[0] === 192 && (($b[1] === 0 && in_array($b[2], [0, 2], true)) || ($b[1] === 88 && $b[2] === 99))) return false;
    if ($b[0] === 198 && (in_array($b[1], [18, 19], true) || ($b[1] === 51 && $b[2] === 100))) return false;
    return !($b[0] === 203 && $b[1] === 0 && $b[2] === 113);
}

function wphm_li_allowed_url(string $url): bool {
    $p = wp_parse_url($url);
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return false;
    $host = strtolower($p['host']);
    $allowed = [strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST)), strtolower((string)wp_parse_url(site_url('/'), PHP_URL_HOST))];
    $allowed = (array)apply_filters('wphm_li_allowed_sitemap_hosts', $allowed);
    if (!in_array($host, $allowed, true)) return false;
    $literal = trim($host, '[]');
    if (filter_var($literal, FILTER_VALIDATE_IP)) return wphm_li_public_ip($literal);
    if (!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D', $host)) return false;
    // Validate every returned address, even for our own hostname (core permits local home URLs).
    $ips = gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record') && defined('DNS_AAAA')) {
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $record) if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
    }
    if (!$ips) return false;
    foreach ($ips as $ip) if (!wphm_li_public_ip($ip)) return false;
    return true;
}

function wphm_li_fetch_body(string $url, int $timeout, ?array &$budget = null): string {
    if ($budget === null) $budget = ['started' => microtime(true), 'requests' => 0];
    for ($redirect = 0; $redirect <= 3; $redirect++) {
        $remaining = WPHM_LI_MAX_SECONDS - (microtime(true) - $budget['started']);
        if ($remaining <= 0 || $budget['requests'] >= WPHM_LI_MAX_FETCHES) {
            $GLOBALS['wphm_li_fetch_error'] = 'サイトマップ取得の時間・回数上限に達しました。対象を絞って再実行してください。'; return '';
        }
        if (!wphm_li_allowed_url($url)) {
            $GLOBALS['wphm_li_fetch_error'] = '安全のためサイトマップの取得先を拒否しました。同一サイトの公開HTTP/HTTPS URLを指定してください。'; return '';
        }
        $remaining = WPHM_LI_MAX_SECONDS - (microtime(true) - $budget['started']);
        if ($remaining <= 0) { $GLOBALS['wphm_li_fetch_error'] = 'サイトマップ取得の時間上限に達しました。'; return ''; }
        $budget['requests']++;
        $response = wp_safe_remote_get($url, [
            'timeout' => max(0.1, min($timeout, $remaining)), 'redirection' => 0,
            'limit_response_size' => WPHM_LI_MAX_BODY + 1, 'decompress' => false,
            'headers' => ['Accept' => 'application/xml,text/xml', 'Accept-Encoding' => 'identity'],
        ]);
        if (is_wp_error($response)) { $GLOBALS['wphm_li_fetch_error'] = 'サイトマップを取得できませんでした。URLと通信状態を確認してください。'; return ''; }
        $code = (int)wp_remote_retrieve_response_code($response);
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $location = wp_remote_retrieve_header($response, 'location');
            if ($redirect === 3 || !is_string($location) || $location === '') break;
            $url = WP_Http::make_absolute_url($location, $url);
            continue;
        }
        if ($code !== 200) { $GLOBALS['wphm_li_fetch_error'] = 'サイトマップの応答がHTTP 200ではありません。'; return ''; }
        $body = (string)wp_remote_retrieve_body($response);
        $encoding = strtolower(trim((string)wp_remote_retrieve_header($response, 'content-encoding')));
        if (strlen($body) > WPHM_LI_MAX_BODY || !in_array($encoding, ['', 'identity'], true)) {
            $GLOBALS['wphm_li_fetch_error'] = 'サイトマップが1MiBを超えるか、未対応の圧縮応答です。小さいサイトマップを指定してください。'; return '';
        }
        return $body;
    }
    $GLOBALS['wphm_li_fetch_error'] = 'サイトマップの転送回数が上限を超えました。';
    return '';
}
