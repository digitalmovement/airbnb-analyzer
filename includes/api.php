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
 * Construct the Airbnb API URL
 * 
 * @param string $listing_id The listing ID
 * @return string The API URL
 */
function construct_airbnb_api_url($listing_id) {
    // Create the StayListing ID format that Airbnb expects
    $stay_listing_id = "StayListing:{$listing_id}";
    $encoded_id = base64_encode($stay_listing_id);
    
    // Current date for check-in/check-out parameters
    $check_in = date('Y-m-d', strtotime('+30 days'));
    $check_out = date('Y-m-d', strtotime('+35 days'));
    
    // Construct variables parameter
    $variables = array(
        'id' => $encoded_id,
        'pdpSectionsRequest' => array(
            'adults' => '1',
            'children' => '0',
            'infants' => '0',
            'pets' => 0,
            'checkIn' => $check_in,
            'checkOut' => $check_out,
            'layouts' => array('SIDEBAR', 'SINGLE_COLUMN'),
        )
    );
    
    $encoded_variables = urlencode(json_encode($variables));
    
    // Construct extensions parameter
    $extensions = array(
        'persistedQuery' => array(
            'version' => 1,
            'sha256Hash' => '6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343'
        )
    );
    
    $encoded_extensions = urlencode(json_encode($extensions));
    
    // Build the final URL
    return "https://www.airbnb.com/api/v3/StaysPdpSections/{$extensions['persistedQuery']['sha256Hash']}?operationName=StaysPdpSections&locale=en-US&currency=USD&variables={$encoded_variables}&extensions={$encoded_extensions}";
}

/**
 * Parse the Airbnb API response
 * 
 * @param array $data The API response data
 * @param string $listing_id The listing ID
 * @return array The parsed listing data
 */
