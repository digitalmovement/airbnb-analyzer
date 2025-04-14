<?php
/**
 * API functionality for AirBnB Listing Analyzer
 */

/**
 * Get listing data from AirBnB
 * 
 * @param string $listing_url The AirBnB listing URL
 * @return array|WP_Error Listing data or error
 */
function airbnb_analyzer_get_listing_data($listing_url) {
    // Extract listing ID from URL - updated to handle international domains
    if (preg_match('/airbnb\.[a-z\.]+\/rooms\/(\d+)/', $listing_url, $matches)) {
        $listing_id = $matches[1];
    } elseif (preg_match('/\/rooms\/(\d+)/', $listing_url, $matches)) {
        $listing_id = $matches[1];
    } elseif (preg_match('/\/h\/([^\/\?]+)/', $listing_url, $matches)) {
        // Handle /h/ style URLs
        $listing_slug = $matches[1];
        // We need to make an initial request to get the actual listing ID
        $response = wp_remote_get($listing_url);
        if (is_wp_error($response)) {
            return $response;
        }
        $body = wp_remote_retrieve_body($response);
        if (preg_match('/\"id\":\"StayListing:(\d+)\"/', $body, $id_matches)) {
            $listing_id = $id_matches[1];
        } else {
            return new WP_Error('invalid_url', 'Could not extract listing ID from URL');
        }
    } else {
        return new WP_Error('invalid_url', 'Invalid AirBnB listing URL format');
    }
    
    // Log debug info
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Processing listing ID: $listing_id from URL: $listing_url", 'API Request');
    }
    
    // Try multiple approaches to get the listing data
    $listing_data = extract_data_from_direct_page($listing_id, $listing_url);
    
    if (is_wp_error($listing_data)) {
        // If direct page extraction fails, try the API approach
        $listing_data = extract_data_from_api($listing_id);
    }
    
    return $listing_data;
}

/**
 * Extract data directly from the listing page
 * 
 * @param string $listing_id The listing ID
 * @param string $listing_url The original listing URL
 * @return array|WP_Error Listing data or error
 */
