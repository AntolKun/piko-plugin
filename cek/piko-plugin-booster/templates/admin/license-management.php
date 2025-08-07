<div class="wrap">
  <h1><?php _e('Kelola Lisensi Piko Plugin Booster', 'piko-plugin-booster'); ?></h1>

  <?php if (isset($_GET['license_generated'])): ?>
    <div class="notice notice-success">
      <p><?php _e('Lisensi baru berhasil dibuat!', 'piko-plugin-booster'); ?></p>
    </div>
  <?php elseif (isset($_GET['license_updated'])): ?>
    <div class="notice notice-success">
      <p><?php _e('Lisensi berhasil diperbarui!', 'piko-plugin-booster'); ?></p>
    </div>
  <?php elseif (isset($_GET['license_deleted'])): ?>
    <div class="notice notice-success">
      <p><?php _e('Lisensi berhasil dihapus!', 'piko-plugin-booster'); ?></p>
    </div>
  <?php endif; ?>

  <h2><?php _e('Buat Lisensi Baru', 'piko-plugin-booster'); ?></h2>
  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <input type="hidden" name="action" value="piko_generate_license">
    <?php wp_nonce_field('piko_generate_license', 'piko_generate_license_nonce'); ?>

    <table class="form-table">
      <tr>
        <th scope="row"><label for="expiry_date"><?php _e('Tanggal Kedaluwarsa', 'piko-plugin-booster'); ?></label></th>
        <td>
          <input type="date" name="expiry_date" id="expiry_date" required min="<?php echo date('Y-m-d'); ?>">
          <p class="description"><?php _e('Tanggal ketika lisensi ini akan kedaluwarsa.', 'piko-plugin-booster'); ?></p>
        </td>
      </tr>
    </table>

    <p class="submit">
      <input type="submit" class="button button-primary" value="<?php _e('Buat Lisensi', 'piko-plugin-booster'); ?>">
    </p>
  </form>

  <hr>

  <h2><?php _e('Daftar Lisensi', 'piko-plugin-booster'); ?></h2>

  <div class="tablenav top">
    <div class="alignleft actions">
      <a href="<?php echo admin_url('admin.php?page=piko-license-stats'); ?>" class="button">
        <?php _e('Lihat Statistik', 'piko-plugin-booster'); ?>
      </a>
    </div>
    <br class="clear">
  </div>

  <table class="wp-list-table widefat fixed striped">
    <thead>
      <tr>
        <th><?php _e('ID', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Kode Lisensi', 'piko-plugin-booster'); ?></th>
        <th><?php _e('User', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Email', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Status', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Dibuat', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Diaktifkan', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Kedaluwarsa', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Terakhir Aktif', 'piko-plugin-booster'); ?></th>
        <th><?php _e('Aksi', 'piko-plugin-booster'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($licenses)): ?>
        <tr>
          <td colspan="10"><?php _e('Belum ada lisensi yang dibuat.', 'piko-plugin-booster'); ?></td>
        </tr>
      <?php else: ?>
        <?php foreach ($licenses as $license): ?>
          <?php
          $user_data = maybe_unserialize($license->user_data);
          $user_info = $license->user_id ? get_userdata($license->user_id) : null;
          ?>
          <tr>
            <td><?php echo $license->id; ?></td>
            <td><code><?php echo esc_html($license->license_key); ?></code></td>
            <td>
              <?php if ($license->user_id && $user_info): ?>
                <a href="<?php echo get_edit_user_link($license->user_id); ?>">
                  <?php echo esc_html($user_data['nama'] ?? $user_info->display_name); ?>
                </a>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td>
              <?php if ($license->user_id): ?>
                <?php echo esc_html($user_data['email'] ?? ($user_info ? $user_info->user_email : '-')); ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>
            <td>
              <span class="license-status <?php echo esc_attr($license->status); ?>">
                <?php echo ucfirst($license->status); ?>
              </span>
            </td>
            <td><?php echo date_i18n('d M Y', strtotime($license->created_at)); ?></td>
            <td>
              <?php echo $license->activated_at ? date_i18n('d M Y', strtotime($license->activated_at)) : '-'; ?>
            </td>
            <td>
              <?php echo date_i18n('d M Y', strtotime($license->expires_at)); ?>
              <?php if (strtotime($license->expires_at) < time()): ?>
                <span class="dashicons dashicons-warning" title="<?php _e('Lisensi telah kadaluarsa', 'piko-plugin-booster'); ?>"></span>
              <?php endif; ?>
            </td>
            <td>
              <?php echo $license->last_active ? date_i18n('d M Y H:i', strtotime($license->last_active)) : '-'; ?>
            </td>
            <td>
              <a href="#edit-license-<?php echo $license->id; ?>" class="edit-license" data-id="<?php echo $license->id; ?>">
                <?php _e('Edit', 'piko-plugin-booster'); ?>
              </a> |
              <a href="#delete-license-<?php echo $license->id; ?>" class="delete-license" data-id="<?php echo $license->id; ?>">
                <?php _e('Hapus', 'piko-plugin-booster'); ?>
              </a>

              <!-- Edit Form -->
              <div id="edit-license-<?php echo $license->id; ?>" style="display:none;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="license-edit-form">
                  <input type="hidden" name="action" value="piko_update_license">
                  <input type="hidden" name="license_id" value="<?php echo $license->id; ?>">
                  <?php wp_nonce_field('piko_update_license', 'piko_update_license_nonce'); ?>

                  <select name="status" style="vertical-align: middle;">
                    <option value="active" <?php selected($license->status, 'active'); ?>>
                      <?php _e('Aktif', 'piko-plugin-booster'); ?>
                    </option>
                    <option value="inactive" <?php selected($license->status, 'inactive'); ?>>
                      <?php _e('Nonaktif', 'piko-plugin-booster'); ?>
                    </option>
                  </select>

                  <input type="date" name="expiry_date"
                    value="<?php echo date('Y-m-d', strtotime($license->expires_at)); ?>"
                    min="<?php echo date('Y-m-d'); ?>"
                    style="vertical-align: middle;">

                  <button type="submit" class="button button-small" style="vertical-align: middle;">
                    <?php _e('Simpan', 'piko-plugin-booster'); ?>
                  </button>
                  <a href="#cancel" class="cancel-edit button button-small" style="vertical-align: middle;">
                    <?php _e('Batal', 'piko-plugin-booster'); ?>
                  </a>
                </form>
              </div>

              <!-- Delete Form -->
              <div id="delete-license-<?php echo $license->id; ?>" style="display:none;">
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="license-delete-form">
                  <input type="hidden" name="action" value="piko_delete_license">
                  <input type="hidden" name="license_id" value="<?php echo $license->id; ?>">
                  <?php wp_nonce_field('piko_delete_license', 'piko_delete_license_nonce'); ?>

                  <p><?php _e('Anda yakin ingin menghapus lisensi ini?', 'piko-plugin-booster'); ?></p>

                  <button type="submit" class="button button-small button-danger">
                    <?php _e('Hapus', 'piko-plugin-booster'); ?>
                  </button>
                  <a href="#cancel" class="cancel-delete button button-small">
                    <?php _e('Batal', 'piko-plugin-booster'); ?>
                  </a>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<style>
  .license-status {
    padding: 4px 8px;
    border-radius: 3px;
    color: #fff;
    font-weight: bold;
  }

  .license-status.active {
    background-color: #46b450;
  }

  .license-status.inactive {
    background-color: #dc3232;
  }
</style>

<script>
  jQuery(document).ready(function($) {
    // Edit license toggle
    $('.edit-license').on('click', function(e) {
      e.preventDefault();
      var licenseId = $(this).data('id');
      $('#edit-license-' + licenseId).show();
      $(this).hide();
      $(this).siblings('.delete-license').hide();
    });

    // Cancel edit
    $('.cancel-edit').on('click', function(e) {
      e.preventDefault();
      var form = $(this).closest('.license-edit-form');
      form.closest('div').hide();
      form.closest('td').find('.edit-license, .delete-license').show();
    });

    // Delete license toggle
    $('.delete-license').on('click', function(e) {
      e.preventDefault();
      var licenseId = $(this).data('id');
      $('#delete-license-' + licenseId).show();
      $(this).hide();
      $(this).siblings('.edit-license').hide();
    });

    // Cancel delete
    $('.cancel-delete').on('click', function(e) {
      e.preventDefault();
      var form = $(this).closest('.license-delete-form');
      form.closest('div').hide();
      form.closest('td').find('.edit-license, .delete-license').show();
    });
  });
</script>