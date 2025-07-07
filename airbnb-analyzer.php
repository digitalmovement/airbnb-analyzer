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
        snapshot_id varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        listing_url text NOT NULL,
        status varchar(50) NOT NULL DEFAULT 'pending',
        brightdata_request_id varchar(255) DEFAULT NULL,
        response_data longtext,
        raw_response_data longtext,
        date_created datetime DEFAULT CURRENT_TIMESTAMP,
        date_completed datetime DEFAULT NULL,
        views int(11) DEFAULT 0,
        last_viewed datetime DEFAULT NULL,
        expert_analysis_data longtext,
        expert_analysis_requested int(11) DEFAULT 0,
        expert_batch_id varchar(255) DEFAULT NULL,
        expert_batch_status varchar(50) DEFAULT NULL,
        expert_batch_submitted_at datetime DEFAULT NULL,
        expert_batch_completed_at datetime DEFAULT NULL,
        expert_batch_results_url text DEFAULT NULL,
        expert_analysis_email_sent tinyint(1) DEFAULT 0,
        PRIMARY KEY (id),
        KEY snapshot_id (snapshot_id),
        KEY email (email),
        KEY status (status),
        KEY expert_batch_id (expert_batch_id),
        KEY expert_batch_status (expert_batch_status)
    ) $charset_collate;";
    dbDelta($sql2);
    
    // Check if we need to add the new columns to existing installations
    $existing_columns = $wpdb->get_col("DESCRIBE $requests_table");
    $new_columns = array(
        'expert_analysis_data' => 'longtext',
        'expert_analysis_requested' => 'int(11) DEFAULT 0',
        'expert_batch_id' => 'varchar(255) DEFAULT NULL',
        'expert_batch_status' => 'varchar(50) DEFAULT NULL',
        'expert_batch_submitted_at' => 'datetime DEFAULT NULL',
        'expert_batch_completed_at' => 'datetime DEFAULT NULL',
        'expert_batch_results_url' => 'text DEFAULT NULL',
        'expert_analysis_email_sent' => 'tinyint(1) DEFAULT 0'
    );
    
    foreach ($new_columns as $column => $definition) {
        if (!in_array($column, $existing_columns)) {
            $wpdb->query("ALTER TABLE $requests_table ADD COLUMN $column $definition");
        }
    }
    
    // Add indexes for new columns if they don't exist
    $indexes = $wpdb->get_results("SHOW INDEX FROM $requests_table");
    $existing_indexes = array();
    foreach ($indexes as $index) {
        $existing_indexes[] = $index->Key_name;
    }
    
    $new_indexes = array(
        'expert_batch_id' => 'expert_batch_id',
        'expert_batch_status' => 'expert_batch_status'
    );
    
    foreach ($new_indexes as $index_name => $column) {
        if (!in_array($index_name, $existing_indexes)) {
            $wpdb->query("ALTER TABLE $requests_table ADD INDEX $index_name ($column)");
        }
    }
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
add_action('wp_ajax_analyze_airbnb_listing', 'airbnb_analyzer_handle_ajax');
add_action('wp_ajax_nopriv_analyze_airbnb_listing', 'airbnb_analyzer_handle_ajax');

add_action('wp_ajax_submit_url_for_analysis', 'airbnb_analyzer_submit_url');
add_action('wp_ajax_nopriv_submit_url_for_analysis', 'airbnb_analyzer_submit_url');

add_action('wp_ajax_expert_analysis_airbnb', 'airbnb_analyzer_handle_expert_analysis');
add_action('wp_ajax_nopriv_expert_analysis_airbnb', 'airbnb_analyzer_handle_expert_analysis');

add_action('wp_ajax_check_expert_analysis_status', 'airbnb_analyzer_check_expert_analysis_status');
add_action('wp_ajax_nopriv_check_expert_analysis_status', 'airbnb_analyzer_check_expert_analysis_status');

// Register WordPress cron hook for processing batch results
add_action('airbnb_analyzer_process_batch_results', 'airbnb_analyzer_process_batch_results_callback');

// Register cron hook for checking batch statuses
add_action('airbnb_analyzer_check_batch_statuses', 'airbnb_analyzer_check_batch_statuses_callback');

// Schedule cron job on plugin activation
register_activation_hook(__FILE__, 'airbnb_analyzer_schedule_cron_jobs');

// Clear cron job on plugin deactivation
register_deactivation_hook(__FILE__, 'airbnb_analyzer_clear_cron_jobs');

/**
 * Schedule cron jobs for batch processing
 */
function airbnb_analyzer_schedule_cron_jobs() {
    // Schedule batch status checking every 15 minutes
    if (!wp_next_scheduled('airbnb_analyzer_check_batch_statuses')) {
        wp_schedule_event(time(), 'every_15_minutes', 'airbnb_analyzer_check_batch_statuses');
    }
}

/**
 * Clear scheduled cron jobs
 */
function airbnb_analyzer_clear_cron_jobs() {
    wp_clear_scheduled_hook('airbnb_analyzer_check_batch_statuses');
}

/**
 * Add custom cron schedule for 15 minutes
 */
add_filter('cron_schedules', 'airbnb_analyzer_cron_schedules');
function airbnb_analyzer_cron_schedules($schedules) {
    $schedules['every_15_minutes'] = array(
        'interval' => 15 * 60, // 15 minutes in seconds
        'display' => __('Every 15 Minutes')
    );
    return $schedules;
}

