<?php
/**
 * Brightdata API functionality for AirBnB Listing Analyzer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trigger Brightdata scraping request
 * 
 * @param string $listing_url The AirBnB listing URL
 * @param string $email User email for notification
 * @return array|WP_Error Response or error
 */
function brightdata_trigger_scraping($listing_url, $email) {
    $api_key = get_option('airbnb_analyzer_brightdata_api_key');
    
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', 'Brightdata API key is not configured. Please configure it in the settings.');
    }
    
    // Prepare the API request data
    $data = array(
        array(
            'url' => $listing_url,
            'country' => 'US'
        )
    );
    
    // Get dataset ID from settings, with fallback to default
    $dataset_id = get_option('airbnb_analyzer_brightdata_dataset_id', 'gd_ld7ll037kqy322v05');
    
    $api_url = 'https://api.brightdata.com/datasets/v3/trigger';
    $api_url .= '?dataset_id=' . $dataset_id;
    
    // Check if test mode is enabled (no notifications)
    $test_mode = get_option('airbnb_analyzer_brightdata_test_mode', false);
    $notify_url = '';
    
    if (!$test_mode) {
        // Create notification URL
        $notify_url = site_url('/wp-content/plugins/airbnb-analyzer/notify.php');
        $api_url .= '&notify=' . urlencode($notify_url);
    }
    
    $api_url .= '&include_errors=true';
    
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => json_encode($data),
        'method' => 'POST',
        'timeout' => 30
    );
    
    // Log debug info
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Triggering Brightdata scraping for URL: $listing_url", 'Brightdata API');
        airbnb_analyzer_debug_log("Test Mode: " . ($test_mode ? 'ENABLED' : 'DISABLED'), 'Brightdata API');
        airbnb_analyzer_debug_log("API URL: $api_url", 'Brightdata API');
        airbnb_analyzer_debug_log("Notification URL: " . ($notify_url ? $notify_url : 'NONE (Test Mode)'), 'Brightdata API');
        airbnb_analyzer_debug_log("Request Body: " . json_encode($data), 'Brightdata API');
        airbnb_analyzer_debug_log("Request Headers: " . json_encode($args['headers']), 'Brightdata API');
    }
    
    $response = wp_remote_post($api_url, $args);
    
    if (is_wp_error($response)) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Brightdata API request error: " . $response->get_error_message(), 'Brightdata API Error');
        }
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($status_code !== 200) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Brightdata API HTTP error: $status_code. Response: $body", 'Brightdata API Error');
        }
        return new WP_Error('api_error', 'Error triggering Brightdata scraping: ' . $status_code);
    }
    
    $response_data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("JSON parse error: " . json_last_error_msg(), 'Brightdata API Error');
        }
        return new WP_Error('json_error', 'Error parsing Brightdata API response: ' . json_last_error_msg());
    }
    
    if (!isset($response_data['snapshot_id'])) {
        return new WP_Error('missing_snapshot_id', 'No snapshot ID returned from Brightdata API');
    }
    
    $snapshot_id = $response_data['snapshot_id'];
    
    // Store the request in database for tracking
    brightdata_store_request($snapshot_id, $listing_url, $email);
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Brightdata request successful. Snapshot ID: $snapshot_id", 'Brightdata API');
    }
    
    return array(
        'status' => 'pending',
        'snapshot_id' => $snapshot_id,
        'message' => 'Scraping request submitted. You will receive results via email in 1-2 minutes.'
    );
}

/**
 * Get snapshot data from Brightdata
 * 
 * @param string $snapshot_id The snapshot ID
 * @return array|WP_Error Listing data or error
 */
function brightdata_get_snapshot($snapshot_id) {
    $api_key = get_option('airbnb_analyzer_brightdata_api_key');
    
    if (empty($api_key)) {
        return new WP_Error('missing_api_key', 'Brightdata API key is not configured');
    }
    
    $api_url = 'https://api.brightdata.com/datasets/v3/snapshot/' . $snapshot_id . '?format=json';
    
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'timeout' => 30
    );
    
    $response = wp_remote_get($api_url, $args);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($status_code !== 200) {
        return new WP_Error('api_error', 'Error fetching snapshot data: ' . $status_code);
    }
    
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', 'Error parsing snapshot data: ' . json_last_error_msg());
    }
    
    return $data;
}

/**
 * Convert Brightdata JSON to analyzer format
 * 
 * @param array $brightdata_data Raw Brightdata JSON data
 * @return array Formatted data for analyzer
 */
