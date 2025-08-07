<?php
defined('ABSPATH') || exit;

class Piko_License_Manager
{
  private $table_name;

  public function __construct()
  {
    global $wpdb;
    $this->table_name = $wpdb->prefix . 'piko_licenses';

    add_action('admin_init', array($this, 'check_license_status'));
  }

  public static function create_license_table()
  {
    global $wpdb;
    $instance = new self();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$instance->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            license_key varchar(255) NOT NULL,
            user_id bigint(20) DEFAULT NULL,
            user_data text DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            activated_at datetime DEFAULT NULL,
            expires_at datetime NOT NULL,
            last_active datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY license_key (license_key),
            KEY user_id (user_id)
        ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Add version option to track updates
    add_option('piko_license_db_version', '1.0');
  }

  public function get_table_name()
  {
    return $this->table_name;
  }

  public function generate_license_key()
  {
    return strtoupper(wp_generate_password(16, false));
  }

  public function validate_license($user_id)
  {
    // Cek metadata user
    $is_active = get_user_meta($user_id, '_piko_license_active', true);
    if (!$is_active) return false;

    // Cek database license
    $license_key = get_user_meta($user_id, '_piko_license_key', true);
    $license = $this->get_license_by_key($license_key);

    return $license && $license->status === 'active';
  }

  public function activate_license($license_key, $user_id, $user_data)
  {
    global $wpdb;

    $license = $this->validate_license($license_key);

    if ($license) {
      $result = $wpdb->update(
        $this->table_name,
        array(
          'user_id' => $user_id,
          'user_data' => maybe_serialize($user_data),
          'activated_at' => current_time('mysql'),
          'last_active' => current_time('mysql'),
          'status' => 'active'
        ),
        array('license_key' => $license_key),
        array('%d', '%s', '%s', '%s', '%s'),
        array('%s')
      );

      return $result !== false;
    }

    return false;
  }

  public function deactivate_license($license_key)
  {
    global $wpdb;

    $result = $wpdb->update(
      $this->table_name,
      array(
        'status' => 'inactive',
        'last_active' => current_time('mysql')
      ),
      array('license_key' => $license_key),
      array('%s', '%s'),
      array('%s')
    );

    return $result !== false;
  }

  public function check_license_status()
  {
    // Skip jika di halaman activation atau user admin
    if ($this->is_activation_page() || current_user_can('administrator')) {
      return;
    }

    $user_id = get_current_user_id();
    $license_active = get_user_meta($user_id, '_piko_license_active', true);

    // Skip jika tidak perlu redirect
    $current_page = isset($_GET['page']) ? $_GET['page'] : '';
    if (empty($current_page) || strpos($current_page, 'piko-') !== 0) {
      return;
    }

    if (!$license_active) {
      wp_redirect(admin_url('admin.php?page=piko-plugin-activation'));
      exit;
    }
  }

  public function show_activation_notice()
  {
    $activation_url = admin_url('admin.php?page=piko-plugin-activation');
?>
    <div class="notice notice-error">
      <p><?php printf(
            __('Piko Plugin Booster membutuhkan aktivasi lisensi. <a href="%s">Klik di sini untuk mengaktifkan</a>.', 'piko-plugin-booster'),
            esc_url($activation_url)
          ); ?></p>
    </div>
<?php
  }

  private function is_activation_page()
  {
    return (isset($_GET['page']) && $_GET['page'] === 'piko-plugin-activation');
  }

  public function get_all_licenses()
  {
    global $wpdb;
    return $wpdb->get_results(
      "SELECT l.*, u.user_login, u.user_email 
             FROM {$this->table_name} l
             LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
             ORDER BY l.created_at DESC"
    );
  }

  public function get_license_by_user($user_id)
  {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->table_name} WHERE user_id = %d",
      $user_id
    ));
  }

  public function get_license_by_key($license_key)
  {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM {$this->table_name} WHERE license_key = %s",
      $license_key
    ));
  }

  public function add_new_license($data)
  {
    global $wpdb;

    $defaults = array(
      'license_key' => $this->generate_license_key(),
      'status' => 'active',
      'created_at' => current_time('mysql'),
      'expires_at' => date('Y-m-d H:i:s', strtotime('+1 year'))
    );

    $data = wp_parse_args($data, $defaults);

    return $wpdb->insert(
      $this->table_name,
      $data,
      array('%s', '%s', '%s', '%s')
    );
  }

  public function update_license($license_id, $data)
  {
    global $wpdb;

    $data['last_active'] = current_time('mysql');

    return $wpdb->update(
      $this->table_name,
      $data,
      array('id' => $license_id),
      array('%s', '%s', '%s'),
      array('%d')
    );
  }

  public function delete_license($license_id)
  {
    global $wpdb;

    return $wpdb->delete(
      $this->table_name,
      array('id' => $license_id),
      array('%d')
    );
  }

  public function get_active_user_count()
  {
    global $wpdb;

    return $wpdb->get_var(
      "SELECT COUNT(DISTINCT user_id) 
             FROM {$this->table_name} 
             WHERE status = 'active' AND user_id IS NOT NULL"
    );
  }

  public static function verify_table_exists()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'piko_licenses';
    return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
  }

  public static function maybe_upgrade_database()
  {
    $current_version = get_option('piko_license_db_version', '0');

    if (version_compare($current_version, '1.0', '<')) {
      self::create_license_table();
      update_option('piko_license_db_version', '1.0');
    }
  }
}
