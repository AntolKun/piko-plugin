  <?php
  defined('ABSPATH') || exit;

  class Piko_Serp_Scraper
  {
    private $license_manager;
    private $api_key;
    private $table_name;

    public function __construct($license_manager)
    {
      $this->license_manager = $license_manager;
      $this->api_key = '43b9ec39abc4b75b5467d2c1b137f308236f0693e206b34e7dfe93d29ce51dd6';

      // Hapus hook admin_menu dari sini
      // Pindahkan ke Admin_Dashboard seperti solusi sebelumnya

      // Hanya menyisakan AJAX handlers
      add_action('wp_ajax_piko_scrape_serp', [$this, 'handle_ajax_scrape']);
      add_action('wp_ajax_nopriv_piko_track_click', [$this, 'track_click']);
      add_action('wp_ajax_piko_user_scrape', [$this, 'handle_user_scrape']);
    }

    public function handle_user_scrape()
    {
      check_ajax_referer('piko_user_scrape_nonce', 'security');

      $user_id = get_current_user_id();
      if (!$this->license_manager->validate_license($user_id)) {
        wp_send_json_error('License not active');
      }

      $keyword = sanitize_text_field($_POST['keyword']);
      $results = $this->scrape_serp($keyword);

      if (is_wp_error($results)) {
        wp_send_json_error($results->get_error_message());
      }

      ob_start();
  ?>
      <h3>Search Results for "<?php echo esc_html($keyword); ?>"</h3>
      <ul class="serp-results">
        <?php foreach ($results as $item): ?>
          <li>
            <h4><a href="<?php echo esc_url($item['url']); ?>" target="_blank"><?php echo esc_html($item['title']); ?></a></h4>
            <p><?php echo esc_html($item['snippet']); ?></p>
            <small><?php echo esc_url($item['url']); ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
  <?php
      $html = ob_get_clean();

      wp_send_json_success($html);
    }

    public function show_api_key_notice()
    {
      if (current_user_can('manage_options')) {
        echo '<div class="notice notice-error">';
        echo '<p>Please <a href="' . admin_url('admin.php?page=piko-api-settings') . '">enter your SERPapi key</a> to use the scraping feature.</p>';
        echo '</div>';
      }
    }

    public function render_scraper_page()
    {
      if (!current_user_can('manage_options')) {
        wp_die('Akses ditolak.');
      }
      include PIKO_PLUGIN_DIR . 'templates/admin/serp-scraper.php';
    }

    public function render_reports_page()
    {
      if (!current_user_can('manage_options')) {
        wp_die('Akses ditolak.');
      }
      global $wpdb;
      $clicks = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY click_time DESC");
      include PIKO_PLUGIN_DIR . 'templates/admin/serp-reports.php';
    }

    public function handle_ajax_scrape()
    {
      check_ajax_referer('piko_serp_nonce', 'security');

      if (!$this->license_manager->validate_license(get_current_user_id())) {
        wp_send_json_error('Lisensi tidak valid');
      }

      $keyword = sanitize_text_field($_POST['keyword']);
      $results = $this->scrape_serp($keyword);

      if (is_wp_error($results)) {
        wp_send_json_error($results->get_error_message());
      }

      wp_send_json_success($results);
    }

    private function scrape_serp($keyword)
    {
      if (empty($this->api_key)) {
        return new WP_Error('no_api_key', 'SERPapi key is missing');
      }

      $api_url = add_query_arg([
        'q' => urlencode($keyword),
        'api_key' => $this->api_key,
        'hl' => 'en',  // Bahasa Inggris
        'gl' => 'us'   // Negara US
      ], 'https://serpapi.com/search.json');

      $response = wp_remote_get($api_url, [
        'timeout' => 30,
        'sslverify' => false
      ]);

      if (is_wp_error($response)) {
        return $response;
      }

      $body = json_decode($response['body'], true);

      if (empty($body['organic_results'])) {
        return new WP_Error('no_results', 'Tidak ada hasil ditemukan');
      }

      return array_map(function ($item) {
        return [
          'title' => $item['title'] ?? '',
          'url' => $item['link'] ?? '',
          'snippet' => $item['snippet'] ?? ''
        ];
      }, $body['organic_results']);
    }

    public function track_click()
    {
      global $wpdb;
      $url_id = intval($_POST['url_id']);

      $wpdb->insert($this->table_name, [
        'url_id' => $url_id,
        'click_time' => current_time('mysql'),
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
      ]);

      wp_send_json_success();
    }

    public function handle_serp_redirect()
    {
      if (!isset($_GET['piko_serp_redirect'])) return;

      $url_id = intval($_GET['url_id']);
      $target_url = esc_url_raw(base64_decode($_GET['target']));

      // Simpan klik ke database
      global $wpdb;
      $wpdb->insert($this->table_name, [
        'url_id' => $url_id,
        'click_time' => current_time('mysql'),
        'ip_address' => $_SERVER['REMOTE_ADDR'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT']
      ]);

      // Manipulasi header sebelum redirect
      add_filter('wp_headers', function ($headers) {
        $headers['Piko-SERP'] = 'Redirect';
        return $headers;
      });

      wp_redirect($target_url);
      exit;
    }

    public static function create_click_table()
    {
      global $wpdb;
      $charset_collate = $wpdb->get_charset_collate();

      $sql = "CREATE TABLE {$wpdb->prefix}piko_serp_clicks (
              id bigint(20) NOT NULL AUTO_INCREMENT,
              url_id bigint(20) NOT NULL,
              click_time datetime NOT NULL,
              ip_address varchar(45) NOT NULL,
              user_agent text NOT NULL,
              PRIMARY KEY (id),
              KEY url_id (url_id),
              KEY click_time (click_time)
          ) $charset_collate;";

      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($sql);
    }
  }