/**
 * Check status of all pending batch requests
 */
function airbnb_analyzer_check_batch_statuses_callback() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    // Log cron execution
    error_log('BATCH_DEBUG: Checking batch statuses cron job started');
    
    // Get all requests with pending batch processing
    $pending_batches = $wpdb->get_results(
        "SELECT snapshot_id, expert_batch_id, expert_batch_submitted_at 
         FROM $table_name 
         WHERE expert_batch_status = 'in_progress' 
         AND expert_batch_id IS NOT NULL 
         AND expert_batch_submitted_at > DATE_SUB(NOW(), INTERVAL 25 HOUR)"
    );
    
    error_log('BATCH_DEBUG: Found ' . count($pending_batches) . ' pending batches');
    
    if (empty($pending_batches)) {
        error_log('BATCH_DEBUG: No pending batches to check');
        return; // No pending batches to check
    }
    
    // Check each batch status
    foreach ($pending_batches as $batch) {
        error_log('BATCH_DEBUG: Checking batch ' . $batch->expert_batch_id . ' for snapshot ' . $batch->snapshot_id);
        
        $batch_status = airbnb_analyzer_check_claude_batch_status($batch->expert_batch_id);
        
        if (is_wp_error($batch_status)) {
            // Log error but continue with other batches
            error_log('BATCH_DEBUG: Failed to check batch status for ' . $batch->snapshot_id . ': ' . $batch_status->get_error_message());
            continue;
        }
        
        $current_status = $batch_status['processing_status'];
        error_log('BATCH_DEBUG: Batch ' . $batch->expert_batch_id . ' status: ' . $current_status);
        
        // Update database with current status
        $update_result = $wpdb->update(
            $table_name,
            array('expert_batch_status' => $current_status),
            array('snapshot_id' => $batch->snapshot_id)
        );
        
        if ($update_result === false) {
            error_log('BATCH_DEBUG: Failed to update batch status in database for ' . $batch->snapshot_id);
            continue;
        }
        
        // If batch is complete, schedule result processing
        if ($current_status === 'ended') {
            $results_url = $batch_status['results_url'] ?? null;
            
            error_log('BATCH_DEBUG: Batch ' . $batch->expert_batch_id . ' completed with results URL: ' . $results_url);
            
            if ($results_url) {
                // Store results URL and mark as completed
                $wpdb->update(
                    $table_name,
                    array(
                        'expert_batch_results_url' => $results_url,
                        'expert_batch_completed_at' => current_time('mysql')
                    ),
                    array('snapshot_id' => $batch->snapshot_id)
                );
                
                error_log('BATCH_DEBUG: Scheduling result processing for ' . $batch->snapshot_id);
                
                // Process results immediately (within 30 seconds)
                wp_schedule_single_event(time() + 30, 'airbnb_analyzer_process_batch_results', array($batch->snapshot_id));
            } else {
                error_log('BATCH_DEBUG: No results URL found for completed batch ' . $batch->expert_batch_id);
            }
        }
    }
    
    error_log('BATCH_DEBUG: Batch status checking completed');
}

