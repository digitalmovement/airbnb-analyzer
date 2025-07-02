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
        expert_analysis_data longtext,
        expert_analysis_requested int(11) DEFAULT 0,
        views int(11) DEFAULT 0,
        last_viewed datetime NULL,
        date_created datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        date_completed datetime NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY snapshot_id (snapshot_id)
    ) $charset_collate;";
    dbDelta($sql2);
    
    // Check if expert analysis columns exist for existing installations
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $requests_table LIKE 'expert_analysis_data'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $requests_table ADD COLUMN expert_analysis_data longtext AFTER raw_response_data");
        $wpdb->query("ALTER TABLE $requests_table ADD COLUMN expert_analysis_requested int(11) DEFAULT 0 AFTER expert_analysis_data");
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

// Add AJAX handler for expert analysis
add_action('wp_ajax_expert_analysis_airbnb', 'airbnb_analyzer_handle_expert_analysis');
add_action('wp_ajax_nopriv_expert_analysis_airbnb', 'airbnb_analyzer_handle_expert_analysis');

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
 * Handle expert analysis AJAX request
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
    
    // Check if expert analysis already exists (cache)
    if (!empty($request->expert_analysis_data)) {
        $expert_analysis = json_decode($request->expert_analysis_data, true);
        if ($expert_analysis) {
            wp_send_json_success(array(
                'analysis' => $expert_analysis,
                'cached' => true
            ));
        }
    }
    
    // Get raw JSON data for expert analysis
    $raw_data = json_decode($request->raw_response_data, true);
    if (empty($raw_data)) {
        wp_send_json_error(array('message' => 'Raw analysis data not available for expert analysis.'));
    }
    
    // Optimize the data for Claude by extracting only essential information
    $optimized_data = optimize_data_for_claude($raw_data);
    
    // Prepare the expert analysis prompt
    $expert_prompt = get_expert_analysis_prompt();
    $full_prompt = $expert_prompt . "\n\nJSON Data to Analyze:\n" . json_encode($optimized_data, JSON_PRETTY_PRINT);
    
    // Make Claude API request
    $claude_response = airbnb_analyzer_claude_expert_request($full_prompt);
    
    if (is_wp_error($claude_response)) {
        wp_send_json_error(array('message' => 'Expert analysis failed: ' . $claude_response->get_error_message()));
    }
    
    // Extract analysis content from Claude response
    $analysis_content = '';
    if (isset($claude_response['content'][0]['text'])) {
        $analysis_content = $claude_response['content'][0]['text'];
    }
    
    if (empty($analysis_content)) {
        wp_send_json_error(array('message' => 'No analysis content received from AI.'));
    }
    
    // Store the expert analysis in database (cache it)
    $wpdb->update(
        $table_name,
        array(
            'expert_analysis_data' => json_encode(array(
                'content' => $analysis_content,
                'generated_at' => current_time('mysql'),
                'model_used' => 'claude-3-sonnet'
            )),
            'expert_analysis_requested' => $request->expert_analysis_requested + 1
        ),
        array('snapshot_id' => $snapshot_id)
    );
    
    wp_send_json_success(array(
        'analysis' => array(
            'content' => $analysis_content,
            'generated_at' => current_time('mysql'),
            'model_used' => 'claude-3-sonnet'
        ),
        'cached' => false
    ));
}

/**
 * Get the expert analysis prompt
 */
function get_expert_analysis_prompt() {
    return '# Airbnb Listing Analysis & Optimization Prompt

You are an expert Airbnb listing consultant and SEO specialist. Please analyze the provided Airbnb listing JSON data and provide comprehensive feedback following this exact structure:

## Analysis Requirements:

### 1. LISTING OVERVIEW ANALYSIS
- Provide overall sentiment assessment of the property
- Identify key strengths and weaknesses
- Highlight unique selling propositions
- Note any red flags or concerns

### 2. DETAILED BREAKDOWN BY SECTION
Analyze each component with specific feedback:

**A. Title Analysis**
- Current title effectiveness (character count: max 50 chars)
- SEO keyword optimization
- Appeal and click-worthiness
- **Provide 3 alternative optimized titles** (all under 50 characters)

**B. Description Analysis**
- Structure and flow assessment
- Content quality and appeal
- Missing information gaps
- Repetition or redundancy issues
- **Provide completely rewritten optimized description** (max 500 words)

**C. Location & Accessibility**
- Location description effectiveness
- Transport links highlighting
- Local attractions and amenities coverage
- **Provide optimized location section** (max 150 words)

**D. Amenities Assessment**
- Amenities presentation and categorization
- Missing amenities that should be highlighted
- Negative amenities impact ("Not included" items)
- **Suggest amenities reordering/rewording**

**E. House Rules Review**
- Current rules clarity and appeal
- Missing important rules
- Rules that might deter bookings
- **Provide optimized house rules**

### 3. AIRBNB SEO OPTIMIZATION
Provide specific recommendations for improving search ranking:
- Primary keywords to target for the location
- Secondary keywords for property type
- Title optimization for search visibility
- Description keyword density recommendations
- Category and tag suggestions
- Instant Book recommendations
- Response time optimization tips

### 4. CONVERSION OPTIMIZATION
- Booking conversion improvement suggestions
- Trust-building elements to add
- Social proof enhancement
- Pricing strategy recommendations (even without current pricing data)
- Photo sequence optimization suggestions
- Urgency and scarcity tactics

### 5. OPTIMIZED CONTENT DELIVERABLES
Provide ready-to-use, optimized content:

**A. 3 Alternative Titles** (max 50 chars each)
**B. Complete Property Description** (max 500 words, SEO-optimized)
**C. Location Description** (max 150 words)
**D. Optimized Amenities List** (properly categorized)
**E. House Rules** (guest-friendly but protective)
**F. Host Welcome Message** (max 100 words)

### 6. SEARCH RANKING FACTORS
Address these specific Airbnb algorithm factors:
- Listing completeness score improvements
- Response rate optimization
- Booking acceptance rate considerations
- Calendar availability optimization
- Review score improvement strategies
- Superhost qualification pathway

### 7. MARKET POSITIONING
- Competitive analysis insights
- Unique value proposition refinement
- Target guest persona identification
- Seasonal optimization strategies

## Output Format Requirements:
- Use clear headings and subheadings
- Provide character/word counts for all optimized content
- Include specific, actionable recommendations
- Highlight critical issues that need immediate attention
- Present optimized content in copy-paste ready format
- Include brief explanations for major changes made

## Character Limits to Respect:
- Title: 50 characters
- Property description: 500 words
- Neighborhood description: 150 words
- House rules: 500 words total
- Individual amenity descriptions: 50 characters each

Please analyze the provided JSON data thoroughly and deliver comprehensive, actionable feedback that will immediately improve the listing\'s performance and search visibility.';
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

// Note: The register_settings function is now in includes/settings.php