<?php
/**
 * Airbnb API functionality for AirBnB Listing Analyzer
 * This uses the custom Airbnb API hosted on Render
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scrape Airbnb listing using custom API
 * 
 * @param string $listing_url The AirBnB listing URL
 * @param string $currency Currency code (default: USD)
 * @param string $language Language code (default: en)
 * @param int $adults Number of adults (default: 2)
 * @return array|WP_Error Listing data or error
 */
function pyairbnb_scrape_listing($listing_url, $currency = 'USD', $language = 'en', $adults = 2) {
    // Get API key and URL from settings
    $api_key = get_option('airbnb_analyzer_api_key', '');
    $api_url = get_option('airbnb_analyzer_api_url', 'https://airbnb-api-cql0.onrender.com/api/listing/details');
    
    if (empty($api_key)) {
        return new WP_Error('api_key_missing', 'Airbnb API key is not configured. Please configure it in the settings.');
    }
    
    // Prepare request body
    $request_body = array(
        'api_key' => $api_key,
        'room_url' => $listing_url,
        'currency' => $currency,
        'language' => $language,
        'adults' => $adults
    );
    
    // Make API request
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($request_body),
        'timeout' => 60, // 60 second timeout
        'sslverify' => true
    ));
    
    // Log debug info
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("API request URL: $api_url", 'Airbnb API');
        airbnb_analyzer_debug_log("API request body: " . json_encode($request_body), 'Airbnb API');
    }
    
    // Check for request errors
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("API request error: $error_message", 'Airbnb API Error');
        }
        return new WP_Error('api_request_error', 'API request failed: ' . $error_message);
    }
    
    // Get response code and body
    $status_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("API response code: $status_code", 'Airbnb API');
        airbnb_analyzer_debug_log("API response body: " . substr($response_body, 0, 500), 'Airbnb API');
    }
    
    // Check HTTP status code
    if ($status_code !== 200) {
        return new WP_Error('api_error', 'API returned error status: ' . $status_code . '. Response: ' . substr($response_body, 0, 200));
    }
    
    // Parse JSON response
    $json_data = json_decode($response_body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', 'Failed to parse API response: ' . json_last_error_msg() . '. Response: ' . substr($response_body, 0, 200));
    }
    
    // Check for error in response
    if (isset($json_data['error']) && $json_data['error']) {
        return new WP_Error('api_error', $json_data['message'] ?? 'Unknown error from API');
    }
    
    // Extract data from response
    if (isset($json_data['data'])) {
        return $json_data['data'];
    }
    
    // If response is the data directly (no wrapper)
    if (isset($json_data['id']) || isset($json_data['title'])) {
        return $json_data;
    }
    
    return new WP_Error('no_data', 'No data returned from API. Response: ' . substr($response_body, 0, 200));
}

/**
 * Trigger scraping request (for async processing)
 * This function stores the request and processes it asynchronously
 * 
 * @param string $listing_url The AirBnB listing URL
 * @param string $email User email for notification
 * @return array|WP_Error Response or error
 */
function pyairbnb_trigger_scraping($listing_url, $email) {
    // Generate a unique request ID
    $request_id = 'pyairbnb_' . time() . '_' . wp_generate_password(12, false);
    
    // Store the request in database
    pyairbnb_store_request($request_id, $listing_url, $email);
    
    // Process asynchronously using WordPress cron
    wp_schedule_single_event(time() + 5, 'airbnb_analyzer_process_pyairbnb_request', array($request_id));
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Airbnb API scraping scheduled for URL: $listing_url", 'Airbnb API');
    }
    
    return array(
        'status' => 'pending',
        'request_id' => $request_id,
        'message' => 'Scraping request submitted. You will receive results via email in 1-2 minutes.'
    );
}

/**
 * Process a PyAirbnb scraping request
 * This is called by WordPress cron
 * 
 * @param string $request_id The request ID
 */
