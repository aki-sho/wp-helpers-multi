<?php
if (!defined('ABSPATH')) exit;

/** @var array $data */
$status = $data['status'];
$search = $data['search'];
$post_id = $data['post_id'];
$result = $data['result'];
$msg = $data['msg'];
$done = $data['done'];
$counts = $data['counts'];
$status_options = $data['status_options'];

$items = $result['items'];
$paged = $result['paged'];
$pages = $result['pages'];

$title = 'コメント管理';

if (function_exists('wphm_render_header')) {
    echo '<div class="wrap wphm-app">';
    wphm_render_header($title);
} else {
    echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
}

if ($msg === 'updated') {
    echo '<div class="notice notice-success"><p>' . esc_html(wphm_comment_manager_get_notice_message($msg, $done)) . '</p></div>';
}
?>

<style>
.wphm-comment-filter-links {
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin:12px 0 16px;
}
.wphm-comment-filter-links a {
    text-decoration:none;
}
.wphm-comment-filter-links .is-current {
    font-weight:700;
}
.wphm-comment-actions {
    display:flex;
    gap:12px;
    align-items:flex-end;
    flex-wrap:wrap;
    margin:12px 0;
}
.wphm-comment-actions .field {
    display:flex;
    flex-direction:column;
    gap:4px;
}
.wphm-comment-actions input[type="text"],
.wphm-comment-actions input[type="number"],
.wphm-comment-actions select {
    min-width:180px;
}
.wphm-comment-bulk {
    display:flex;
    gap:10px;
    align-items:center;
    margin:10px 0;
    flex-wrap:wrap;
}
.wphm-comment-danger {
    color:#b32d2e;
}
.wphm-comment-meta {
    color:#666;
    font-size:12px;
}
.wphm-comment-content {
    white-space:pre-wrap;
    word-break:break-word;
}
</style>

<div class="wphm-comment-filter-links">
<?php
foreach ($status_options as $key => $label) {
    $url = wphm_comment_manager_admin_url([
        'status' => $key,
        's' => $search,
        'post_id' => $post_id,
    ]);

    $class = ($status === $key) ? 'is-current' : '';
    $count = isset($counts[$key]) ? (int)$counts[$key] : 0;

    echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">';
    echo esc_html($label) . ' (' . (int)$count . ')';
    echo '</a>';
}
?>
</div>

<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
    <input type="hidden" name="page" value="wphm-comment-manager">

    <div class="wphm-comment-actions">
        <div class="field">
            <label>状態</label>
            <select name="status">
                <?php foreach ($status_options as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>>
                        <?php echo esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label>検索</label>
            <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="本文・著者名・メールなど">
        </div>

        <div class="field">
            <label>投稿ID</label>
            <input type="number" name="post_id" value="<?php echo $post_id > 0 ? (int)$post_id : ''; ?>" min="1" placeholder="例: 123">
        </div>

        <div class="field">
            <button class="button button-primary" type="submit">絞り込み</button>
        </div>

        <div class="field">
            <a class="button" href="<?php echo esc_url(wphm_comment_manager_admin_url()); ?>">リセット</a>
        </div>
    </div>
</form>

<form method="post" action="<?php echo esc_url(wphm_comment_manager_admin_url([
    'status' => $status,
    's' => $search,
    'post_id' => $post_id,
    'paged' => $paged,
])); ?>">
    <?php wp_nonce_field('wphm_comment_manager_action'); ?>
    <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
    <input type="hidden" name="s" value="<?php echo esc_attr($search); ?>">
    <input type="hidden" name="post_id" value="<?php echo (int)$post_id; ?>">
    <input type="hidden" name="paged" value="<?php echo (int)$paged; ?>">

    <div class="wphm-comment-bulk">
        <button class="button" type="submit" name="wphm_comment_manager_action" value="approve">選択承認</button>
        <button class="button" type="submit" name="wphm_comment_manager_action" value="unapprove">選択承認待ち</button>
        <button class="button" type="submit" name="wphm_comment_manager_action" value="spam" onclick="return confirm('選択したコメントをスパムにします。よろしいですか？');">選択スパム</button>
        <button class="button" type="submit" name="wphm_comment_manager_action" value="trash" onclick="return confirm('選択したコメントをゴミ箱に移動します。よろしいですか？');">選択ゴミ箱</button>
        <button class="button wphm-comment-danger" type="submit" name="wphm_comment_manager_action" value="delete" onclick="return confirm('選択したコメントを完全削除します。よろしいですか？');">選択完全削除</button>
    </div>

    <?php require __DIR__ . '/parts/table.php'; ?>
</form>

<?php
if ($pages > 1) {
    $base_args = [
        'page' => 'wphm-comment-manager',
        'status' => $status,
        's' => $search,
        'post_id' => $post_id,
    ];

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

echo '</div>';