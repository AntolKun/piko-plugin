<div class="wrap">
  <h1><?php _e('Statistik Lisensi Piko Plugin Booster', 'piko-plugin-booster'); ?></h1>

  <div class="license-stats-container">
    <div class="stats-box">
      <h3><?php _e('Total Lisensi Aktif', 'piko-plugin-booster'); ?></h3>
      <div class="stats-number"><?php echo $active_users; ?></div>
    </div>
  </div>

  <a href="<?php echo admin_url('admin.php?page=piko-license-management'); ?>" class="button">
    <?php _e('Kembali ke Kelola Lisensi', 'piko-plugin-booster'); ?>
  </a>
</div>

<style>
  .license-stats-container {
    display: flex;
    flex-wrap: wrap;
    gap: 20px;
    margin: 20px 0;
  }

  .stats-box {
    background: #fff;
    border: 1px solid #ccd0d4;
    padding: 20px;
    border-radius: 4px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
    min-width: 250px;
  }

  .stats-number {
    font-size: 32px;
    font-weight: bold;
    color: #2271b1;
    margin: 10px 0;
  }
</style>