function pyairbnb_process_request($request_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests'; // Keep same table name for compatibility
    
    // Get the request from database
    $request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE snapshot_id = %s",
        $request_id
    ));
    
    if (!$request) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Request not found: $request_id", 'Airbnb API Error');
        }
        return false;
    }
    
    if ($request->status !== 'pending') {
        // Already processed
        return false;
    }
    
    // Get currency and language from settings or use defaults
    $currency = get_option('airbnb_analyzer_currency', 'USD');
    $language = get_option('airbnb_analyzer_language', 'en');
    $adults = 2;
    
    // Scrape the listing
    $raw_data = pyairbnb_scrape_listing($request->listing_url, $currency, $language, $adults);
    
    if (is_wp_error($raw_data)) {
        // Update request status to error
        pyairbnb_update_request($request_id, 'error', array('error' => $raw_data->get_error_message()), null);
        
        // Send error email
        send_analysis_email($request->email, $request->listing_url, null, $raw_data->get_error_message(), $request_id);
        return false;
    }
    
    // Convert pyairbnb data to analyzer format
    $listing_data = pyairbnb_format_for_analyzer($raw_data);
    
    if (empty($listing_data)) {
        // Update request status to error
        pyairbnb_update_request($request_id, 'error', array('error' => 'No listing data found'), $raw_data);
        
        // Send error email
        send_analysis_email($request->email, $request->listing_url, null, 'No listing data found in the response', $request_id);
        return false;
    }
    
    // Analyze the listing
    $analysis = null;
    if (!empty(get_option('airbnb_analyzer_claude_api_key'))) {
        $analysis = airbnb_analyzer_analyze_listing_with_claude($listing_data);
    } else {
        $analysis = airbnb_analyzer_analyze_listing($listing_data);
    }
    
    // Update request status to completed with both processed and raw data
    pyairbnb_update_request($request_id, 'completed', array(
        'listing_data' => $listing_data,
        'analysis' => $analysis
    ), $raw_data);
    
    // Send the analysis via email
    send_analysis_email($request->email, $request->listing_url, $analysis, null, $request_id);
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Analysis completed and sent for request ID: $request_id", 'Airbnb API');
    }
    
    return true;
}

/**
 * Convert API JSON response to analyzer format
 * 
 * @param array $pyairbnb_data Raw API JSON data
 * @return array Formatted data for analyzer
 */
