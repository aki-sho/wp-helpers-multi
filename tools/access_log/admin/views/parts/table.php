<?php
if (!defined('ABSPATH')) exit;

$items = $data['result']['items'] ?? [];
if (!is_array($items)) $items = [];

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
      <th style="width:80px;">User</th>
      <th>URL</th>
      <th>Referrer</th>
      <th>UA</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$items): ?>
      <tr><td colspan="10">ログがありません。</td></tr>
    <?php else: ?>
      <?php foreach ($items as $r): ?>
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
