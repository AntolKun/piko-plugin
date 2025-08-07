<div class="wrap">
  <h1>SERP Click Reports</h1>

  <table class="wp-list-table widefat fixed striped">
    <thead>
      <tr>
        <th>ID</th>
        <th>URL ID</th>
        <th>Waktu Klik</th>
        <th>IP Address</th>
        <th>User Agent</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($clicks)): ?>
        <tr>
          <td colspan="5">Belum ada data klik.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($clicks as $click): ?>
          <tr>
            <td><?php echo $click->id; ?></td>
            <td><?php echo $click->url_id; ?></td>
            <td><?php echo date('Y-m-d H:i:s', strtotime($click->click_time)); ?></td>
            <td><?php echo $click->ip_address; ?></td>
            <td><?php echo substr($click->user_agent, 0, 50); ?>...</td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>