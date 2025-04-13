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
        return new WP_Error('api_error', 'Error fetching listing data: ' . $status_code);
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return new WP_Error('json_error', 'Error parsing API response: ' . json_last_error_msg());
    }
    
    // Parse the API response
    return parse_airbnb_api_response($data, $listing_id);
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
        'rating' => 0,
        'review_count' => 0,
        'house_rules' => '',
        'cancellation_policy' => ''
    );
    
    try {
        // Check if we have the expected data structure
        if (!isset($data['data']['presentation']['stayProductDetailPage']['sections']['sections'])) {
            return new WP_Error('invalid_response', 'Unexpected API response structure');
        }
        
        $sections = $data['data']['presentation']['stayProductDetailPage']['sections'];
        
        // Extract title
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'TITLE_DEFAULT') {
                if (isset($section['section']['title'])) {
                    $listing_data['title'] = $section['section']['title'];
                }
                break;
            }
        }
        
        // Extract description
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'DESCRIPTION_DEFAULT') {
                if (isset($section['section']['htmlDescription']['htmlText'])) {
                    $listing_data['description'] = $section['section']['htmlDescription']['htmlText'];
                } elseif (isset($section['section']['description'])) {
                    $listing_data['description'] = $section['section']['description'];
                } elseif (isset($section['section']['items'])) {
                    $description = '';
                    foreach ($section['section']['items'] as $item) {
                        if (isset($item['html']['htmlText'])) {
                            $description .= $item['html']['htmlText'] . "\n\n";
                        }
                    }
                    $listing_data['description'] = trim($description);
                }
                break;
            }
        }
        
        // Extract photos
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE_MODAL') {
                if (isset($section['section']['mediaItems'])) {
                    foreach ($section['section']['mediaItems'] as $media) {
                        if (isset($media['baseUrl'])) {
                            $listing_data['photos'][] = $media['baseUrl'];
                        }
                    }
                }
                break;
            } elseif (isset($section['sectionId']) && $section['sectionId'] === 'HERO_DEFAULT') {
                if (isset($section['section']['mediaItems'])) {
                    foreach ($section['section']['mediaItems'] as $media) {
                        if (isset($media['baseUrl'])) {
                            $listing_data['photos'][] = $media['baseUrl'];
                        }
                    }
                }
            }
        }
        
        // Extract ratings and reviews
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'REVIEWS_DEFAULT') {
                if (isset($section['section']['overallRating'])) {
                    $listing_data['rating'] = (float)$section['section']['overallRating'];
                }
                if (isset($section['section']['reviewsCount'])) {
                    $listing_data['review_count'] = (int)$section['section']['reviewsCount'];
                }
                break;
            }
        }
        
        // Extract host information
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'HOST_PROFILE_DEFAULT') {
                if (isset($section['section']['hostAvatar']['name'])) {
                    $listing_data['host_name'] = $section['section']['hostAvatar']['name'];
                }
                if (isset($section['section']['hostSince'])) {
                    $listing_data['host_since'] = $section['section']['hostSince'];
                }
                if (isset($section['section']['hostAvatar']['isSuperhost'])) {
                    $listing_data['host_is_superhost'] = (bool)$section['section']['hostAvatar']['isSuperhost'];
                }
                break;
            }
        }
        
        // Extract location
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'LOCATION_DEFAULT') {
                if (isset($section['section']['subtitle'])) {
                    $listing_data['location'] = $section['section']['subtitle'];
                }
                break;
            }
        }
        
        // Extract property details
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'OVERVIEW_DEFAULT') {
                if (isset($section['section']['detailItems'])) {
                    foreach ($section['section']['detailItems'] as $detail) {
                        if (strpos($detail['title'], 'bedroom') !== false) {
                            $listing_data['bedrooms'] = (int)$detail['title'];
                        } elseif (strpos($detail['title'], 'bathroom') !== false) {
                            $listing_data['bathrooms'] = (float)$detail['title'];
                        } elseif (strpos($detail['title'], 'bed') !== false) {
                            $listing_data['beds'] = (int)$detail['title'];
                        } elseif (strpos($detail['title'], 'guest') !== false) {
                            $listing_data['max_guests'] = (int)$detail['title'];
                        }
                    }
                }
                break;
            }
        }
        
        // Extract amenities - UPDATED to handle both previewAmenitiesGroups and seeAllAmenitiesGroups
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'AMENITIES_DEFAULT') {
                // Check for preview amenities
                if (isset($section['section']['previewAmenitiesGroups'])) {
                    foreach ($section['section']['previewAmenitiesGroups'] as $group) {
                        if (isset($group['amenities'])) {
                            foreach ($group['amenities'] as $amenity) {
                                if (isset($amenity['title']) && $amenity['available'] === true) {
                                    $listing_data['amenities'][] = $amenity['title'];
                                }
                            }
                        }
                    }
                }
                
                // Check for all amenities
                if (isset($section['section']['seeAllAmenitiesGroups'])) {
                    foreach ($section['section']['seeAllAmenitiesGroups'] as $group) {
                        if (isset($group['amenities'])) {
                            foreach ($group['amenities'] as $amenity) {
                                if (isset($amenity['title']) && $amenity['available'] === true) {
                                    // Avoid duplicates
                                    if (!in_array($amenity['title'], $listing_data['amenities'])) {
                                        $listing_data['amenities'][] = $amenity['title'];
                                    }
                                }
                            }
                        }
                    }
                }
                
                // If we still don't have amenities, try the old structure
                if (empty($listing_data['amenities']) && isset($section['section']['amenityGroups'])) {
                    foreach ($section['section']['amenityGroups'] as $group) {
                        if (isset($group['amenities'])) {
                            foreach ($group['amenities'] as $amenity) {
                                if (isset($amenity['title'])) {
                                    $listing_data['amenities'][] = $amenity['title'];
                                }
                            }
                        }
                    }
                }
                
                break;
            }
        }
        
        // Extract price information
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'BOOK_IT_SIDEBAR') {
                if (isset($section['section']['structuredDisplayPrice']['primaryLine']['price'])) {
                    $price_info = $section['section']['structuredDisplayPrice']['primaryLine']['price'];
                    if (isset($price_info['amount'])) {
                        $listing_data['price'] = (float)$price_info['amount'];
                    }
                    if (isset($price_info['currency'])) {
                        $listing_data['price_currency'] = $price_info['currency'];
                    }
                }
                break;
            }
        }
        
        // Extract house rules
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'POLICIES_DEFAULT') {
                if (isset($section['section']['policyGroups'])) {
                    $rules = '';
                    foreach ($section['section']['policyGroups'] as $group) {
                        if (isset($group['policies'])) {
                            foreach ($group['policies'] as $policy) {
                                if (isset($policy['title'])) {
                                    $rules .= $policy['title'] . "\n";
                                }
                            }
                        }
                    }
                    $listing_data['house_rules'] = trim($rules);
                }
                break;
            }
        }
        
        // Extract cancellation policy
        foreach ($sections['sections'] as $section) {
            if (isset($section['sectionId']) && $section['sectionId'] === 'POLICIES_DEFAULT') {
                if (isset($section['section']['cancellationPolicyTitle'])) {
                    $listing_data['cancellation_policy'] = $section['section']['cancellationPolicyTitle'];
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