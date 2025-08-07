<div class="wrap">
  <h1><?php _e('Aktivasi Piko Plugin Booster', 'piko-plugin-booster'); ?></h1>

  <?php if (isset($_GET['error'])): ?>
    <div class="notice notice-error">
      <p><?php _e('Terjadi kesalahan saat menyimpan data. Silakan coba lagi.', 'piko-plugin-booster'); ?></p>
    </div>
  <?php endif; ?>

  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <input type="hidden" name="action" value="piko_save_user_data">
    <?php wp_nonce_field('piko_save_user_data', 'piko_user_data_nonce'); ?>

    <table class="form-table">
      <tr>
        <th scope="row"><label for="nama"><?php _e('Nama Lengkap', 'piko-plugin-booster'); ?></label></th>
        <td>
          <input type="text" name="nama" id="nama" class="regular-text" required>
          <p class="description"><?php _e('Nama lengkap sesuai identitas', 'piko-plugin-booster'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="email"><?php _e('Email', 'piko-plugin-booster'); ?></label></th>
        <td>
          <input type="email" name="email" id="email" class="regular-text" required>
          <p class="description"><?php _e('Alamat email aktif', 'piko-plugin-booster'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="telepon"><?php _e('Nomor Telepon', 'piko-plugin-booster'); ?></label></th>
        <td>
          <input type="tel" name="telepon" id="telepon" class="regular-text" required>
          <p class="description"><?php _e('Nomor yang bisa dihubungi', 'piko-plugin-booster'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="alamat"><?php _e('Alamat Lengkap', 'piko-plugin-booster'); ?></label></th>
        <td>
          <textarea name="alamat" id="alamat" class="regular-text" rows="5" required></textarea>
          <p class="description"><?php _e('Alamat tempat tinggal saat ini', 'piko-plugin-booster'); ?></p>
        </td>
      </tr>
    </table>

    <p class="submit">
      <input type="submit" class="button button-primary" value="<?php _e('Simpan Data', 'piko-plugin-booster'); ?>">
    </p>
  </form>
</div>