function brightdata_format_for_analyzer($brightdata_data) {
    if (empty($brightdata_data) || !is_array($brightdata_data) || empty($brightdata_data[0])) {
        return array();
    }
    
    $listing = $brightdata_data[0]; // Brightdata returns array, we want first item
    
    // Parse bedrooms, bathrooms, beds from details
    $bedrooms = 0;
    $bathrooms = 0;
    $beds = 0;
    $max_guests = isset($listing['guests']) ? intval($listing['guests']) : 0;
    
    if (isset($listing['details']) && is_array($listing['details'])) {
        foreach ($listing['details'] as $detail) {
            if (strpos($detail, 'bedroom') !== false) {
                preg_match('/(\d+)/', $detail, $matches);
                if (!empty($matches[1])) {
                    $bedrooms = intval($matches[1]);
                }
            }
            if (strpos($detail, 'bathroom') !== false) {
                preg_match('/(\d+(?:\.\d+)?)/', $detail, $matches);
                if (!empty($matches[1])) {
                    $bathrooms = floatval($matches[1]);
                }
            }
            if (strpos($detail, 'bed') !== false && strpos($detail, 'bedroom') === false) {
                preg_match('/(\d+)/', $detail, $matches);
                if (!empty($matches[1])) {
                    $beds = intval($matches[1]);
                }
            }
        }
    }
    
    // Extract amenities
    $amenities = array();
    if (isset($listing['amenities']) && is_array($listing['amenities'])) {
        foreach ($listing['amenities'] as $amenity_group) {
            if (isset($amenity_group['items']) && is_array($amenity_group['items'])) {
                foreach ($amenity_group['items'] as $amenity) {
                    if (isset($amenity['name'])) {
                        $amenities[] = $amenity['name'];
                    }
                }
            }
        }
    }
    
    // Extract host info
    $host_name = '';
    $host_since = '';
    $host_is_superhost = isset($listing['is_supperhost']) ? (bool)$listing['is_supperhost'] : false;
    $host_response_rate = isset($listing['host_response_rate']) ? intval($listing['host_response_rate']) : 0;
    $host_rating = isset($listing['host_rating']) ? floatval($listing['host_rating']) : 0;
    $host_review_count = isset($listing['host_number_of_reviews']) ? intval($listing['host_number_of_reviews']) : 0;
    
    // Calculate host years
    $hosts_year = isset($listing['hosts_year']) ? intval($listing['hosts_year']) : 0;
    if ($hosts_year > 0) {
        $host_since = date('Y', strtotime('-' . $hosts_year . ' years'));
    }
    
    // Extract property rating details
    $property_rating_details = array();
    if (isset($listing['category_rating']) && is_array($listing['category_rating'])) {
        foreach ($listing['category_rating'] as $rating) {
            if (isset($rating['name']) && isset($rating['value'])) {
                $property_rating_details[] = array(
                    'name' => $rating['name'],
                    'value' => floatval($rating['value'])
                );
            }
        }
    }
    
    // Extract house rules
    $house_rules = '';
    if (isset($listing['house_rules']) && is_array($listing['house_rules'])) {
        $house_rules = implode("\n", $listing['house_rules']);
    }
    
    // Extract cancellation policy
    $cancellation_policy = '';
    $cancellation_policy_details = array(
        'name' => '',
        'description' => '',
        'strictness' => 0,
        'can_instant_book' => false
    );
    
    if (isset($listing['cancellation_policy']) && is_array($listing['cancellation_policy'])) {
        foreach ($listing['cancellation_policy'] as $policy) {
            if (isset($policy['cancellation_name'])) {
                $cancellation_policy = $policy['cancellation_name'];
                $cancellation_policy_details['name'] = $policy['cancellation_name'];
                $cancellation_policy_details['description'] = isset($policy['cancellation_value']) ? $policy['cancellation_value'] : '';
                break;
            }
        }
    }
    
    // Format data for analyzer
    $listing_data = array(
        'id' => isset($listing['property_id']) ? $listing['property_id'] : '',
        'title' => isset($listing['listing_title']) ? $listing['listing_title'] : '',
        'description' => isset($listing['description']) ? $listing['description'] : '',
        'photos' => isset($listing['images']) ? $listing['images'] : array(),
        'price' => isset($listing['price']) ? floatval($listing['price']) : 0,
        'price_currency' => isset($listing['currency']) ? $listing['currency'] : 'USD',
        'location' => isset($listing['location']) ? $listing['location'] : '',
        'bedrooms' => $bedrooms,
        'bathrooms' => $bathrooms,
        'beds' => $beds,
        'max_guests' => $max_guests,
        'amenities' => $amenities,
        'host_name' => $host_name,
        'host_since' => $host_since,
        'host_is_superhost' => $host_is_superhost,
        'host_about' => '',
        'host_response_rate' => $host_response_rate,
        'host_response_time' => '',
        'host_highlights' => array(),
        'host_rating' => $host_rating,
        'host_review_count' => $host_review_count,
        'neighborhood_details' => '',
        'rating' => isset($listing['ratings']) ? floatval($listing['ratings']) : 0,
        'review_count' => isset($listing['property_number_of_reviews']) ? intval($listing['property_number_of_reviews']) : 0,
        'property_rating_details' => $property_rating_details,
        'is_new_listing' => false,
        'is_guest_favorite' => isset($listing['is_guest_favorite']) ? (bool)$listing['is_guest_favorite'] : false,
        'property_type' => isset($listing['category']) ? $listing['category'] : '',
        'house_rules' => $house_rules,
        'cancellation_policy' => $cancellation_policy,
        'cancellation_policy_details' => $cancellation_policy_details
    );
    
    return $listing_data;
}

/**
 * Store Brightdata request in database
 */
function brightdata_store_request($snapshot_id, $listing_url, $email) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    // Check if raw_response_data column exists, if not add it (for existing installations)
    $column_exists = $wpdb->get_results("SHOW COLUMNS FROM $table_name LIKE 'raw_response_data'");
    if (empty($column_exists)) {
        $wpdb->query("ALTER TABLE $table_name ADD COLUMN raw_response_data longtext AFTER response_data");
    }
    
    // Insert request into database
    $wpdb->insert(
        $table_name,
        array(
            'snapshot_id' => $snapshot_id,
            'listing_url' => $listing_url,
            'email' => $email,
            'status' => 'pending'
        )
    );
}

/**
 * Update Brightdata request status
 */
function brightdata_update_request($snapshot_id, $status, $response_data = null, $raw_response_data = null) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
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
        array('snapshot_id' => $snapshot_id)
    );
}

/**
 * Get Brightdata request by snapshot ID
 */
function brightdata_get_request($snapshot_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE snapshot_id = %s",
        $snapshot_id
    ));
}