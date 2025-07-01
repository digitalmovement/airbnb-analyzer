<?php
/**
 * API functionality for AirBnB Listing Analyzer
 */

/**
 * Get listing data from AirBnB
 * Note: This function now uses Brightdata API instead of direct Airbnb API
 * For async processing, use brightdata_trigger_scraping() instead
 * 
 * @param string $listing_url The AirBnB listing URL
 * @return array|WP_Error Listing data or error
 */
function airbnb_analyzer_get_listing_data($listing_url) {
    // Check if Brightdata API is available
    $brightdata_api_key = get_option('airbnb_analyzer_brightdata_api_key');
    if (empty($brightdata_api_key)) {
        return new WP_Error('api_unavailable', 'Brightdata API key is not configured. The old Airbnb API is no longer available.');
    }
    
    // Try to get data from a recent successful request first
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    $recent_request = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE listing_url = %s AND status = 'completed' ORDER BY date_completed DESC LIMIT 1",
        $listing_url
    ));
    
    if ($recent_request && !empty($recent_request->response_data)) {
        $response_data = json_decode($recent_request->response_data, true);
        if (isset($response_data['listing_data'])) {
        if (function_exists('airbnb_analyzer_debug_log')) {
                airbnb_analyzer_debug_log("Using cached listing data for URL: $listing_url", 'API Cache');
            }
            return $response_data['listing_data'];
        }
    }
    
    // If no cached data, return error as this function is now deprecated for new requests
    return new WP_Error('deprecated_function', 'This function is deprecated. Please use the async brightdata_trigger_scraping() function instead, or use the web interface which will email you the results.');
}

/**
 * Legacy function - now deprecated in favor of Brightdata API
 * This function kept all the old Airbnb API logic for reference
 */
function airbnb_analyzer_get_listing_data_legacy($listing_url) {
    return new WP_Error('deprecated', 'This function has been replaced by Brightdata API integration.');
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