function airbnb_analyzer_handle_ajax() {
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

/**
 * Handle expert analysis requests with async batch processing
 */
function airbnb_analyzer_handle_expert_analysis() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'airbnb_analyzer_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    $snapshot_id = sanitize_text_field($_POST['snapshot_id']);
    
    if (empty($snapshot_id)) {
        wp_send_json_error(array('message' => 'Invalid request. No snapshot ID provided.'));
    }
    
    // Check if Claude API is configured
    $claude_api_key = get_option('airbnb_analyzer_claude_api_key');
    if (empty($claude_api_key)) {
        wp_send_json_error(array('message' => 'Expert analysis is not available. Claude API is not configured.'));
    }
    
    // Get the request from database
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    $request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE snapshot_id = %s",
        $snapshot_id
    ));
    
    if (!$request) {
        wp_send_json_error(array('message' => 'Analysis not found.'));
    }
    
    if ($request->status !== 'completed') {
        wp_send_json_error(array('message' => 'Analysis is not completed yet.'));
    }
    
    // Check if expert analysis already exists and is complete
    if (!empty($request->expert_analysis_data)) {
        $expert_analysis = json_decode($request->expert_analysis_data, true);
        if ($expert_analysis && isset($expert_analysis['content'])) {
            wp_send_json_success(array(
                'analysis' => $expert_analysis,
                'cached' => true,
                'status' => 'completed'
            ));
        }
    }
    
    // Check if batch is already in progress or pending
    if (!empty($request->expert_batch_id)) {
        $batch_status = $request->expert_batch_status;
        
        // Define statuses that indicate processing is ongoing
        $processing_statuses = array('in_progress', 'validating', 'processing', 'finalizing');
        
        if (in_array($batch_status, $processing_statuses)) {
            wp_send_json_success(array(
                'status' => 'processing',
                'batch_id' => $request->expert_batch_id,
                'batch_status' => $batch_status,
                'submitted_at' => $request->expert_batch_submitted_at,
                'message' => 'Your expert analysis is already being processed. This can take up to 24 hours, but often completes much sooner. You will receive an email when it\'s ready.',
                'prevent_new_request' => true
            ));
        }
        
        // If batch failed, allow new request but show warning
        if ($batch_status === 'failed' || $batch_status === 'error') {
            // Log the failed batch for debugging
            error_log("Previous batch failed for snapshot {$snapshot_id}: {$request->expert_batch_id}");
            
            // Continue to allow new request, but we'll track this
            $previous_failures = intval($request->expert_analysis_requested);
            
            // Prevent too many retry attempts (max 3)
            if ($previous_failures >= 3) {
                wp_send_json_error(array(
                    'message' => 'Expert analysis has failed multiple times for this listing. Please contact support if you continue to experience issues.',
                    'max_retries_reached' => true
                ));
            }
        }
    }
    
    // Additional validation: Check for recent batch requests (within last 5 minutes)
    if (!empty($request->expert_batch_submitted_at)) {
        $submitted_time = strtotime($request->expert_batch_submitted_at);
        $current_time = time();
        $time_diff = $current_time - $submitted_time;
        
        // If submitted within last 5 minutes and not failed, prevent new request
        if ($time_diff < 300 && !in_array($request->expert_batch_status, array('failed', 'error', 'completed'))) {
            wp_send_json_success(array(
                'status' => 'processing',
                'batch_id' => $request->expert_batch_id,
                'batch_status' => $request->expert_batch_status,
                'submitted_at' => $request->expert_batch_submitted_at,
                'message' => 'Your expert analysis was recently submitted and is still being processed. Please wait before requesting another analysis.',
                'prevent_new_request' => true,
                'time_remaining' => 300 - $time_diff
            ));
        }
    }
    
    // Create new batch request
    $raw_data = json_decode($request->raw_response_data, true);
    if (empty($raw_data)) {
        wp_send_json_error(array('message' => 'Raw analysis data not available for expert analysis.'));
    }
    
    error_log('BATCH_DEBUG: Creating new batch for snapshot ' . $snapshot_id);
    
    // Optimize the data for Claude
    $optimized_data = optimize_data_for_claude($raw_data);
    
    // Prepare the expert analysis prompt
    $expert_prompt = get_expert_analysis_prompt();
    $full_prompt = $expert_prompt . "\n\nJSON Data to Analyze:\n" . json_encode($optimized_data, JSON_PRETTY_PRINT);
    
    error_log('BATCH_DEBUG: Prompt length: ' . strlen($full_prompt) . ' characters');
    
    // Validate prompt size (Claude has input limits)
    $max_prompt_length = 180000; // Conservative limit for Claude API (roughly 45k tokens)
    if (strlen($full_prompt) > $max_prompt_length) {
        error_log('BATCH_DEBUG: Prompt too long (' . strlen($full_prompt) . ' chars), truncating data');
        
        // Truncate the optimized data to fit within limits
        $data_json = json_encode($optimized_data, JSON_PRETTY_PRINT);
        $available_space = $max_prompt_length - strlen($expert_prompt) - 100; // Leave some buffer
        
        if (strlen($data_json) > $available_space) {
            $truncated_data = substr($data_json, 0, $available_space - 100) . "\n... [Data truncated due to size limits]";
            $full_prompt = $expert_prompt . "\n\nJSON Data to Analyze:\n" . $truncated_data;
            error_log('BATCH_DEBUG: Truncated prompt to ' . strlen($full_prompt) . ' characters');
        }
    }
    
    // Create Claude batch
    $batch_response = airbnb_analyzer_create_claude_batch($snapshot_id, $full_prompt);
    
    if (is_wp_error($batch_response)) {
        error_log('BATCH_DEBUG: Failed to create batch for ' . $snapshot_id . ': ' . $batch_response->get_error_message());
        wp_send_json_error(array('message' => 'Failed to create expert analysis batch: ' . $batch_response->get_error_message()));
    }
    
    // Update database with batch information
    $batch_id = $batch_response['id'];
    error_log('BATCH_DEBUG: Created batch ' . $batch_id . ' for snapshot ' . $snapshot_id);
    
    $update_result = $wpdb->update(
        $table_name,
        array(
            'expert_batch_id' => $batch_id,
            'expert_batch_status' => 'in_progress',
            'expert_batch_submitted_at' => current_time('mysql'),
            'expert_analysis_requested' => $request->expert_analysis_requested + 1
        ),
        array('snapshot_id' => $snapshot_id)
    );
    
    if ($update_result === false) {
        error_log('BATCH_DEBUG: Failed to update database with batch info for ' . $snapshot_id . ': ' . $wpdb->last_error);
        wp_send_json_error(array('message' => 'Failed to update database with batch information.'));
    }
    
    error_log('BATCH_DEBUG: Successfully stored batch info for ' . $snapshot_id);
    
    wp_send_json_success(array(
        'status' => 'processing',
        'batch_id' => $batch_id,
        'message' => 'Your expert analysis has been submitted for processing. This can take up to 24 hours, but often completes much sooner. You will receive an email when it\'s ready.',
        'estimated_completion' => 'within 24 hours'
    ));
}

/**
 * Get the enhanced expert analysis prompt for batch processing
 */