function extract_data_from_direct_page($listing_id, $listing_url) {
    // Use the original URL to preserve locale/currency settings
    $direct_url = $listing_url;
    if (!preg_match('/\/rooms\/\d+/', $direct_url)) {
        $direct_url = "https://www.airbnb.com/rooms/$listing_id";
    }
    
    $args = array(
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ),
        'timeout' => 30,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    );
    
    $request_time = microtime(true);
    $response = wp_remote_get($direct_url, $args);
    $response_time = microtime(true) - $request_time;
    
    if (is_wp_error($response)) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Direct page request error: " . $response->get_error_message(), 'API Error');
        }
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            $error_body = wp_remote_retrieve_body($response);
            airbnb_analyzer_debug_log("Direct page HTTP error: $status_code. Response: " . substr($error_body, 0, 500), 'API Error');
            airbnb_analyzer_debug_log("Request URL: $direct_url", 'API Error');
        }
        return new WP_Error('api_error', 'Error fetching listing page: ' . $status_code);
    }
    
    $body = wp_remote_retrieve_body($response);
    
    // Initialize listing data with defaults
    $listing_data = array(
        'id' => $listing_id,
        'title' => '',
        'description' => '',
        'photos' => array(),
        'price' => 0,
        'price_currency' => '',
        'location' => '',
        'bedrooms' => 0,
        'bathrooms' => 0,
        'beds' => 0,
        'max_guests' => 0,
        'amenities' => array(),
        'host_name' => '',
        'host_since' => '',
        'host_is_superhost' => false,
        'host_about' => '',
        'host_response_rate' => '',
        'host_response_time' => '',
        'host_highlights' => array(),
        'host_rating' => 0,
        'host_review_count' => 0,
        'neighborhood_details' => '',
        'rating' => 0,
        'review_count' => 0,
        'property_rating_details' => array(),
        'is_new_listing' => false,
        'is_guest_favorite' => false,
        'property_type' => '',
        'house_rules' => '',
        'cancellation_policy' => '',
        'cancellation_policy_details' => array(
            'name' => '',
            'description' => '',
            'strictness' => 0,
            'can_instant_book' => false
        )
    );
    
    $parsing_steps = array();
    
    // Extract data using meta tags and HTML parsing
    try {
        // Extract title
        if (preg_match('/<meta property="og:title" content="([^"]+)"/s', $body, $title_matches)) {
            $listing_data['title'] = html_entity_decode($title_matches[1]);
            $parsing_steps[] = "Extracted title from meta tag";
        }
        
        // Extract description
        if (preg_match('/<meta property="og:description" content="([^"]+)"/s', $body, $desc_matches)) {
            $listing_data['description'] = html_entity_decode($desc_matches[1]);
            $parsing_steps[] = "Extracted short description from meta tag";
        }
        
        // Extract full description (try to find it in the HTML)
        if (preg_match('/"description":"(.*?)","descriptionLocale"/s', $body, $full_desc_matches)) {
            $full_desc = str_replace('\n', "\n", $full_desc_matches[1]);
            $full_desc = str_replace('\\"', '"', $full_desc);
            $listing_data['description'] = html_entity_decode($full_desc);
            $parsing_steps[] = "Extracted full description from JSON data";
        }
        
        // Extract images
        if (preg_match_all('/"url":"(https:\/\/a0\.muscache\.com\/im\/pictures\/[^"]+)"/s', $body, $img_matches)) {
            $listing_data['photos'] = array_unique($img_matches[1]);
            $parsing_steps[] = "Extracted " . count($listing_data['photos']) . " photos from JSON data";
        }
        
        // Extract location
        if (preg_match('/"location":{"city":"([^"]+)"/s', $body, $loc_matches)) {
            $listing_data['location'] = html_entity_decode($loc_matches[1]);
            $parsing_steps[] = "Extracted location from JSON data";
        }
        
        // Extract price
        if (preg_match('/"price":{"rate":{"amount":(\d+),"currency":"([^"]+)"/s', $body, $price_matches)) {
            $listing_data['price'] = floatval($price_matches[1]);
            $listing_data['price_currency'] = $price_matches[2];
            $parsing_steps[] = "Extracted price and currency from JSON data";
        }
        
        // Extract bedrooms, bathrooms, beds
        if (preg_match('/"bedrooms":(\d+)/', $body, $bedroom_matches)) {
            $listing_data['bedrooms'] = intval($bedroom_matches[1]);
            $parsing_steps[] = "Extracted bedrooms from JSON data";
        }
        
        if (preg_match('/"bathrooms":(\d+(\.\d+)?)/', $body, $bathroom_matches)) {
            $listing_data['bathrooms'] = floatval($bathroom_matches[1]);
            $parsing_steps[] = "Extracted bathrooms from JSON data";
        }
        
        if (preg_match('/"beds":(\d+)/', $body, $beds_matches)) {
            $listing_data['beds'] = intval($beds_matches[1]);
            $parsing_steps[] = "Extracted beds from JSON data";
        }
        
        // Extract max guests
        if (preg_match('/"person_capacity":(\d+)/', $body, $guests_matches)) {
            $listing_data['max_guests'] = intval($guests_matches[1]);
            $parsing_steps[] = "Extracted max_guests from JSON data";
        }
        
        // Extract amenities
        if (preg_match_all('/"amenities":\[([^\]]+)\]/', $body, $amenities_matches)) {
            $amenities_json = '[' . $amenities_matches[1][0] . ']';
            $amenities_json = preg_replace('/([{,])([a-zA-Z0-9_]+):/', '$1"$2":', $amenities_json);
            $amenities_data = json_decode($amenities_json, true);
            
            if (is_array($amenities_data)) {
                foreach ($amenities_data as $amenity) {
                    if (isset($amenity['name'])) {
                        $listing_data['amenities'][] = $amenity['name'];
                    }
                }
                $parsing_steps[] = "Extracted " . count($listing_data['amenities']) . " amenities from JSON data";
            }
        }
        
        // Extract host name
        if (preg_match('/"host":{"name":"([^"]+)"/', $body, $host_matches)) {
            $listing_data['host_name'] = html_entity_decode($host_matches[1]);
            $parsing_steps[] = "Extracted host_name from JSON data";
        }
        
        // Extract host superhost status
        if (preg_match('/"is_superhost":(true|false)/', $body, $superhost_matches)) {
            $listing_data['host_is_superhost'] = ($superhost_matches[1] === 'true');
            $parsing_steps[] = "Extracted host_is_superhost from JSON data";
        }
        
        // Extract rating
        if (preg_match('/"star_rating":([0-9.]+)/', $body, $rating_matches)) {
            $listing_data['rating'] = floatval($rating_matches[1]);
            $parsing_steps[] = "Extracted rating from JSON data";
        }
        
        // Extract review count
        if (preg_match('/"review_count":(\d+)/', $body, $review_matches)) {
            $listing_data['review_count'] = intval($review_matches[1]);
            $parsing_steps[] = "Extracted review_count from JSON data";
        }
        
        // Extract property type
        if (preg_match('/"property_type":"([^"]+)"/', $body, $property_matches)) {
            $listing_data['property_type'] = html_entity_decode($property_matches[1]);
            $parsing_steps[] = "Extracted property_type from JSON data";
        }
        
        // Extract house rules
        if (preg_match('/"house_rules":"([^"]+)"/', $body, $rules_matches)) {
            $listing_data['house_rules'] = html_entity_decode($rules_matches[1]);
            $parsing_steps[] = "Extracted house_rules from JSON data";
        }
        
        // Extract cancellation policy
        if (preg_match('/"cancellation_policy":"([^"]+)"/', $body, $policy_matches)) {
            $listing_data['cancellation_policy'] = html_entity_decode($policy_matches[1]);
            $parsing_steps[] = "Extracted cancellation_policy from JSON data";
        }
        
        // Store parsing steps for debugging
        $listing_data['_parsing_steps'] = $parsing_steps;
        
    } catch (Exception $e) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Error parsing page data: " . $e->getMessage(), 'API Error');
        }
        return new WP_Error('parsing_error', 'Error parsing page data: ' . $e->getMessage());
    }
    
    // Add debug information if enabled
    if (get_option('airbnb_analyzer_enable_debugging')) {
        $debug_level = get_option('airbnb_analyzer_debug_level', 'basic');
        
        $debug_data = array(
            'html_sample' => substr($body, 0, 5000),
            'extracted_data' => $listing_data
        );
        
        if ($debug_level === 'advanced' || $debug_level === 'full') {
            $debug_data['request_details'] = array(
                'listing_id' => $listing_id,
                'direct_url' => $direct_url,
                'request_time' => date('Y-m-d H:i:s'),
                'response_time_seconds' => round($response_time, 2),
                'status_code' => $status_code,
                'headers' => wp_remote_retrieve_headers($response)->getAll()
            );
        }
        
        if ($debug_level === 'full') {
            $debug_data['php_info'] = array(
                'php_version' => PHP_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'memory_usage' => size_format(memory_get_usage(true)),
                'max_memory' => ini_get('memory_limit')
            );
            
            // Add parsing steps if available
            if (isset($listing_data['_parsing_steps'])) {
                $debug_data['parsing_steps'] = $listing_data['_parsing_steps'];
                unset($listing_data['_parsing_steps']);
            }
        }
        
        $listing_data['_debug'] = $debug_data;
    }
    
    return $listing_data;
}

