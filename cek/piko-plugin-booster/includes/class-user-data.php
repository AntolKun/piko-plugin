<?php
defined('ABSPATH') || exit;

class Piko_User_Data
{
  private $license_manager;

  public function __construct($license_manager)
  {
    $this->license_manager = $license_manager;

    add_action('admin_menu', array($this, 'add_activation_page'));
    add_action('admin_post_piko_save_user_data', array($this, 'save_user_data'));
    add_action('admin_post_piko_activate_license', array($this, 'activate_license'));
  }

  public function add_activation_page()
  {
    add_menu_page(
      __('Aktivasi Piko Plugin', 'piko-plugin-booster'),
      __('Piko Plugin', 'piko-plugin-booster'),
      'read',
      'piko-plugin-activation',
      array($this, 'render_activation_page'),
      'dashicons-lock',
      80
    );
  }

  public function render_activation_page()
  {
    if (!function_exists('wp_nonce_field')) {
      require_once(ABSPATH . WPINC . '/pluggable.php');
    }

    $user_id = get_current_user_id();
    $user_data = get_user_meta($user_id, '_piko_user_data', true);
    $license_active = get_user_meta($user_id, '_piko_license_active', true);

    if ($license_active) {
      include PIKO_PLUGIN_DIR . 'templates/activation-success.php';
    } elseif (empty($user_data)) {
      include PIKO_PLUGIN_DIR . 'templates/user-data-form.php';
    } else {
      include PIKO_PLUGIN_DIR . 'templates/activation-form.php';
    }
  }

  public function save_user_data()
  {
    if (!isset($_POST['piko_user_data_nonce']) || !wp_verify_nonce($_POST['piko_user_data_nonce'], 'piko_save_user_data')) {
      wp_die(__('Aksi tidak diizinkan.', 'piko-plugin-booster'));
    }

    $user_id = get_current_user_id();
    $user_data = array(
      'nama' => sanitize_text_field($_POST['nama']),
      'email' => sanitize_email($_POST['email']),
      'telepon' => sanitize_text_field($_POST['telepon']),
      'alamat' => sanitize_textarea_field($_POST['alamat'])
    );

    update_user_meta($user_id, '_piko_user_data', $user_data);

    wp_redirect(admin_url('admin.php?page=piko-plugin-activation'));
    exit;
  }

  public function activate_license()
  {
    if (!isset($_POST['piko_activate_license_nonce']) || !wp_verify_nonce($_POST['piko_activate_license_nonce'], 'piko_activate_license')) {
      wp_die(__('Aksi tidak diizinkan.', 'piko-plugin-booster'));
    }

    $user_id = get_current_user_id();
    $license_key = sanitize_text_field($_POST['license_key']);
    $user_data = get_user_meta($user_id, '_piko_user_data', true);

    if ($this->license_manager->activate_license($license_key, $user_id, $user_data)) {
      update_user_meta($user_id, '_piko_license_active', true);
      update_user_meta($user_id, '_piko_license_key', $license_key);

      // Update user role if needed
      $user = get_userdata($user_id);
      if (in_array('subscriber', $user->roles)) {
        $user->add_role('piko_member');
      }

      $this->log_activation($user_id, $license_key);

      wp_redirect(admin_url('admin.php?page=piko-plugin-activation&activated=1'));
    } else {
      wp_redirect(admin_url('admin.php?page=piko-plugin-activation&error=1'));
    }

    exit;
  }

  private function log_activation($user_id, $license_key)
  {
    $user = get_userdata($user_id);
    $log_message = sprintf(
      '[Piko Plugin] User %s (ID: %d) activated license %s on %s',
      $user->user_login,
      $user_id,
      $license_key,
      current_time('mysql')
    );
    error_log($log_message);
  }
}
