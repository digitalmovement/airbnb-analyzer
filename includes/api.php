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
    
    // Instead of using the GraphQL API, let's directly access the listing page and extract data
    $direct_url = "https://www.airbnb.com/rooms/$listing_id";
    
    $args = array(
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ),
        'timeout' => 30
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
    
    // Extract JSON data from the page
    if (preg_match('/<script id="data-state" data-state="(.+?)"><\/script>/s', $body, $matches)) {
        $json_data = html_entity_decode($matches[1]);
        $data = json_decode($json_data, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            if (function_exists('airbnb_analyzer_debug_log')) {
                airbnb_analyzer_debug_log("JSON parse error: " . json_last_error_msg(), 'API Error');
            }
            return new WP_Error('json_error', 'Error parsing page data: ' . json_last_error_msg());
        }
    } else {
        // Try alternative method to extract data
        if (preg_match('/"bootstrapData":({.+?}),"niobeMinimalClientData"/s', $body, $matches)) {
            $json_data = $matches[1];
            $data = json_decode($json_data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                if (function_exists('airbnb_analyzer_debug_log')) {
                    airbnb_analyzer_debug_log("JSON parse error (alternative method): " . json_last_error_msg(), 'API Error');
                }
                return new WP_Error('json_error', 'Error parsing page data (alternative method): ' . json_last_error_msg());
            }
        } else {
            if (function_exists('airbnb_analyzer_debug_log')) {
                airbnb_analyzer_debug_log("Could not extract JSON data from page", 'API Error');
            }
            return new WP_Error('parsing_error', 'Could not extract data from listing page');
        }
    }
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Page data extracted successfully in " . round($response_time, 2) . " seconds", 'API Success');
    }
    
    // Parse the extracted data
    $listing_data = parse_airbnb_page_data($data, $listing_id, $body);
    
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
 * Parse Airbnb page data to extract listing information
 * 
 * @param array $data The extracted JSON data
 * @param string $listing_id The listing ID
 * @param string $html_body The full HTML body (as fallback)
 * @return array The extracted listing data
 */
