<?php
/*
Plugin Name: WP License Manager
Description: Plugin untuk mengelola lisensi produk dan scraping SERP
Version: 1.1
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

class WP_License_Manager
{
    private $table_name;
    private $scraping_table;
    private $serpapi_key;
    private $ipapi_key;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'license_manager';
        $this->scraping_table = $wpdb->prefix . 'license_scraping_results';
        $this->serpapi_key = '43b9ec39abc4b75b5467d2c1b137f308236f0693e206b34e7dfe93d29ce51dd6'; // Replace with your actual key
        $this->ipapi_key = '64f64fd98566f5aa8b12640b5d383ec5'; // Replace with your actual key

        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_shortcode('license_input_form', array($this, 'license_input_form_shortcode'));
        add_action('wp_ajax_generate_license', array($this, 'generate_license'));
        add_action('wp_ajax_validate_license', array($this, 'validate_license'));
        add_action('wp_ajax_delete_license', array($this, 'delete_license'));
        add_action('wp_ajax_serp_search', array($this, 'serp_search'));
        add_action('wp_ajax_scrape_url', array($this, 'scrape_url'));
        add_action('wp_ajax_get_scraped_page', array($this, 'get_scraped_page'));
        add_action('wp_ajax_track_click', array($this, 'track_click'));
        add_action('wp_ajax_delete_scraped_page', array($this, 'delete_scraped_page'));

        // Add rewrite rule for scraped pages
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'handle_scraped_page_view'));
    }

    public function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // License table
        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            license_key varchar(100) NOT NULL,
            customer_name varchar(100) DEFAULT NULL,
            customer_phone varchar(20) DEFAULT NULL,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key)
        ) $charset_collate;";

        // Scraping results table
        $sql2 = "CREATE TABLE $this->scraping_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            license_id mediumint(9) NOT NULL,
            keyword varchar(255) NOT NULL,
            url varchar(512) NOT NULL,
            title varchar(255) DEFAULT NULL,
            snippet text DEFAULT NULL,
            devices varchar(255) DEFAULT 'desktop',
            scraped_content longtext DEFAULT NULL,
            views int(11) DEFAULT 0,
            clicks int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY license_id (license_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);

        // Flush rewrite rules
        $this->add_rewrite_rules();
        flush_rewrite_rules();
    }

    public function add_rewrite_rules()
    {
        add_rewrite_rule('^scraped-page/([0-9]+)/?', 'index.php?scraped_page_id=$matches[1]', 'top');
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'scraped_page_id';
        return $vars;
    }

    public function handle_scraped_page_view()
    {
        if (get_query_var('scraped_page_id')) {
            $page_id = intval(get_query_var('scraped_page_id'));
            $this->display_scraped_page($page_id);
            exit;
        }
    }

    private function display_scraped_page($page_id)
    {
        global $wpdb;

        $page = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->scraping_table WHERE id = %d",
            $page_id
        ));

        if (!$page) {
            wp_die('Page not found');
        }

        // Update view count
        $wpdb->update(
            $this->scraping_table,
            array('views' => $page->views + 1),
            array('id' => $page_id),
            array('%d'),
            array('%d')
        );

        // Track visitor info using ipapi
        $visitor_ip = $this->get_user_ip();
        $this->track_visitor_info($page_id, $visitor_ip);

        // Output the scraped content with device-specific modifications
        $this->output_scraped_content($page);
    }

    private function get_user_ip()
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            return $_SERVER['REMOTE_ADDR'];
        }
    }

    private function track_visitor_info($page_id, $ip)
    {
        // You can implement ipapi tracking here
        // For example:
        /*
        $ipapi_data = wp_remote_get("http://api.ipapi.com/{$ip}?access_key={$this->ipapi_key}");
        if (!is_wp_error($ipapi_data)) {
            $data = json_decode($ipapi_data['body']);
            // Store the tracking data in your database
        }
        */
    }

    private function output_scraped_content($page)
    {
        // Get the user agent to detect device
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $is_mobile = wp_is_mobile();
        $is_tablet = (strpos($user_agent, 'Tablet') !== false || strpos($user_agent, 'iPad') !== false);

        // Check if the page is allowed for this device type
        $allowed_devices = explode(',', $page->devices);
        $can_view = false;

        if ($is_tablet && in_array('tablet', $allowed_devices)) {
            $can_view = true;
        } elseif ($is_mobile && !$is_tablet && in_array('mobile', $allowed_devices)) {
            $can_view = true;
        } elseif (!$is_mobile && !$is_tablet && in_array('desktop', $allowed_devices)) {
            $can_view = true;
        }

        if (!$can_view) {
            wp_die('This page is not available for your device type');
        }

        // Output the scraped content with modified headers
        echo $this->modify_scraped_content($page->scraped_content, $is_mobile, $is_tablet);
    }

    private function modify_scraped_content($content, $is_mobile, $is_tablet)
    {
        // Modify the content based on device type
        // This is a simple example - you might need more sophisticated modifications
        if ($is_mobile) {
            $content = str_replace('<body', '<body class="mobile-device"', $content);
        } elseif ($is_tablet) {
            $content = str_replace('<body', '<body class="tablet-device"', $content);
        } else {
            $content = str_replace('<body', '<body class="desktop-device"', $content);
        }

        return $content;
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

        add_submenu_page(
            'license-manager',
            'Scraping Tool',
            'Scraping Tool',
            'manage_options',
            'license-scraping',
            array($this, 'scraping_tool_page')
        );
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

        echo '<div class="wrap lm-full-width">';
        echo '<h1 class="wp-heading-inline">License Manager</h1>';
        echo '<hr class="wp-header-end">';

        // Generate License Form
        echo '<div class="card lm-card">';
        echo '<h2 class="title">Generate New License</h2>';
        echo '<form method="post" class="lm-form">';
        echo '<input type="hidden" name="generate_license" value="1">';

        echo '<div class="lm-form-row">';
        echo '<label for="customer_name">Customer Name</label>';
        echo '<input type="text" name="customer_name" id="customer_name" class="regular-text" required>';
        echo '</div>';

        echo '<div class="lm-form-row">';
        echo '<label for="customer_phone">Phone Number</label>';
        echo '<input type="text" name="customer_phone" id="customer_phone" class="regular-text" required>';
        echo '</div>';

        echo '<div class="lm-form-submit">';
        echo '<input type="submit" class="button button-primary" value="Generate License Key">';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        // Existing Licenses Table
        echo '<div class="card lm-card">';
        echo '<h2 class="title">Existing Licenses</h2>';

        if (empty($licenses)) {
            echo '<p>No licenses found.</p>';
        } else {
            echo '<div class="table-responsive">';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th width="5%">ID</th>';
            echo '<th width="20%">License Key</th>';
            echo '<th width="20%">Customer Name</th>';
            echo '<th width="15%">Phone</th>';
            echo '<th width="10%">Status</th>';
            echo '<th width="15%">Created At</th>';
            echo '<th width="15%">Actions</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($licenses as $license) {
                echo '<tr>';
                echo '<td>' . $license->id . '</td>';
                echo '<td><code>' . $license->license_key . '</code></td>';
                echo '<td>' . esc_html($license->customer_name) . '</td>';
                echo '<td>' . esc_html($license->customer_phone) . '</td>';
                echo '<td><span class="lm-status lm-status-' . esc_attr($license->status) . '">' . ucfirst($license->status) . '</span></td>';
                echo '<td>' . date('M j, Y H:i', strtotime($license->created_at)) . '</td>';
                echo '<td>';
                echo '<button class="button button-small delete-license" data-id="' . $license->id . '">Delete</button>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>'; // .table-responsive
        }

        echo '</div>'; // .card
        echo '</div>'; // .wrap

        $this->admin_scripts();
    }

    public function scraping_tool_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        global $wpdb;

        echo '<div class="wrap lm-full-width">';
        echo '<h1 class="wp-heading-inline">SERP Scraping Tool</h1>';
        echo '<a href="' . admin_url('admin.php?page=license-manager') . '" class="page-title-action">Back to License Manager</a>';
        echo '<hr class="wp-header-end">';

        // Search and Scrape Tool
        echo '<div class="card lm-card">';
        echo '<h2 class="title">Search and Scrape</h2>';
        echo '<div id="scraping-tool-container" class="lm-scraping-tool" style="max-width:none" >';

        // Step 1: Search
        echo '<div id="search-step" class="lm-step">';
        echo '<h3><span class="step-number">1</span> Enter Keyword</h3>';
        echo '<div class="lm-search-box">';
        echo '<input type="text" id="search-keyword" placeholder="Enter keyword to search" class="regular-text">';
        echo '<button id="search-button" class="button button-primary">Search</button>';
        echo '</div>';
        echo '</div>';

        // Step 2: Results
        echo '<div id="results-step" class="lm-step" style="display:none;">';
        echo '<h3><span class="step-number">2</span> Select Search Result</h3>';
        echo '<div id="search-results" class="lm-search-results"></div>';
        echo '</div>';

        // Step 3: Scraping Options
        echo '<div id="scraping-step" class="lm-step" style="display:none;">';
        echo '<h3><span class="step-number">3</span> Configure Scraping Options</h3>';
        echo '<div class="lm-form-row">';
        echo '<label><strong>Available for devices:</strong></label>';
        echo '<div class="lm-checkbox-group">';
        echo '<label><input type="checkbox" name="device_desktop" value="desktop" checked> Desktop</label>';
        echo '<label><input type="checkbox" name="device_mobile" value="mobile" checked> Mobile</label>';
        echo '<label><input type="checkbox" name="device_tablet" value="tablet" checked> Tablet</label>';
        echo '</div>';
        echo '</div>';
        echo '<button id="scrape-button" class="button button-primary">Scrape URL</button>';
        echo '</div>';

        // Step 4: Preview
        echo '<div id="preview-step" class="lm-step" style="display:none;">';
        echo '<h3><span class="step-number">4</span> Preview & Stats</h3>';
        echo '<div id="preview-container" class="lm-preview-container"></div>';
        echo '<div id="stats-container" class="lm-stats-container"></div>';
        echo '</div>';
        echo '</div>'; // #scraping-tool-container
        echo '</div>'; // .card

        // Previously Scraped Pages
        $scraped_pages = $wpdb->get_results("
        SELECT s.*, l.license_key 
        FROM $this->scraping_table s
        LEFT JOIN $this->table_name l ON s.license_id = l.id
        ORDER BY s.created_at DESC
    ");

        echo '<div class="card lm-card">';
        echo '<h2 class="title">Previously Scraped Pages</h2>';

        if (empty($scraped_pages)) {
            echo '<p>No scraped pages found.</p>';
        } else {
            echo '<div class="table-responsive">';
            echo '<table class="wp-list-table widefat fixed striped lm-scraped-table" style="width:100%">';
            echo '<thead>';
            echo '<tr>';
            echo '<th class="column-id">ID</th>';
            echo '<th class="column-keyword">Keyword</th>';
            echo '<th class="column-title">Title</th>';
            echo '<th class="column-url">URL</th>';
            echo '<th class="column-devices">Devices</th>';
            echo '<th class="column-stats">Stats</th>';
            echo '<th class="column-license">License</th>';
            echo '<th class="column-actions">Actions</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($scraped_pages as $page) {
                echo '<tr>';
                echo '<td class="column-id">' . $page->id . '</td>';
                echo '<td class="column-keyword">' . esc_html($page->keyword) . '</td>';
                echo '<td class="column-title">' . esc_html($this->truncate_text($page->title, 30)) . '</td>';
                echo '<td class="column-url"><a href="' . esc_url($page->url) . '" target="_blank" title="' . esc_attr($page->url) . '">' . esc_html($this->truncate_url($page->url)) . '</a></td>';
                echo '<td class="column-devices">' . $this->format_devices($page->devices) . '</td>';
                echo '<td class="column-stats">';
                echo '<span class="stat-box"><span class="dashicons dashicons-visibility"></span> ' . $page->views . '</span>';
                echo '<span class="stat-box"><span class="dashicons dashicons-external"></span> ' . $page->clicks . '</span>';
                echo '</td>';
                echo '<td class="column-license">' . ($page->license_key ? esc_html($this->truncate_text($page->license_key, 8)) : 'Admin') . '</td>';
                echo '<td class="column-actions">';
                echo '<div class="lm-action-buttons">';
                echo '<button class="button button-small preview-scraped-page" data-id="' . $page->id . '" title="Preview"><span class="dashicons dashicons-welcome-view-site"></span></button>';
                echo '<button class="button button-small delete-scraped-page" data-id="' . $page->id . '" title="Delete"><span class="dashicons dashicons-trash"></span></button>';
                echo '</div>';
                echo '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
            echo '</div>'; // .table-responsive
        }

        echo '</div>'; // .card
        echo '</div>'; // .wrap

        $this->scraping_tool_scripts();
    }

    private function truncate_text($text, $length = 30)
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return mb_substr($text, 0, $length) . '...';
    }

    private function truncate_url($url, $length = 25)
    {
        $url = str_replace(['https://', 'http://'], '', $url);
        if (mb_strlen($url) <= $length) {
            return $url;
        }
        return mb_substr($url, 0, $length) . '...';
    }

    private function format_devices($devices)
    {
        $device_map = [
            'desktop' => '<span class="dashicons dashicons-desktop"></span>',
            'mobile' => '<span class="dashicons dashicons-smartphone"></span>',
            'tablet' => '<span class="dashicons dashicons-tablet"></span>'
        ];

        $output = [];
        $devices = explode(',', $devices);

        foreach ($device_map as $key => $icon) {
            if (in_array($key, $devices)) {
                $output[] = $icon;
            }
        }

        return implode(' ', $output);
    }

    public function serp_search()
    {
        if (!current_user_can('manage_options') && !$this->has_valid_license()) {
            wp_send_json_error('Unauthorized or invalid license');
        }

        $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';

        if (empty($keyword)) {
            wp_send_json_error('Keyword is required');
        }

        // Call SERP API
        $api_url = "https://serpapi.com/search.json?q=" . urlencode($keyword) . "&api_key=" . $this->serpapi_key;

        $response = wp_remote_get($api_url);

        if (is_wp_error($response)) {
            wp_send_json_error('API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['organic_results'])) {
            wp_send_json_error('No results found');
        }

        $results = array();
        foreach ($data['organic_results'] as $result) {
            $results[] = array(
                'title' => $result['title'] ?? '',
                'url' => $result['link'] ?? '',
                'snippet' => $result['snippet'] ?? ''
            );
        }

        wp_send_json_success($results);
    }

    public function scrape_url()
    {
        if (!current_user_can('manage_options') && !$this->has_valid_license()) {
            wp_send_json_error('Unauthorized or invalid license');
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';
        $snippet = isset($_POST['snippet']) ? sanitize_textarea_field($_POST['snippet']) : '';
        $devices = isset($_POST['devices']) ? sanitize_text_field($_POST['devices']) : 'desktop,mobile,tablet';

        if (empty($url)) {
            wp_send_json_error('URL is required');
        }

        // Get the content of the URL
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch URL: ' . $response->get_error_message());
        }

        $content = wp_remote_retrieve_body($response);

        // Store the scraped content
        global $wpdb;

        $license_id = 0;
        if (!$this->is_admin()) {
            $license_id = $this->get_current_license_id();
        }

        $wpdb->insert(
            $this->scraping_table,
            array(
                'license_id' => $license_id,
                'keyword' => $keyword,
                'url' => $url,
                'title' => $title,
                'snippet' => $snippet,
                'devices' => $devices,
                'scraped_content' => $content
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($wpdb->insert_id) {
            $page_id = $wpdb->insert_id;
            $preview_url = home_url("/scraped-page/{$page_id}/");

            wp_send_json_success(array(
                'page_id' => $page_id,
                'preview_url' => $preview_url,
                'title' => $title,
                'url' => $url,
                'devices' => $devices
            ));
        } else {
            wp_send_json_error('Failed to save scraped content');
        }
    }

    public function get_scraped_page()
    {
        $page_id = isset($_GET['page_id']) ? intval($_GET['page_id']) : 0;

        global $wpdb;
        $page = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->scraping_table WHERE id = %d",
            $page_id
        ));

        if (!$page) {
            wp_send_json_error('Page not found');
        }

        $preview_url = home_url("/scraped-page/{$page_id}/");

        wp_send_json_success(array(
            'title' => $page->title,
            'url' => $page->url,
            'devices' => $page->devices,
            'preview_url' => $preview_url,
            'views' => $page->views,
            'clicks' => $page->clicks
        ));
    }

    public function track_click()
    {
        $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;

        if ($page_id) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "UPDATE $this->scraping_table SET clicks = clicks + 1 WHERE id = %d",
                $page_id
            ));

            wp_send_json_success('Click tracked');
        } else {
            wp_send_json_error('Invalid page ID');
        }
    }

    private function has_valid_license()
    {
        $license_key = get_user_meta(get_current_user_id(), 'valid_license_key', true);

        if (empty($license_key)) {
            return false;
        }

        global $wpdb;
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE license_key = %s AND status = 'active'",
            $license_key
        ));

        return !empty($license);
    }

    private function is_admin()
    {
        return current_user_can('manage_options');
    }

    private function get_current_license_id()
    {
        $license_key = get_user_meta(get_current_user_id(), 'valid_license_key', true);

        if (empty($license_key)) {
            return 0;
        }

        global $wpdb;
        $license = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $this->table_name WHERE license_key = %s",
            $license_key
        ));

        return $license ? $license->id : 0;
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

            <?php if ($this->has_valid_license()) : ?>
                <div id="scraping-tool-shortcode" style="margin-top: 20px;">
                    <h3>SERP Scraping Tool</h3>
                    <div id="scraping-tool-container">
                        <div id="search-step">
                            <p><strong>Step 1:</strong> Enter a keyword to search</p>
                            <input type="text" id="search-keyword" placeholder="Enter keyword" class="regular-text">
                            <button id="search-button" class="button button-primary">Search</button>
                        </div>

                        <div id="results-step" style="display:none; margin-top:20px;">
                            <p><strong>Step 2:</strong> Select a result to scrape</p>
                            <div id="search-results" style="margin: 10px 0;"></div>
                        </div>

                        <div id="scraping-step" style="display:none; margin-top:20px;">
                            <p><strong>Step 3:</strong> Configure scraping options</p>
                            <div class="form-table">
                                <div class="form-field">
                                    <label><strong>Available for devices:</strong></label><br>
                                    <label><input type="checkbox" name="device_desktop" value="desktop" checked> Desktop</label>
                                    <label><input type="checkbox" name="device_mobile" value="mobile" checked> Mobile</label>
                                    <label><input type="checkbox" name="device_tablet" value="tablet" checked> Tablet</label>
                                </div>
                                <button id="scrape-button" class="button button-primary">Scrape URL</button>
                            </div>
                        </div>

                        <div id="preview-step" style="display:none; margin-top:20px;">
                            <p><strong>Step 4:</strong> Preview and stats</p>
                            <div id="preview-container"></div>
                            <div id="stats-container" style="margin-top:20px;"></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php
        $this->frontend_scripts();
        return ob_get_clean();
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
            wp_send_json_success('License validated successfully!');
        } else {
            wp_send_json_error('Invalid or inactive license key');
        }
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

    public function delete_scraped_page()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : 0;

        global $wpdb;
        $deleted = $wpdb->delete(
            $this->scraping_table,
            array('id' => $page_id),
            array('%d')
        );

        if ($deleted) {
            wp_send_json_success('Scraped page deleted successfully');
        } else {
            wp_send_json_error('Failed to delete scraped page');
        }
    }

    private function admin_scripts()
    {
    ?>
        <style>
            /* Full Width Layout */
            .lm-full-width {
                max-width: none !important;
                padding: 20px;
                margin: 0;
                width: calc(100% - 40px);
            }

            .lm-full-width .lm-card {
                margin: 0 0 20px 0;
                width: 100%;
                box-sizing: border-box;
            }

            /* Header Adjustments */
            .lm-full-width .wp-heading-inline {
                margin-right: 15px;
            }

            .lm-full-width .page-title-action {
                margin-top: 3px;
            }

            /* Table Improvements */
            .lm-full-width .table-responsive {
                width: 100%;
                overflow-x: auto;
            }

            .lm-full-width .wp-list-table {
                width: 100%;
                table-layout: auto;
            }

            /* Card Layout */
            .lm-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
                padding: 20px;
                margin-bottom: 20px;
            }

            /* Column Width Adjustments */
            .lm-scraped-table .column-id {
                width: 5%;
            }

            .lm-scraped-table .column-keyword {
                width: 15%;
            }

            .lm-scraped-table .column-title {
                width: 20%;
            }

            .lm-scraped-table .column-url {
                width: 20%;
            }

            .lm-scraped-table .column-devices {
                width: 10%;
            }

            .lm-scraped-table .column-stats {
                width: 10%;
            }

            .lm-scraped-table .column-license {
                width: 10%;
            }

            .lm-scraped-table .column-actions {
                width: 10%;
            }

            /* Responsive Adjustments */
            @media screen and (max-width: 1600px) {
                .lm-scraped-table .column-url {
                    width: 15%;
                }
            }

            @media screen and (max-width: 1200px) {
                .lm-scraped-table .column-url {
                    display: table-cell;
                }
            }
        </style>
        <style>
            /* General Styles */
            .lm-container {
                max-width: 1200px;
                margin: 0 auto;
            }

            .lm-card {
                margin-bottom: 20px;
                padding: 20px;
                background: #fff;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
                border: 1px solid #ccd0d4;
            }

            .lm-card .title {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #eee;
            }

            /* Form Styles */
            .lm-form-row {
                margin-bottom: 15px;
            }

            .lm-form-row label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }

            .lm-form-submit {
                margin-top: 20px;
            }

            /* Status Badges */
            .lm-status {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }

            .lm-status-active {
                background: #e6f4ea;
                color: #1d7b30;
            }

            .lm-status-inactive {
                background: #f5e8e8;
                color: #d63638;
            }

            /* Scraping Tool Styles */
            .lm-scraping-tool {
                max-width: 800px;
            }

            .lm-step {
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 1px dashed #ddd;
            }

            .lm-step h3 {
                margin: 0 0 15px 0;
                display: flex;
                align-items: center;
            }

            .step-number {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 24px;
                height: 24px;
                background: #2271b1;
                color: white;
                border-radius: 50%;
                margin-right: 10px;
                font-size: 14px;
            }

            .lm-search-box {
                display: flex;
                gap: 10px;
            }

            .lm-search-box input {
                flex: 1;
            }

            .lm-search-results {
                max-height: 400px;
                overflow-y: auto;
                border: 1px solid #ddd;
                padding: 10px;
                background: #f9f9f9;
            }

            .lm-checkbox-group {
                display: flex;
                gap: 15px;
                margin-top: 5px;
            }

            .lm-preview-container {
                border: 1px solid #ddd;
                padding: 15px;
                background: #f9f9f9;
            }

            .lm-stats-container {
                margin-top: 15px;
                padding: 10px;
                background: #f5f5f5;
                border-radius: 3px;
            }

            /* Responsive Tables */
            .table-responsive {
                overflow-x: auto;
            }

            /* Buttons */
            .button-small {
                padding: 0 8px;
                font-size: 12px;
                line-height: 26px;
            }
        </style>
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

    private function scraping_tool_scripts()
    {
    ?>
        <style>
            /* General Scraping Tool Styles */
            .lm-scraping-tool {
                max-width: 800px;
                margin: 0 auto;
            }

            /* Step Container */
            .lm-step {
                background: #fff;
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
                border: 1px solid #ccd0d4;
            }

            .lm-step h3 {
                margin-top: 0;
                color: #1d2327;
                display: flex;
                align-items: center;
                font-size: 14px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .step-number {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 24px;
                height: 24px;
                background: #2271b1;
                color: white;
                border-radius: 50%;
                margin-right: 10px;
                font-size: 12px;
                font-weight: bold;
            }

            /* Search Box */
            .lm-search-box {
                display: flex;
                gap: 10px;
                margin-top: 15px;
            }

            .lm-search-box input {
                flex: 1;
                padding: 8px 12px;
                border: 1px solid #8c8f94;
                border-radius: 3px;
                min-height: 36px;
            }

            /* Search Results */
            .lm-search-results {
                max-height: 400px;
                overflow-y: auto;
                margin-top: 15px;
                border: 1px solid #ddd;
                border-radius: 4px;
            }

            .search-result-item {
                padding: 15px;
                border-bottom: 1px solid #eee;
                transition: background 0.2s;
                cursor: pointer;
            }

            .search-result-item:hover {
                background-color: #f8f9fa;
            }

            .search-result-item h4 {
                margin: 0 0 5px 0;
                color: #2271b1;
                font-size: 15px;
            }

            .search-result-item p {
                margin: 0;
                color: #646970;
                font-size: 13px;
            }

            .search-result-item .url {
                color: #0a7c36;
                font-size: 13px;
                margin-bottom: 5px;
            }

            /* Device Options */
            .lm-checkbox-group {
                display: flex;
                gap: 15px;
                margin: 15px 0;
            }

            .lm-checkbox-group label {
                display: flex;
                align-items: center;
                gap: 5px;
                cursor: pointer;
            }

            /* Preview Container */
            .lm-preview-container {
                background: #fff;
                padding: 20px;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                margin-top: 15px;
            }

            .lm-preview-container p {
                margin: 0 0 10px 0;
                line-height: 1.5;
            }

            .lm-preview-container iframe {
                width: 100%;
                height: 500px;
                border: 1px solid #ddd;
                margin-top: 15px;
                background: #fff;
            }

            /* Stats Container */
            .lm-stats-container {
                background: #f6f7f7;
                padding: 15px;
                border-radius: 4px;
                margin-top: 15px;
                font-size: 14px;
            }

            /* Modal Preview */
            #scraped-page-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.7);
                z-index: 99999;
                display: flex;
                justify-content: center;
                align-items: center;
            }

            #scraped-page-modal>div {
                background: #fff;
                padding: 25px;
                width: 90%;
                max-width: 1000px;
                max-height: 90vh;
                overflow: auto;
                border-radius: 5px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            }

            #scraped-page-modal h2 {
                margin-top: 0;
                color: #1d2327;
            }

            /* Responsive Adjustments */
            @media (max-width: 782px) {
                .lm-search-box {
                    flex-direction: column;
                }

                .lm-checkbox-group {
                    flex-direction: column;
                    gap: 8px;
                }

                .lm-preview-container iframe {
                    height: 300px;
                }

                .lm-scraped-pages-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                }

                .lm-scraped-pages-table th {
                    background: #f6f7f7;
                    text-align: left;
                    padding: 10px;
                    border-bottom: 1px solid #ccd0d4;
                    font-weight: 600;
                }

                .lm-scraped-pages-table td {
                    padding: 10px;
                    border-bottom: 1px solid #eee;
                    vertical-align: top;
                }

                .lm-scraped-pages-table tr:hover td {
                    background-color: #f9f9f9;
                }

                .lm-scraped-url {
                    max-width: 200px;
                    white-space: nowrap;
                    overflow: hidden;
                    text-overflow: ellipsis;
                    display: inline-block;
                }

                /* Action Buttons */
                .lm-action-buttons {
                    display: flex;
                    gap: 5px;
                }

                .lm-action-buttons .button {
                    padding: 4px 8px;
                    font-size: 13px;
                    line-height: 1.5;
                }

                /* Scraped Pages Table */
                .lm-scraped-table {
                    table-layout: fixed;
                    width: 100%;
                    margin-top: 15px;
                }

                .lm-scraped-table th {
                    font-weight: 600;
                }

                .lm-scraped-table td,
                .lm-scraped-table th {
                    padding: 10px;
                    vertical-align: middle;
                    word-wrap: break-word;
                }

                /* Column Widths */
                .lm-scraped-table .column-id {
                    width: 50px;
                    text-align: center;
                }

                .lm-scraped-table .column-keyword {
                    width: 120px;
                }

                .lm-scraped-table .column-title {
                    width: 180px;
                }

                .lm-scraped-table .column-url {
                    width: 150px;
                }

                .lm-scraped-table .column-devices {
                    width: 80px;
                    text-align: center;
                }

                .lm-scraped-table .column-stats {
                    width: 100px;
                }

                .lm-scraped-table .column-license {
                    width: 80px;
                    text-align: center;
                }

                .lm-scraped-table .column-actions {
                    width: 80px;
                    text-align: center;
                }

                /* Stats Box */
                .stat-box {
                    display: inline-flex;
                    align-items: center;
                    margin: 0 5px;
                    font-size: 12px;
                }

                .stat-box .dashicons {
                    font-size: 16px;
                    margin-right: 3px;
                    color: #646970;
                }

                /* Action Buttons */
                .lm-action-buttons {
                    display: flex;
                    justify-content: center;
                    gap: 5px;
                }

                .lm-action-buttons .button {
                    padding: 0;
                    width: 30px;
                    height: 30px;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                }

                .lm-action-buttons .button .dashicons {
                    font-size: 16px;
                    line-height: 1;
                }

                /* Device Icons */
                .column-devices .dashicons {
                    font-size: 20px;
                    color: #2271b1;
                }

                /* Hover Effects */
                .lm-scraped-table tr:hover td {
                    background-color: #f8f9fa;
                }

                /* Responsive Table */
                @media screen and (max-width: 1200px) {

                    .lm-scraped-table .column-title,
                    .lm-scraped-table .column-url {
                        display: none;
                    }
                }

                @media screen and (max-width: 782px) {
                    .lm-scraped-table {
                        display: block;
                        overflow-x: auto;
                        white-space: nowrap;
                    }

                    .lm-scraped-table .column-stats,
                    .lm-scraped-table .column-license {
                        display: none;
                    }
                }
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                // Handle search
                $('#search-button').on('click', function() {
                    var keyword = $('#search-keyword').val().trim();

                    if (!keyword) {
                        alert('Please enter a keyword');
                        return;
                    }

                    $('#search-button').prop('disabled', true).text('Searching...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'serp_search',
                            keyword: keyword
                        },
                        success: function(response) {
                            $('#search-button').prop('disabled', false).text('Search');

                            if (response.success) {
                                var results = response.data;
                                var html = '<div class="search-results-list">';

                                results.forEach(function(result, index) {
                                    html += '<div class="search-result-item" style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; cursor: pointer;" data-url="' + result.url + '" data-title="' + result.title + '" data-snippet="' + result.snippet + '" data-keyword="' + keyword + '">';
                                    html += '<h4 style="margin: 0 0 5px 0;">' + result.title + '</h4>';
                                    html += '<p style="margin: 0 0 5px 0; color: #006621;">' + result.url + '</p>';
                                    html += '<p style="margin: 0;">' + result.snippet + '</p>';
                                    html += '</div>';
                                });

                                html += '</div>';
                                $('#search-results').html(html);
                                $('#results-step').show();

                                // Handle result selection
                                $('.search-result-item').on('click', function() {
                                    $('.search-result-item').css('border-color', '#ddd');
                                    $(this).css('border-color', '#0073aa');

                                    $('#scraping-step').data('url', $(this).data('url'));
                                    $('#scraping-step').data('title', $(this).data('title'));
                                    $('#scraping-step').data('snippet', $(this).data('snippet'));
                                    $('#scraping-step').data('keyword', $(this).data('keyword'));
                                    $('#scraping-step').show();
                                });
                            } else {
                                alert(response.data);
                            }
                        },
                        error: function() {
                            $('#search-button').prop('disabled', false).text('Search');
                            alert('An error occurred during search');
                        }
                    });
                });

                // Handle scraping
                $('#scrape-button').on('click', function() {
                    var url = $('#scraping-step').data('url');
                    var title = $('#scraping-step').data('title');
                    var snippet = $('#scraping-step').data('snippet');
                    var keyword = $('#scraping-step').data('keyword');

                    // Get selected devices
                    var devices = [];
                    $('input[name^="device_"]:checked').each(function() {
                        devices.push($(this).val());
                    });

                    if (devices.length === 0) {
                        alert('Please select at least one device type');
                        return;
                    }

                    $('#scrape-button').prop('disabled', true).text('Scraping...');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'scrape_url',
                            url: url,
                            title: title,
                            snippet: snippet,
                            keyword: keyword,
                            devices: devices.join(',')
                        },
                        success: function(response) {
                            $('#scrape-button').prop('disabled', false).text('Scrape URL');

                            if (response.success) {
                                var data = response.data;
                                $('#preview-step').show();

                                // Show preview
                                var previewHtml = '<p><strong>Page Title:</strong> ' + data.title + '</p>';
                                previewHtml += '<p><strong>Original URL:</strong> <a href="' + data.url + '" target="_blank">' + data.url + '</a></p>';
                                previewHtml += '<p><strong>Available for:</strong> ' + data.devices.replace(/,/g, ', ') + '</p>';
                                previewHtml += '<p><strong>Preview URL:</strong> <a href="' + data.preview_url + '" target="_blank">' + data.preview_url + '</a></p>';
                                previewHtml += '<iframe src="' + data.preview_url + '" style="width:100%; height:500px; border:1px solid #ddd;"></iframe>';

                                $('#preview-container').html(previewHtml);
                                $('#stats-container').html('<p>Views: 0 | Clicks: 0</p>');

                                // Track clicks on the original URL
                                $('#preview-container a[href="' + data.url + '"]').on('click', function(e) {
                                    e.preventDefault();

                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'track_click',
                                            page_id: data.page_id
                                        },
                                        success: function() {
                                            window.open(data.url, '_blank');
                                        }
                                    });
                                });

                                // Update stats periodically
                                var updateStats = function() {
                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'GET',
                                        data: {
                                            action: 'get_scraped_page',
                                            page_id: data.page_id
                                        },
                                        success: function(response) {
                                            if (response.success) {
                                                var stats = response.data;
                                                $('#stats-container').html('<p>Views: ' + stats.views + ' | Clicks: ' + stats.clicks + '</p>');
                                            }
                                        }
                                    });
                                };

                                setInterval(updateStats, 5000);
                                updateStats();
                            } else {
                                alert(response.data);
                            }
                        },
                        error: function() {
                            $('#scrape-button').prop('disabled', false).text('Scrape URL');
                            alert('An error occurred during scraping');
                        }
                    });
                });

                // Handle preview of previously scraped pages
                // Ganti kode untuk preview scraped page dengan ini:
                $('.preview-scraped-page').on('click', function() {
                    var pageId = $(this).data('id');

                    $.ajax({
                        url: ajaxurl,
                        type: 'GET',
                        data: {
                            action: 'get_scraped_page',
                            page_id: pageId
                        },
                        success: function(response) {
                            if (response.success) {
                                var data = response.data;

                                // Show preview in a modal
                                var modalHtml = '<div id="scraped-page-modal" style="position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.7); z-index:99999; display:flex; justify-content:center; align-items:center;">';
                                modalHtml += '<div style="background:#fff; padding:20px; width:80%; max-width:1000px; max-height:90vh; overflow:auto;">';
                                modalHtml += '<div style="display:flex; justify-content:space-between; margin-bottom:15px;">';
                                modalHtml += '<h2 style="margin:0;">Preview: ' + data.title + '</h2>';
                                modalHtml += '<button id="close-preview-modal" class="button">Close</button>';
                                modalHtml += '</div>';

                                modalHtml += '<p><strong>Original URL:</strong> <a href="' + data.url + '" target="_blank">' + data.url + '</a></p>';
                                modalHtml += '<p><strong>Available for:</strong> ' + data.devices.replace(/,/g, ', ') + '</p>';
                                modalHtml += '<p><strong>Views:</strong> ' + data.views + ' | <strong>Clicks:</strong> ' + data.clicks + '</p>';
                                modalHtml += '<iframe src="' + data.preview_url + '" style="width:100%; height:500px; border:1px solid #ddd;"></iframe>';
                                modalHtml += '</div></div>';

                                $('body').append(modalHtml);

                                // Handle close button
                                $('#close-preview-modal').on('click', function() {
                                    $('#scraped-page-modal').remove();
                                });

                                // Track clicks on the original URL
                                $('a[href="' + data.url + '"]').on('click', function(e) {
                                    e.preventDefault();

                                    $.ajax({
                                        url: ajaxurl,
                                        type: 'POST',
                                        data: {
                                            action: 'track_click',
                                            page_id: pageId
                                        },
                                        success: function() {
                                            window.open(data.url, '_blank');
                                        }
                                    });
                                });
                            } else {
                                alert(response.data);
                            }
                        },
                        error: function() {
                            alert('An error occurred while loading the preview');
                        }
                    });
                });

                // Handle deletion of scraped pages
                $('.delete-scraped-page').on('click', function() {
                    if (!confirm('Are you sure you want to delete this scraped page?')) {
                        return;
                    }

                    var pageId = $(this).data('id');
                    var $row = $(this).closest('tr');

                    $.ajax({
                        url: ajaxurl, // Sudah benar
                        type: 'POST',
                        data: {
                            action: 'delete_scraped_page',
                            page_id: pageId
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
            .search-results-list {
                max-height: 400px;
                overflow-y: auto;
            }

            .search-result-item:hover {
                background-color: #f5f5f5;
            }

            .wp-list-table th,
            .wp-list-table td {
                vertical-align: middle;
            }
        </style>
    <?php
    }

    private function frontend_scripts()
    {
    ?>
        <script>
            jQuery(document).ready(function($) {
                // License validation
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
                                $('#scraping-tool-shortcode').show();
                            } else {
                                $result.html('<p class="notice notice-error">' + response.data + '</p>');
                                $('#scraping-tool-shortcode').hide();
                            }
                        },
                        error: function() {
                            $result.html('<p class="notice notice-error">An error occurred during validation</p>');
                        }
                    });
                });

                // Scraping tool functionality (same as admin but for frontend)
                if ($('#scraping-tool-shortcode').length) {
                    // Handle search
                    $('#search-button').on('click', function() {
                        var keyword = $('#search-keyword').val().trim();

                        if (!keyword) {
                            alert('Please enter a keyword');
                            return;
                        }

                        $('#search-button').prop('disabled', true).text('Searching...');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'serp_search',
                                keyword: keyword
                            },
                            success: function(response) {
                                $('#search-button').prop('disabled', false).text('Search');

                                if (response.success) {
                                    var results = response.data;
                                    var html = '<div class="search-results-list">';

                                    results.forEach(function(result, index) {
                                        html += '<div class="search-result-item" style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; cursor: pointer;" data-url="' + result.url + '" data-title="' + result.title + '" data-snippet="' + result.snippet + '" data-keyword="' + keyword + '">';
                                        html += '<h4 style="margin: 0 0 5px 0;">' + result.title + '</h4>';
                                        html += '<p style="margin: 0 0 5px 0; color: #006621;">' + result.url + '</p>';
                                        html += '<p style="margin: 0;">' + result.snippet + '</p>';
                                        html += '</div>';
                                    });

                                    html += '</div>';
                                    $('#search-results').html(html);
                                    $('#results-step').show();

                                    // Handle result selection
                                    $('.search-result-item').on('click', function() {
                                        $('.search-result-item').css('border-color', '#ddd');
                                        $(this).css('border-color', '#0073aa');

                                        $('#scraping-step').data('url', $(this).data('url'));
                                        $('#scraping-step').data('title', $(this).data('title'));
                                        $('#scraping-step').data('snippet', $(this).data('snippet'));
                                        $('#scraping-step').data('keyword', $(this).data('keyword'));
                                        $('#scraping-step').show();
                                    });
                                } else {
                                    alert(response.data);
                                }
                            },
                            error: function() {
                                $('#search-button').prop('disabled', false).text('Search');
                                alert('An error occurred during search');
                            }
                        });
                    });

                    // Handle scraping
                    $('#scrape-button').on('click', function() {
                        var url = $('#scraping-step').data('url');
                        var title = $('#scraping-step').data('title');
                        var snippet = $('#scraping-step').data('snippet');
                        var keyword = $('#scraping-step').data('keyword');

                        // Get selected devices
                        var devices = [];
                        $('input[name^="device_"]:checked').each(function() {
                            devices.push($(this).val());
                        });

                        if (devices.length === 0) {
                            alert('Please select at least one device type');
                            return;
                        }

                        $('#scrape-button').prop('disabled', true).text('Scraping...');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'scrape_url',
                                url: url,
                                title: title,
                                snippet: snippet,
                                keyword: keyword,
                                devices: devices.join(',')
                            },
                            success: function(response) {
                                $('#scrape-button').prop('disabled', false).text('Scrape URL');

                                if (response.success) {
                                    var data = response.data;
                                    $('#preview-step').show();

                                    // Show preview
                                    var previewHtml = '<p><strong>Page Title:</strong> ' + data.title + '</p>';
                                    previewHtml += '<p><strong>Original URL:</strong> <a href="' + data.url + '" target="_blank">' + data.url + '</a></p>';
                                    previewHtml += '<p><strong>Available for:</strong> ' + data.devices.replace(/,/g, ', ') + '</p>';
                                    previewHtml += '<p><strong>Preview URL:</strong> <a href="' + data.preview_url + '" target="_blank">' + data.preview_url + '</a></p>';
                                    previewHtml += '<iframe src="' + data.preview_url + '" style="width:100%; height:500px; border:1px solid #ddd;"></iframe>';

                                    $('#preview-container').html(previewHtml);
                                    $('#stats-container').html('<p>Views: 0 | Clicks: 0</p>');

                                    // Track clicks on the original URL
                                    $('#preview-container a[href="' + data.url + '"]').on('click', function(e) {
                                        e.preventDefault();

                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'POST',
                                            data: {
                                                action: 'track_click',
                                                page_id: data.page_id
                                            },
                                            success: function() {
                                                window.open(data.url, '_blank');
                                            }
                                        });
                                    });

                                    // Update stats periodically
                                    var updateStats = function() {
                                        $.ajax({
                                            url: ajaxurl,
                                            type: 'GET',
                                            data: {
                                                action: 'get_scraped_page',
                                                page_id: data.page_id
                                            },
                                            success: function(response) {
                                                if (response.success) {
                                                    var stats = response.data;
                                                    $('#stats-container').html('<p>Views: ' + stats.views + ' | Clicks: ' + stats.clicks + '</p>');
                                                }
                                            }
                                        });
                                    };

                                    setInterval(updateStats, 5000);
                                    updateStats();
                                } else {
                                    alert(response.data);
                                }
                            },
                            error: function() {
                                $('#scrape-button').prop('disabled', false).text('Scrape URL');
                                alert('An error occurred during scraping');
                            }
                        });
                    });
                }
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

            .search-results-list {
                max-height: 400px;
                overflow-y: auto;
            }

            .search-result-item:hover {
                background-color: #f5f5f5;
            }

            .notice {
                padding: 10px;
                margin: 10px 0;
                border-left: 4px solid;
            }

            .notice.notice-info {
                border-color: #00a0d2;
                background: #e5f5fa;
            }

            .notice.notice-success {
                border-color: #46b450;
                background: #f0f9f1;
            }

            .notice.notice-error {
                border-color: #dc3232;
                background: #fbeaea;
            }
        </style>
<?php
    }
}

new WP_License_Manager();
