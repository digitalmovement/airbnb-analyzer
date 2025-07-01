<?php
/**
 * Plugin Name: AirBnB Listing Analyzer
 * Description: Analyzes AirBnB listings for optimization opportunities
 * Version: 1.0
 * Author: Your Name
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AIRBNB_ANALYZER_PATH', plugin_dir_path(__FILE__));
define('AIRBNB_ANALYZER_URL', plugin_dir_url(__FILE__));

// Include required files
require_once(AIRBNB_ANALYZER_PATH . 'includes/shortcode.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/analyzer.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/api.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/brightdata-api.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/settings.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/claude-api.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/admin.php');

// Include notify.php functions for admin processing (but don't execute the handler)
if (is_admin()) {
    // Define a flag to prevent automatic execution
    define('AIRBNB_ANALYZER_ADMIN_CONTEXT', true);
    require_once(AIRBNB_ANALYZER_PATH . 'notify.php');
}

// Register activation hook
register_activation_hook(__FILE__, 'airbnb_analyzer_activate');

function airbnb_analyzer_activate() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '5.6', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('This plugin requires PHP 5.6 or higher.');
    }
    
    // Check WordPress version
    if (version_compare($GLOBALS['wp_version'], '4.7', '<')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('This plugin requires WordPress 4.7 or higher.');
    }
    
    // Create database tables
    airbnb_analyzer_create_tables();
}

/**
 * Create database tables
 */
function airbnb_analyzer_create_tables() {
    global $wpdb;
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    $charset_collate = $wpdb->get_charset_collate();
    
    // Create emails table
    $emails_table = $wpdb->prefix . 'airbnb_analyzer_emails';
    $sql1 = "CREATE TABLE $emails_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        email varchar(100) NOT NULL,
        listing_url text NOT NULL,
        date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql1);
    
    // Create brightdata requests table
    $requests_table = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    $sql2 = "CREATE TABLE $requests_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        snapshot_id varchar(100) NOT NULL,
        listing_url text NOT NULL,
        email varchar(100) NOT NULL,
        status varchar(20) DEFAULT 'pending' NOT NULL,
        response_data longtext,
        raw_response_data longtext,
        views int(11) DEFAULT 0,
        last_viewed datetime NULL,
        date_created datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        date_completed datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY snapshot_id (snapshot_id)
    ) $charset_collate;";
    dbDelta($sql2);
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'airbnb_analyzer_deactivate');

function airbnb_analyzer_deactivate() {
    // Deactivation tasks if needed
}

// Enqueue scripts and styles
function airbnb_analyzer_enqueue_scripts() {
    // Only load scripts and styles when the shortcode is present on the page
    global $post;
    if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'airbnb_analyzer')) {
        wp_enqueue_style('airbnb-analyzer-css', AIRBNB_ANALYZER_URL . 'css/style.css', array(), '1.0.0');
        wp_enqueue_script('airbnb-analyzer-js', AIRBNB_ANALYZER_URL . 'js/script.js', array('jquery'), '1.0.0', true);
        
        // Add reCAPTCHA script
        wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true);
        
        // Add ajax url for JavaScript
        wp_localize_script('airbnb-analyzer-js', 'airbnb_analyzer_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('airbnb_analyzer_nonce')
        ));
    }
}
add_action('wp_enqueue_scripts', 'airbnb_analyzer_enqueue_scripts');

// Register AJAX handlers
add_action('wp_ajax_analyze_airbnb_listing', 'airbnb_analyzer_process_request');
add_action('wp_ajax_nopriv_analyze_airbnb_listing', 'airbnb_analyzer_process_request');

function airbnb_analyzer_process_request() {
    // Verify nonce
    check_ajax_referer('airbnb_analyzer_nonce', 'nonce');
    
    // Get form data
    $listing_url = isset($_POST['listing_url']) ? sanitize_text_field($_POST['listing_url']) : '';
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $captcha = isset($_POST['captcha']) ? sanitize_text_field($_POST['captcha']) : '';
    
    if (empty($listing_url)) {
        wp_send_json_error(array('message' => 'Please provide a valid AirBnB listing URL'));
    }
    
    if (empty($email)) {
        wp_send_json_error(array('message' => 'Please provide a valid email address'));
    }
    
    // Check if Brightdata API key is configured
    $brightdata_api_key = get_option('airbnb_analyzer_brightdata_api_key');
    if (empty($brightdata_api_key)) {
        wp_send_json_error(array('message' => 'Brightdata API key is not configured. Please contact the administrator.'));
    }
    
    // Verify CAPTCHA
    $recaptcha_secret = get_option('airbnb_analyzer_recaptcha_secret_key');
    $verify_response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
        'body' => array(
            'secret' => $recaptcha_secret,
            'response' => $captcha
        )
    ));
    
    if (is_wp_error($verify_response)) {
        wp_send_json_error(array('message' => 'CAPTCHA verification failed. Please try again.'));
    }
    
    $verify_data = json_decode(wp_remote_retrieve_body($verify_response), true);
    
    if (!isset($verify_data['success']) || $verify_data['success'] !== true) {
        wp_send_json_error(array('message' => 'CAPTCHA verification failed. Please try again.'));
    }
    
    // Store the email in the database
    airbnb_analyzer_store_email($email, $listing_url);
    
    // Trigger Brightdata scraping (async)
    $brightdata_result = brightdata_trigger_scraping($listing_url, $email);
    
    if (is_wp_error($brightdata_result)) {
        wp_send_json_error(array('message' => $brightdata_result->get_error_message()));
    }
    
    // Check if test mode is enabled
    $test_mode = get_option('airbnb_analyzer_brightdata_test_mode', false);
    
    if ($test_mode) {
        $message = 'Your request has been submitted successfully! We are now analyzing your Airbnb listing. NOTE: Test mode is enabled, so email notifications are disabled. Please check the admin dashboard for results or disable test mode to receive email alerts.';
    } else {
        $message = 'Your request has been submitted successfully! We are now analyzing your Airbnb listing. You will receive the results via email within 1-2 minutes.';
    }
    
    // Return success with pending status
    wp_send_json_success(array(
        'status' => 'pending',
        'message' => $message,
        'snapshot_id' => $brightdata_result['snapshot_id'],
        'test_mode' => $test_mode
    ));
}

/**
 * Store user email in the database
 */
function airbnb_analyzer_store_email($email, $listing_url) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
    
    // Insert email into database
    $wpdb->insert(
        $table_name,
        array(
            'email' => $email,
            'listing_url' => $listing_url
        )
    );
}

// Note: The register_settings function is now in includes/settings.php