function get_expert_analysis_prompt() {
    return '# COMPREHENSIVE AIRBNB LISTING ANALYSIS & OPTIMIZATION SYSTEM

You are the world\'s leading Airbnb listing consultant, SEO specialist, and conversion optimization expert with 15+ years of experience in vacation rental optimization. You have helped over 10,000 hosts increase their bookings by 300%+ through strategic listing improvements.

## YOUR EXPERTISE COVERS:
- Airbnb algorithm optimization and search ranking factors
- Advanced SEO techniques for short-term rental platforms
- Conversion psychology and booking optimization
- Revenue management and pricing strategies
- Guest experience design and host reputation building
- Market analysis and competitive positioning
- Content creation and copywriting for hospitality
- Visual marketing and photo optimization strategies
- Cross-platform listing optimization (Airbnb, VRBO, Booking.com)

## ANALYSIS FRAMEWORK:

Please provide an extremely detailed, actionable analysis following this comprehensive structure. Use the full token limit to provide maximum value.

### 1. EXECUTIVE SUMMARY & OVERALL ASSESSMENT
- **Property Overview**: Detailed summary of the listing type, location, and unique characteristics
- **Current Performance Assessment**: Overall rating of listing effectiveness (1-10) with detailed justification
- **Top 5 Critical Issues**: Most important problems holding back bookings
- **Revenue Impact Potential**: Estimated booking increase percentage from implementing recommendations
- **Competitive Positioning**: How this listing compares to similar properties in the area
- **Guest Persona Analysis**: Ideal target guests based on property characteristics

### 2. TITLE OPTIMIZATION (Character Limit: 50)
**Current Title Analysis:**
- Character count and utilization efficiency
- Keyword density and search optimization
- Emotional appeal and click-worthiness assessment
- Local SEO integration analysis
- Competitive differentiation evaluation

**Optimization Recommendations:**
- **3 Alternative Titles** (each exactly 50 characters or less)
- Keyword strategy explanation for each title
- A/B testing suggestions for title variations
- Seasonal title adjustment recommendations
- Character optimization techniques

### 3. COMPREHENSIVE DESCRIPTION OPTIMIZATION (Max: 500 words)
**Current Description Analysis:**
- Structure and readability assessment
- First 400 characters analysis (preview text critical importance)
- Keyword optimization opportunities
- Emotional triggers and persuasion techniques evaluation
- Information gaps and missing selling points
- Competitive differentiation in description

**Complete Rewritten Description:**
- **Primary Description** (500 words max, SEO-optimized)
- **Alternative Short Version** (250 words for platforms requiring brevity)
- **Seasonal Variations** (summer/winter descriptions if applicable)
- **Keyword Integration Map**: Primary and secondary keywords placement
- **Call-to-Action Optimization**: Specific booking-driving language

### 4. LOCATION & NEIGHBORHOOD OPTIMIZATION (Max: 150 words)
**Current Location Description Analysis:**
- Transportation accessibility coverage
- Local attractions and amenities highlighting
- Neighborhood safety and appeal factors
- Distance information accuracy and appeal
- Local business and restaurant recommendations

**Optimized Location Content:**
- **Complete Neighborhood Description** (150 words max)
- **Transportation Guide**: Detailed access instructions
- **Local Attractions List**: Top 10 nearby activities with distances
- **Dining & Entertainment**: Restaurant and nightlife recommendations
- **Practical Information**: Grocery stores, pharmacies, ATMs locations

### 5. ADVANCED AMENITIES STRATEGY
**Current Amenities Analysis:**
- Amenities categorization and presentation effectiveness
- Missing essential amenities for property type and price point
- Competitive amenities comparison
- Seasonal amenities optimization
- Technology and modern amenities integration

**Optimized Amenities Strategy:**
- **Reordered Amenities List**: Most important amenities first
- **Category Optimization**: Best grouping for visual appeal
- **Missing Amenities Recommendations**: What to add to increase bookings
- **Amenities Description Enhancement**: Better wording for each amenity
- **Competitive Amenities Analysis**: What competitors offer that you don\'t

### 6. HOUSE RULES OPTIMIZATION
**Current Rules Analysis:**
- Guest-friendliness vs host protection balance
- Rules that might deter bookings
- Missing important rules for property protection
- Enforcement practicality assessment

**Optimized House Rules:**
- **Guest-Friendly Rules List**: Positive framing of necessary restrictions
- **Essential Rules**: Non-negotiable items for property protection
- **Welcome Rules**: Positive rules that enhance guest experience
- **Check-in/Check-out Procedures**: Streamlined and clear instructions

### 7. AIRBNB SEO MASTERY
**Advanced SEO Analysis:**
- Primary keyword optimization opportunities
- Long-tail keyword potential
- Local SEO integration strategies
- Seasonal keyword adjustments
- Cross-platform SEO considerations

**Complete SEO Strategy:**
- **Primary Keywords**: Top 5 keywords to target
- **Secondary Keywords**: Supporting keyword list
- **Keyword Density Map**: Where and how often to use each keyword
- **Local SEO Integration**: City, neighborhood, and landmark optimization
- **Seasonal SEO Adjustments**: Different keywords for different seasons
- **Competition Analysis**: Keyword gaps competitors are missing

### 8. CONVERSION RATE OPTIMIZATION
**Current Conversion Analysis:**
- Booking funnel analysis and optimization opportunities
- Trust signals assessment and enhancement
- Social proof optimization strategies
- Urgency and scarcity psychology implementation
- Price anchoring and value perception optimization

**Advanced Conversion Strategies:**
- **Trust Building Elements**: What to add to increase guest confidence
- **Social Proof Enhancement**: How to better showcase reviews and ratings
- **Urgency Creation**: Ethical scarcity and urgency tactics
- **Value Perception**: How to justify pricing through description
- **Booking Friction Reduction**: Remove barriers to booking

### 9. COMPREHENSIVE CONTENT DELIVERABLES

#### A. READY-TO-USE OPTIMIZED CONTENT:

**TITLE OPTIONS** (3 variations, max 50 characters each):
1. [TITLE 1 - 50 chars exactly]
2. [TITLE 2 - 50 chars exactly]  
3. [TITLE 3 - 50 chars exactly]

**COMPLETE PROPERTY DESCRIPTION** (500 words max):
[Full optimized description ready to copy/paste]

**LOCATION DESCRIPTION** (150 words max):
[Complete neighborhood/location description ready to copy/paste]

**AMENITIES LIST** (organized and optimized):
[Bullet-pointed list of amenities in optimal order]

**HOUSE RULES** (guest-friendly format):
[Complete house rules ready to copy/paste]

**HOST WELCOME MESSAGE** (100 words max):
[Personalized welcome message template]

#### B. ADDITIONAL OPTIMIZATION CONTENT:

**INSTANT BOOK RECOMMENDATIONS**:
- Should instant book be enabled for this property type?
- Pros and cons analysis
- Alternative strategies if instant book is not suitable

**PRICING STRATEGY INSIGHTS**:
- Pricing position recommendations (premium/mid-range/budget)
- Seasonal pricing adjustment suggestions
- Value justification strategies

**GUEST COMMUNICATION TEMPLATES**:
- Pre-arrival message template
- Check-in instructions template
- Post-stay follow-up message

### 10. AIRBNB ALGORITHM OPTIMIZATION
**Algorithm Ranking Factors:**
- Listing completeness score optimization
- Response rate and time optimization strategies
- Booking acceptance rate improvement
- Calendar availability optimization
- Review score enhancement tactics
- Superhost qualification pathway

**Advanced Algorithm Strategies:**
- **Search Ranking Factors**: 15 key factors affecting visibility
- **Booking Conversion Optimization**: Elements that increase booking likelihood
- **Host Performance Metrics**: KPIs to monitor and improve
- **Seasonal Optimization**: How to maintain visibility year-round
- **New Listing Bootstrap**: Strategies for new listings to gain traction

### 11. COMPETITIVE MARKET ANALYSIS
**Competitive Landscape:**
- Market positioning analysis based on location and property type
- Competitor pricing and value proposition analysis
- Differentiation opportunities identification
- Market gap analysis for positioning

**Competitive Strategy:**
- **Unique Value Proposition**: What makes this listing special
- **Competitive Advantages**: Strengths to emphasize
- **Competitive Weaknesses**: Areas where competitors are stronger
- **Market Opportunity**: Underserved guest segments to target

### 12. REVENUE OPTIMIZATION ROADMAP
**Implementation Priority:**
1. **Immediate Actions** (0-7 days): Quick wins for immediate impact
2. **Short-term Actions** (1-4 weeks): Medium effort, high impact changes
3. **Long-term Strategies** (1-6 months): Investment-required improvements

**Expected Results:**
- **Booking Increase Estimate**: Percentage increase in bookings expected
- **Revenue Impact**: Estimated annual revenue increase
- **Timeline**: When to expect results from each change
- **ROI Analysis**: Return on investment for recommended changes

### 13. ADVANCED GUEST EXPERIENCE DESIGN
**Guest Journey Optimization:**
- Pre-booking: Search and discovery optimization
- Booking process: Conversion optimization
- Pre-arrival: Communication and expectation setting
- Arrival: Check-in experience optimization
- Stay: Guest experience enhancement
- Departure: Check-out and follow-up optimization

**Experience Enhancement Strategies:**
- **Welcome Experience**: First impression optimization
- **Comfort Optimization**: Physical space improvements
- **Communication Excellence**: Host-guest interaction optimization
- **Problem Prevention**: Anticipating and preventing common issues
- **Review Generation**: Strategies to encourage positive reviews

### 14. LONG-TERM SUCCESS STRATEGIES
**Sustainable Growth Plan:**
- **6-Month Optimization Roadmap**: Continuous improvement schedule
- **Performance Monitoring**: Key metrics to track success
- **Seasonal Adjustments**: Year-round optimization strategies
- **Market Evolution**: Adapting to platform and market changes
- **Portfolio Scaling**: Strategies for managing multiple properties

**Advanced Tactics:**
- **Cross-Platform Optimization**: Maximizing visibility across all platforms
- **Dynamic Pricing Integration**: Revenue management best practices
- **Guest Retention**: Strategies for repeat bookings
- **Referral Generation**: Word-of-mouth marketing optimization
- **Brand Building**: Developing a recognizable host brand

## OUTPUT REQUIREMENTS:

1. **Use the FULL token limit** - provide maximum detail and value
2. **Include specific character/word counts** for all optimized content
3. **Provide copy-paste ready content** in clearly marked sections
4. **Include actionable next steps** with specific timelines
5. **Justify all recommendations** with data-driven reasoning
6. **Address the specific property type and location** - no generic advice
7. **Include estimated impact** for major recommendations
8. **Provide alternative strategies** for different scenarios

## CRITICAL SUCCESS FACTORS:
- Every recommendation must be immediately actionable
- All content must be ready to implement without further editing
- Focus on high-impact changes that require minimal investment
- Address both short-term wins and long-term growth strategies
- Provide specific, measurable success metrics
- Consider the property\'s unique characteristics and target market

Please analyze the provided JSON data thoroughly and deliver the most comprehensive, actionable listing optimization analysis possible. This analysis should serve as a complete roadmap for transforming this listing into a top-performing property that consistently ranks high in search results and converts viewers into bookers.

Remember: You have the full 50,000 token limit - use it to provide extraordinary value and detail that justifies the premium analysis service.';
}

