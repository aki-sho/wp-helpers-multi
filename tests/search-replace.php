<?php
// Standalone regression suite: php -n tests/search-replace.php [optional WordPress core directory]
if (PHP_SAPI !== 'cli') exit;
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class AjaxResponse extends Exception {
    public $value;
    public $status;
    public function __construct($value, $status) { $this->value = $value; $this->status = $status; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return strip_tags((string) $value); }
function get_current_user_id() { return $GLOBALS['test_user']; }
function current_user_can($cap, $id = 0) { return $GLOBALS['test_can'] && !in_array($id, $GLOBALS['test_forbidden'], true); }
function get_transient($key) { return $GLOBALS['test_transients'][$key] ?? false; }
function set_transient($key, $value, $expiry) { $GLOBALS['test_transients'][$key] = $value; return true; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function current_time($format, $gmt) { return '2026-10-25 12:00:00'; }
function clean_post_cache($id) {}
function get_post($id) { return isset($GLOBALS['test_posts'][$id]) ? clone $GLOBALS['test_posts'][$id] : null; }
function get_post_field($field, $id, $context) { return $GLOBALS['test_posts'][$id]->$field ?? ''; }
function get_edit_post_link($id, $context) { return '/wp-admin/post.php?post=' . $id; }
function wp_check_post_lock($id) { return $GLOBALS['test_editor_lock']; }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function wp_update_post($data, $error) {
    $GLOBALS['test_writes']++;
    if ($GLOBALS['test_save_fail']) return new WP_Error('save', 'failed');
    $content = wp_unslash($data['post_content']);
    if ($GLOBALS['test_save_filter']) $content = call_user_func($GLOBALS['test_save_filter'], $content);
    $GLOBALS['test_posts'][$data['ID']]->post_content = $content;
    return $data['ID'];
}
function wphm_sr_lock($id) { return !$GLOBALS['test_busy']; }
function wphm_sr_unlock($id) { $GLOBALS['test_unlocks']++; }
function wphm_sr_history_insert($row) {
    if ($GLOBALS['test_backup_fail']) return 0;
    $id = count($GLOBALS['test_history']) + 1;
    $row['id'] = $id;
    $GLOBALS['test_history'][$id] = $row;
    if ($GLOBALS['test_backup_hook']) call_user_func($GLOBALS['test_backup_hook']);
    return $id;
}
function wphm_sr_history_update($id, $row) {
    if ($GLOBALS['test_history_fail']) return false;
    $GLOBALS['test_history'][$id] = array_merge($GLOBALS['test_history'][$id], $row);
    return true;
}
function wphm_sr_history_get($id) { return $GLOBALS['test_history'][$id] ?? null; }
function wphm_sr_history_for_run($run, $id) {
    foreach ($GLOBALS['test_history'] as $row) if ($row['run_id'] === $run && $row['post_id'] === $id) return true;
    return false;
}
function wphm_sr_candidate_ids($settings, $cursor, $max) {
    $ids = [];
    foreach ($GLOBALS['test_posts'] as $id => $post) {
        if ($id <= $cursor || $id > $max || ($settings['post_id'] && $id !== $settings['post_id'])) continue;
        if (!in_array($post->post_type, ['post', 'page'], true)) continue;
        if ($settings['type'] !== 'both' && $post->post_type !== $settings['type']) continue;
        if ($settings['status'] !== 'all' && $post->post_status !== $settings['status']) continue;
        $ids[] = $id;
    }
    sort($ids);
    return array_slice($ids, 0, 21);
}
function add_action($action, $callback) {}
function wphm_sr_ensure_schema() { return $GLOBALS['test_schema']; }
function check_ajax_referer($action, $field) { if (!$GLOBALS['test_nonce']) throw new AjaxResponse('nonce', 403); }
function wp_send_json_error($value, $status) { throw new AjaxResponse($value, $status); }
function wp_send_json_success($value) { throw new AjaxResponse($value, 200); }
function apply_filters($name, $value) { return $value; }
function get_option($name, $default = false) { return $name === 'wphm_tool_states' ? ['search_replace' => (int) $GLOBALS['test_enabled']] : $default; }
if (isset($argv[1]) && is_file($argv[1] . '/wp-includes/shortcodes.php')) {
    require $argv[1] . '/wp-includes/shortcodes.php';
} else {
    function get_shortcode_regex() { return '\\[gallery(?:\\s[^\\]]*)?\\](?:.*?\\[\\/gallery\\])?'; }
}
class TestDatabase {
    public $posts = 'posts';
    public function get_var($sql) { return $GLOBALS['test_posts'] ? max(array_keys($GLOBALS['test_posts'])) : 0; }
}
require __DIR__ . '/../tools/search_replace/app/matcher.php';
require __DIR__ . '/../tools/search_replace/app/service.php';
require __DIR__ . '/../tools/search_replace/app/ajax.php';
require __DIR__ . '/../includes/settings.php';

$checks = 0;
function same($expected, $actual, $label) {
    global $checks;
    $checks++;
    if ($expected !== $actual) { fwrite(STDERR, "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n"); exit(1); }
}
function error_code($expected, $actual, $label) { same($expected, is_wp_error($actual) ? $actual->get_error_code() : 'success', $label); }
function replace_fixture($source, $search, $replacement, $selected = null, $case = true) {
    $scan = wphm_sr_scan($source, $search, $case);
    return wphm_sr_replace_matches($source, $scan['matches'], $selected ?? array_column($scan['matches'], 'id'), $replacement);
}
function reset_fixture() {
    $GLOBALS['test_user'] = 1; $GLOBALS['test_can'] = true; $GLOBALS['test_forbidden'] = [];
    $GLOBALS['test_editor_lock'] = false; $GLOBALS['test_busy'] = false; $GLOBALS['test_backup_fail'] = false;
    $GLOBALS['test_save_fail'] = false; $GLOBALS['test_history_fail'] = false; $GLOBALS['test_save_filter'] = null;
    $GLOBALS['test_backup_hook'] = null; $GLOBALS['test_writes'] = 0; $GLOBALS['test_unlocks'] = 0;
    $GLOBALS['test_transients'] = []; $GLOBALS['test_history'] = []; $GLOBALS['test_enabled'] = true;
    $GLOBALS['test_schema'] = true; $GLOBALS['test_nonce'] = true;
    $GLOBALS['test_posts'] = [1 => (object) ['ID' => 1, 'post_title' => '日本語のテスト', 'post_type' => 'post', 'post_status' => 'publish', 'post_content' => '<p>旧名称と旧名称</p>']];
    $GLOBALS['wpdb'] = new TestDatabase(); $GLOBALS['shortcode_tags'] = ['gallery' => 'unused'];
}
function preview($replacement = '新名称') {
    $started = wphm_sr_start(['search' => '旧名称', 'replacement' => $replacement]);
    $result = wphm_sr_search($started['token']);
    same(2, $result['count'], 'search returns both occurrences');
    return $started['token'];
}
reset_fixture();
same('<p>旧名称と新名称</p>', replace_fixture('<p>旧名称と旧名称</p>', '旧名称', '新名称', [1]), 'only selected occurrence');
same('<p>猫🐱猫</p>', replace_fixture('<p>犬🐱犬</p>', '犬', '猫'), 'Japanese and emoji byte offsets');
same('<p>new new old</p>', replace_fixture('<p>OLD Old old</p>', 'old', 'new', [0, 1], false), 'case insensitive selection');
same('<p>OLD Old new</p>', replace_fixture('<p>OLD Old old</p>', 'old', 'new'), 'case sensitive');
same('<p>literal</p>', replace_fixture('<p>.*+$1</p>', '.*+$1', 'literal'), 'regex metacharacters are literal');
same('<p>A &amp; B</p>', replace_fixture('<p>A &amp; B</p>', 'href', 'x'), 'unmatched markup stays byte identical');
same('<p>会社</p>', replace_fixture('<p>Tom &amp; Jerry</p>', 'Tom & Jerry', '会社'), 'entity decoded match');
same('<p>&amp; new &lt; new</p>', replace_fixture('<p>&amp; old &lt; old</p>', 'old', 'new'), 'offsets after multiple entities');
same('<p>&lt;script&gt; &amp; \' "</p>', replace_fixture('<p>old</p>', 'old', '<script> & \' "'), 'replacement is text, never executable markup');
same("<p>A\nB</p>", replace_fixture("<p>old\r\nname</p>", "old\nname", "A\nB"), 'multiline CRLF matching');
$markup = '<!-- wp:paragraph {"anchor":"old"} --><p id="old"><a href="https://old.test/?a=1&amp;b=2" title="x > old">old</a></p><!-- /wp:paragraph -->';
same(str_replace('>old</a>', '>new</a>', $markup), replace_fixture($markup, 'old', 'new'), 'attributes and block JSON preserved');
same('<p>new</p><!-- old --><script>old</script><style>old</style><pre>old</pre><code>old</code>', replace_fixture('<p>old</p><!-- old --><script>old</script><style>old</style><pre>old</pre><code>old</code>', 'old', 'new'), 'non-text contexts excluded');
$custom = '<!-- wp:vendor/custom --><!-- wp:paragraph --><p>old</p><!-- /wp:paragraph --><!-- /wp:vendor/custom --><!-- wp:paragraph --><p>old</p><!-- /wp:paragraph -->';
same(1, count(wphm_sr_scan($custom, 'old', true)['matches']), 'unsupported ancestors excluded');
same(['vendor/custom'], wphm_sr_scan($custom, 'old', true)['unsupported'], 'unsupported blocks reported');
same('[gallery title="old"]old[/gallery]<p>new</p>', replace_fixture('[gallery title="old"]old[/gallery]<p>old</p>', 'old', 'new'), 'registered shortcode enclosure excluded');
same('[unknown title="old"]<p>new</p>', replace_fixture('[unknown title="old"]<p>old</p>', 'old', 'new'), 'unregistered shortcode attributes excluded');
same(true, wphm_sr_scan('<!-- wp:paragraph --><p>old</p><!-- /wp:heading -->', 'old', true)['invalid'], 'malformed block rejected');
same(true, wphm_sr_scan('text <a href="old', 'old', true)['invalid'], 'incomplete markup rejected');
same('<p>a &lt; b</p>', replace_fixture('<p>a < b</p>', 'a < b', 'a < b'), 'literal angle bracket comparison');
same('<p>&NotEqualTilde;</p>', replace_fixture('<p>&NotEqualTilde;</p>', "\u{2242}", 'x'), 'partial compound entity excluded');
same('<p>x</p>', replace_fixture('<p>&NotEqualTilde;</p>', "\u{2242}\u{0338}", 'x'), 'whole compound entity supported');
same(true, wphm_sr_scan(str_repeat('x ', 501), 'x', true)['limited'], 'match cap explicit');
$long = str_repeat('あ', 200000) . 'old';
same(1, count(wphm_sr_scan($long, 'old', true)['matches']), 'large UTF-8 text does not allocate a character map');
same(65, preg_match_all('/./us', wphm_sr_scan($long, 'old', true)['matches'][0]['before']), 'context remains bounded and valid UTF-8');
same('', replace_fixture('old', 'old', ''), 'empty replacement deletes text');
error_code('empty_search', wphm_sr_normalize_settings(['search' => '']), 'empty search rejected');
error_code('invalid_text', wphm_sr_normalize_settings(['search' => str_repeat('a', 5001)]), 'search limit');
error_code('invalid_filter', wphm_sr_normalize_settings(['search' => 'x', 'type' => 'revision']), 'arbitrary post types rejected');
same(true, wphm_is_tool_enabled('search_replace'), 'tool registered in existing dashboard');

reset_fixture(); $token = preview();
$result = wphm_sr_apply($token, 1, [1]);
same(false, is_wp_error($result), 'apply succeeds');
same('<p>旧名称と新名称</p>', $GLOBALS['test_posts'][1]->post_content, 'selected replacement saved');
same('<p>旧名称と旧名称</p>', $GLOBALS['test_history'][1]['before_content'], 'full original backed up');
same('applied', $GLOBALS['test_history'][1]['status'], 'history finalized');
same(1, $GLOBALS['test_unlocks'], 'lock released after save');
error_code('processed', wphm_sr_apply($token, 1, [0]), 'duplicate request prevented');
same(1, $GLOBALS['test_writes'], 'duplicate request never saves twice');
same(false, is_wp_error(wphm_sr_restore(1)), 'restore succeeds');
same('<p>旧名称と旧名称</p>', $GLOBALS['test_posts'][1]->post_content, 'restore exact original');
error_code('history', wphm_sr_restore(1), 'cannot restore twice');

reset_fixture(); $token = preview(); $GLOBALS['test_posts'][1]->post_content .= '他の編集';
error_code('conflict', wphm_sr_apply($token, 1, [0]), 'stale preview rejected');
same(0, $GLOBALS['test_writes'], 'stale preview never writes');
reset_fixture(); $token = preview(); $GLOBALS['test_editor_lock'] = true;
error_code('locked', wphm_sr_apply($token, 1, [0]), 'editor lock respected');
reset_fixture(); $token = preview(); $GLOBALS['test_forbidden'] = [1];
error_code('permission', wphm_sr_apply($token, 1, [0]), 'per-post permission enforced');
reset_fixture(); $token = preview(); $GLOBALS['test_user'] = 2;
error_code('expired', wphm_sr_apply($token, 1, [0]), 'preview bound to requesting user');
reset_fixture(); $token = preview();
error_code('selection', wphm_sr_apply($token, 2, [0]), 'cannot inject post ID');
error_code('selection', wphm_sr_apply($token, 1, [99]), 'cannot inject occurrence ID');
error_code('selection', wphm_sr_apply($token, 1, ['0']), 'selection IDs must be integers');
reset_fixture(); $token = preview(); $GLOBALS['test_backup_fail'] = true;
error_code('backup', wphm_sr_apply($token, 1, [0]), 'backup failure aborts');
same(0, $GLOBALS['test_writes'], 'backup failure never writes');
reset_fixture(); $token = preview(); $GLOBALS['test_save_fail'] = true;
error_code('save', wphm_sr_apply($token, 1, [0]), 'save error surfaced');
same('failed', $GLOBALS['test_history'][1]['status'], 'failed save recorded');
reset_fixture(); $token = preview();
$GLOBALS['test_backup_hook'] = function () { $GLOBALS['test_posts'][1]->post_content = '保存直前の変更'; };
error_code('conflict', wphm_sr_apply($token, 1, [0]), 'change during backup detected');
same(0, $GLOBALS['test_writes'], 'last moment conflict never writes');
reset_fixture(); $token = preview("C:\\test\\file '引用' & <tag>");
same(false, is_wp_error(wphm_sr_apply($token, 1, [0])), 'backslashes and quotes saved');
same(true, strpos($GLOBALS['test_posts'][1]->post_content, "C:\\test\\file '引用' &amp; &lt;tag&gt;") !== false, 'WordPress slash contract retained');
reset_fixture(); $token = preview(); wphm_sr_apply($token, 1, [0]);
$GLOBALS['test_posts'][1]->post_content .= '後の編集';
error_code('conflict', wphm_sr_restore(1), 'restore does not erase subsequent edits');
reset_fixture(); $token = preview(); $GLOBALS['test_save_filter'] = function ($text) { return $text . 'filter'; };
same(false, is_wp_error(wphm_sr_apply($token, 1, [0])), 'save filter outcome recorded');
same($GLOBALS['test_posts'][1]->post_content, $GLOBALS['test_history'][1]['after_content'], 'history tracks actual saved content');
$GLOBALS['test_save_filter'] = null;
same(false, is_wp_error(wphm_sr_restore(1)), 'filtered outcome can be restored');
reset_fixture(); $token = preview(); $GLOBALS['test_history_fail'] = true;
error_code('history', wphm_sr_apply($token, 1, [0]), 'history finalize failure surfaced');
$GLOBALS['test_history_fail'] = false;
same(false, is_wp_error(wphm_sr_restore(1)), 'pending backup recoverable');
reset_fixture();
for ($id = 2; $id <= 45; $id++) { $GLOBALS['test_posts'][$id] = clone $GLOBALS['test_posts'][1]; $GLOBALS['test_posts'][$id]->ID = $id; }
$token = wphm_sr_start(['search' => '旧名称', 'replacement' => '新名称'])['token'];
$first = wphm_sr_search($token); $second = wphm_sr_search($token); $third = wphm_sr_search($token);
same([20, 20, 5], [count($first['items']), count($second['items']), count($third['items'])], 'search batches all articles without gaps');
same([false, false, true], [$first['done'], $second['done'], $third['done']], 'batch completion');
same(90, $third['count'], 'cumulative match count');

function ajax($payload) {
    $_POST['payload'] = wp_slash(json_encode($payload));
    try { wphm_sr_ajax(); } catch (AjaxResponse $response) { return $response; }
    throw new Exception('AJAX failed to respond');
}
reset_fixture(); same(200, ajax(['op' => 'start', 'settings' => ['search' => '旧名称']])->status, 'AJAX valid start');
$GLOBALS['test_can'] = false; same(403, ajax(['op' => 'start', 'settings' => ['search' => 'x']])->status, 'AJAX admin capability');
reset_fixture(); $GLOBALS['test_enabled'] = false; same(403, ajax(['op' => 'start', 'settings' => ['search' => 'x']])->status, 'AJAX feature switch');
reset_fixture(); $GLOBALS['test_nonce'] = false; same(403, ajax(['op' => 'start', 'settings' => ['search' => 'x']])->status, 'AJAX nonce');
reset_fixture(); $GLOBALS['test_schema'] = false; same(500, ajax(['op' => 'start', 'settings' => ['search' => 'x']])->status, 'schema failure stops operation');
reset_fixture(); same(400, ajax(['op' => 'start', 'settings' => ['search' => ['invalid']]])->status, 'nested malicious fields rejected');
same(400, ajax(['op' => 'apply', 'token' => ['invalid'], 'selected' => [0]])->status, 'non-scalar tokens rejected');
echo "PASS: {$checks} assertions (matcher, search batches, apply, restore, permissions, AJAX guards).\n";
