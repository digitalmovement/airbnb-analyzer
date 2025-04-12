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
    preg_match('/\/rooms\/(\d+)/', $listing_url, $matches);
    
    if (empty($matches[1])) {
        return new WP_Error('invalid_url', 'Invalid AirBnB listing URL');
    }
    
    $listing_id = $matches[1];
    
    // Use WordPress HTTP API to fetch listing data
    $response = wp_remote_get($listing_url);
    
    if (is_wp_error($response)) {
        return $response;
    }
    
    $body = wp_remote_retrieve_body($response);
    
    if (empty($body)) {
        return new WP_Error('empty_response', 'Empty response from AirBnB');
    }
    
    // Parse listing data
    $listing_data = airbnb_analyzer_parse_listing_html($body, $listing_id);
    
    if (is_wp_error($listing_data)) {
        return $listing_data;
    }
    
    return $listing_data;
}

/**
 * Parse AirBnB listing HTML
 * 
 * @param string $html The HTML content
 * @param string $listing_id The listing ID
 * @return array|WP_Error Parsed listing data or error
 */
function airbnb_analyzer_parse_listing_html($html, $listing_id) {
    // Create a new DOMDocument
    $dom = new DOMDocument();
    
    // Suppress warnings for malformed HTML
    @$dom->loadHTML($html);
    
    $xpath = new DOMXPath($dom);
    
    // Extract listing data
    $listing_data = array(
        'id' => $listing_id,
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
    
    // Extract title
    $title_nodes = $xpath->query('//h1');
    if ($title_nodes->length > 0) {
        $listing_data['title'] = trim($title_nodes->item(0)->textContent);
    }
    
    // Extract description
    $description_nodes = $xpath->query('//div[contains(@data-section-id, "DESCRIPTION_DEFAULT")]');
    if ($description_nodes->length > 0) {
        $listing_data['description'] = trim($description_nodes->item(0)->textContent);
    }
    
    // Extract photos
    $photo_nodes = $xpath->query('//img[contains(@class, "gallery")]/@src');
    foreach ($photo_nodes as $node) {
        $listing_data['photos'][] = $node->value;
    }
    
    // If no photos found, try alternative selectors
    if (empty($listing_data['photos'])) {
        $photo_nodes = $xpath->query('//picture/source/@srcset');
        foreach ($photo_nodes as $node) {
            $srcset = $node->value;
            $parts = explode(',', $srcset);
            if (!empty($parts)) {
                $url_parts = explode(' ', trim($parts[0]));
                if (!empty($url_parts[0])) {
                    $listing_data['photos'][] = $url_parts[0];
                }
            }
        }
    }
    
    // If still no photos, try another approach
    if (empty($listing_data['photos'])) {
        if (preg_match_all('/"url":"(https:\/\/a0\.muscache\.com\/im\/pictures\/[^"]+)"/', $html, $matches)) {
            $listing_data['photos'] = $matches[1];
        }
    }
    
    // Extract amenities
    $amenity_nodes = $xpath->query('//div[contains(@data-section-id, "AMENITIES_DEFAULT")]//div[contains(@class, "amenity")]');
    foreach ($amenity_nodes as $node) {
        $listing_data['amenities'][] = trim($node->textContent);
    }
    
    // If we couldn't extract data properly, try JSON approach
    if (empty($listing_data['title']) || empty($listing_data['description'])) {
        // Look for JSON data in the page
        if (preg_match('/"pdpDisplayExtensions":(.*?),"pdpReviews"/', $html, $matches)) {
            $json_data = json_decode($matches[1], true);
            if (!empty($json_data)) {
                // Extract data from JSON
                // This would need to be adjusted based on actual AirBnB JSON structure
            }
        }
    }
    
    return $listing_data;
}
?> 