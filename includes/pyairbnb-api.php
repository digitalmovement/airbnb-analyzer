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
        $response_length = strlen($response_body);
        airbnb_analyzer_debug_log("API response body length: $response_length bytes", 'Airbnb API');
        
        // Check if response looks like valid JSON
        $is_valid_json_start = (substr(trim($response_body), 0, 1) === '{' || substr(trim($response_body), 0, 1) === '[');
        airbnb_analyzer_debug_log("Response appears to be JSON: " . ($is_valid_json_start ? 'YES' : 'NO'), 'Airbnb API');
        
        airbnb_analyzer_debug_log("API response body (first 1000 chars): " . substr($response_body, 0, 1000), 'Airbnb API');
        if ($response_length > 1000) {
            airbnb_analyzer_debug_log("API response body (last 500 chars): " . substr($response_body, -500), 'Airbnb API');
            // Check if JSON appears complete (ends with } or ])
            $last_char = trim(substr($response_body, -1));
            airbnb_analyzer_debug_log("Response ends with: '$last_char' (complete: " . (in_array($last_char, ['}', ']']) ? 'YES' : 'NO') . ")", 'Airbnb API');
        }
    }
    
    // Check HTTP status code
    if ($status_code !== 200) {
        return new WP_Error('api_error', 'API returned error status: ' . $status_code . '. Response: ' . substr($response_body, 0, 200));
    }
    
    // Parse JSON response
    $json_data = json_decode($response_body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error_msg = 'Failed to parse API response: ' . json_last_error_msg() . '. Response length: ' . strlen($response_body);
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log($error_msg, 'Airbnb API Error');
            airbnb_analyzer_debug_log("Response body (first 1000 chars): " . substr($response_body, 0, 1000), 'Airbnb API Error');
        }
        return new WP_Error('json_error', $error_msg);
    }
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("JSON parsed successfully. Keys: " . implode(', ', array_keys($json_data)), 'Airbnb API');
    }
    
    // Check for error in response
    if (isset($json_data['error']) && $json_data['error']) {
        $error_msg = $json_data['message'] ?? 'Unknown error from API';
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("API returned error: $error_msg", 'Airbnb API Error');
        }
        return new WP_Error('api_error', $error_msg);
    }
    
    // Extract data from response
    if (isset($json_data['data'])) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            $data_keys = is_array($json_data['data']) ? implode(', ', array_keys($json_data['data'])) : 'not an array';
            airbnb_analyzer_debug_log("Extracting data from response. Data keys: $data_keys", 'Airbnb API');
        }
        return $json_data['data'];
    }
    
    // If response is the data directly (no wrapper)
    if (isset($json_data['id']) || isset($json_data['title'])) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Response is data directly (no wrapper). Keys: " . implode(', ', array_keys($json_data)), 'Airbnb API');
        }
        return $json_data;
    }
    
    $error_msg = 'No data returned from API. Response keys: ' . implode(', ', array_keys($json_data));
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log($error_msg, 'Airbnb API Error');
        airbnb_analyzer_debug_log("Full response structure: " . json_encode($json_data, JSON_PRETTY_PRINT), 'Airbnb API Error');
    }
    return new WP_Error('no_data', $error_msg);
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
    $scheduled = wp_schedule_single_event(time() + 5, 'airbnb_analyzer_process_pyairbnb_request', array($request_id));
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Airbnb API scraping scheduled for URL: $listing_url", 'Airbnb API');
        airbnb_analyzer_debug_log("Cron scheduled: " . ($scheduled !== false ? 'SUCCESS' : 'FAILED') . " for request_id: $request_id", 'Airbnb API');
        airbnb_analyzer_debug_log("Cron will run at: " . date('Y-m-d H:i:s', time() + 5), 'Airbnb API');
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
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Processing request: $request_id", 'Airbnb API');
    }
    
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
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Request already processed with status: {$request->status}", 'Airbnb API');
        }
        return false;
    }
    
    // Get currency and language from settings or use defaults
    $currency = get_option('airbnb_analyzer_currency', 'USD');
    $language = get_option('airbnb_analyzer_language', 'en');
    $adults = 2;
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Scraping listing: {$request->listing_url} (currency: $currency, language: $language, adults: $adults)", 'Airbnb API');
    }
    
    // Scrape the listing
    $raw_data = pyairbnb_scrape_listing($request->listing_url, $currency, $language, $adults);
    
    if (is_wp_error($raw_data)) {
        $error_message = $raw_data->get_error_message();
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Scraping failed: $error_message", 'Airbnb API Error');
        }
        // Update request status to error
        pyairbnb_update_request($request_id, 'error', array('error' => $error_message), null);
        
        // Send error email
        send_analysis_email($request->email, $request->listing_url, null, $error_message, $request_id);
        return false;
    }
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        $data_type = is_array($raw_data) ? 'array' : gettype($raw_data);
        $data_size = is_array($raw_data) ? count($raw_data) : strlen($raw_data);
        airbnb_analyzer_debug_log("Scraping successful. Data type: $data_type, Size: $data_size", 'Airbnb API');
        if (is_array($raw_data)) {
            airbnb_analyzer_debug_log("Raw data keys: " . implode(', ', array_keys($raw_data)), 'Airbnb API');
        }
    }
    
    // Convert pyairbnb data to analyzer format
    $listing_data = pyairbnb_format_for_analyzer($raw_data);
    
    // Check if format conversion returned valid data (not just empty array)
    $has_data = false;
    if (is_array($listing_data) && !empty($listing_data)) {
        // Check if at least one field has a non-empty value
        foreach ($listing_data as $key => $value) {
            if (!empty($value) || (is_numeric($value) && $value == 0)) {
                $has_data = true;
                break;
            }
        }
    }
    
    if (!$has_data) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Format conversion returned empty or invalid data", 'Airbnb API Error');
            airbnb_analyzer_debug_log("Listing data structure: " . json_encode($listing_data, JSON_PRETTY_PRINT), 'Airbnb API Error');
        }
        // Update request status to error
        pyairbnb_update_request($request_id, 'error', array('error' => 'No listing data found after format conversion'), $raw_data);
        
        // Send error email
        send_analysis_email($request->email, $request->listing_url, null, 'No listing data found in the response', $request_id);
        return false;
    }
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Format conversion successful. Listing data keys: " . implode(', ', array_keys($listing_data)), 'Airbnb API');
    }
    
    // Analyze the listing
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Starting analysis...", 'Airbnb API');
    }
    
    $analysis = null;
    if (!empty(get_option('airbnb_analyzer_claude_api_key'))) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Using Claude API for analysis", 'Airbnb API');
        }
        $analysis = airbnb_analyzer_analyze_listing_with_claude($listing_data);
    } else {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Using standard analysis", 'Airbnb API');
        }
        $analysis = airbnb_analyzer_analyze_listing($listing_data);
    }
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        $analysis_status = is_wp_error($analysis) ? 'failed: ' . $analysis->get_error_message() : 'completed';
        airbnb_analyzer_debug_log("Analysis $analysis_status", 'Airbnb API');
    }
    
    // Update request status to completed with both processed and raw data
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Updating request status to completed", 'Airbnb API');
    }
    
    pyairbnb_update_request($request_id, 'completed', array(
        'listing_data' => $listing_data,
        'analysis' => $analysis
    ), $raw_data);
    
    // Send the analysis via email
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Sending analysis email to: {$request->email}", 'Airbnb API');
    }
    
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
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Starting format conversion. Input type: " . gettype($pyairbnb_data), 'Airbnb API Format');
        if (is_array($pyairbnb_data)) {
            airbnb_analyzer_debug_log("Input keys: " . implode(', ', array_keys($pyairbnb_data)), 'Airbnb API Format');
        }
    }
    
    if (empty($pyairbnb_data) || !is_array($pyairbnb_data)) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Format conversion failed: Input is empty or not an array", 'Airbnb API Format Error');
        }
        return array();
    }
    
    // Handle wrapped response (data key) or direct response
    $listing = isset($pyairbnb_data['data']) ? $pyairbnb_data['data'] : $pyairbnb_data;
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Listing data extracted. Listing keys: " . (is_array($listing) ? implode(', ', array_keys($listing)) : 'not an array'), 'Airbnb API Format');
    }
    
    // Parse bedrooms, bathrooms, beds, max_guests from sub_description.items
    $bedrooms = 0;
    $bathrooms = 0;
    $beds = 0;
    $max_guests = isset($listing['person_capacity']) ? intval($listing['person_capacity']) : 0;
    
    if (isset($listing['sub_description']['items']) && is_array($listing['sub_description']['items'])) {
        foreach ($listing['sub_description']['items'] as $item) {
            if (is_string($item)) {
                // Parse strings like "1 bedroom", "2 beds", "1.5 baths", "4 guests"
                if (preg_match('/(\d+(?:\.\d+)?)\s*bedroom/i', $item, $matches)) {
                    $bedrooms = intval($matches[1]);
                }
                if (preg_match('/(\d+(?:\.\d+)?)\s*bed(?!room)/i', $item, $matches)) {
                    $beds = intval($matches[1]);
                }
                if (preg_match('/(\d+(?:\.\d+)?)\s*bath/i', $item, $matches)) {
                    $bathrooms = floatval($matches[1]);
                }
                if (preg_match('/(\d+)\s*guest/i', $item, $matches)) {
                    $max_guests = intval($matches[1]);
                }
            }
        }
    }
    
    // Fallback to direct fields if available
    if ($bedrooms == 0 && isset($listing['bedrooms'])) {
        $bedrooms = intval($listing['bedrooms']);
    }
    if ($bathrooms == 0 && isset($listing['bathrooms'])) {
        $bathrooms = floatval($listing['bathrooms']);
    }
    if ($beds == 0 && isset($listing['beds'])) {
        $beds = intval($listing['beds']);
    }
    if ($max_guests == 0 && isset($listing['max_guests'])) {
        $max_guests = intval($listing['max_guests']);
    }
    
    // Convert amenities from new format {title, values[{available, icon, subtitle, title}]}
    // to analyzer format {group_name, items[{name, value}]}
    $amenities = array();
    if (isset($listing['amenities']) && is_array($listing['amenities'])) {
        foreach ($listing['amenities'] as $amenity_group) {
            if (is_array($amenity_group) && isset($amenity_group['title']) && isset($amenity_group['values'])) {
                $group_items = array();
                foreach ($amenity_group['values'] as $amenity_item) {
                    if (is_array($amenity_item) && isset($amenity_item['title'])) {
                        // Only include available amenities
                        $is_available = isset($amenity_item['available']) ? $amenity_item['available'] : true;
                        if ($is_available) {
                            $group_items[] = array(
                                'name' => $amenity_item['title'],
                                'value' => isset($amenity_item['icon']) ? $amenity_item['icon'] : '',
                                'subtitle' => isset($amenity_item['subtitle']) ? $amenity_item['subtitle'] : ''
                            );
                        }
                    }
                }
                if (!empty($group_items)) {
                    $amenities[] = array(
                        'group_name' => $amenity_group['title'],
                        'items' => $group_items
                    );
                }
            }
        }
    }
    
    // Extract host info from host object and host_details
    $host_name = '';
    if (isset($listing['host']['name'])) {
        $host_name = $listing['host']['name'];
        // Remove "Hosted by " prefix if present
        $host_name = preg_replace('/^Hosted by\s+/i', '', $host_name);
    } elseif (isset($listing['host_name'])) {
        $host_name = $listing['host_name'];
    }
    
    $host_since = isset($listing['host_since']) ? $listing['host_since'] : '';
    
    // is_super_host in new API format (note the underscore)
    $host_is_superhost = isset($listing['is_super_host']) ? (bool)$listing['is_super_host'] : 
                         (isset($listing['is_superhost']) ? (bool)$listing['is_superhost'] : 
                         (isset($listing['is_supperhost']) ? (bool)$listing['is_supperhost'] : false));
    
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
    
    // Extract rating - new API uses rating object with individual category values
    $overall_rating = 0;
    $review_count = 0;
    $property_rating_details = array();
    
    if (isset($listing['rating']) && is_array($listing['rating'])) {
        // New API format: rating object with accuracy, checking, cleanliness, etc.
        $rating_obj = $listing['rating'];
        
        // Use guest_satisfaction as the main rating, or calculate average
        if (isset($rating_obj['guest_satisfaction'])) {
            $overall_rating = floatval($rating_obj['guest_satisfaction']);
        } else {
            // Calculate average from available ratings
            $rating_sum = 0;
            $rating_count = 0;
            foreach (['accuracy', 'cleanliness', 'communication', 'location', 'value', 'checking'] as $key) {
                if (isset($rating_obj[$key]) && is_numeric($rating_obj[$key])) {
                    $rating_sum += floatval($rating_obj[$key]);
                    $rating_count++;
                }
            }
            if ($rating_count > 0) {
                $overall_rating = $rating_sum / $rating_count;
            }
        }
        
        // Extract review count from rating object
        if (isset($rating_obj['review_count'])) {
            $review_count = intval($rating_obj['review_count']);
        }
        
        // Convert to property_rating_details format
        $rating_category_map = array(
            'accuracy' => 'Accuracy',
            'checking' => 'Check-in',
            'cleanliness' => 'Cleanliness',
            'communication' => 'Communication',
            'location' => 'Location',
            'value' => 'Value'
        );
        
        foreach ($rating_category_map as $key => $name) {
            if (isset($rating_obj[$key]) && is_numeric($rating_obj[$key])) {
                $property_rating_details[] = array(
                    'name' => $name,
                    'value' => floatval($rating_obj[$key])
                );
            }
        }
    } elseif (isset($listing['rating']) && is_numeric($listing['rating'])) {
        // Old format: rating is a simple number
        $overall_rating = floatval($listing['rating']);
    } elseif (isset($listing['ratings']) && is_numeric($listing['ratings'])) {
        $overall_rating = floatval($listing['ratings']);
    }
    
    // Fallback review count
    if ($review_count == 0 && isset($listing['review_count'])) {
        $review_count = intval($listing['review_count']);
    }
    
    // Extract photos from images array (new API uses 'images' with title/url)
    $photos = array();
    if (isset($listing['images']) && is_array($listing['images'])) {
        foreach ($listing['images'] as $image) {
            if (is_array($image) && isset($image['url'])) {
                $photos[] = $image['url'];
            } elseif (is_string($image)) {
                $photos[] = $image;
            }
        }
    } elseif (isset($listing['photos']) && is_array($listing['photos'])) {
        $photos = $listing['photos'];
    }
    
    // Extract house rules
    $house_rules = '';
    if (isset($listing['house_rules'])) {
        if (is_array($listing['house_rules'])) {
            // New format: {general, aditional}
            $rules_parts = array();
            if (!empty($listing['house_rules']['general'])) {
                $rules_parts[] = trim($listing['house_rules']['general']);
            }
            if (!empty($listing['house_rules']['aditional'])) {
                $rules_parts[] = trim($listing['house_rules']['aditional']);
            }
            $house_rules = implode("\n", array_filter($rules_parts));
        } elseif (is_string($listing['house_rules'])) {
            $house_rules = $listing['house_rules'];
        }
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
    
    // Parse description - new API includes HTML-formatted sections
    $description = isset($listing['description']) ? $listing['description'] : '';
    $description_by_sections = pyairbnb_parse_description_sections($description);
    
    // Extract location from location_descriptions or sub_description.title
    $location = '';
    if (isset($listing['location_descriptions']) && is_array($listing['location_descriptions']) && !empty($listing['location_descriptions'])) {
        // First location_descriptions item usually has the location
        $first_location = $listing['location_descriptions'][0];
        if (isset($first_location['title'])) {
            $location = $first_location['title'];
        }
    } elseif (isset($listing['sub_description']['title'])) {
        // Extract location from title like "Entire rental unit in Dubai, United Arab Emirates"
        if (preg_match('/in\s+(.+)$/i', $listing['sub_description']['title'], $matches)) {
            $location = trim($matches[1]);
        }
    } elseif (isset($listing['location'])) {
        $location = $listing['location'];
    }
    
    // Extract property type
    $property_type = '';
    if (isset($listing['room_type'])) {
        $property_type = $listing['room_type'];
    } elseif (isset($listing['sub_description']['title'])) {
        // Extract property type from title like "Entire rental unit in..."
        if (preg_match('/^(Entire\s+\w+(?:\s+\w+)?)/i', $listing['sub_description']['title'], $matches)) {
            $property_type = trim($matches[1]);
        }
    } elseif (isset($listing['property_type'])) {
        $property_type = $listing['property_type'];
    }
    
    // Extract neighborhood details from location_descriptions
    $neighborhood_details = '';
    if (isset($listing['location_descriptions']) && is_array($listing['location_descriptions'])) {
        $neighborhood_parts = array();
        foreach ($listing['location_descriptions'] as $loc_desc) {
            if (isset($loc_desc['content'])) {
                $neighborhood_parts[] = $loc_desc['content'];
            }
        }
        $neighborhood_details = implode("\n\n", $neighborhood_parts);
    } elseif (isset($listing['neighborhood_details'])) {
        $neighborhood_details = $listing['neighborhood_details'];
    }
    
    // Format data for analyzer
    $listing_data = array(
        'id' => isset($listing['id']) ? $listing['id'] : '',
        'title' => isset($listing['title']) ? $listing['title'] : '',
        'listing_title' => isset($listing['title']) ? $listing['title'] : '', // Alias for analyzer
        'description' => $description,
        'description_by_sections' => $description_by_sections,
        'photos' => $photos,
        'price' => isset($listing['price']) ? floatval($listing['price']) : 0,
        'price_currency' => isset($listing['price_currency']) ? $listing['price_currency'] : 
                           (isset($listing['currency']) ? $listing['currency'] : 'USD'),
        'location' => $location,
        'bedrooms' => $bedrooms,
        'bathrooms' => $bathrooms,
        'beds' => $beds,
        'max_guests' => $max_guests,
        'amenities' => $amenities,
        'host_name' => $host_name,
        'host_since' => $host_since,
        'is_superhost' => $host_is_superhost,
        'is_supperhost' => $host_is_superhost, // Keep for backwards compatibility with analyzer
        'host_about' => $host_about,
        'host_response_rate' => $host_response_rate,
        'host_response_time' => $host_response_time,
        'host_highlights' => $host_highlights,
        'host_rating' => $host_rating,
        'host_review_count' => $host_review_count,
        'hosts_year' => $hosts_year,
        'neighborhood_details' => $neighborhood_details,
        'rating' => $overall_rating,
        'ratings' => $overall_rating,
        'review_count' => $review_count,
        'property_number_of_reviews' => $review_count, // Alias for analyzer
        'property_rating_details' => $property_rating_details,
        'is_new_listing' => isset($listing['is_new_listing']) ? (bool)$listing['is_new_listing'] : false,
        'is_guest_favorite' => isset($listing['is_guest_favorite']) ? (bool)$listing['is_guest_favorite'] : false,
        'property_type' => $property_type,
        'house_rules' => $house_rules,
        'cancellation_policy' => $cancellation_policy,
        'cancellation_policy_details' => $cancellation_policy_details,
        // Additional data for display
        'highlights' => isset($listing['highlights']) ? $listing['highlights'] : array(),
        'reviews' => isset($listing['reviews']) ? $listing['reviews'] : array(),
        'coordinates' => isset($listing['coordinates']) ? $listing['coordinates'] : null
    );
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        $data_keys = array_keys($listing_data);
        $non_empty_count = 0;
        foreach ($listing_data as $key => $value) {
            if (!empty($value)) {
                $non_empty_count++;
            }
        }
        airbnb_analyzer_debug_log("Format conversion completed. Output keys: " . implode(', ', $data_keys), 'Airbnb API Format');
        airbnb_analyzer_debug_log("Format conversion: $non_empty_count non-empty fields out of " . count($listing_data), 'Airbnb API Format');
    }
    
    return $listing_data;
}

