<?php
/*
Plugin Name: WP License Manager
Description: Plugin untuk mengelola lisensi produk
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

class WP_License_Manager
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'license_manager';

        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_shortcode('license_input_form', array($this, 'license_input_form_shortcode'));
        add_action('wp_ajax_generate_license', array($this, 'generate_license'));
        add_action('wp_ajax_validate_license', array($this, 'validate_license'));
        add_action('wp_ajax_delete_license', array($this, 'delete_license'));
    }

    public function activate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

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

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
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
}

new WP_License_Manager();
