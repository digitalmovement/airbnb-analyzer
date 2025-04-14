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
                    
                    if (isset($event_data['bedType'])) {
                        $listing_data['beds'] = intval($event_data['bedType']);
                        $parsing_steps[] = "Extracted beds from eventDataLogging";
                    }
                    
                    if (isset($event_data['roomType'])) {
                        if (empty($listing_data['property_type'])) {
                            $listing_data['property_type'] = $event_data['roomType'];
                            $parsing_steps[] = "Extracted property_type from eventDataLogging";
                        }
                    }
                    
                    if (isset($event_data['isSuperhost'])) {
                        $listing_data['host_is_superhost'] = (bool)$event_data['isSuperhost'];
                        $parsing_steps[] = "Extracted host_is_superhost from eventDataLogging";
                    }
                    
                    // Extract rating details
                    $rating_fields = array(
                        'accuracyRating' => 'accuracy',
                        'checkinRating' => 'check_in',
                        'cleanlinessRating' => 'cleanliness',
                        'communicationRating' => 'communication',
                        'locationRating' => 'location',
                        'valueRating' => 'value'
                    );
                    
                    foreach ($rating_fields as $api_field => $our_field) {
                        if (isset($event_data[$api_field])) {
                            $listing_data['property_rating_details'][$our_field] = floatval($event_data[$api_field]);
                            $parsing_steps[] = "Extracted rating detail: $our_field";
                        }
                    }
                    
                    if (isset($event_data['guestSatisfactionOverall']) && empty($listing_data['rating'])) {
                        $listing_data['rating'] = floatval($event_data['guestSatisfactionOverall']);
                        $parsing_steps[] = "Extracted rating from guestSatisfactionOverall";
                    }
                    
                    if (isset($event_data['visibleReviewCount']) && empty($listing_data['review_count'])) {
                        $listing_data['review_count'] = intval($event_data['visibleReviewCount']);
                        $parsing_steps[] = "Extracted review_count from visibleReviewCount";
                    }
                }
            }
            
            // Process sections to extract more data
            if (isset($pdp['sections']['sections'])) {
                $parsing_steps[] = "Processing sections";
                $sections = $pdp['sections']['sections'];
                
                foreach ($sections as $section) {
                    if (!isset($section['sectionId'])) {
                        continue;
                    }
                    
                    // Extract description
                    if ($section['sectionId'] === 'DESCRIPTION_DEFAULT' && isset($section['section']['htmlDescription']['htmlText'])) {
                        $listing_data['description'] = $section['section']['htmlDescription']['htmlText'];
                        $parsing_steps[] = "Extracted description from DESCRIPTION_DEFAULT section";
                    }
                    
                    // Extract photos
                    if ($section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE_MODAL' && isset($section['section']['mediaItems'])) {
                        foreach ($section['section']['mediaItems'] as $media_item) {
                            if (isset($media_item['baseUrl'])) {
                                $listing_data['photos'][] = $media_item['baseUrl'];
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['photos']) . " photos from PHOTO_TOUR_SCROLLABLE_MODAL section";
                    }
                    
                    // Extract amenities
                    if ($section['sectionId'] === 'AMENITIES_DEFAULT') {
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
                            $parsing_steps[] = "Extracted " . count($listing_data['amenities']) . " amenities from AMENITIES_DEFAULT section";
                        }
                    }
                    
                    // Extract host information
                    if ($section['sectionId'] === 'HOST_PROFILE_DEFAULT' && isset($section['section']['hostAvatar'])) {
                        if (isset($section['section']['hostName'])) {
                            $listing_data['host_name'] = $section['section']['hostName'];
                            $parsing_steps[] = "Extracted host_name from HOST_PROFILE_DEFAULT section";
                        }
                        
                        if (isset($section['section']['hostSince'])) {
                            $listing_data['host_since'] = $section['section']['hostSince'];
                            $parsing_steps[] = "Extracted host_since from HOST_PROFILE_DEFAULT section";
                        }
                        
                        if (isset($section['section']['hostResponseRate'])) {
                            $listing_data['host_response_rate'] = $section['section']['hostResponseRate'];
                            $parsing_steps[] = "Extracted host_response_rate from HOST_PROFILE_DEFAULT section";
                        }
                        
                        if (isset($section['section']['hostResponseTime'])) {
                            $listing_data['host_response_time'] = $section['section']['hostResponseTime'];
                            $parsing_steps[] = "Extracted host_response_time from HOST_PROFILE_DEFAULT section";
                        }
                    }
                    
                    // Extract cancellation policy
                    if ($section['sectionId'] === 'POLICIES_DEFAULT' && isset($section['section']['cancellationPolicyTitle'])) {
                        if (isset($section['section']['cancellationPolicyForDisplay']['title'])) {
                            $policy_name = $section['section']['cancellationPolicyForDisplay']['title'];
                            $listing_data['cancellation_policy'] = $policy_name;
                            $parsing_steps[] = "Extracted cancellation_policy from POLICIES_DEFAULT section";
                            
                            // Determine strictness level
                            $listing_data['cancellation_policy_details']['name'] = $policy_name;
                            
                            if (strpos(strtolower($policy_name), 'flexible') !== false) {
                                $listing_data['cancellation_policy_details']['strictness'] = 1;
                            } elseif (strpos(strtolower($policy_name), 'moderate') !== false) {
                                $listing_data['cancellation_policy_details']['strictness'] = 2;
                            } elseif (strpos(strtolower($policy_name), 'strict') !== false) {
                                if (strpos(strtolower($policy_name), 'super strict') !== false) {
                                    $listing_data['cancellation_policy_details']['strictness'] = 5;
                                } else {
                                    $listing_data['cancellation_policy_details']['strictness'] = 4;
                                }
                            } elseif (strpos(strtolower($policy_name), 'long term') !== false) {
                                $listing_data['cancellation_policy_details']['strictness'] = 3;
                            } else {
                                $listing_data['cancellation_policy_details']['strictness'] = 3; // Default to moderate
                            }
                            
                            $parsing_steps[] = "Determined cancellation policy strictness: " . $listing_data['cancellation_policy_details']['strictness'];
                        }
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
                    
                    // Extract overview details (bedrooms, bathrooms, etc.)
                    if ($section['sectionId'] === 'OVERVIEW_DEFAULT_V2' && isset($section['sectionData']['overviewItems'])) {
                        foreach ($section['sectionData']['overviewItems'] as $item) {
                            if (isset($item['title'])) {
                                $title = strtolower($item['title']);
                                
                                if (strpos($title, 'bedroom') !== false) {
                                    $listing_data['bedrooms'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted bedrooms from OVERVIEW_DEFAULT_V2 section";
                                } else if (strpos($title, 'bath') !== false) {
                                    $listing_data['bathrooms'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted bathrooms from OVERVIEW_DEFAULT_V2 section";
                                } else if (strpos($title, 'bed') !== false && $listing_data['beds'] == 0) {
                                    $listing_data['beds'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted beds from OVERVIEW_DEFAULT_V2 section";
                                } else if (strpos($title, 'guest') !== false && $listing_data['max_guests'] == 0) {
                                    $listing_data['max_guests'] = (int)filter_var($title, FILTER_SANITIZE_NUMBER_INT);
                                    $parsing_steps[] = "Extracted max_guests from OVERVIEW_DEFAULT_V2 section";
                                }
                            }
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