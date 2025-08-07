<?php
/*
Plugin Name: Piko Plugin Booster
Plugin URI: https://yourwebsite.com/piko-plugin-booster
Description: Plugin premium yang membutuhkan aktivasi lisensi untuk digunakan.
Version: 1.0.0
Author: Your Name
Author URI: https://yourwebsite.com
License: GPLv2 or later
Text Domain: piko-plugin-booster
*/

defined('ABSPATH') || exit;

// Define plugin constants
define('PIKO_PLUGIN_VERSION', '1.0.0');
define('PIKO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PIKO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once PIKO_PLUGIN_DIR . 'includes/class-license-manager.php';
require_once PIKO_PLUGIN_DIR . 'includes/class-user-data.php';
require_once PIKO_PLUGIN_DIR . 'includes/class-admin-dashboard.php';
require_once PIKO_PLUGIN_DIR . 'includes/class-plugin-core.php';
require_once PIKO_PLUGIN_DIR . 'includes/class-serp-scraper.php';

// Initialize the plugin
function piko_plugin_booster_init()
{
  $license_manager = new Piko_License_Manager();
  $user_data = new Piko_User_Data($license_manager);
  $admin_dashboard = new Piko_Admin_Dashboard($license_manager); // Scraper diinisialisasi di sini
  $plugin_core = new Piko_Plugin_Core($license_manager);

  add_action('init', [$admin_dashboard, 'sync_user_licenses']);
}

add_action('plugins_loaded', 'piko_plugin_booster_init');

// Register hooks
register_activation_hook(__FILE__, function () {
  Piko_License_Manager::create_license_table();
  Piko_Serp_Scraper::create_click_table();
});

register_deactivation_hook(__FILE__, array('Piko_Admin_Dashboard', 'cleanup'));

// Load text domain
function piko_plugin_booster_load_textdomain()
{
  load_plugin_textdomain('piko-plugin-booster', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('init', 'piko_plugin_booster_load_textdomain');
