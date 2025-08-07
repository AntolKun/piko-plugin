<?php
/*
Plugin Name: WP License Manager with SERP Scraper
Description: Plugin untuk mengelola lisensi dengan fitur SERP scraping dan tracking
Version: 1.3
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

class WP_License_Manager_Enhanced
{
    private $table_name;
    private $serpapi_key;
    private $scraped_results_table;
    private $click_tracking_table;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'license_manager';
        $this->scraped_results_table = $wpdb->prefix . 'serp_scraped_results';
        $this->click_tracking_table = $wpdb->prefix . 'serp_click_tracking';
        $this->serpapi_key = '43b9ec39abc4b75b5467d2c1b137f308236f0693e206b34e7dfe93d29ce51dd6';

        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_menu', array($this, 'add_user_menu'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_shortcode('license_input_form', array($this, 'license_input_form_shortcode'));
        add_action('wp_ajax_generate_license', array($this, 'generate_license'));
        add_action('wp_ajax_validate_license', array($this, 'validate_license'));
        add_action('wp_ajax_delete_license', array($this, 'delete_license'));
        add_action('wp_ajax_serp_search', array($this, 'serp_search'));
        add_action('wp_ajax_scrape_url', array($this, 'scrape_url'));
        add_action('wp_ajax_get_click_reports', array($this, 'get_click_reports'));

        // Handle the landing page display
        add_action('template_redirect', array($this, 'handle_landing_page'));
    }

    public function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE $this->table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        license_key varchar(100) NOT NULL,
        customer_name varchar(100) DEFAULT NULL,
        customer_phone varchar(20) DEFAULT NULL,
        status varchar(20) DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY license_key (license_key)
    ) $charset_collate;";

        $sql2 = "CREATE TABLE $this->scraped_results_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        keyword varchar(255) NOT NULL,
        search_results longtext NOT NULL,
        selected_url varchar(512) DEFAULT NULL,
        scraped_content longtext DEFAULT NULL,
        allowed_devices text DEFAULT '[\"desktop\",\"mobile\",\"tablet\"]',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

        $sql3 = "CREATE TABLE $this->click_tracking_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        result_id mediumint(9) NOT NULL,
        visitor_ip varchar(45) DEFAULT NULL,
        user_agent varchar(255) DEFAULT NULL,
        referrer varchar(512) DEFAULT NULL,
        device_type varchar(20) DEFAULT 'desktop',
        viewport_width int DEFAULT NULL,
        viewport_height int DEFAULT NULL,
        clicked_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY result_id (result_id)
    ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        // Add column if not exists for existing installations
        $wpdb->query("ALTER TABLE $this->scraped_results_table 
                 ADD COLUMN IF NOT EXISTS allowed_devices text DEFAULT '[\"desktop\",\"mobile\",\"tablet\"]'");
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'License Manager',
            'License Manager',
            'manage_options',
            'license-manager',
            array($this, 'license_manager_page'),
            'dashicons-lock',
            80
        );
    }

    public function add_user_menu()
    {
        add_menu_page(
            'SERP Scraper',
            'SERP Scraper',
            'read',
            'serp-scraper',
            array($this, 'serp_scraper_page'),
            'dashicons-search',
            30
        );

        add_submenu_page(
            'serp-scraper',
            'Click Reports',
            'Click Reports',
            'read',
            'serp-reports',
            array($this, 'serp_reports_page')
        );
    }

    public function add_settings_page()
    {
        add_submenu_page(
            'license-manager', // Parent slug harus sesuai dengan menu utama
            'Device Settings',
            'Device Settings',
            'manage_options',
            'device-settings',
            array($this, 'device_settings_page')
        );
    }

    public function device_settings_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (isset($_POST['save_device_settings']) && check_admin_referer('device_settings_nonce')) {
            $device = sanitize_text_field($_POST['default_device']);
            update_option('serp_default_device', $device);
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }

        $current_device = get_option('serp_default_device', 'desktop');
?>
        <div class="wrap">
            <h1>Device Settings</h1>
            <form method="post">
                <?php wp_nonce_field('device_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="default_device">Default Device View</label></th>
                        <td>
                            <select name="default_device" id="default_device" class="regular-text">
                                <option value="desktop" <?php selected($current_device, 'desktop'); ?>>Desktop</option>
                                <option value="mobile" <?php selected($current_device, 'mobile'); ?>>Mobile</option>
                                <option value="tablet" <?php selected($current_device, 'tablet'); ?>>Tablet</option>
                            </select>
                            <p class="description">Set the default device view for previews</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
    <?php
    }

    public function license_manager_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        global $wpdb;

        if (isset($_POST['generate_license'])) {
            $this->handle_generate_license();
        }

        $licenses = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY created_at DESC");

        echo '<div class="wrap">';
        echo '<h1>License Manager</h1>';

        echo '<div class="card">';
        echo '<h2>Generate New License</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="generate_license" value="1">';

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row"><label for="customer_name">Customer Name</label></th>';
        echo '<td><input type="text" name="customer_name" id="customer_name" class="regular-text" required></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="customer_phone">Phone Number</label></th>';
        echo '<td><input type="text" name="customer_phone" id="customer_phone" class="regular-text" required></td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<input type="submit" class="button button-primary" value="Generate License Key">';
        echo '</p>';
        echo '</form>';
        echo '</div>';

        echo '<div class="card">';
        echo '<h2>Existing Licenses</h2>';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>ID</th><th>License Key</th><th>Customer Name</th><th>Phone</th><th>Status</th><th>Created At</th><th>Actions</th></tr></thead>';
        echo '<tbody>';

        foreach ($licenses as $license) {
            echo '<tr>';
            echo '<td>' . $license->id . '</td>';
            echo '<td>' . $license->license_key . '</td>';
            echo '<td>' . esc_html($license->customer_name) . '</td>';
            echo '<td>' . esc_html($license->customer_phone) . '</td>';
            echo '<td>' . ucfirst($license->status) . '</td>';
            echo '<td>' . $license->created_at . '</td>';
            echo '<td>';
            echo '<button class="button delete-license" data-id="' . $license->id . '">Delete</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';

        $this->admin_scripts();
    }

    public function serp_scraper_page()
    {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (!$this->has_valid_license()) {
            echo '<div class="notice notice-error"><p>You need a valid license to access this feature.</p></div>';
            echo do_shortcode('[license_input_form]');
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>SERP Scraper Tool</h1>';

        echo '<div class="serp-container" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">';

        // Left Column - Search
        echo '<div class="card">';
        echo '<h2>Search Google</h2>';
        echo '<form id="serp-search-form">';
        echo '<p><input type="text" name="keyword" id="serp-keyword" class="regular-text" placeholder="Enter keyword" required></p>';
        echo '<p><button type="submit" class="button button-primary">Search</button></p>';
        echo '</form>';
        echo '<div id="serp-results-container"></div>';
        echo '</div>';

        // Right Column - Scraping Tools
        echo '<div class="card" id="scraping-section" style="display:none;">';
        echo '<h2>Scrape URL</h2>';
        echo '<form id="scrape-url-form">';
        echo '<input type="hidden" name="result_id" id="result-id">';
        echo '<div id="selected-url-display" style="word-break:break-all;margin:10px 0;padding:10px;background:#f5f5f5;"></div>';

        // Device Restriction Section
        echo '<div class="device-restrict-container" style="margin:15px 0;padding:15px;background:#f8f9fa;border:1px solid #ddd;border-radius:4px;">';
        echo '<h3 style="margin-top:0;">Device Access Control</h3>';
        echo '<p style="margin-bottom:10px;">Select which devices can access this URL:</p>';
        echo '<label style="display:block;margin:5px 0;"><input type="checkbox" name="devices[]" value="desktop" checked> Desktop</label>';
        echo '<label style="display:block;margin:5px 0;"><input type="checkbox" name="devices[]" value="mobile" checked> Mobile</label>';
        echo '<label style="display:block;margin:5px 0;"><input type="checkbox" name="devices[]" value="tablet" checked> Tablet</label>';
        echo '</div>';

        echo '<button type="submit" class="button button-primary">Scrape URL</button>';
        echo '</form>';
        echo '<div id="scraping-result" style="margin-top:20px;"></div>';
        echo '</div>';

        echo '</div>'; // .serp-container
        echo '</div>'; // .wrap

        $this->serp_scraper_scripts();
    }

    public function serp_reports_page()
    {
        if (!current_user_can('read')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if (!$this->has_valid_license()) {
            echo '<div class="notice notice-error"><p>You need a valid license to access this feature.</p></div>';
            echo do_shortcode('[license_input_form]');
            return;
        }

        global $wpdb;
        $user_id = get_current_user_id();

        $scraped_results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, 
        (SELECT COUNT(*) FROM $this->click_tracking_table WHERE result_id = s.id) as click_count
        FROM $this->scraped_results_table s 
        WHERE s.user_id = %d 
        ORDER BY s.created_at DESC",
            $user_id
        ));

        echo '<div class="wrap">';
        echo '<h1>Click Reports</h1>';

        if (empty($scraped_results)) {
            echo '<div class="notice notice-info"><p>No scraped results found.</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>
        <th>ID</th>
        <th>Keyword</th>
        <th>URL</th>
        <th>Clicks</th>
        <th>Device Access</th>
        <th>Device Distribution</th>
        <th>Created</th>
        <th>Actions</th>
    </tr></thead>';
        echo '<tbody>';

        foreach ($scraped_results as $result) {
            if (empty($result->selected_url)) continue;

            // Get device distribution
            $device_stats = $wpdb->get_results($wpdb->prepare(
                "SELECT device_type, COUNT(*) as count 
            FROM $this->click_tracking_table 
            WHERE result_id = %d 
            GROUP BY device_type",
                $result->id
            ));

            // Format device distribution
            $device_distribution = '';
            foreach ($device_stats as $stat) {
                $device_distribution .= ucfirst($stat->device_type) . ': ' . $stat->count . '<br>';
            }

            echo '<tr>';
            echo '<td>' . $result->id . '</td>';
            echo '<td>' . esc_html($result->keyword) . '</td>';
            echo '<td><a href="' . esc_url($result->selected_url) . '" target="_blank">' . esc_html($this->shorten_url($result->selected_url, 40)) . '</a></td>';
            echo '<td>' . $result->click_count . '</td>';
            echo '<td>' . implode(', ', json_decode($result->allowed_devices, true)) . '</td>';
            echo '<td>' . $device_distribution . '</td>';
            echo '<td>' . date('Y-m-d H:i', strtotime($result->created_at)) . '</td>';
            echo '<td><button class="button view-clicks" data-id="' . $result->id . '">View Details</button></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Click details modal
        echo '<div id="click-details-modal" style="display:none;">';
        echo '<div class="modal-content" style="background:white;padding:20px;max-width:800px;margin:20px auto;border-radius:5px;box-shadow:0 0 20px rgba(0,0,0,0.2);">';
        echo '<h2>Click Details</h2>';
        echo '<div id="click-details-content"></div>';
        echo '<button class="button close-modal" style="margin-top:15px;">Close</button>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .wrap

        $this->reports_scripts();
    }

    private function handle_generate_license()
    {
        global $wpdb;

        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
        $customer_phone = isset($_POST['customer_phone']) ? sanitize_text_field($_POST['customer_phone']) : '';
        $license_key = wp_generate_password(16, false);

        $wpdb->insert(
            $this->table_name,
            array(
                'license_key' => $license_key,
                'customer_name' => $customer_name,
                'customer_phone' => $customer_phone,
                'status' => 'active'
            ),
            array('%s', '%s', '%s', '%s')
        );

        if ($wpdb->insert_id) {
            echo '<div class="notice notice-success"><p>License generated successfully!</p>';
            echo '<p><strong>License Key:</strong> ' . $license_key . '</p>';
            echo '<p><strong>Customer:</strong> ' . esc_html($customer_name) . '</p>';
            echo '<p><strong>Phone:</strong> ' . esc_html($customer_phone) . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to generate license.</p></div>';
        }
    }

    public function license_input_form_shortcode()
    {
        ob_start();
    ?>
        <div class="license-input-form">
            <h3>Enter Your License Key</h3>
            <form id="license-validation-form">
                <input type="text" name="license_key" placeholder="Enter license key" required>
                <button type="submit" class="button">Validate License</button>
            </form>
            <div id="license-validation-result"></div>
        </div>
    <?php
        $this->frontend_scripts();
        return ob_get_clean();
    }

    public function serp_search()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Unauthorized');
        }

        $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';

        if (empty($keyword)) {
            wp_send_json_error('Keyword is required');
        }

        // Call SERP API
        $api_url = 'https://serpapi.com/search.json?q=' . urlencode($keyword) . '&api_key=' . $this->serpapi_key;
        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['organic_results'])) {
            wp_send_json_error('No results found');
        }

        global $wpdb;
        $user_id = get_current_user_id();

        // Save search results to database
        $wpdb->insert(
            $this->scraped_results_table,
            array(
                'user_id' => $user_id,
                'keyword' => $keyword,
                'search_results' => json_encode($data['organic_results'])
            ),
            array('%d', '%s', '%s')
        );

        $result_id = $wpdb->insert_id;

        // Prepare results for display
        $output = '<h3>Search Results for: ' . esc_html($keyword) . '</h3>';
        $output .= '<ul class="serp-results-list">';

        foreach ($data['organic_results'] as $item) {
            if (empty($item['link'])) continue;

            $output .= '<li>';
            $output .= '<h4><a href="' . esc_url($item['link']) . '" target="_blank">' . esc_html($item['title'] ?? 'No title') . '</a></h4>';
            $output .= '<p>' . esc_html($item['snippet'] ?? 'No description') . '</p>';
            $output .= '<p class="url">' . esc_html($this->shorten_url($item['link'])) . '</p>';
            $output .= '<button class="button select-url" data-url="' . esc_url($item['link']) . '" data-result="' . $result_id . '">Select This URL</button>';
            $output .= '</li>';
        }

        $output .= '</ul>';

        wp_send_json_success($output);
    }

    public function scrape_url()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Unauthorized');
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $result_id = isset($_POST['result_id']) ? intval($_POST['result_id']) : 0;
        $devices = isset($_POST['devices']) ? array_map('sanitize_text_field', $_POST['devices']) : ['desktop', 'mobile', 'tablet'];

        if (empty($url) || empty($result_id)) {
            wp_send_json_error('Invalid parameters');
        }

        // Scrape with timeout and custom user-agent
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            )
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch URL: ' . $response->get_error_message());
        }

        $content = wp_remote_retrieve_body($response);

        if (empty($content)) {
            wp_send_json_error('The scraped content is empty');
        }

        global $wpdb;
        $wpdb->update(
            $this->scraped_results_table,
            array(
                'selected_url' => $url,
                'scraped_content' => $content,
                'allowed_devices' => json_encode($devices)
            ),
            array('id' => $result_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        // Generate URLs for each allowed device
        $output = '<div class="notice notice-success">';
        $output .= '<p><strong>URL scraped successfully!</strong> Share these links:</p>';
        $output .= '<ul style="list-style:disc;padding-left:20px;margin-top:10px;">';

        foreach ($devices as $device) {
            $landing_url = add_query_arg(array(
                'serp_landing' => $result_id,
                'device' => $device
            ), home_url('/'));

            $output .= '<li style="margin-bottom:5px;"><strong>' . ucfirst($device) . ':</strong> ';
            $output .= '<a href="' . esc_url($landing_url) . '" target="_blank" style="text-decoration:underline;">';
            $output .= esc_html($this->shorten_url($landing_url, 60)) . '</a></li>';
        }

        $output .= '</ul>';
        $output .= '<p style="margin-top:15px;">Clicks will be tracked separately for each device type.</p>';
        $output .= '</div>';

        wp_send_json_success($output);
    }

    public function handle_landing_page()
    {
        if (!isset($_GET['serp_landing'])) {
            return;
        }

        $result_id = intval($_GET['serp_landing']);
        $device = isset($_GET['device']) ? sanitize_text_field($_GET['device']) : 'desktop';

        global $wpdb;

        // Get the scraped result
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->scraped_results_table WHERE id = %d",
            $result_id
        ));

        if (!$result || empty($result->scraped_content)) {
            $this->show_landing_error('The requested content is not available or has expired.');
            return;
        }

        // Check device access
        $allowed_devices = json_decode($result->allowed_devices, true);
        if (!in_array($device, $allowed_devices)) {
            $this->show_landing_error('This content is not available for your device type (' . esc_html($device) . ').');
            return;
        }

        // Track the visit
        $this->track_click($result_id);

        // Render the content
        $this->render_landing_content($result->scraped_content);
    }

    private function show_landing_error($message)
    {
        status_header(404);
    ?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Content Unavailable</title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 600px;
                    margin: 0 auto;
                    padding: 20px;
                }

                .error-container {
                    background: #fff8f8;
                    border: 1px solid #ffdddd;
                    padding: 30px;
                    margin-top: 50px;
                    border-radius: 5px;
                    text-align: center;
                }

                .error-container h2 {
                    color: #d63638;
                    margin-top: 0;
                }

                .home-link {
                    display: inline-block;
                    margin-top: 15px;
                    padding: 8px 15px;
                    background: #2271b1;
                    color: white;
                    text-decoration: none;
                    border-radius: 3px;
                }

                .home-link:hover {
                    background: #135e96;
                }
            </style>
        </head>

        <body>
            <div class="error-container">
                <h2>Content Unavailable</h2>
                <p><?php echo esc_html($message); ?></p>
                <a href="<?php echo home_url('/'); ?>" class="home-link">Return to Homepage</a>
            </div>
        </body>

        </html>
    <?php
        exit;
    }

    private function render_landing_content($content)
    {
    ?>
        <!DOCTYPE html>
        <html>

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Content Preview</title>
            <style>
                body {
                    margin: 0;
                    padding: 0;
                    background: white;
                }

                img,
                iframe,
                video {
                    max-width: 100%;
                    height: auto;
                }
            </style>
        </head>

        <body>
            <?php echo $content; ?>
        </body>

        </html>
    <?php
        exit;
    }

    private function track_click($result_id)
    {
        global $wpdb;

        $track_data = array(
            'result_id' => $result_id,
            'visitor_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
            'device_type' => isset($_GET['device']) ? sanitize_text_field($_GET['device']) : 'desktop',
            'viewport_width' => isset($_GET['vpw']) ? intval($_GET['vpw']) : null,
            'viewport_height' => isset($_GET['vph']) ? intval($_GET['vph']) : null,
            'http_accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
            'request_time' => current_time('mysql')
        );

        try {
            $wpdb->insert(
                $this->click_tracking_table,
                $track_data,
                array(
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%s',
                    '%s'
                )
            );
        } catch (Exception $e) {
            error_log('Tracking error: ' . $e->getMessage());
        }
    }

    public function get_click_reports()
    {
        if (!current_user_can('read')) {
            wp_send_json_error('Unauthorized');
        }

        $result_id = isset($_POST['result_id']) ? intval($_POST['result_id']) : 0;

        if (empty($result_id)) {
            wp_send_json_error('Invalid result ID');
        }

        global $wpdb;

        $clicks = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->click_tracking_table 
        WHERE result_id = %d 
        ORDER BY clicked_at DESC",
            $result_id
        ));

        if (empty($clicks)) {
            wp_send_json_success('<p>No clicks recorded yet.</p>');
        }

        $output = '<table class="wp-list-table widefat fixed striped">';
        $output .= '<thead><tr>
        <th>Date</th>
        <th>Device</th>
        <th>IP Address</th>
        <th>User Agent</th>
        <th>Referrer</th>
    </tr></thead>';
        $output .= '<tbody>';

        foreach ($clicks as $click) {
            $output .= '<tr>';
            $output .= '<td>' . date('Y-m-d H:i', strtotime($click->clicked_at)) . '</td>';
            $output .= '<td>' . ucfirst($click->device_type) . '</td>';
            $output .= '<td>' . esc_html($click->visitor_ip) . '</td>';
            $output .= '<td>' . esc_html($this->shorten_string($click->user_agent, 50)) . '</td>';
            $output .= '<td>' . esc_html($this->shorten_string($click->referrer, 60)) . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody>';
        $output .= '</table>';

        // Add device statistics summary
        $device_stats = $wpdb->get_results($wpdb->prepare(
            "SELECT device_type, COUNT(*) as count 
        FROM $this->click_tracking_table 
        WHERE result_id = %d 
        GROUP BY device_type",
            $result_id
        ));

        $output .= '<div style="margin-top:30px;padding:15px;background:#f5f5f5;border-radius:5px;">';
        $output .= '<h3>Device Statistics</h3>';
        $output .= '<ul>';
        foreach ($device_stats as $stat) {
            $output .= '<li>' . ucfirst($stat->device_type) . ': ' . $stat->count . ' clicks</li>';
        }
        $output .= '</ul>';
        $output .= '</div>';

        wp_send_json_success($output);
    }

    public function delete_license()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $license_id = isset($_POST['license_id']) ? intval($_POST['license_id']) : 0;

        global $wpdb;
        $deleted = $wpdb->delete(
            $this->table_name,
            array('id' => $license_id),
            array('%d')
        );

        if ($deleted) {
            wp_send_json_success('License deleted successfully');
        } else {
            wp_send_json_error('Failed to delete license');
        }
    }

    private function has_valid_license()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        $license_key = get_user_meta(get_current_user_id(), 'valid_license_key', true);

        if (empty($license_key)) {
            return false;
        }

        global $wpdb;
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE license_key = %s AND status = 'active'",
            $license_key
        ));

        return $license !== null;
    }

    private function shorten_url($url, $length = 50)
    {
        if (strlen($url) <= $length) {
            return $url;
        }

        return substr($url, 0, $length) . '...';
    }

    private function shorten_string($str, $length = 50)
    {
        if (strlen($str) <= $length) {
            return $str;
        }

        return substr($str, 0, $length) . '...';
    }

    private function admin_scripts()
    {
    ?>
        <script>
            jQuery(document).ready(function($) {
                $('.delete-license').on('click', function() {
                    if (!confirm('Are you sure you want to delete this license?')) {
                        return;
                    }

                    var licenseId = $(this).data('id');
                    var $row = $(this).closest('tr');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'delete_license',
                            license_id: licenseId
                        },
                        success: function(response) {
                            if (response.success) {
                                $row.fadeOut(300, function() {
                                    $(this).remove();
                                });
                            } else {
                                alert(response.data);
                            }
                        },
                        error: function() {
                            alert('An error occurred');
                        }
                    });
                });
            });
        </script>
        <style>
            .wp-list-table th,
            .wp-list-table td {
                vertical-align: middle;
            }
        </style>
    <?php
    }

    private function serp_scraper_scripts()
    {
    ?>
        <style>
            .serp-container {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }

            .serp-search-column,
            .serp-tools-column {
                min-width: 0;
            }

            .device-restrict-container {
                background: #f5f5f5;
                padding: 15px;
                border-radius: 4px;
                margin: 15px 0;
                border: 1px solid #ddd;
            }

            @media (max-width: 1200px) {
                .serp-container {
                    grid-template-columns: 1fr;
                }
            }

            #selected-url-display {
                max-height: 200px;
                overflow-y: auto;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Search form handler
                $('#serp-search-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $container = $('#serp-results-container');
                    $container.html('<div class="notice notice-info"><p>Searching...</p></div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'serp_search',
                            keyword: $form.find('#serp-keyword').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                $container.html(response.data);
                            } else {
                                $container.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function(xhr) {
                            var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : 'Search failed';
                            $container.html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                        }
                    });
                });

                // URL selection handler
                $(document).on('click', '.select-url', function() {
                    var url = $(this).data('url');
                    var resultId = $(this).data('result');
                    $('#selected-url-display').html('<strong>Selected URL:</strong><br>' + url);
                    $('#result-id').val(resultId);
                    $('#scraping-section').show();
                    $('html, body').animate({
                        scrollTop: $('#scraping-section').offset().top - 20
                    }, 300);
                });

                // Scrape form handler
                $('#scrape-url-form').on('submit', function(e) {
                    e.preventDefault();
                    var $form = $(this);
                    var $container = $('#scraping-result');
                    $container.html('<div class="notice notice-info"><p>Processing URL...</p></div>');

                    // Get checked devices
                    var devices = [];
                    $form.find('input[name="devices[]"]:checked').each(function() {
                        devices.push($(this).val());
                    });

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'scrape_url',
                            url: $form.find('#selected-url-display').text().replace('Selected URL:', '').trim(),
                            result_id: $form.find('#result-id').val(),
                            devices: devices
                        },
                        success: function(response) {
                            if (response.success) {
                                $container.html(response.data);
                            } else {
                                $container.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function(xhr) {
                            var msg = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : 'Scraping failed';
                            $container.html('<div class="notice notice-error"><p>' + msg + '</p></div>');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    private function reports_scripts()
    {
    ?>
        <script>
            jQuery(document).ready(function($) {
                $('.view-clicks').on('click', function() {
                    var resultId = $(this).data('id');
                    var $modal = $('#click-details-modal');
                    var $content = $('#click-details-content');

                    $content.html('<p>Loading click details...</p>');
                    $modal.show();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'get_click_reports',
                            result_id: resultId
                        },
                        success: function(response) {
                            if (response.success) {
                                $content.html(response.data);
                            } else {
                                $content.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                            }
                        },
                        error: function() {
                            $content.html('<div class="notice notice-error"><p>Failed to load click details</p></div>');
                        }
                    });
                });

                $('.close-modal').on('click', function() {
                    $('#click-details-modal').hide();
                });
            });
        </script>
        <style>
            #click-details-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .modal-content {
                background: #fff;
                padding: 20px;
                max-width: 800px;
                width: 90%;
                max-height: 80vh;
                overflow-y: auto;
            }

            .close-modal {
                margin-top: 15px;
            }
        </style>
    <?php
    }

    public function validate_license()
    {
        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

        global $wpdb;
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE license_key = %s AND status = 'active'",
            $license_key
        ));

        if ($license) {
            update_user_meta(get_current_user_id(), 'valid_license_key', $license_key);
            wp_send_json_success('License validated successfully! Redirecting...');
        } else {
            wp_send_json_error('Invalid or inactive license key');
        }
    }

    private function frontend_scripts()
    {
    ?>
        <script>
            jQuery(document).ready(function($) {
                $('#license-validation-form').on('submit', function(e) {
                    e.preventDefault();

                    var $form = $(this);
                    var $result = $('#license-validation-result');

                    $result.html('<p class="notice notice-info">Validating license...</p>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'validate_license',
                            license_key: $form.find('input[name="license_key"]').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<p class="notice notice-success">' + response.data + '</p>');
                                setTimeout(function() {
                                    window.location.href = '<?php echo admin_url('admin.php?page=serp-scraper'); ?>';
                                }, 1500);
                            } else {
                                $result.html('<p class="notice notice-error">' + response.data + '</p>');
                            }
                        },
                        error: function() {
                            $result.html('<p class="notice notice-error">An error occurred during validation</p>');
                        }
                    });
                });
            });
        </script>
        <style>
            .license-input-form {
                max-width: 500px;
                margin: 20px auto;
                padding: 20px;
                border: 1px solid #ddd;
                background: #f9f9f9;
            }

            .license-input-form input[type="text"] {
                width: 100%;
                padding: 8px;
                margin-bottom: 10px;
            }
        </style>
<?php
    }

    private function sanitize_scraped_content($content)
    {
        // Remove unwanted tags
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);
        $content = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $content);

        // Fix relative URLs
        $base_url = home_url('/');
        $content = preg_replace_callback(
            '/(href|src)=["\'](\/[^"\']+)/i',
            function ($matches) use ($base_url) {
                return $matches[1] . '="' . $base_url . ltrim($matches[2], '/') . '"';
            },
            $content
        );

        // Ensure proper HTML structure
        if (!preg_match('/<html/i', $content)) {
            $content = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>';
        }

        return $content;
    }
}

new WP_License_Manager_Enhanced();