/**
 * Parse HTML description into sections
 * New API format includes HTML with <b>The space</b>, <b>Guest access</b>, <b>Other things to note</b>
 * 
 * @param string $description HTML description
 * @return array Array of sections with title and value
 */
function pyairbnb_parse_description_sections($description) {
    if (empty($description)) {
        return array();
    }
    
    $sections = array();
    
    // Split by bold section headers like <b>The space</b>, <b>Guest access</b>, etc.
    $pattern = '/<b>([^<]+)<\/b>/i';
    
    // Find all section headers
    preg_match_all($pattern, $description, $matches, PREG_OFFSET_CAPTURE);
    
    if (!empty($matches[0])) {
        // Extract text before first section header as main description
        $first_header_pos = $matches[0][0][1];
        if ($first_header_pos > 0) {
            $main_text = substr($description, 0, $first_header_pos);
            $main_text = pyairbnb_clean_description_text($main_text);
            if (!empty($main_text)) {
                $sections[] = array(
                    'title' => null,
                    'value' => $main_text
                );
            }
        }
        
        // Extract each section
        for ($i = 0; $i < count($matches[0]); $i++) {
            $section_title = $matches[1][$i][0];
            $section_start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            
            // Find end of section (next header or end of string)
            if ($i < count($matches[0]) - 1) {
                $section_end = $matches[0][$i + 1][1];
            } else {
                $section_end = strlen($description);
            }
            
            $section_text = substr($description, $section_start, $section_end - $section_start);
            $section_text = pyairbnb_clean_description_text($section_text);
            
            if (!empty($section_text)) {
                $sections[] = array(
                    'title' => $section_title,
                    'value' => $section_text
                );
            }
        }
    } else {
        // No section headers found, use entire description as main section
        $clean_text = pyairbnb_clean_description_text($description);
        if (!empty($clean_text)) {
            $sections[] = array(
                'title' => null,
                'value' => $clean_text
            );
        }
    }
    
    return $sections;
}

/**
 * Clean description text by removing HTML tags and normalizing whitespace
 * 
 * @param string $text HTML text
 * @return string Clean text
 */
function pyairbnb_clean_description_text($text) {
    // Replace <br> and <br/> with newlines
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    
    // Remove remaining HTML tags
    $text = strip_tags($text);
    
    // Decode HTML entities
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    
    // Normalize whitespace
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n\s+/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    
    return trim($text);
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