function pyairbnb_format_for_analyzer($pyairbnb_data) {
    if (empty($pyairbnb_data) || !is_array($pyairbnb_data)) {
        return array();
    }
    
    // Extract basic listing information
    $listing = $pyairbnb_data;
    
    // Parse bedrooms, bathrooms, beds
    $bedrooms = isset($listing['bedrooms']) ? intval($listing['bedrooms']) : 0;
    $bathrooms = isset($listing['bathrooms']) ? floatval($listing['bathrooms']) : 0;
    $beds = isset($listing['beds']) ? intval($listing['beds']) : 0;
    $max_guests = isset($listing['max_guests']) ? intval($listing['max_guests']) : 
                  (isset($listing['accommodates']) ? intval($listing['accommodates']) : 0);
    
    // Extract amenities - preserve structure if available
    $amenities = array();
    if (isset($listing['amenities']) && is_array($listing['amenities'])) {
        $amenities = $listing['amenities'];
    }
    
    // Extract host info
    $host_name = isset($listing['host_name']) ? $listing['host_name'] : '';
    $host_since = isset($listing['host_since']) ? $listing['host_since'] : '';
    $host_is_superhost = isset($listing['is_superhost']) ? (bool)$listing['is_superhost'] : 
                         (isset($listing['is_supperhost']) ? (bool)$listing['is_supperhost'] : false);
    $host_response_rate = isset($listing['host_response_rate']) ? intval($listing['host_response_rate']) : 0;
    $host_rating = isset($listing['host_rating']) ? floatval($listing['host_rating']) : 0;
    $host_review_count = isset($listing['host_review_count']) ? intval($listing['host_review_count']) : 0;
    $host_about = isset($listing['host_about']) ? $listing['host_about'] : '';
    $host_response_time = isset($listing['host_response_time']) ? $listing['host_response_time'] : '';
    $host_highlights = isset($listing['host_highlights']) && is_array($listing['host_highlights']) ? 
                       $listing['host_highlights'] : array();
    
    // Calculate host years if available
    $hosts_year = 0;
    if (!empty($host_since)) {
        $since_date = strtotime($host_since);
        if ($since_date !== false) {
            $years = (time() - $since_date) / (365.25 * 24 * 60 * 60);
            $hosts_year = floor($years);
        }
    }
    
    // Extract property rating details
    $property_rating_details = array();
    if (isset($listing['property_rating_details']) && is_array($listing['property_rating_details'])) {
        $property_rating_details = $listing['property_rating_details'];
    } elseif (isset($listing['rating_details']) && is_array($listing['rating_details'])) {
        foreach ($listing['rating_details'] as $key => $value) {
            if (is_numeric($value)) {
                $property_rating_details[] = array(
                    'name' => ucfirst(str_replace('_', ' ', $key)),
                    'value' => floatval($value)
                );
            }
        }
    }
    
    // Extract house rules
    $house_rules = '';
    if (isset($listing['house_rules']) && is_array($listing['house_rules'])) {
        $house_rules = implode("\n", $listing['house_rules']);
    } elseif (isset($listing['house_rules']) && is_string($listing['house_rules'])) {
        $house_rules = $listing['house_rules'];
    }
    
    // Extract cancellation policy
    $cancellation_policy = '';
    $cancellation_policy_details = array(
        'name' => '',
        'description' => '',
        'strictness' => 0,
        'can_instant_book' => false
    );
    
    if (isset($listing['cancellation_policy'])) {
        if (is_string($listing['cancellation_policy'])) {
            $cancellation_policy = $listing['cancellation_policy'];
            $cancellation_policy_details['name'] = $listing['cancellation_policy'];
        } elseif (is_array($listing['cancellation_policy'])) {
            if (isset($listing['cancellation_policy']['name'])) {
                $cancellation_policy = $listing['cancellation_policy']['name'];
                $cancellation_policy_details = array_merge($cancellation_policy_details, $listing['cancellation_policy']);
            }
        }
    }
    
    // Extract description by sections if available
    $description_by_sections = null;
    if (isset($listing['description_by_sections']) && is_array($listing['description_by_sections'])) {
        $description_by_sections = $listing['description_by_sections'];
    }
    
    // Format data for analyzer
    $listing_data = array(
        'id' => isset($listing['id']) ? $listing['id'] : '',
        'title' => isset($listing['title']) ? $listing['title'] : '',
        'description' => isset($listing['description']) ? $listing['description'] : '',
        'description_by_sections' => $description_by_sections,
        'photos' => isset($listing['photos']) && is_array($listing['photos']) ? $listing['photos'] : array(),
        'price' => isset($listing['price']) ? floatval($listing['price']) : 0,
        'price_currency' => isset($listing['price_currency']) ? $listing['price_currency'] : 
                           (isset($listing['currency']) ? $listing['currency'] : 'USD'),
        'location' => isset($listing['location']) ? $listing['location'] : '',
        'bedrooms' => $bedrooms,
        'bathrooms' => $bathrooms,
        'beds' => $beds,
        'max_guests' => $max_guests,
        'amenities' => $amenities,
        'host_name' => $host_name,
        'host_since' => $host_since,
        'is_superhost' => $host_is_superhost,
        'host_about' => $host_about,
        'host_response_rate' => $host_response_rate,
        'host_response_time' => $host_response_time,
        'host_highlights' => $host_highlights,
        'host_rating' => $host_rating,
        'host_review_count' => $host_review_count,
        'hosts_year' => $hosts_year,
        'neighborhood_details' => isset($listing['neighborhood_details']) ? $listing['neighborhood_details'] : '',
        'rating' => isset($listing['rating']) ? floatval($listing['rating']) : 
                   (isset($listing['ratings']) ? floatval($listing['ratings']) : 0),
        'ratings' => isset($listing['rating']) ? floatval($listing['rating']) : 
                    (isset($listing['ratings']) ? floatval($listing['ratings']) : 0),
        'review_count' => isset($listing['review_count']) ? intval($listing['review_count']) : 0,
        'property_rating_details' => $property_rating_details,
        'is_new_listing' => isset($listing['is_new_listing']) ? (bool)$listing['is_new_listing'] : false,
        'is_guest_favorite' => isset($listing['is_guest_favorite']) ? (bool)$listing['is_guest_favorite'] : false,
        'property_type' => isset($listing['property_type']) ? $listing['property_type'] : '',
        'house_rules' => $house_rules,
        'cancellation_policy' => $cancellation_policy,
        'cancellation_policy_details' => $cancellation_policy_details
    );
    
    return $listing_data;
}

/**
 * Store PyAirbnb request in database
 */
function pyairbnb_store_request($request_id, $listing_url, $email) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests'; // Keep same table for compatibility
    
    // Insert request into database
    $wpdb->insert(
        $table_name,
        array(
            'snapshot_id' => $request_id,
            'listing_url' => $listing_url,
            'email' => $email,
            'status' => 'pending'
        )
    );
}

/**
 * Update PyAirbnb request status
 */
function pyairbnb_update_request($request_id, $status, $response_data = null, $raw_response_data = null) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests'; // Keep same table for compatibility
    
    $update_data = array(
        'status' => $status,
        'date_completed' => current_time('mysql')
    );
    
    if ($response_data !== null) {
        $update_data['response_data'] = json_encode($response_data);
    }
    
    if ($raw_response_data !== null) {
        $update_data['raw_response_data'] = json_encode($raw_response_data);
    }
    
    $wpdb->update(
        $table_name,
        $update_data,
        array('snapshot_id' => $request_id)
    );
}

/**
 * Get PyAirbnb request by request ID
 */
function pyairbnb_get_request($request_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests'; // Keep same table for compatibility
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE snapshot_id = %s",
        $request_id
    ));
}