/**
 * Optimize raw Brightdata JSON for Claude analysis
 * Extracts only essential data to reduce payload size and processing time
 * 
 * @param array $raw_data Full Brightdata response
 * @return array Optimized data for Claude analysis
 */
function optimize_data_for_claude($raw_data) {
    // Initialize optimized data structure
    $optimized = array();
    
    // Get the first listing data (main property)
    $listing = isset($raw_data[0]) ? $raw_data[0] : array();
    
    // Extract essential listing information
    $optimized['basic_info'] = array(
        'title' => $listing['listing_title'] ?? $listing['name'] ?? '',
        'description' => $listing['description'] ?? '',
        'property_type' => $listing['category'] ?? $listing['property_type'] ?? '',
        'room_type' => $listing['room_type'] ?? '',
        'location' => array(
            'city' => $listing['city'] ?? '',
            'neighborhood' => $listing['neighborhood'] ?? '',
            'address' => $listing['address'] ?? '',
            'coordinates' => array(
                'lat' => $listing['latitude'] ?? '',
                'lng' => $listing['longitude'] ?? ''
            )
        )
    );
    
    // Extract host information
    $optimized['host_info'] = array(
        'name' => $listing['host_name'] ?? '',
        'is_superhost' => $listing['is_supperhost'] ?? false,
        'response_rate' => $listing['host_response_rate'] ?? '',
        'response_time' => $listing['host_response_time'] ?? '',
        'host_about' => $listing['host_about'] ?? '',
        'host_highlights' => $listing['host_highlights'] ?? array(),
        'verification_labels' => $listing['host_verification_labels'] ?? array()
    );
    
    // Extract pricing and booking info
    $optimized['pricing'] = array(
        'base_price' => $listing['price'] ?? '',
        'currency' => $listing['currency'] ?? '',
        'cleaning_fee' => $listing['cleaning_fee'] ?? '',
        'service_fee' => $listing['service_fee'] ?? '',
        'instant_book' => $listing['instant_book'] ?? false,
        'minimum_nights' => $listing['minimum_nights'] ?? '',
        'maximum_nights' => $listing['maximum_nights'] ?? ''
    );
    
    // Extract reviews and ratings
    $optimized['reviews'] = array(
        'rating' => $listing['ratings'] ?? $listing['overall_rating'] ?? '',
        'review_count' => $listing['property_number_of_reviews'] ?? $listing['review_count'] ?? '',
        'is_guest_favorite' => $listing['is_guest_favorite'] ?? false,
        'rating_breakdown' => array(
            'accuracy' => $listing['rating_accuracy'] ?? '',
            'cleanliness' => $listing['rating_cleanliness'] ?? '',
            'checkin' => $listing['rating_checkin'] ?? '',
            'communication' => $listing['rating_communication'] ?? '',
            'location' => $listing['rating_location'] ?? '',
            'value' => $listing['rating_value'] ?? ''
        )
    );
    
    // Extract amenities (limit to important ones)
    $important_amenities = array();
    if (isset($listing['amenities']) && is_array($listing['amenities'])) {
        // Only include first 50 amenities to avoid overwhelming Claude
        $important_amenities = array_slice($listing['amenities'], 0, 50);
    }
    $optimized['amenities'] = $important_amenities;
    
    // Extract house rules
    $optimized['house_rules'] = array(
        'rules' => $listing['house_rules'] ?? array(),
        'cancellation_policy' => $listing['cancellation_policy'] ?? '',
        'check_in_time' => $listing['check_in_time'] ?? '',
        'check_out_time' => $listing['check_out_time'] ?? ''
    );
    
    // Extract photo information (just count and first few URLs)
    $optimized['photos'] = array(
        'count' => isset($listing['photos']) ? count($listing['photos']) : 0,
        'first_photo' => isset($listing['photos'][0]) ? $listing['photos'][0] : '',
        'has_multiple' => isset($listing['photos']) && count($listing['photos']) > 1
    );
    
    // Extract neighborhood and location details
    $optimized['location_details'] = array(
        'neighborhood_overview' => $listing['neighborhood_overview'] ?? '',
        'transit' => $listing['transit'] ?? '',
        'neighborhood_details' => $listing['neighborhood_details'] ?? '',
        'getting_around' => $listing['getting_around'] ?? ''
    );
    
    // Extract availability and calendar info
    $optimized['availability'] = array(
        'availability_30' => $listing['availability_30'] ?? '',
        'availability_60' => $listing['availability_60'] ?? '',
        'availability_90' => $listing['availability_90'] ?? '',
        'availability_365' => $listing['availability_365'] ?? ''
    );
    
    return $optimized;
}