/**
 * Extract data using the Airbnb API
 * 
 * @param string $listing_id The listing ID
 * @return array|WP_Error Listing data or error
 */
function extract_data_from_api($listing_id) {
    // This is a fallback method if direct page extraction fails
    // Implementation would be similar to our original API approach
    return new WP_Error('not_implemented', 'API extraction method not implemented');
}

/**
 * Construct the Airbnb API URL for a listing
 * 
 * @param string $listing_id The listing ID
 * @return string The API URL
 */
function construct_airbnb_api_url($listing_id) {
    // Current date for check-in (today + 30 days)
    $check_in = date('Y-m-d', strtotime('+30 days'));
    // Check-out (check-in + 5 days)
    $check_out = date('Y-m-d', strtotime($check_in . ' +5 days'));
    
    // Construct the GraphQL API URL with proper encoding
    $variables = array(
        'id' => "StayListing:$listing_id",
        'pdpSectionsRequest' => array(
            'adults' => '1',
            'children' => '0',
            'infants' => '0',
            'pets' => 0,
            'checkIn' => $check_in,
            'checkOut' => $check_out,
            'layouts' => array('SIDEBAR', 'SINGLE_COLUMN')
        )
    );
    
    $extensions = array(
        'persistedQuery' => array(
            'version' => 1,
            'sha256Hash' => '6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343'
        )
    );
    
    // Use the exact URL format that works with Airbnb's API
    $url = 'https://www.airbnb.com/api/v3/StaysPdpSections/6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343';
    $url .= '?operationName=StaysPdpSections';
    $url .= '&locale=en-US';
    $url .= '&currency=USD';
    $url .= '&variables=' . urlencode(json_encode($variables));
    $url .= '&extensions=' . urlencode(json_encode($extensions));
    
    return $url;
}
?>