<?php
/**
 * Brightdata notification endpoint
 * This file handles callbacks from Brightdata when scraping is complete
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    // Load WordPress
    $wp_path = dirname(dirname(dirname(dirname(__FILE__))));
    require_once($wp_path . '/wp-load.php');
}

// Include required files
require_once(plugin_dir_path(__FILE__) . 'includes/brightdata-api.php');
require_once(plugin_dir_path(__FILE__) . 'includes/analyzer.php');
require_once(plugin_dir_path(__FILE__) . 'includes/claude-api.php');
require_once(plugin_dir_path(__FILE__) . 'includes/settings.php');

// Handle the notification
function handle_brightdata_notification() {
    // Get the raw POST data
    $json_data = file_get_contents('php://input');
    
    if (empty($json_data)) {
        http_response_code(400);
        echo json_encode(array('error' => 'No data received'));
        exit;
    }
    
    // Parse the JSON data
    $data = json_decode($json_data, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(array('error' => 'Invalid JSON data'));
        exit;
    }
    
    // Log the notification
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Brightdata notification received: " . $json_data, 'Brightdata Notification');
    }
    
    // Extract snapshot ID from the notification
    $snapshot_id = null;
    
    // Brightdata notifications can have different formats, try common patterns
    if (isset($data['snapshot_id'])) {
        $snapshot_id = $data['snapshot_id'];
    } elseif (isset($data['id'])) {
        $snapshot_id = $data['id'];
    } elseif (is_array($data) && count($data) > 0) {
        // Sometimes the notification is just the data array
        // We need to find the request from the data content
        $first_item = $data[0];
        if (isset($first_item['input']['url'])) {
            $listing_url = $first_item['input']['url'];
            // Find the request by URL (as backup method)
            global $wpdb;
            $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
            $request = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_name WHERE listing_url = %s AND status = 'pending' ORDER BY date_created DESC LIMIT 1",
                $listing_url
            ));
            if ($request) {
                $snapshot_id = $request->snapshot_id;
            }
        }
    }
    
    if (empty($snapshot_id)) {
        http_response_code(400);
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("No snapshot ID found in notification: " . $json_data, 'Brightdata Notification Error');
        }
        echo json_encode(array('error' => 'No snapshot ID found'));
        exit;
    }
    
    // Get the request from database
    $request = brightdata_get_request($snapshot_id);
    
    if (!$request) {
        http_response_code(404);
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Request not found for snapshot ID: $snapshot_id", 'Brightdata Notification Error');
        }
        echo json_encode(array('error' => 'Request not found'));
        exit;
    }
    
    // Get the actual data from Brightdata
    $brightdata_response = brightdata_get_snapshot($snapshot_id);
    
    if (is_wp_error($brightdata_response)) {
        // Update request status to error
        brightdata_update_request($snapshot_id, 'error', array('error' => $brightdata_response->get_error_message()));
        
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Error fetching snapshot data: " . $brightdata_response->get_error_message(), 'Brightdata Notification Error');
        }
        
        // Send error email
        send_analysis_email($request->email, $request->listing_url, null, $brightdata_response->get_error_message());
        
        http_response_code(500);
        echo json_encode(array('error' => $brightdata_response->get_error_message()));
        exit;
    }
    
    // Convert Brightdata data to analyzer format
    $listing_data = brightdata_format_for_analyzer($brightdata_response);
    
    if (empty($listing_data)) {
        // Update request status to error
        brightdata_update_request($snapshot_id, 'error', array('error' => 'No listing data found'));
        
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("No listing data found for snapshot ID: $snapshot_id", 'Brightdata Notification Error');
        }
        
        // Send error email
        send_analysis_email($request->email, $request->listing_url, null, 'No listing data found in the response');
        
        http_response_code(500);
        echo json_encode(array('error' => 'No listing data found'));
        exit;
    }
    
    // Analyze the listing
    $analysis = null;
    if (!empty(get_option('airbnb_analyzer_claude_api_key'))) {
        $analysis = airbnb_analyzer_analyze_listing_with_claude($listing_data);
    } else {
        $analysis = airbnb_analyzer_analyze_listing($listing_data);
    }
    
    // Update request status to completed
    brightdata_update_request($snapshot_id, 'completed', array(
        'listing_data' => $listing_data,
        'analysis' => $analysis
    ));
    
    // Send the analysis via email
    send_analysis_email($request->email, $request->listing_url, $analysis);
    
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Analysis completed and sent for snapshot ID: $snapshot_id", 'Brightdata Notification');
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode(array('status' => 'success', 'message' => 'Analysis completed and sent'));
    exit;
}

/**
 * Send analysis results via email
 */
function send_analysis_email($email, $listing_url, $analysis = null, $error_message = null) {
    $subject = 'AirBnB Listing Analysis Results';
    
    if ($error_message) {
        $subject = 'AirBnB Listing Analysis - Error';
        $message = "We encountered an error while analyzing your Airbnb listing:\n\n";
        $message .= "Listing URL: $listing_url\n\n";
        $message .= "Error: $error_message\n\n";
        $message .= "Please try again or contact support if the problem persists.";
    } else {
        $message = "Your Airbnb listing analysis is complete!\n\n";
        $message .= "Listing URL: $listing_url\n\n";
        
        if ($analysis && is_array($analysis)) {
            $message .= "=== ANALYSIS RESULTS ===\n\n";
            
            // Add title analysis
            if (isset($analysis['title'])) {
                $message .= "TITLE ANALYSIS:\n";
                $message .= "Length: " . (isset($analysis['title']['length']) ? $analysis['title']['length'] : 'N/A') . " characters\n";
                $message .= "Recommendation: " . (isset($analysis['title']['recommendation']) ? $analysis['title']['recommendation'] : 'N/A') . "\n\n";
            }
            
            // Add description analysis
            if (isset($analysis['description'])) {
                $message .= "DESCRIPTION ANALYSIS:\n";
                $message .= "Length: " . (isset($analysis['description']['length']) ? $analysis['description']['length'] : 'N/A') . " characters\n";
                $message .= "Recommendation: " . (isset($analysis['description']['recommendation']) ? $analysis['description']['recommendation'] : 'N/A') . "\n\n";
            }
            
            // Add photos analysis
            if (isset($analysis['photos'])) {
                $message .= "PHOTOS ANALYSIS:\n";
                $message .= "Count: " . (isset($analysis['photos']['count']) ? $analysis['photos']['count'] : 'N/A') . " photos\n";
                $message .= "Recommendation: " . (isset($analysis['photos']['recommendation']) ? $analysis['photos']['recommendation'] : 'N/A') . "\n\n";
            }
            
            // Add overall recommendation
            if (isset($analysis['overall_recommendation'])) {
                $message .= "OVERALL RECOMMENDATION:\n";
                $message .= $analysis['overall_recommendation'] . "\n\n";
            }
            
            // Add Claude analysis if available
            if (isset($analysis['claude_analysis'])) {
                $message .= "AI ANALYSIS:\n";
                $message .= $analysis['claude_analysis'] . "\n\n";
            }
        } else {
            $message .= "Analysis completed, but no detailed results available.\n\n";
        }
        
        $message .= "Thank you for using our Airbnb Listing Analyzer!";
    }
    
    // Send email
    wp_mail($email, $subject, $message);
}

// Handle the request
handle_brightdata_notification();
?> 