/**
 * Check the status of an expert analysis batch
 */
function airbnb_analyzer_check_expert_analysis_status() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'airbnb_analyzer_nonce')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    $snapshot_id = sanitize_text_field($_POST['snapshot_id']);
    
    if (empty($snapshot_id)) {
        wp_send_json_error(array('message' => 'Invalid request. No snapshot ID provided.'));
    }
    
    // Get the request from database
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    $request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE snapshot_id = %s",
        $snapshot_id
    ));
    
    if (!$request) {
        wp_send_json_error(array('message' => 'Analysis not found.'));
    }
    
    // Check if analysis is already complete
    if (!empty($request->expert_analysis_data)) {
        $expert_analysis = json_decode($request->expert_analysis_data, true);
        if ($expert_analysis && isset($expert_analysis['content'])) {
            wp_send_json_success(array(
                'status' => 'completed',
                'analysis' => $expert_analysis,
                'cached' => true
            ));
        }
    }
    
    // If no batch ID, analysis hasn't been submitted yet
    if (empty($request->expert_batch_id)) {
        wp_send_json_success(array(
            'status' => 'not_submitted',
            'message' => 'Expert analysis has not been submitted yet.'
        ));
    }
    
    // Check batch status with Claude API
    $batch_status = airbnb_analyzer_check_claude_batch_status($request->expert_batch_id);
    
    if (is_wp_error($batch_status)) {
        wp_send_json_error(array('message' => 'Failed to check batch status: ' . $batch_status->get_error_message()));
    }
    
    // Update database with current status
    $current_status = $batch_status['processing_status'];
    $wpdb->update(
        $table_name,
        array('expert_batch_status' => $current_status),
        array('snapshot_id' => $snapshot_id)
    );
    
    // If batch is complete, process the results
    if ($current_status === 'ended') {
        $results_url = $batch_status['results_url'];
        
        if ($results_url) {
            // Store results URL and mark as completed
            $wpdb->update(
                $table_name,
                array(
                    'expert_batch_results_url' => $results_url,
                    'expert_batch_completed_at' => current_time('mysql')
                ),
                array('snapshot_id' => $snapshot_id)
            );
            
            // Process results in background
            wp_schedule_single_event(time() + 10, 'airbnb_analyzer_process_batch_results', array($snapshot_id));
            
            wp_send_json_success(array(
                'status' => 'processing_results',
                'message' => 'Analysis completed! Processing results...'
            ));
        }
    }
    
    // Return current status
    $status_messages = array(
        'in_progress' => 'Your expert analysis is being processed. This can take up to 24 hours, but often completes much sooner.',
        'canceling' => 'Analysis is being canceled.',
        'ended' => 'Analysis completed! Processing results...'
    );
    
    wp_send_json_success(array(
        'status' => $current_status,
        'message' => isset($status_messages[$current_status]) ? $status_messages[$current_status] : 'Unknown status',
        'request_counts' => $batch_status['request_counts'] ?? null
    ));
}

