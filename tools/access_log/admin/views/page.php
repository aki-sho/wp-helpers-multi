<?php
if (!defined('ABSPATH')) exit;

/** @var array $data */
$q = $data['q'];
$ip = $data['ip'];
$from = $data['from'];
$to = $data['to'];
$result = $data['result'];
$msg = $data['msg'];
$ajax_nonce = $data['ajax_nonce'];

$items = $result['items'];
$paged = $result['paged'];
$pages = $result['pages'];

$title = 'アクセスログ';

if (function_exists('wphm_render_header')) {
    echo '<div class="wrap wphm-app">';
    wphm_render_header($title);
} else {
    echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
}

if ($msg === 'deleted')  echo '<div class="notice notice-success"><p>選択したログを削除しました。</p></div>';
if ($msg === 'purged')   echo '<div class="notice notice-success"><p>古いログを削除しました。</p></div>';
if ($msg === 'truncated')echo '<div class="notice notice-success"><p>全ログを削除しました。</p></div>';

?>

<style>
/* ここは最小。asset css に逃がすなら削除OK */
.wphm-accesslog-actions { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; margin: 12px 0; }
.wphm-accesslog-actions .field { display:flex; flex-direction:column; gap:4px; }
.wphm-accesslog-actions input[type="text"], .wphm-accesslog-actions input[type="date"] { min-width: 220px; }
.wphm-accesslog-danger { color:#b32d2e; }
</style>

<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
  <input type="hidden" name="page" value="wphm-access-log">

  <div class="wphm-accesslog-actions">
    <div class="field">
      <label>検索（URL/Referrer/UA）</label>
      <input type="text" name="q" value="<?php echo esc_attr($q); ?>" placeholder="例: /blog/ など">
    </div>

    <div class="field">
      <label>IP</label>
      <input type="text" name="ip" value="<?php echo esc_attr($ip); ?>" placeholder="例: 203.0.113.1">
    </div>

    <div class="field">
      <label>From</label>
      <input type="date" name="from" value="<?php echo esc_attr($from); ?>">
    </div>

    <div class="field">
      <label>To</label>
      <input type="date" name="to" value="<?php echo esc_attr($to); ?>">
    </div>

    <div class="field">
      <button class="button button-primary" type="submit">絞り込み</button>
    </div>

    <div class="field">
      <a class="button" href="<?php echo esc_url(wphm_access_log_admin_url()); ?>">リセット</a>
    </div>

    <div class="field">
      <?php
        $csv_url = add_query_arg([
          'page' => 'wphm-access-log',
          'wphm_export' => 'csv',
          'q' => $q,
          'ip' => $ip,
          'from' => $from,
          'to' => $to,
        ], admin_url('admin.php'));
      ?>
      <a class="button" href="<?php echo esc_url($csv_url); ?>">CSV出力（最大200件）</a>
    </div>
  </div>
</form>

<form method="post" action="<?php echo esc_url(wphm_access_log_admin_url([
    'q' => $q, 'ip' => $ip, 'from' => $from, 'to' => $to, 'paged' => $paged
])); ?>">
  <?php wp_nonce_field('wphm_access_log_action'); ?>

  <div style="display:flex; gap:10px; align-items:center; margin: 10px 0;">
    <button class="button" type="submit" name="wphm_access_log_action" value="delete_selected"
      onclick="return confirm('選択したログを削除します。よろしいですか？');">
      選択削除
    </button>

    <span style="margin-left:auto;"></span>

    <label>○日より古いログを削除：</label>
    <input type="number" name="days" value="30" min="1" style="width:90px;">
    <button class="button" type="submit" name="wphm_access_log_action" value="purge_days"
      onclick="return confirm('古いログを削除します。よろしいですか？');">
      実行
    </button>

    <button class="button wphm-accesslog-danger" type="submit" name="wphm_access_log_action" value="truncate"
      onclick="return confirm('全ログ削除（取り消し不可）です。よろしいですか？');">
      全削除
    </button>
  </div>

  <?php require __DIR__ . '/parts/table.php'; ?>
  <?php require __DIR__ . '/parts/document.php'; ?>
</form>

<?php
// pagination
if ($pages > 1) {
    $base_args = ['page' => 'wphm-access-log', 'q' => $q, 'ip' => $ip, 'from' => $from, 'to' => $to];
    echo '<div class="tablenav"><div class="tablenav-pages" style="margin: 12px 0;">';

    $prev = max(1, $paged - 1);
    $next = min($pages, $paged + 1);

    echo '<span class="pagination-links">';
    echo '<a class="button" href="' . esc_url(add_query_arg($base_args + ['paged' => 1], admin_url('admin.php'))) . '">«</a> ';
    echo '<a class="button" href="' . esc_url(add_query_arg($base_args + ['paged' => $prev], admin_url('admin.php'))) . '">‹</a> ';
    echo '<span style="padding:0 8px;">' . (int)$paged . ' / ' . (int)$pages . '</span>';
    echo '<a class="button" href="' . esc_url(add_query_arg($base_args + ['paged' => $next], admin_url('admin.php'))) . '">›</a> ';
    echo '<a class="button" href="' . esc_url(add_query_arg($base_args + ['paged' => $pages], admin_url('admin.php'))) . '">»</a>';
    echo '</span>';

    echo '</div></div>';
}

echo '</div>'; // wrap
