<?php
if (!defined('ABSPATH')) exit;

$items = $data['result']['items'] ?? [];
if (!is_array($items)) $items = [];
$blocked_ips = $data['blocked_ips'] ?? [];
$spam_comment_counts = $data['spam_comment_counts'] ?? [];

?>
<table class="widefat striped">
  <thead>
    <tr>
      <th style="width:32px;"><input type="checkbox" onclick="document.querySelectorAll('.wphm-alog-cb').forEach(x=>x.checked=this.checked)"></th>
      <th style="width:70px;">ID</th>
      <th style="width:160px;">日時</th>
      <th style="width:70px;">Method</th>
      <th style="width:70px;">Status</th>
      <th style="width:140px;">IP</th>
      <th style="width:120px;">IP拒否</th>
      <th style="width:120px;">スパムコメント</th>
      <th style="width:80px;">User</th>
      <th>URL</th>
      <th>Referrer</th>
      <th>UA</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="12">ログがありません。</td></tr>
    <?php else: ?>
      <?php foreach ($items as $r): ?>
        <?php
          $normalized_ip = function_exists('wphm_ip_blocklist_normalize_ip')
            ? wphm_ip_blocklist_normalize_ip((string)$r['ip'])
            : trim((string)$r['ip']);
          $ip_blocked = $normalized_ip !== '' && isset($blocked_ips[$normalized_ip]);
          $spam_count = (int)($spam_comment_counts[$normalized_ip] ?? 0);
          $spam_comments_url = $spam_count > 0 && function_exists('wphm_comment_manager_admin_url')
            ? wphm_comment_manager_admin_url(['status' => 'spam', 's' => $normalized_ip])
            : '';
        ?>
        <tr>
          <td>
            <input class="wphm-alog-cb" type="checkbox" name="ids[]"
              value="<?php echo (int)$r['id']; ?>">
          </td>
          <td><?php echo (int)$r['id']; ?></td>
          <td><?php echo esc_html(date('Y-m-d H:i:s', (int)$r['created_at'])); ?></td>
          <td><?php echo esc_html($r['method']); ?></td>
          <td><?php echo esc_html($r['status']); ?></td>
          <td><?php echo esc_html($r['ip']); ?></td>
          <td>
            <?php if ($normalized_ip === ''): ?>
              —
            <?php elseif ($ip_blocked): ?>
              <div class="wphm-accesslog-blocked">拒否中</div>
              <button class="button button-small wphm-accesslog-ip-action" type="submit"
                name="wphm_access_log_unblock_ip" value="<?php echo esc_attr($normalized_ip); ?>"
                onclick="return confirm('IPアドレス <?php echo esc_js($normalized_ip); ?> の拒否を解除しますか？');">
                拒否解除
              </button>
            <?php else: ?>
              <div class="wphm-accesslog-allowed">未拒否</div>
              <button class="button button-small wphm-accesslog-ip-action" type="submit"
                name="wphm_access_log_block_ip" value="<?php echo esc_attr($normalized_ip); ?>"
                onclick="return confirm('IPアドレス <?php echo esc_js($normalized_ip); ?> を拒否しますか？');">
                IP拒否
              </button>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($spam_count > 0): ?>
              <?php if ($spam_comments_url !== ''): ?>
                <a href="<?php echo esc_url($spam_comments_url); ?>"><strong>あり（<?php echo $spam_count; ?>件）</strong></a>
              <?php else: ?>
                <strong>あり（<?php echo $spam_count; ?>件）</strong>
              <?php endif; ?>
            <?php else: ?>
              なし
            <?php endif; ?>
          </td>
          <td><?php echo (int)$r['user_id']; ?></td>
          <td style="max-width:420px; word-break:break-all;">
            <a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener">
              <?php echo esc_html($r['url']); ?>
            </a>
          </td>
          <td style="max-width:260px; word-break:break-all;">
            <?php echo $r['referrer'] ? '<a href="' . esc_url($r['referrer']) . '" target="_blank" rel="noopener">' . esc_html($r['referrer']) . '</a>' : ''; ?>
          </td>
          <td style="max-width:260px; word-break:break-all;">
            <?php echo esc_html($r['user_agent']); ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
  </tbody>
</table>