/**
 * Process completed batch results
 * 
 * @param string $snapshot_id The snapshot ID to process
 */
function airbnb_analyzer_process_batch_results_callback($snapshot_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    error_log('BATCH_DEBUG: Processing batch results for snapshot ' . $snapshot_id);
    
    // Get the request from database
    $request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE snapshot_id = %s",
        $snapshot_id
    ));
    
    if (!$request) {
        error_log('BATCH_DEBUG: No request found for snapshot ' . $snapshot_id);
        return false;
    }
    
    if (empty($request->expert_batch_results_url)) {
        error_log('BATCH_DEBUG: No results URL found for snapshot ' . $snapshot_id);
        return false;
    }
    
    error_log('BATCH_DEBUG: Retrieving results from URL: ' . $request->expert_batch_results_url);
    
    // Retrieve batch results
    $batch_results = airbnb_analyzer_retrieve_claude_batch_results($request->expert_batch_results_url);
    
    if (is_wp_error($batch_results)) {
        // Log error but don't fail completely
        error_log('BATCH_DEBUG: Failed to retrieve batch results for ' . $snapshot_id . ': ' . $batch_results->get_error_message());
        
        // Update database with error status
        $wpdb->update(
            $table_name,
            array('expert_batch_status' => 'error'),
            array('snapshot_id' => $snapshot_id)
        );
        
        return false;
    }
    
    error_log('BATCH_DEBUG: Retrieved ' . count($batch_results) . ' batch results');
    
    // Find our specific result
    $analysis_result = null;
    $custom_id = 'expert_analysis_' . $snapshot_id;
    
    foreach ($batch_results as $result) {
        if (isset($result['custom_id']) && $result['custom_id'] === $custom_id) {
            $analysis_result = $result;
            break;
        }
    }
    
    if (!$analysis_result) {
        error_log('BATCH_DEBUG: Could not find analysis result for custom_id: ' . $custom_id);
        error_log('BATCH_DEBUG: Available custom_ids: ' . implode(', ', array_column($batch_results, 'custom_id')));
        return false;
    }
    
    error_log('BATCH_DEBUG: Found analysis result for ' . $snapshot_id);
    
    // Check if the result was successful
    if ($analysis_result['result']['type'] !== 'succeeded') {
        $error_type = $analysis_result['result']['error']['type'] ?? 'unknown';
        $error_message = $analysis_result['result']['error']['message'] ?? 'Unknown error';
        
        error_log('BATCH_DEBUG: Analysis failed - Type: ' . $error_type . ', Message: ' . $error_message);
        error_log('BATCH_DEBUG: Full error structure: ' . print_r($analysis_result['result'], true));
        
        // Update database with error status
        $wpdb->update(
            $table_name,
            array(
                'expert_batch_status' => 'error',
                'expert_analysis_data' => json_encode(array(
                    'error' => true,
                    'error_type' => $error_type,
                    'error_message' => $error_message,
                    'generated_at' => current_time('mysql'),
                    'full_error' => $analysis_result['result']
                ))
            ),
            array('snapshot_id' => $snapshot_id)
        );
        
        // Send error email
        airbnb_analyzer_send_expert_analysis_email($request->email, $request->listing_url, null, $error_message, $snapshot_id);
        return false;
    }
    
    // Extract the analysis content
    $message = $analysis_result['result']['message'];
    $analysis_content = '';
    
    if (isset($message['content'][0]['text'])) {
        $analysis_content = $message['content'][0]['text'];
    }
    
    if (empty($analysis_content)) {
        error_log('BATCH_DEBUG: No analysis content found for ' . $snapshot_id);
        error_log('BATCH_DEBUG: Message structure: ' . print_r($message, true));
        return false;
    }
    
    error_log('BATCH_DEBUG: Analysis content length: ' . strlen($analysis_content) . ' characters');
    
    // Store the completed analysis
    $expert_analysis_data = array(
        'content' => $analysis_content,
        'generated_at' => current_time('mysql'),
        'model_used' => $message['model'] ?? 'claude-3-5-sonnet',
        'input_tokens' => $message['usage']['input_tokens'] ?? 0,
        'output_tokens' => $message['usage']['output_tokens'] ?? 0,
        'batch_processing' => true
    );
    
    error_log('BATCH_DEBUG: Storing analysis data with ' . ($expert_analysis_data['output_tokens']) . ' output tokens');
    
    $update_result = $wpdb->update(
        $table_name,
        array(
            'expert_analysis_data' => json_encode($expert_analysis_data),
            'expert_batch_status' => 'completed'
        ),
        array('snapshot_id' => $snapshot_id)
    );
    
    if ($update_result === false) {
        error_log('BATCH_DEBUG: Failed to update database with analysis results for ' . $snapshot_id);
        error_log('BATCH_DEBUG: Database error: ' . $wpdb->last_error);
        return false;
    }
    
    error_log('BATCH_DEBUG: Successfully stored analysis data for ' . $snapshot_id);
    
    // Send success email
    $email_sent = airbnb_analyzer_send_expert_analysis_email($request->email, $request->listing_url, $expert_analysis_data, null, $snapshot_id);
    
    if ($email_sent) {
        error_log('BATCH_DEBUG: Email sent successfully for ' . $snapshot_id);
        
        // Mark email as sent
        $wpdb->update(
            $table_name,
            array('expert_analysis_email_sent' => 1),
            array('snapshot_id' => $snapshot_id)
        );
    } else {
        error_log('BATCH_DEBUG: Failed to send email for ' . $snapshot_id);
    }
    
    error_log('BATCH_DEBUG: Batch result processing completed for ' . $snapshot_id);
    
    return true;
}

