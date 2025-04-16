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
    
    // Use the Airbnb API to get listing data
    $api_url = construct_airbnb_api_url($listing_id);
    
    $args = array(
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json',
            'X-Airbnb-API-Key' => 'd306zoyjsyarp7ifhu67rjxn52tv0t20'
        ),
        'timeout' => 30
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
            $error_body = wp_remote_retrieve_body($response);
            airbnb_analyzer_debug_log("API HTTP error: $status_code. Response: " . substr($error_body, 0, 500), 'API Error');
            airbnb_analyzer_debug_log("Request URL: $api_url", 'API Error');
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
    
    // Parse the API response
    try {
        if (isset($data['data']['presentation']['stayProductDetailPage']['sections']['sections'])) {
            $sections = $data['data']['presentation']['stayProductDetailPage']['sections']['sections'];
            
            foreach ($sections as $section) {
                // Extract title from TITLE_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'TITLE_DEFAULT') {
                    if (isset($section['section']['title'])) {
                        $listing_data['title'] = $section['section']['title'];
                        $parsing_steps[] = "Extracted title from TITLE_DEFAULT section";
                    }
                    
                    if (isset($section['section']['subtitle'])) {
                        $listing_data['location'] = $section['section']['subtitle'];
                        $parsing_steps[] = "Extracted location from TITLE_DEFAULT section";
                    }
                }
                
                // Extract description from DESCRIPTION_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'DESCRIPTION_DEFAULT') {
                    if (isset($section['section']['htmlDescription']['htmlText'])) {
                        $listing_data['description'] = $section['section']['htmlDescription']['htmlText'];
                        $parsing_steps[] = "Extracted description from DESCRIPTION_DEFAULT section";
                    }
                }
                
                // Extract photos from PHOTO_TOUR_SCROLLABLE_MODAL section
                if (isset($section['sectionId']) && $section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE_MODAL') {
                    if (isset($section['section']['mediaItems']) && is_array($section['section']['mediaItems'])) {
                        foreach ($section['section']['mediaItems'] as $mediaItem) {
                            if (isset($mediaItem['baseUrl'])) {
                                $listing_data['photos'][] = $mediaItem['baseUrl'];
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['photos']) . " photos from PHOTO_TOUR_SCROLLABLE_MODAL section";
                    }
                }
                
                // Extract house rules from POLICIES_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'POLICIES_DEFAULT') {
                    if (isset($section['section']['houseRules'])) {
                        $listing_data['house_rules'] = $section['section']['houseRules'];
                        $parsing_steps[] = "Extracted house_rules from POLICIES_DEFAULT section";
                    }
                    
                    if (isset($section['section']['cancellationPolicy']['title'])) {
                        $listing_data['cancellation_policy'] = $section['section']['cancellationPolicy']['title'];
                        $parsing_steps[] = "Extracted cancellation_policy from POLICIES_DEFAULT section";
                    }
                }
                
                // Extract amenities from AMENITIES_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'AMENITIES_DEFAULT') {
                    if (isset($section['section']['amenities']) && is_array($section['section']['amenities'])) {
                        foreach ($section['section']['amenities'] as $amenityGroup) {
                            if (isset($amenityGroup['amenities']) && is_array($amenityGroup['amenities'])) {
                                foreach ($amenityGroup['amenities'] as $amenity) {
                                    if (isset($amenity['title'])) {
                                        $listing_data['amenities'][] = $amenity['title'];
                                    }
                                }
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['amenities']) . " amenities from AMENITIES_DEFAULT section";
                    }
                }
                
                // Extract host info from HOST_PROFILE_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'HOST_PROFILE_DEFAULT') {
                    if (isset($section['section']['host']['name'])) {
                        $listing_data['host_name'] = $section['section']['host']['name'];
                        $parsing_steps[] = "Extracted host_name from HOST_PROFILE_DEFAULT section";
                    }
                    
                    if (isset($section['section']['host']['isSuperhost'])) {
                        $listing_data['host_is_superhost'] = (bool)$section['section']['host']['isSuperhost'];
                        $parsing_steps[] = "Extracted host_is_superhost from HOST_PROFILE_DEFAULT section";
                    }
                    
                    if (isset($section['section']['host']['memberSince'])) {
                        $listing_data['host_since'] = $section['section']['host']['memberSince'];
                        $parsing_steps[] = "Extracted host_since from HOST_PROFILE_DEFAULT section";
                    }
                }
                
                // Extract ratings from REVIEWS_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'REVIEWS_DEFAULT') {
                    if (isset($section['section']['overallRating'])) {
                        $listing_data['rating'] = floatval($section['section']['overallRating']);
                        $parsing_steps[] = "Extracted rating from REVIEWS_DEFAULT section";
                    }
                    
                    if (isset($section['section']['reviewsCount'])) {
                        $listing_data['review_count'] = intval($section['section']['reviewsCount']);
                        $parsing_steps[] = "Extracted review_count from REVIEWS_DEFAULT section";
                    }
                    
                    if (isset($section['section']['reviewsSubgroups']) && is_array($section['section']['reviewsSubgroups'])) {
                        foreach ($section['section']['reviewsSubgroups'] as $subgroup) {
                            if (isset($subgroup['localizedRating']) && isset($subgroup['title'])) {
                                $listing_data['property_rating_details'][] = array(
                                    'name' => $subgroup['title'],
                                    'rating' => floatval($subgroup['localizedRating'])
                                );
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['property_rating_details']) . " property rating details from REVIEWS_DEFAULT section";
                    }
                }
                
                // Extract property details from OVERVIEW_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'OVERVIEW_DEFAULT') {
                    if (isset($section['section']['detailItems']) && is_array($section['section']['detailItems'])) {
                        foreach ($section['section']['detailItems'] as $detailItem) {
                            if (isset($detailItem['title']) && isset($detailItem['value'])) {
                                $title = strtolower($detailItem['title']);
                                $value = $detailItem['value'];
                                
                                if (strpos($title, 'bedroom') !== false) {
                                    $listing_data['bedrooms'] = intval($value);
                                    $parsing_steps[] = "Extracted bedrooms from OVERVIEW_DEFAULT section";
                                } elseif (strpos($title, 'bathroom') !== false) {
                                    $listing_data['bathrooms'] = floatval($value);
                                    $parsing_steps[] = "Extracted bathrooms from OVERVIEW_DEFAULT section";
                                } elseif (strpos($title, 'bed') !== false) {
                                    $listing_data['beds'] = intval($value);
                                    $parsing_steps[] = "Extracted beds from OVERVIEW_DEFAULT section";
                                } elseif (strpos($title, 'guest') !== false) {
                                    $listing_data['max_guests'] = intval($value);
                                    $parsing_steps[] = "Extracted max_guests from OVERVIEW_DEFAULT section";
                                } elseif (strpos($title, 'property type') !== false) {
                                    $listing_data['property_type'] = $value;
                                    $parsing_steps[] = "Extracted property_type from OVERVIEW_DEFAULT section";
                                }
                            }
                        }
                    }
                }
                
                // Extract price from BOOK_IT_FLOATING_FOOTER
                if (isset($section['sectionId']) && $section['sectionId'] === 'BOOK_IT_FLOATING_FOOTER') {
                    if (isset($section['section']['structuredDisplayPrice']['primaryLine']['price'])) {
                        $price_info = $section['section']['structuredDisplayPrice']['primaryLine']['price'];
                        if (isset($price_info['amount'])) {
                            $listing_data['price'] = floatval($price_info['amount']);
                            $parsing_steps[] = "Extracted price from BOOK_IT_FLOATING_FOOTER section";
                        }
                        if (isset($price_info['currency'])) {
                            $listing_data['price_currency'] = $price_info['currency'];
                            $parsing_steps[] = "Extracted price_currency from BOOK_IT_FLOATING_FOOTER section";
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
    // Current date for check-in (today + 30 days)
    $check_in = date('Y-m-d', strtotime('+30 days'));
    // Check-out (check-in + 5 days)
    $check_out = date('Y-m-d', strtotime($check_in . ' +5 days'));
    
    // Base64 encode the listing ID with the StayListing prefix
    $encoded_id = base64_encode("StayListing:$listing_id");
    
    // Construct the variables parameter
    $variables = array(
        'id' => $encoded_id,
        'pdpSectionsRequest' => array(
            'adults' => "2",
            'amenityFilters' => null,
            'bypassTargetings' => false,
            'categoryTag' => null,
            'causeId' => null,
            'children' => null,
            'disasterId' => null,
            'discountedGuestFeeVersion' => null,
            'displayExtensions' => null,
            'federatedSearchId' => wp_generate_uuid4(),
            'forceBoostPriorityMessageType' => null,
            'hostPreview' => false,
            'infants' => null,
            'interactionType' => null,
            'layouts' => array('SIDEBAR', 'SINGLE_COLUMN'),
            'pets' => 0,
            'pdpTypeOverride' => null,
            'photoId' => null,
            'preview' => false,
            'previousStateCheckIn' => null,
            'previousStateCheckOut' => null,
            'priceDropSource' => null,
            'privateBooking' => false,
            'promotionUuid' => null,
            'relaxedAmenityIds' => null,
            'searchId' => null,
            'selectedCancellationPolicyId' => null,
            'selectedRatePlanId' => null,
            'splitStays' => null,
            'staysBookingMigrationEnabled' => false,
            'translateUgc' => null,
            'useNewSectionWrapperApi' => false,
            'sectionIds' => null,
            'checkIn' => $check_in,
            'checkOut' => $check_out,
            'p3ImpressionId' => 'p3_' . time() . '_' . substr(md5(rand()), 0, 16)
        )
    );
    
    // Construct the extensions parameter
    $extensions = array(
        'persistedQuery' => array(
            'version' => 1,
            'sha256Hash' => '063357cc871e3c7d764e0536a09ffec74d999d362af071636c719559005a35d4'
        )
    );
    
    // Use the exact URL format that works with Airbnb's API
    $url = 'https://www.airbnb.com/api/v3/StaysPdpSections/063357cc871e3c7d764e0536a09ffec74d999d362af071636c719559005a35d4';
    $url .= '?operationName=StaysPdpSections';
    $url .= '&locale=en-GB';
    $url .= '&currency=GBP';
    $url .= '&variables=' . urlencode(json_encode($variables));
    $url .= '&extensions=' . urlencode(json_encode($extensions));
    
    return $url;
}
?>