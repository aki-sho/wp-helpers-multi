<?php
if (!defined('ABSPATH')) exit;
?>

<table class="widefat fixed striped">
    <thead>
        <tr>
            <td style="width:40px;">
                <input type="checkbox" onclick="document.querySelectorAll('.wphm-comment-check').forEach(el => el.checked = this.checked);">
            </td>
            <th style="width:70px;">ID</th>
            <th style="width:180px;">投稿</th>
            <th style="width:180px;">投稿者</th>
            <th>コメント</th>
            <th style="width:110px;">状態</th>
            <th style="width:160px;">日時</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr>
                <td colspan="7">コメントがありません。</td>
            </tr>
        <?php else: ?>
            <?php foreach ($items as $comment): ?>
                <?php
                $comment_id = (int) $comment->comment_ID;
                $post_id = (int) $comment->comment_post_ID;
                $post_title = get_the_title($post_id);
                $author = $comment->comment_author ?: '名前なし';
                $email = $comment->comment_author_email ?: '';
                $status_raw = wp_get_comment_status($comment);
                $status_label = wphm_comment_manager_get_status_label((string)$status_raw);
                $content = wphm_comment_manager_excerpt((string)$comment->comment_content, 120);
                $edit_link = get_edit_comment_link($comment_id);
                $post_link = get_edit_post_link($post_id);
                ?>
                <tr>
                    <td>
                        <input class="wphm-comment-check" type="checkbox" name="ids[]" value="<?php echo $comment_id; ?>">
                    </td>
                    <td><?php echo $comment_id; ?></td>
                    <td>
                        <?php if ($post_link): ?>
                            <a href="<?php echo esc_url($post_link); ?>">
                                <?php echo esc_html($post_title !== '' ? $post_title : ('投稿ID: ' . $post_id)); ?>
                            </a>
                        <?php else: ?>
                            <?php echo esc_html($post_title !== '' ? $post_title : ('投稿ID: ' . $post_id)); ?>
                        <?php endif; ?>
                        <div class="wphm-comment-meta">Post ID: <?php echo $post_id; ?></div>
                    </td>
                    <td>
                        <div><?php echo esc_html($author); ?></div>
                        <?php if ($email !== ''): ?>
                            <div class="wphm-comment-meta"><?php echo esc_html($email); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="wphm-comment-content"><?php echo esc_html($content); ?></div>
                        <?php if ($edit_link): ?>
                            <div class="wphm-comment-meta">
                                <a href="<?php echo esc_url($edit_link); ?>">WordPress標準画面で開く</a>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($status_label); ?></td>
                    <td><?php echo esc_html(get_date_from_gmt($comment->comment_date_gmt, 'Y-m-d H:i:s')); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>