/**
 * Send expert analysis completion email
 * 
 * @param string $email Recipient email
 * @param string $listing_url Original listing URL
 * @param array|null $analysis_data Analysis data (null if error)
 * @param string|null $error_message Error message (null if success)
 * @param string $snapshot_id Snapshot ID for tracking
 */
function airbnb_analyzer_send_expert_analysis_email($email, $listing_url, $analysis_data = null, $error_message = null, $snapshot_id = '') {
    $site_name = get_bloginfo('name');
    $results_url = home_url('/airbnb-analysis-results/?id=' . $snapshot_id);
    
    if ($error_message) {
        // Error email
        $subject = 'Expert Analysis Failed - ' . $site_name;
        $message = "Unfortunately, your expert analysis request has failed.\n\n";
        $message .= "Listing URL: " . $listing_url . "\n";
        $message .= "Error: " . $error_message . "\n\n";
        $message .= "You can try submitting a new request at: " . home_url() . "\n\n";
        $message .= "If this issue persists, please contact support.\n\n";
        $message .= "Best regards,\n" . $site_name;
    } else {
        // Success email
        $subject = 'Your Expert Analysis is Ready! - ' . $site_name;
        $message = "Great news! Your expert Airbnb listing analysis is ready.\n\n";
        $message .= "Listing URL: " . $listing_url . "\n";
        $message .= "Analysis Generated: " . ($analysis_data['generated_at'] ?? 'Now') . "\n";
        $message .= "Model Used: " . ($analysis_data['model_used'] ?? 'Claude AI') . "\n\n";
        
        if (isset($analysis_data['output_tokens'])) {
            $message .= "Analysis Length: " . number_format($analysis_data['output_tokens']) . " tokens (comprehensive analysis)\n\n";
        }
        
        $message .= "View your detailed analysis here:\n" . $results_url . "\n\n";
        $message .= "Your analysis includes:\n";
        $message .= "• Complete listing optimization recommendations\n";
        $message .= "• SEO improvements for better search visibility\n";
        $message .= "• Ready-to-use optimized content\n";
        $message .= "• Conversion optimization strategies\n";
        $message .= "• Market positioning insights\n\n";
        $message .= "This analysis will remain available for 30 days.\n\n";
        $message .= "Best regards,\n" . $site_name;
    }
    
    $headers = array(
        'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . get_option('admin_email'),
        'Content-Type: text/plain; charset=UTF-8'
    );
    
    wp_mail($email, $subject, $message, $headers);
}

// Note: The register_settings function is now in includes/settings.php