<div class="wrap">
  <h1><?php _e('Aktivasi Lisensi Piko Plugin Booster', 'piko-plugin-booster'); ?></h1>

  <?php if (isset($_GET['error'])): ?>
    <div class="notice notice-error">
      <p><?php _e('Kode lisensi tidak valid. Silakan coba lagi atau hubungi administrator.', 'piko-plugin-booster'); ?></p>
    </div>
  <?php endif; ?>

  <div class="notice notice-info">
    <p><?php _e('Silakan masukkan kode lisensi yang Anda dapatkan setelah pembelian.', 'piko-plugin-booster'); ?></p>
  </div>

  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <input type="hidden" name="action" value="piko_activate_license">
    <?php wp_nonce_field('piko_activate_license', 'piko_activate_license_nonce'); ?>

    <table class="form-table">
      <tr>
        <th scope="row"><label for="license_key"><?php _e('Kode Lisensi', 'piko-plugin-booster'); ?></label></th>
        <td>
          <input type="text" name="license_key" id="license_key" class="regular-text" required>
          <p class="description"><?php _e('Masukkan 16 karakter kode lisensi Anda', 'piko-plugin-booster'); ?></p>
        </td>
      </tr>
    </table>

    <p class="submit">
      <input type="submit" class="button button-primary" value="<?php _e('Aktifkan Lisensi', 'piko-plugin-booster'); ?>">
    </p>
  </form>
</div>