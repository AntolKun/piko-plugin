<?php
defined('ABSPATH') || exit;

class Piko_Admin_Dashboard
{
  private $license_manager;
  private $serp_scraper;

  public function __construct($license_manager)
  {
    $this->license_manager = $license_manager;
    $this->serp_scraper = new Piko_Serp_Scraper($license_manager); // Inisialisasi di sini

    add_action('admin_menu', [$this, 'unified_admin_menu']);
  }

  public static function cleanup()
  {
    // Cleanup operations on deactivation
    delete_option('piko_license_db_version');
  }

  public function unified_admin_menu()
  {
    // Menu Utama
    add_menu_page(
      'Piko Plugin',
      'Piko Plugin',
      'manage_options',
      'piko-main-menu',
      [$this, 'render_dashboard'],
      'dashicons-admin-generic',
      30
    );

    // Submenu: License Management (dari class ini)
    add_submenu_page(
      'piko-main-menu',
      'License Management',
      'Licenses',
      'manage_options',
      'piko-license-management',
      [$this, 'render_license_management_page']
    );

    // Submenu: SERP Scraper (dari class scraper)
    add_submenu_page(
      'piko-main-menu',
      'SERP Scraper',
      'SERP Scraper',
      'manage_options',
      'piko-serp-scraper',
      [$this->serp_scraper, 'render_scraper_page']
    );

    // Submenu: SERP Reports (dari class scraper)
    add_submenu_page(
      'piko-main-menu',
      'SERP Reports',
      'SERP Reports',
      'manage_options',
      'piko-serp-reports',
      [$this->serp_scraper, 'render_reports_page']
    );
  }

  public function render_api_settings_page()
  {
    // Handle form submission
    if (isset($_POST['submit_api_key'])) {
      check_admin_referer('piko_save_api_key');
      update_option('piko_serpapi_key', sanitize_text_field($_POST['serpapi_key']));
      echo '<div class="notice notice-success"><p>API key saved!</p></div>';
    }

    // Get current key
    $current_key = get_option('piko_serpapi_key', '');
?>
    <div class="wrap">
      <h1>SERPapi Settings</h1>
      <form method="post">
        <?php wp_nonce_field('piko_save_api_key'); ?>
        <table class="form-table">
          <tr>
            <th scope="row"><label for="serpapi_key">SERPapi Key</label></th>
            <td>
              <input type="password" name="serpapi_key" id="serpapi_key"
                value="<?php echo esc_attr($current_key); ?>"
                class="regular-text">
              <p class="description">Get your API key from <a href="https://serpapi.com" target="_blank">serpapi.com</a></p>
            </td>
          </tr>
        </table>
        <?php submit_button('Save API Key', 'primary', 'submit_api_key'); ?>
      </form>
    </div>
<?php
  }

  public function render_license_management_page()
  {
    if (!function_exists('wp_nonce_field')) {
      require_once(ABSPATH . WPINC . '/pluggable.php');
    }

    $licenses = $this->license_manager->get_all_licenses();
    include PIKO_PLUGIN_DIR . 'templates/admin/license-management.php';
  }

  public function render_license_stats_page()
  {
    $active_users = $this->license_manager->get_active_user_count();
    include PIKO_PLUGIN_DIR . 'templates/admin/license-stats.php';
  }

  public function sync_user_licenses()
  {
    // Only run in admin and not during AJAX requests
    if (!is_admin() || defined('DOING_AJAX')) {
      return;
    }

    global $wpdb;

    // Get all users with license keys
    $users_with_license = $wpdb->get_results(
      "SELECT user_id, meta_value as license_key 
             FROM {$wpdb->usermeta} 
             WHERE meta_key = '_piko_license_key'"
    );

    foreach ($users_with_license as $user) {
      $license = $this->license_manager->get_license_by_key($user->license_key);

      if ($license && (!$license->user_id || $license->user_id != $user->user_id)) {
        $wpdb->update(
          $this->license_manager->get_table_name(),
          array('user_id' => $user->user_id),
          array('license_key' => $user->license_key),
          array('%d'),
          array('%s')
        );

        // Update last active time
        $this->license_manager->update_license($license->id, array(
          'last_active' => current_time('mysql')
        ));
      }
    }
  }

  public function generate_license()
  {
    $this->verify_admin_request('piko_generate_license');

    $data = array(
      'expires_at' => sanitize_text_field($_POST['expiry_date'])
    );

    $this->license_manager->add_new_license($data);

    wp_redirect(admin_url('admin.php?page=piko-license-management&license_generated=1'));
    exit;
  }

  public function update_license()
  {
    $this->verify_admin_request('piko_update_license');

    $license_id = intval($_POST['license_id']);
    $data = array(
      'status' => sanitize_text_field($_POST['status']),
      'expires_at' => sanitize_text_field($_POST['expiry_date'])
    );

    $this->license_manager->update_license($license_id, $data);

    if ($data['status'] === 'inactive') {
      $this->deactivate_user_license($license_id);
    }

    wp_redirect(admin_url('admin.php?page=piko-license-management&license_updated=1'));
    exit;
  }

  public function delete_license()
  {
    $this->verify_admin_request('piko_delete_license');

    $license_id = intval($_POST['license_id']);
    $this->deactivate_user_license($license_id);
    $this->license_manager->delete_license($license_id);

    wp_redirect(admin_url('admin.php?page=piko-license-management&license_deleted=1'));
    exit;
  }

  private function verify_admin_request($action)
  {
    if (
      !current_user_can('manage_options') ||
      !isset($_POST[$action . '_nonce']) ||
      !wp_verify_nonce($_POST[$action . '_nonce'], $action)
    ) {
      wp_die(__('Aksi tidak diizinkan.', 'piko-plugin-booster'));
    }
  }

  private function deactivate_user_license($license_id)
  {
    global $wpdb;

    $license = $wpdb->get_row($wpdb->prepare(
      "SELECT user_id FROM {$this->license_manager->get_table_name()} WHERE id = %d",
      $license_id
    ));

    if ($license && $license->user_id) {
      delete_user_meta($license->user_id, '_piko_license_active');
      delete_user_meta($license->user_id, '_piko_license_key');

      // Optional: Downgrade user role
      $user = get_userdata($license->user_id);
      if ($user && in_array('piko_member', $user->roles)) {
        $user->remove_role('piko_member');
      }
    }
  }

  public function repair_database()
  {
    if (!Piko_License_Manager::verify_table_exists()) {
      Piko_License_Manager::create_license_table();
      return 'Table created';
    }

    // Perbaiki kolom yang mungkin hilang
    global $wpdb;
    $table_name = $this->license_manager->get_table_name();

    $wpdb->query("ALTER TABLE $table_name 
            ADD COLUMN IF NOT EXISTS last_active datetime DEFAULT NULL AFTER expires_at");

    return 'Database verified';
  }
}
