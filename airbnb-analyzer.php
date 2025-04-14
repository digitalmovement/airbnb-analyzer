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
require_once(AIRBNB_ANALYZER_PATH . 'includes/settings.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/claude-api.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/admin.php');

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
    
    // Other activation tasks if needed
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
    
    // Get listing data
    $listing_data = airbnb_analyzer_get_listing_data($listing_url);
    
    if (is_wp_error($listing_data)) {
        wp_send_json_error(array('message' => $listing_data->get_error_message()));
    }
    
    // Check if listing data is empty
    if (empty($listing_data) || !is_array($listing_data)) {
        wp_send_json_error(array('message' => 'Unable to retrieve listing data. Please check the URL and try again.'));
    }
    
    // Analyze listing with Claude if API key is available
    if (!empty(get_option('airbnb_analyzer_claude_api_key'))) {
        $analysis = airbnb_analyzer_analyze_listing_with_claude($listing_data);
    } else {
        $analysis = airbnb_analyzer_analyze_listing($listing_data);
    }
    
    // Return results
    wp_send_json_success($analysis);
}

/**
 * Store user email in the database
 */
function airbnb_analyzer_store_email($email, $listing_url) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
    
    // Create table if it doesn't exist
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            email varchar(100) NOT NULL,
            listing_url text NOT NULL,
            date_added datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
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
?> 