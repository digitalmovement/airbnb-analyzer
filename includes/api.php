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
    
    $response = wp_remote_get($api_url, $args);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        return new WP_Error('api_error', 'Error fetching data from AirBnB API: ' . $status_code);
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (empty($data) || json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', 'Error parsing JSON response from AirBnB API');
    }
    
    // Parse the API response into our format
    return parse_airbnb_api_response($data);
}

/**
 * Construct the Airbnb API URL
 * 
 * @param string $listing_id The listing ID
 * @return string The API URL
 */
function construct_airbnb_api_url($listing_id) {
    // Create the encoded listing ID
    $encoded_id = 'StayListing:' . $listing_id;
    $base64_id = base64_encode($encoded_id);
    
    // Current date for check-in/out
    $check_in = date('Y-m-d', strtotime('+30 days'));
    $check_out = date('Y-m-d', strtotime('+35 days'));
    
    // Construct variables
    $variables = array(
        'id' => $base64_id,
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
    
    // Encode variables
    $encoded_variables = urlencode(json_encode($variables));
    
    // Construct extensions
    $extensions = array(
        'persistedQuery' => array(
            'version' => 1,
            'sha256Hash' => '6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343'
        )
    );
    
    // Encode extensions
    $encoded_extensions = urlencode(json_encode($extensions));
    
    // Construct the full URL
    return "https://www.airbnb.com/api/v3/StaysPdpSections/6f2c582da19b486271d60c4b19e7bdd1147184662f1f4e9a83b08211a73d7343?operationName=StaysPdpSections&locale=en-US&currency=USD&variables={$encoded_variables}&extensions={$encoded_extensions}";
}

/**
 * Parse the Airbnb API response
 * 
 * @param array $api_data The API response data
 * @return array Parsed listing data
 */
function parse_airbnb_api_response($api_data) {
    // Initialize data structure
    $listing_data = array(
        'id' => '',
        'title' => '',
        'description' => '',
        'photos' => array(),
        'amenities' => array(),
        'price' => '',
        'rating' => '',
        'reviews_count' => 0,
        'host_name' => '',
        'host_since' => '',
        'location' => '',
        'bedrooms' => 0,
        'bathrooms' => 0,
        'max_guests' => 0,
    );
    
    // Extract data from API response
    try {
        $sections = $api_data['data']['presentation']['stayProductDetailPage']['sections'];
        
        // Extract listing ID
        if (isset($api_data['data']['presentation']['stayProductDetailPage']['id'])) {
            $id_parts = explode(':', $api_data['data']['presentation']['stayProductDetailPage']['id']);
            $listing_data['id'] = end($id_parts);
        }
        
        // Extract title
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'TITLE_DEFAULT') {
                if (isset($section['section']['title'])) {
                    $listing_data['title'] = $section['section']['title'];
                }
                break;
            }
        }
        
        // Extract description
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'DESCRIPTION_DEFAULT') {
                if (isset($section['section']['htmlDescription']['htmlText'])) {
                    $listing_data['description'] = strip_tags($section['section']['htmlDescription']['htmlText']);
                }
                break;
            }
        }
        
        // Extract photos
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE') {
                if (isset($section['section']['mediaItems'])) {
                    foreach ($section['section']['mediaItems'] as $media) {
                        if (isset($media['baseUrl'])) {
                            $listing_data['photos'][] = $media['baseUrl'];
                        }
                    }
                }
                break;
            }
        }
        
        // Extract amenities
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'AMENITIES_DEFAULT') {
                if (isset($section['section']['amenities'])) {
                    foreach ($section['section']['amenities'] as $category) {
                        if (isset($category['amenities'])) {
                            foreach ($category['amenities'] as $amenity) {
                                $listing_data['amenities'][] = $amenity['title'];
                            }
                        }
                    }
                }
                break;
            }
        }
        
        // Extract price
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'BOOK_IT_SIDEBAR') {
                if (isset($section['section']['structuredDisplayPrice']['primaryLine']['price'])) {
                    $listing_data['price'] = $section['section']['structuredDisplayPrice']['primaryLine']['price'];
                }
                break;
            }
        }
        
        // Extract rating and reviews count
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'REVIEWS_DEFAULT') {
                if (isset($section['section']['reviews']['overallRating'])) {
                    $listing_data['rating'] = $section['section']['reviews']['overallRating'];
                }
                if (isset($section['section']['reviews']['reviewsCount'])) {
                    $listing_data['reviews_count'] = $section['section']['reviews']['reviewsCount'];
                }
                break;
            }
        }
        
        // Extract host information
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'HOST_PROFILE_DEFAULT') {
                if (isset($section['section']['hostAvatar']['name'])) {
                    $listing_data['host_name'] = $section['section']['hostAvatar']['name'];
                }
                if (isset($section['section']['hostSince'])) {
                    $listing_data['host_since'] = $section['section']['hostSince'];
                }
                break;
            }
        }
        
        // Extract location
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'LOCATION_DEFAULT') {
                if (isset($section['section']['subtitle'])) {
                    $listing_data['location'] = $section['section']['subtitle'];
                }
                break;
            }
        }
        
        // Extract property details
        foreach ($sections['sections'] as $section) {
            if ($section['sectionId'] === 'OVERVIEW_DEFAULT') {
                if (isset($section['section']['detailItems'])) {
                    foreach ($section['section']['detailItems'] as $detail) {
                        if (strpos($detail['title'], 'bedroom') !== false) {
                            $listing_data['bedrooms'] = (int)$detail['title'];
                        } elseif (strpos($detail['title'], 'bathroom') !== false) {
                            $listing_data['bathrooms'] = (float)$detail['title'];
                        } elseif (strpos($detail['title'], 'guest') !== false) {
                            $listing_data['max_guests'] = (int)$detail['title'];
                        }
                    }
                }
                break;
            }
        }
        
    } catch (Exception $e) {
        error_log('Error parsing Airbnb API response: ' . $e->getMessage());
        // Return basic data if we can extract at least the title
        if (empty($listing_data['title'])) {
            return new WP_Error('parsing_error', 'Error parsing Airbnb API response');
        }
    }
    
    return $listing_data;
}
?> 