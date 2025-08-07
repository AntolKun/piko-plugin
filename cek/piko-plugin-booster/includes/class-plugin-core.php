<?php
defined('ABSPATH') || exit;

class Piko_Plugin_Core
{
  private $license_manager;

  public function __construct($license_manager)
  {
    $this->license_manager = $license_manager;

    add_action('admin_init', array($this, 'check_plugin_access'));
    add_action('init', array($this, 'register_user_role'));
    add_action('wp_enqueue_scripts', [$this, 'load_user_assets']);
  }

  public function load_user_assets()
  {
    if (is_admin()) return;

    wp_enqueue_style(
      'piko-user-styles',
      PIKO_PLUGIN_URL . 'assets/css/user.css',
      [],
      PIKO_PLUGIN_VERSION
    );
  }

  public function check_plugin_access()
  {
    // Skip untuk admin atau halaman tertentu
    if (current_user_can('administrator') || $this->is_activation_page()) {
      return;
    }

    $user_id = get_current_user_id();
    $license_active = get_user_meta($user_id, '_piko_license_active', true);

    // Pastikan kita hanya memproses halaman plugin kita
    if ($this->is_plugin_page() && !$license_active) {
      wp_redirect(admin_url('admin.php?page=piko-plugin-activation'));
      exit;
    }
  }

  private function is_activation_page() 
  {
    return (isset($_GET['page']) && $_GET['page'] === 'piko-plugin-activation');
  }

  public function register_user_role()
  {
    add_role('piko_member', __('Piko Member', 'piko-plugin-booster'), array(
      'read' => true,
      'edit_posts' => false,
      'delete_posts' => false
    ));
  }

  private function is_plugin_page()
  {
    return (isset($_GET['page']) && strpos($_GET['page'], 'piko-') === 0);
  }
  
}
