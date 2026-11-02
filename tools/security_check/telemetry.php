<?php
if (!defined('ABSPATH')) exit;

/** Fixed-size, local counters. No URLs, payloads, credentials, IPs or user names are stored. */
function wphm_sc_telemetry_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'wphm_security_counts';
}

function wphm_sc_telemetry_schema(): bool {
    global $wpdb;
    $table = wphm_sc_telemetry_table();
    if (get_option('wphm_sc_counts_schema') === '1') return true;
    // Schema creation is restricted to a genuine administrator page, never a public request.
    if (!is_admin() || !current_user_can('manage_options')) return false;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        slot smallint unsigned NOT NULL,
        epoch int unsigned NOT NULL,
        requests int unsigned NOT NULL DEFAULT 0,
        failures int unsigned NOT NULL DEFAULT 0,
        successes int unsigned NOT NULL DEFAULT 0,
        after_failure int unsigned NOT NULL DEFAULT 0,
        sql_hits int unsigned NOT NULL DEFAULT 0,
        xss_hits int unsigned NOT NULL DEFAULT 0,
        traversal_hits int unsigned NOT NULL DEFAULT 0,
        errors int unsigned NOT NULL DEFAULT 0,
        truncated int unsigned NOT NULL DEFAULT 0,
        PRIMARY KEY  (slot),
        KEY epoch (epoch)
    ) {$charset};");
    if ($wpdb->last_error) return false;
    update_option('wphm_sc_counts_schema', '1', false);
    update_option('wphm_sc_counts_started', time(), false);
    wphm_sc_schedule_cleanup();
    return true;
}

function wphm_sc_schedule_cleanup(): void {
    if (get_option('wphm_sc_counts_schema') === '1' && !wp_next_scheduled('wphm_sc_counts_cleanup')) wp_schedule_single_event(time()+HOUR_IN_SECONDS,'wphm_sc_counts_cleanup');
}

function wphm_sc_cleanup_counts(): void {
    global $wpdb;
    $previous=$wpdb->suppress_errors(true);
    try {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . wphm_sc_telemetry_table() . ' WHERE (slot<1440 AND epoch<=%d) OR (slot>=1440 AND epoch<%d)',intdiv(time(),60)-1440,intdiv(time(),300)));
    } finally { $wpdb->suppress_errors($previous); }
    wphm_sc_schedule_cleanup();
}

function wphm_sc_counter(int $slot, int $epoch, array $values): void {
    global $wpdb;
    if (!wphm_is_tool_enabled('security_check') || get_option('wphm_sc_counts_schema') !== '1') return;
    if ($slot < 0 || $slot >= 1568) return;
    $fields = ['requests', 'failures', 'successes', 'after_failure', 'sql_hits', 'xss_hits', 'traversal_hits', 'errors', 'truncated'];
    $numbers = []; $updates = [];
    foreach ($fields as $field) {
        $numbers[] = max(0, min(1, (int)($values[$field] ?? 0)));
        $updates[] = "{$field}=IF(epoch=VALUES(epoch),LEAST(60000,{$field}+VALUES({$field})),VALUES({$field}))";
    }
    $table = wphm_sc_telemetry_table();
    $marks = implode(',', array_fill(0, count($fields) + 2, '%d'));
    $previous = $wpdb->suppress_errors(true);
    try {
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (slot,epoch," . implode(',', $fields) . ") VALUES ({$marks}) ON DUPLICATE KEY UPDATE " . implode(',', $updates) . ',epoch=VALUES(epoch)', array_merge([$slot, $epoch], $numbers)));
    } finally { $wpdb->suppress_errors($previous); }
}

/** Only classify short, non-secret values; discard the inspected text immediately. */
function wphm_sc_classify_request(array $query, array $post, string $path): array {
    $flags = ['sql_hits' => 0, 'xss_hits' => 0, 'traversal_hits' => 0, 'truncated' => 0];
    $budget = 8192; $nodes = 0;
    $inspect = function ($value, string $key = '', int $depth = 0) use (&$inspect, &$flags, &$budget, &$nodes): void {
        if (++$nodes > 64 || $depth > 3 || $budget <= 0) { $flags['truncated'] = 1; return; }
        if (preg_match('/pass|pwd|secret|token|nonce|auth|cookie|session|credit|card|email/i', $key)) return;
        if (is_array($value)) {
            foreach ($value as $child_key => $child) {
                if ($nodes >= 64 || $budget <= 0) { $flags['truncated'] = 1; break; }
                $inspect($child, (string)$child_key, $depth + 1);
            }
            return;
        }
        if (!is_scalar($value)) return;
        $text = (string)$value; $take = min(1024, $budget);
        if (strlen($text) > $take) $flags['truncated'] = 1;
        $text = substr($text, 0, $take); $budget -= strlen($text);
        $text = rawurldecode(rawurldecode($text));
        if (preg_match('~\bunion\s+(?:all\s+)?select\b|\bsleep\s*\(|\bbenchmark\s*\(~i', $text)) $flags['sql_hits'] = 1;
        if (preg_match('~<\s*(?:script|svg|iframe)\b|javascript\s*:|\bon(?:error|load)\s*=~i', $text)) $flags['xss_hits'] = 1;
        if (preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $text)) $flags['traversal_hits'] = 1;
    };
    $inspect($path); $inspect($query); $inspect($post);
    return $flags;
}

