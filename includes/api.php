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
    // Extract listing ID from URL
    if (preg_match('/\/rooms\/(\d+)/', $listing_url, $matches)) {
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
    
    // Construct API URL
    $api_url = construct_airbnb_api_url($listing_id);
    
    // Use WordPress HTTP API to fetch listing data
    $args = array(
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json',
            'X-Airbnb-API-Key' => 'd306zoyjsyarp7ifhu67rjxn52tv0t20' // This is a public key used by Airbnb's website
        )
    );
    
    $request_time = microtime(true);
    $response = wp_remote_get($api_url, $args);
    $response_time = microtime(true) - $request_time;
    
    if (is_wp_error($response)) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("API request error: " . $response->get_error_message(), 'API Error');
        }
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("API HTTP error: $status_code", 'API Error');
        }
        return new WP_Error('api_error', 'Error fetching listing data: ' . $status_code);
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("JSON parse error: " . json_last_error_msg(), 'API Error');
        }
        return new WP_Error('json_error', 'Error parsing API response: ' . json_last_error_msg());
    }
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("API response received successfully in " . round($response_time, 2) . " seconds", 'API Success');
    }
    
    // Parse the API response
    $listing_data = parse_airbnb_api_response($data, $listing_id);
    
    // Add debug information if enabled
    if (get_option('airbnb_analyzer_enable_debugging')) {
        $debug_level = get_option('airbnb_analyzer_debug_level', 'basic');
        
        $debug_data = array(
            'raw_data' => $data,
            'extracted_data' => $listing_data
        );
        
        if ($debug_level === 'advanced' || $debug_level === 'full') {
            $debug_data['request_details'] = array(
                'listing_id' => $listing_id,
                'api_url' => $api_url,
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
 * Construct the Airbnb API URL for a listing
 * 
 * @param string $listing_id The listing ID
 * @return string The API URL
 */
function construct_airbnb_api_url($listing_id) {
    // Construct the GraphQL query parameters
    $variables = array(
        'id' => "StayListing:$listing_id",
        'pdpSectionsRequest' => array(
            'adults' => '1',
            'children' => '0',
            'infants' => '0',
            'pets' => 0,
            'checkIn' => date('Y-m-d', strtotime('+1 month')),
            'checkOut' => date('Y-m-d', strtotime('+1 month +5 days')),
            'layouts' => array('SIDEBAR', 'SINGLE_COLUMN')
        )
    );
    
    $extensions = array(
        'persistedQuery' => array(
            'version' => 1,
            'sha256Hash' => '6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343'
        )
    );
    
    // Build the URL
    $base_url = 'https://www.airbnb.com/api/v3/StaysPdpSections/6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343';
    $query_params = array(
        'operationName' => 'StaysPdpSections',
        'locale' => 'en-US',
        'currency' => 'USD',
        'variables' => json_encode($variables),
        'extensions' => json_encode($extensions)
    );
    
    return $base_url . '?' . http_build_query($query_params);
}

/**
 * Parse the Airbnb API response to extract listing data
 * 
 * @param array $data The API response data
 * @param string $listing_id The listing ID
 * @return array The extracted listing data
 */
function parse_airbnb_api_response($data, $listing_id) {
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
    
    // Add parsing steps for debugging
    $parsing_steps = array();
    
    try {
        // Extract data from the new API structure
        if (isset($data['data']['presentation']['stayProductDetailPage'])) {
            $parsing_steps[] = "Found stayProductDetailPage structure";
            $pdp = $data['data']['presentation']['stayProductDetailPage'];
            
            // Extract metadata if available
            if (isset($pdp['metadata'])) {
                $parsing_steps[] = "Processing metadata";
                $metadata = $pdp['metadata'];
                
                if (isset($metadata['sharingConfig'])) {
                    $sharing = $metadata['sharingConfig'];
                    
                    if (isset($sharing['title'])) {
                        $listing_data['title'] = $sharing['title'];
                        $parsing_steps[] = "Extracted title from sharingConfig";
                    }
                    
                    if (isset($sharing['propertyType'])) {
                        $listing_data['property_type'] = $sharing['propertyType'];
                        $parsing_steps[] = "Extracted property_type from sharingConfig";
                    }
                    
                    if (isset($sharing['location'])) {
                        $listing_data['location'] = $sharing['location'];
                        $parsing_steps[] = "Extracted location from sharingConfig";
                    }
                    
                    if (isset($sharing['personCapacity'])) {
                        $listing_data['max_guests'] = intval($sharing['personCapacity']);
                        $parsing_steps[] = "Extracted max_guests from sharingConfig";
                    }
                    
                    if (isset($sharing['reviewCount'])) {
                        $listing_data['review_count'] = intval($sharing['reviewCount']);
                        $parsing_steps[] = "Extracted review_count from sharingConfig";
                    }
                    
                    if (isset($sharing['starRating'])) {
                        $listing_data['rating'] = floatval($sharing['starRating']);
                        $parsing_steps[] = "Extracted rating from sharingConfig";
                    }
                }
                
                // Extract more details from loggingContext if available
                if (isset($metadata['loggingContext']['eventDataLogging'])) {
                    $parsing_steps[] = "Processing loggingContext";
                    $event_data = $metadata['loggingContext']['eventDataLogging'];
                    
                    // Extract amenities
                    if (isset($event_data['amenities']) && is_array($event_data['amenities'])) {
                        $amenity_map = array(
                            1 => 'TV',
                            4 => 'Wifi',
                            8 => 'Kitchen',
                            9 => 'Free parking on premises',
                            15 => 'Washer',
                            30 => 'Hair dryer',
                            33 => 'Dryer',
                            51 => 'Lake access',
                            133 => 'Dedicated workspace'
                        );
                        
                        foreach ($event_data['amenities'] as $amenity_id) {
                            if (isset($amenity_map[$amenity_id])) {
                                $listing_data['amenities'][] = $amenity_map[$amenity_id];
                                $parsing_steps[] = "Added amenity: " . $amenity_map[$amenity_id];
                            }
                        }
                    }
                }
            }
            
            // Process sections
            if (isset($pdp['sections']['sections']) && is_array($pdp['sections']['sections'])) {
                $parsing_steps[] = "Processing sections";
                $sections = $pdp['sections']['sections'];
                
                foreach ($sections as $section) {
                    // Extract description
                    if ($section['sectionId'] === 'DESCRIPTION_DEFAULT' && isset($section['section']['htmlDescription']['htmlText'])) {
                        $listing_data['description'] = $section['section']['htmlDescription']['htmlText'];
                        $parsing_steps[] = "Extracted description from DESCRIPTION_DEFAULT section";
                    }
                    
                    // Extract photos
                    if ($section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE_MODAL' && isset($section['section']['mediaItems'])) {
                        foreach ($section['section']['mediaItems'] as $media) {
                            if (isset($media['baseUrl'])) {
                                $listing_data['photos'][] = $media['baseUrl'];
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['photos']) . " photos from PHOTO_TOUR_SCROLLABLE_MODAL section";
                    }
                    
                    // Extract house rules
                    if ($section['sectionId'] === 'POLICIES_DEFAULT' && isset($section['section']['houseRules'])) {
                        $rules = array();
                        foreach ($section['section']['houseRules'] as $rule) {
                            if (isset($rule['title'])) {
                                $rules[] = $rule['title'];
                            }
                        }
                        $listing_data['house_rules'] = implode("\n", $rules);
                        $parsing_steps[] = "Extracted house_rules from POLICIES_DEFAULT section";
                    }
                    
                    // Extract cancellation policy
                    if ($section['sectionId'] === 'POLICIES_DEFAULT' && isset($section['section']['cancellationPolicyTitle'])) {
                        if (isset($section['section']['seeCancellationPolicyButton']['title'])) {
                            $policy_name = $section['section']['seeCancellationPolicyButton']['title'];
                            $listing_data['cancellation_policy'] = $policy_name;
                            $parsing_steps[] = "Extracted cancellation_policy from POLICIES_DEFAULT section: " . $policy_name;
                        }
                    }
                    
                    // Extract host information from HOST_PROFILE_DEFAULT
                    if ($section['sectionId'] === 'HOST_OVERVIEW_DEFAULT' && isset($section['sectionData'])) {
                        if (isset($section['sectionData']['title'])) {
                            $host_title = $section['sectionData']['title'];
                            if (preg_match('/Hosted by (.+)$/', $host_title, $matches)) {
                                $listing_data['host_name'] = $matches[1];
                                $parsing_steps[] = "Extracted host_name from HOST_OVERVIEW_DEFAULT section: " . $matches[1];
                            }
                        }
                        
                        if (isset($section['sectionData']['overviewItems'])) {
                            foreach ($section['sectionData']['overviewItems'] as $item) {
                                if (isset($item['title']) && strpos($item['title'], 'hosting') !== false) {
                                    $listing_data['host_since'] = $item['title'];
                                    $parsing_steps[] = "Extracted host_since from HOST_OVERVIEW_DEFAULT section: " . $item['title'];
                                }
                            }
                        }
                    }
                    
                    // Extract overview details (bedrooms, bathrooms, etc.)
                    if ($section['sectionId'] === 'OVERVIEW_DEFAULT_V2' && isset($section['sectionData']['overviewItems'])) {
                        foreach ($section['sectionData']['overviewItems'] as $item) {
                            if (isset($item['title'])) {
                                $title = strtolower($item['title']);
                                
                                if (strpos($title, 'bedroom') !== false) {
                                    $listing_data['bedrooms'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted bedrooms from OVERVIEW_DEFAULT_V2 section: " . $listing_data['bedrooms'];
                                } else if (strpos($title, 'bath') !== false) {
                                    $listing_data['bathrooms'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted bathrooms from OVERVIEW_DEFAULT_V2 section: " . $listing_data['bathrooms'];
                                } else if (strpos($title, 'bed') !== false && $listing_data['beds'] == 0) {
                                    $listing_data['beds'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted beds from OVERVIEW_DEFAULT_V2 section: " . $listing_data['beds'];
                                } else if (strpos($title, 'guest') !== false && $listing_data['max_guests'] == 0) {
                                    $listing_data['max_guests'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted max_guests from OVERVIEW_DEFAULT_V2 section: " . $listing_data['max_guests'];
                                }
                            }
                        }
                    }
                    
                    // Extract price from BOOK_IT_FLOATING_FOOTER
                    if ($section['sectionId'] === 'BOOK_IT_FLOATING_FOOTER' && isset($section['section']['structuredDisplayPrice']['primaryLine']['price'])) {
                        $price_info = $section['section']['structuredDisplayPrice']['primaryLine']['price'];
                        if (isset($price_info['amount'])) {
                            $listing_data['price'] = floatval($price_info['amount']);
                            $parsing_steps[] = "Extracted price from BOOK_IT_FLOATING_FOOTER section: " . $price_info['amount'];
                        }
                        if (isset($price_info['currency'])) {
                            $listing_data['price_currency'] = $price_info['currency'];
                            $parsing_steps[] = "Extracted price_currency from BOOK_IT_FLOATING_FOOTER section: " . $price_info['currency'];
                        }
                    }
                }
            }
        }
        
        // Store parsing steps for debugging
        $listing_data['_parsing_steps'] = $parsing_steps;
        
    } catch (Exception $e) {
        error_log('Error parsing Airbnb API response: ' . $e->getMessage());
        // Return basic data if we can extract at least the title
        if (empty($listing_data['title'])) {
            return new WP_Error('parsing_error', 'Error parsing Airbnb API response: ' . $e->getMessage());
        }
    }
    
    return $listing_data;
}
?>