function parse_airbnb_api_response($data, $listing_id) {
    // Initialize listing data array
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
            'strictness' => 0, // 1-5 scale, 5 being most strict
            'can_instant_book' => false
        ),
    );
    
    try {
        // Extract basic info from sharing config
        if (isset($data['data']['presentation']['stayProductDetailPage']['metadata']['sharingConfig'])) {
            $sharing = $data['data']['presentation']['stayProductDetailPage']['metadata']['sharingConfig'];
            
            if (isset($sharing['title'])) {
                $listing_data['title'] = $sharing['title'];
            }
            
            if (isset($sharing['propertyType'])) {
                $listing_data['property_type'] = $sharing['propertyType'];
            }
            
            if (isset($sharing['location'])) {
                $listing_data['location'] = $sharing['location'];
            }
            
            if (isset($sharing['personCapacity'])) {
                $listing_data['max_guests'] = intval($sharing['personCapacity']);
            }
            
            if (isset($sharing['imageUrl'])) {
                $listing_data['photos'][] = $sharing['imageUrl'];
            }
            
            if (isset($sharing['reviewCount'])) {
                $listing_data['review_count'] = intval($sharing['reviewCount']);
            }
            
            if (isset($sharing['starRating'])) {
                $listing_data['rating'] = floatval($sharing['starRating']);
            }
        }
        
        // Extract photos from PHOTO_TOUR_SCROLLABLE_MODAL
        if (isset($data['data']['presentation']['stayProductDetailPage']['sections']['sections'])) {
            foreach ($data['data']['presentation']['stayProductDetailPage']['sections']['sections'] as $section) {
                if (isset($section['sectionId']) && $section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE_MODAL') {
                    if (isset($section['section']['mediaItems']) && is_array($section['section']['mediaItems'])) {
                        $listing_data['photos'] = []; // Reset photos to get the full collection
                        foreach ($section['section']['mediaItems'] as $item) {
                            if (isset($item['baseUrl'])) {
                                $listing_data['photos'][] = $item['baseUrl'];
                            }
                        }
                    }
                    break;
                }
            }
        }
        
        // Extract description from DESCRIPTION_MODAL section
        if (isset($data['data']['presentation']['stayProductDetailPage']['sections']['sections'])) {
            foreach ($data['data']['presentation']['stayProductDetailPage']['sections']['sections'] as $section) {
                if (isset($section['sectionId']) && $section['sectionId'] === 'DESCRIPTION_MODAL') {
                    if (isset($section['section']['items']) && is_array($section['section']['items'])) {
                        $full_description = '';
                        
                        // Loop through all items to get the full description
                        foreach ($section['section']['items'] as $item) {
                            if (isset($item['html']['htmlText'])) {
                                // The second item usually contains the full description with "The space" title
                                if (isset($item['title']) && $item['title'] === 'The space') {
                                    $listing_data['description'] = $item['html']['htmlText'];
                                    break;
                                } else if (empty($full_description)) {
                                    // Store the first item as a fallback
                                    $full_description = $item['html']['htmlText'];
                                }
                            }
                        }
                        
                        // If we didn't find "The space" section, use the first description
                        if (empty($listing_data['description']) && !empty($full_description)) {
                            $listing_data['description'] = $full_description;
                        }
                    }
                    break;
                }
            }
        }
        
        // Extract room details from OVERVIEW_DEFAULT_V2
        if (isset($data['data']['presentation']['stayProductDetailPage']['sbuiData']['sectionConfiguration']['root']['sections'])) {
            $sections = $data['data']['presentation']['stayProductDetailPage']['sbuiData']['sectionConfiguration']['root']['sections'];
            
            foreach ($sections as $section) {
                if (isset($section['sectionId']) && $section['sectionId'] === 'OVERVIEW_DEFAULT_V2') {
                    // Extract property type
                    if (isset($section['sectionData']['title'])) {
                        $title_parts = explode(' in ', $section['sectionData']['title']);
                        if (count($title_parts) > 0) {
                            $listing_data['property_type'] = $title_parts[0];
                        }
                    }
                    
                    // Extract room details
                    if (isset($section['sectionData']['overviewItems']) && is_array($section['sectionData']['overviewItems'])) {
                        foreach ($section['sectionData']['overviewItems'] as $item) {
                            if (isset($item['title'])) {
                                if (strpos($item['title'], 'guests') !== false) {
                                    $listing_data['max_guests'] = intval($item['title']);
                                } else if (strpos($item['title'], 'bedrooms') !== false) {
                                    $listing_data['bedrooms'] = intval($item['title']);
                                } else if (strpos($item['title'], 'beds') !== false) {
                                    $listing_data['beds'] = intval($item['title']);
                                } else if (strpos($item['title'], 'bathrooms') !== false) {
                                    $listing_data['bathrooms'] = intval($item['title']);
                                }
                            }
                        }
                    }
                    
                    // Extract review data
                    if (isset($section['sectionData']['reviewData'])) {
                        $review_data = $section['sectionData']['reviewData'];
                        
                        if (isset($review_data['ratingText'])) {
                            $listing_data['rating'] = floatval($review_data['ratingText']);
                        }
                        
                        if (isset($review_data['reviewCount'])) {
                            $listing_data['review_count'] = intval($review_data['reviewCount']);
                        }
                        
                        if (isset($review_data['isNewListing'])) {
                            $listing_data['is_new_listing'] = (bool)$review_data['isNewListing'];
                        }
                    }
                    
                    // Extract guest favorite status
                    if (isset($section['loggingData']['eventData']['pdpContext']['isGuestFavorite'])) {
                        $listing_data['is_guest_favorite'] = ($section['loggingData']['eventData']['pdpContext']['isGuestFavorite'] === 'true');
                    }
                    
                    break;
                }
            }
        }
        
        // Extract host information from HOST_OVERVIEW_DEFAULT
        if (isset($data['data']['presentation']['stayProductDetailPage']['sbuiData']['sectionConfiguration']['root']['sections'])) {
            $sections = $data['data']['presentation']['stayProductDetailPage']['sbuiData']['sectionConfiguration']['root']['sections'];
            
            foreach ($sections as $section) {
                if (isset($section['sectionId']) && $section['sectionId'] === 'HOST_OVERVIEW_DEFAULT') {
                    if (isset($section['sectionData']['title'])) {
                        $host_title = $section['sectionData']['title'];
                        if (strpos($host_title, 'Hosted by ') === 0) {
                            $listing_data['host_name'] = substr($host_title, 10); // Remove "Hosted by " prefix
                        }
                    }
                    
                    if (isset($section['sectionData']['overviewItems']) && is_array($section['sectionData']['overviewItems'])) {
                        foreach ($section['sectionData']['overviewItems'] as $item) {
                            if (isset($item['title']) && strpos($item['title'], 'years hosting') !== false) {
                                $years = intval($item['title']);
                                $listing_data['host_since'] = date('Y-m-d', strtotime("-{$years} years"));
                            }
                        }
                    }
                    
                    if (isset($section['loggingData']['eventData']['pdpContext']['isSuperHost'])) {
                        $listing_data['host_is_superhost'] = ($section['loggingData']['eventData']['pdpContext']['isSuperHost'] === 'true');
                    }
                    
                    break;
                }
            }
        }
        
        // Extract cancellation policy from the metadata
        if (isset($data['data']['presentation']['stayProductDetailPage']['metadata']['bookingPrefetchData'])) {
            $booking_data = $data['data']['presentation']['stayProductDetailPage']['metadata']['bookingPrefetchData'];
            
            // Extract instant book status
            if (isset($booking_data['canInstantBook'])) {
                $listing_data['cancellation_policy_details']['can_instant_book'] = (bool)$booking_data['canInstantBook'];
            }
            
            // Extract cancellation policy details
            if (isset($booking_data['cancellationPolicies']) && is_array($booking_data['cancellationPolicies']) && !empty($booking_data['cancellationPolicies'])) {
                $policy = $booking_data['cancellationPolicies'][0];
                
                if (isset($policy['localized_cancellation_policy_name'])) {
                    $listing_data['cancellation_policy_details']['name'] = $policy['localized_cancellation_policy_name'];
                    $listing_data['cancellation_policy'] = $policy['localized_cancellation_policy_name'];
                }
                
                if (isset($policy['book_it_module_tooltip'])) {
                    $listing_data['cancellation_policy_details']['description'] = $policy['book_it_module_tooltip'];
                }
                
                // Determine strictness level based on policy name
                if (!empty($listing_data['cancellation_policy_details']['name'])) {
                    $policy_name = strtolower($listing_data['cancellation_policy_details']['name']);
                    
                    if (strpos($policy_name, 'flexible') !== false) {
                        $listing_data['cancellation_policy_details']['strictness'] = 1;
                    } elseif (strpos($policy_name, 'moderate') !== false) {
                        $listing_data['cancellation_policy_details']['strictness'] = 2;
                    } elseif (strpos($policy_name, 'strict') !== false) {
                        if (strpos($policy_name, 'super strict') !== false) {
                            $listing_data['cancellation_policy_details']['strictness'] = 5;
                        } else {
                            $listing_data['cancellation_policy_details']['strictness'] = 4;
                        }
                    } elseif (strpos($policy_name, 'long term') !== false) {
                        $listing_data['cancellation_policy_details']['strictness'] = 3;
                    } else {
                        $listing_data['cancellation_policy_details']['strictness'] = 3; // Default to moderate
                    }
                }
            }
        }
        
        // Extract amenities (try to find them in the new structure)
        // This part might need further adjustment based on the actual JSON structure
        if (isset($data['data']['presentation']['stayProductDetailPage']['sections']['sections'])) {
            foreach ($data['data']['presentation']['stayProductDetailPage']['sections']['sections'] as $section) {
                if (isset($section['sectionId']) && $section['sectionId'] === 'AMENITIES_DEFAULT') {
                    if (isset($section['section']['amenityGroups'])) {
                        foreach ($section['section']['amenityGroups'] as $group) {
                            if (isset($group['amenities'])) {
                                foreach ($group['amenities'] as $amenity) {
                                    if (isset($amenity['title']) && (!isset($amenity['available']) || $amenity['available'] === true)) {
                                        $listing_data['amenities'][] = $amenity['title'];
                                    }
                                }
                            }
                        }
                    }
                    
                    // Try alternative structures
                    if (empty($listing_data['amenities'])) {
                        if (isset($section['section']['previewAmenitiesGroups'])) {
                            foreach ($section['section']['previewAmenitiesGroups'] as $group) {
                                if (isset($group['amenities'])) {
                                    foreach ($group['amenities'] as $amenity) {
                                        if (isset($amenity['title']) && (!isset($amenity['available']) || $amenity['available'] === true)) {
                                            $listing_data['amenities'][] = $amenity['title'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
                    break;
                }
            }
        }
        
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