function parse_airbnb_page_data($data, $listing_id, $html_body) {
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
        // Extract data from HTML if needed
        if (empty($data) || !is_array($data)) {
            $parsing_steps[] = "Falling back to HTML parsing";
            
            // Extract title
            if (preg_match('/<meta property="og:title" content="([^"]+)"/s', $html_body, $title_matches)) {
                $listing_data['title'] = html_entity_decode($title_matches[1]);
                $parsing_steps[] = "Extracted title from HTML meta tag";
            }
            
            // Extract description
            if (preg_match('/<meta property="og:description" content="([^"]+)"/s', $html_body, $desc_matches)) {
                $listing_data['description'] = html_entity_decode($desc_matches[1]);
                $parsing_steps[] = "Extracted description from HTML meta tag";
            }
            
            // Extract images
            if (preg_match_all('/<meta property="og:image" content="([^"]+)"/s', $html_body, $img_matches)) {
                $listing_data['photos'] = $img_matches[1];
                $parsing_steps[] = "Extracted " . count($listing_data['photos']) . " photos from HTML meta tags";
            }
            
            // Extract location
            if (preg_match('/<meta property="og:locality" content="([^"]+)"/s', $html_body, $loc_matches)) {
                $listing_data['location'] = html_entity_decode($loc_matches[1]);
                $parsing_steps[] = "Extracted location from HTML meta tag";
            }
        } else {
            $parsing_steps[] = "Processing JSON data from page";
            
            // Extract data from the JSON structure
            // This will need to be adapted based on the actual structure of the data
            
            // Look for pdp_listing_detail
            if (isset($data['pdp_listing_detail'])) {
                $pdp_data = $data['pdp_listing_detail'];
                $parsing_steps[] = "Found pdp_listing_detail data";
                
                // Extract basic listing info
                if (isset($pdp_data['listing'])) {
                    $listing = $pdp_data['listing'];
                    
                    if (isset($listing['name'])) {
                        $listing_data['title'] = $listing['name'];
                        $parsing_steps[] = "Extracted title from listing data";
                    }
                    
                    if (isset($listing['description'])) {
                        $listing_data['description'] = $listing['description'];
                        $parsing_steps[] = "Extracted description from listing data";
                    }
                    
                    if (isset($listing['city'])) {
                        $listing_data['location'] = $listing['city'];
                        $parsing_steps[] = "Extracted location from listing data";
                    }
                    
                    if (isset($listing['bedrooms'])) {
                        $listing_data['bedrooms'] = intval($listing['bedrooms']);
                        $parsing_steps[] = "Extracted bedrooms from listing data: " . $listing_data['bedrooms'];
                    }
                    
                    if (isset($listing['bathrooms'])) {
                        $listing_data['bathrooms'] = floatval($listing['bathrooms']);
                        $parsing_steps[] = "Extracted bathrooms from listing data: " . $listing_data['bathrooms'];
                    }
                    
                    if (isset($listing['beds'])) {
                        $listing_data['beds'] = intval($listing['beds']);
                        $parsing_steps[] = "Extracted beds from listing data: " . $listing_data['beds'];
                    }
                    
                    if (isset($listing['person_capacity'])) {
                        $listing_data['max_guests'] = intval($listing['person_capacity']);
                        $parsing_steps[] = "Extracted max_guests from listing data: " . $listing_data['max_guests'];
                    }
                    
                    if (isset($listing['property_type'])) {
                        $listing_data['property_type'] = $listing['property_type'];
                        $parsing_steps[] = "Extracted property_type from listing data";
                    }
                    
                    // Extract photos
                    if (isset($listing['photos']) && is_array($listing['photos'])) {
                        foreach ($listing['photos'] as $photo) {
                            if (isset($photo['large'])) {
                                $listing_data['photos'][] = $photo['large'];
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['photos']) . " photos from listing data";
                    }
                    
                    // Extract amenities
                    if (isset($listing['amenities']) && is_array($listing['amenities'])) {
                        foreach ($listing['amenities'] as $amenity) {
                            if (isset($amenity['name'])) {
                                $listing_data['amenities'][] = $amenity['name'];
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($listing_data['amenities']) . " amenities from listing data";
                    }
                    
                    // Extract host info
                    if (isset($listing['primary_host'])) {
                        $host = $listing['primary_host'];
                        
                        if (isset($host['first_name'])) {
                            $listing_data['host_name'] = $host['first_name'];
                            $parsing_steps[] = "Extracted host_name from listing data";
                        }
                        
                        if (isset($host['is_superhost'])) {
                            $listing_data['host_is_superhost'] = (bool)$host['is_superhost'];
                            $parsing_steps[] = "Extracted host_is_superhost from listing data";
                        }
                    }
                    
                    // Extract rating info
                    if (isset($listing['star_rating'])) {
                        $listing_data['rating'] = floatval($listing['star_rating']);
                        $parsing_steps[] = "Extracted rating from listing data: " . $listing_data['rating'];
                    }
                    
                    if (isset($listing['review_count'])) {
                        $listing_data['review_count'] = intval($listing['review_count']);
                        $parsing_steps[] = "Extracted review_count from listing data: " . $listing_data['review_count'];
                    }
                }
                
                // Extract price info
                if (isset($pdp_data['pricing_quote'])) {
                    $pricing = $pdp_data['pricing_quote'];
                    
                    if (isset($pricing['rate']['amount'])) {
                        $listing_data['price'] = floatval($pricing['rate']['amount']);
                        $parsing_steps[] = "Extracted price from pricing data: " . $listing_data['price'];
                    }
                    
                    if (isset($pricing['rate']['currency'])) {
                        $listing_data['price_currency'] = $pricing['rate']['currency'];
                        $parsing_steps[] = "Extracted price_currency from pricing data";
                    }
                }
            }
        }
        
        // Store parsing steps for debugging
        $listing_data['_parsing_steps'] = $parsing_steps;
        
    } catch (Exception $e) {
        error_log('Error parsing Airbnb page data: ' . $e->getMessage());
        // Return basic data if we can extract at least the title
        if (empty($listing_data['title'])) {
            return new WP_Error('parsing_error', 'Error parsing Airbnb page data: ' . $e->getMessage());
        }
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