function wphm_sc_observe_request(): void {
    if (!wphm_is_tool_enabled('security_check') || get_option('wphm_sc_counts_schema') !== '1') return;
    if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON)) return;
    $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($uri === '') return;
    $path = explode('?', substr($uri, 0, 2048), 2)[0];
    $GLOBALS['wphm_sc_request_counts'] = ['requests' => 1] + wphm_sc_classify_request($_GET, $_POST, $path);
    // Native shutdown runs after WordPress' fatal-error handler, including early exits.
    register_shutdown_function('wphm_sc_flush_request');
}

function wphm_sc_flush_request(): void {
    if (empty($GLOBALS['wphm_sc_request_counts'])) return;
    $values = $GLOBALS['wphm_sc_request_counts']; unset($GLOBALS['wphm_sc_request_counts']);
    $status = http_response_code(); $values['errors'] = is_int($status) && $status >= 500 && $status <= 599 ? 1 : 0;
    $epoch = intdiv(time(), 60);
    wphm_sc_counter($epoch % 1440, $epoch, $values);
}

/** Daily salted hashes select shared buckets; collisions are expressly treated as approximate. */
function wphm_sc_auth_event(string $username, bool $success): void {
    global $wpdb;
    if (!wphm_is_tool_enabled('security_check') || get_option('wphm_sc_counts_schema') !== '1') return;
    $epoch = intdiv(time(), 60); $window = intdiv(time(), 300);
    $salt = wp_salt('auth') . gmdate('Y-m-d');
    $ip = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : '';
    $names = [strtolower(substr($username, 0, 256)), $ip]; $after = false;
    foreach ($names as $i => $name) {
        if ($name === '') continue;
        $slot = 1440 + $i * 64 + hexdec(substr(hash_hmac('sha256', $name, $salt), 0, 6)) % 64;
        if ($success && $i === 0) {
            $previous = $wpdb->suppress_errors(true);
            try { $failed = (int)$wpdb->get_var($wpdb->prepare('SELECT failures FROM ' . wphm_sc_telemetry_table() . ' WHERE slot=%d AND epoch=%d', $slot, $window)); }
            finally { $wpdb->suppress_errors($previous); }
            $after = $failed >= 5;
        }
        wphm_sc_counter($slot, $window, [$success ? 'successes' : 'failures' => 1]);
    }
    wphm_sc_counter($epoch % 1440, $epoch, [$success ? 'successes' : 'failures' => 1, 'after_failure' => $after ? 1 : 0]);
}

function wphm_sc_login_failed($username): void { wphm_sc_auth_event(is_string($username) ? $username : '', false); }
function wphm_sc_login_success($username): void { wphm_sc_auth_event(is_string($username) ? $username : '', true); }
function wphm_sc_application_success($user): void { if ($user instanceof WP_User) wphm_sc_auth_event($user->user_login, true); }
function wphm_sc_application_failed($error): void { wphm_sc_auth_event('', false); }

function wphm_sc_telemetry_facts(): array {
    global $wpdb;
    $out = ['available' => false, 'started' => (int)get_option('wphm_sc_counts_started', 0), 'minutes' => 0, 'from' => 0, 'to' => 0, 'peak' => 0, 'auth_peak' => 0, 'focused_failures' => 0, 'saturated' => false];
    foreach (['requests','failures','successes','after_failure','sql_hits','xss_hits','traversal_hits','errors','truncated'] as $field) $out[$field] = 0;
    if (get_option('wphm_sc_counts_schema') !== '1') return $out;
    $now = intdiv(time(), 60); $previous = $wpdb->suppress_errors(true);
    try {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . wphm_sc_telemetry_table() . ' WHERE slot<1440 AND epoch>%d AND epoch<=%d ORDER BY epoch LIMIT 1440', $now - 1440, $now), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) return $out;
        $out['available'] = true;
        $out['focused_failures'] = (int)$wpdb->get_var($wpdb->prepare('SELECT MAX(failures) FROM ' . wphm_sc_telemetry_table() . ' WHERE slot>=1440 AND epoch=%d', intdiv(time(), 300)));
    } finally { $wpdb->suppress_errors($previous); }
    foreach ($rows as $row) {
        foreach (['requests','failures','successes','after_failure','sql_hits','xss_hits','traversal_hits','errors','truncated'] as $field) {
            $out[$field] += (int)$row[$field];
            if ((int)$row[$field] >= 60000) $out['saturated'] = true;
        }
        $out['peak'] = max($out['peak'], (int)$row['requests']);
        $out['auth_peak'] = max($out['auth_peak'], (int)$row['failures']);
    }
    $out['minutes'] = count($rows);
    if ($rows) { $out['from'] = (int)$rows[0]['epoch'] * 60; $out['to'] = (int)$rows[count($rows)-1]['epoch'] * 60 + 59; }
    return $out;
}

add_action('init', 'wphm_sc_observe_request', 0);
add_action('init', 'wphm_sc_schedule_cleanup', 20);
add_action('wphm_sc_counts_cleanup', 'wphm_sc_cleanup_counts');
add_action('wp_login_failed', 'wphm_sc_login_failed', 10, 1);
// Priority 0 observes the password step even when a 2FA provider exits later. It is NOT final MFA success.
add_action('wp_login', 'wphm_sc_login_success', 0, 1);
add_action('application_password_did_authenticate', 'wphm_sc_application_success', 10, 1);
add_action('application_password_failed_authentication', 'wphm_sc_application_failed', 10, 1);
