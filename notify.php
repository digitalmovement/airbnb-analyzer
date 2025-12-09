<?php
/**
 * Notification endpoint (DEPRECATED)
 * This file is kept for backward compatibility but is no longer used.
 * The plugin now uses WordPress cron to process requests via pyairbnb_process_request()
 * 
 * @deprecated This webhook handler is no longer needed as we use cron-based processing
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    // Load WordPress
    $wp_path = dirname(dirname(dirname(dirname(__FILE__))));
    require_once($wp_path . '/wp-load.php');
}

// Include required files
require_once(plugin_dir_path(__FILE__) . 'includes/pyairbnb-api.php');
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
    $request = pyairbnb_get_request($snapshot_id);
    
    if (!$request) {
        http_response_code(404);
        if (function_exists('airbnb_analyzer_debug_log')) {
            airbnb_analyzer_debug_log("Request not found for snapshot ID: $snapshot_id", 'Notification Error');
        }
        echo json_encode(array('error' => 'Request not found'));
        exit;
    }
    
    // This webhook is deprecated - requests are now processed via cron
    // If we receive a webhook, just trigger the processing function
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Deprecated webhook received for snapshot ID: $snapshot_id. Processing via cron function.", 'Notification');
    }
    
    // Process the request using the new function
    $result = pyairbnb_process_request($snapshot_id);
    
    if (!$result) {
        http_response_code(500);
        echo json_encode(array('error' => 'Failed to process request'));
        exit;
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode(array('status' => 'success', 'message' => 'Request queued for processing'));
    exit;
}

/**
 * Send analysis results via email
 */
function send_analysis_email($email, $listing_url, $analysis = null, $error_message = null, $snapshot_id = null) {
    $subject = 'AirBnB Listing Analysis Results';
    
    if ($error_message) {
        $subject = 'AirBnB Listing Analysis - Error';
        $message = "We encountered an error while analyzing your Airbnb listing:\n\n";
        $message .= "Listing URL: $listing_url\n\n";
        $message .= "Error: $error_message\n\n";
        $message .= "Please try again or contact support if the problem persists.";
    } else {
        $site_name = get_bloginfo('name');
        $results_url = site_url("/airbnb-analysis-results/?id=" . urlencode($snapshot_id));
        
        $message = "🎉 Great news! Your Airbnb listing analysis is complete!\n\n";
        $message .= "📍 Analyzed Listing: $listing_url\n\n";
        
        // Add a quick preview
        $has_ai_analysis = isset($analysis['claude_analysis']) && is_array($analysis['claude_analysis']);
        
        if ($has_ai_analysis) {
            $message .= "✨ Your analysis includes AI-powered insights covering:\n";
            $message .= "• Title optimization with alternative suggestions\n";
            $message .= "• Description analysis and improvement tips\n";
            $message .= "• Host profile recommendations\n";
            $message .= "• Amenities analysis\n";
            $message .= "• Review performance insights\n";
            $message .= "• Cancellation policy recommendations\n\n";
        } else {
            $message .= "📊 Your analysis includes:\n";
            $message .= "• Title and description optimization\n";
            $message .= "• Photo recommendations\n";
            $message .= "• Amenities analysis\n";
            $message .= "• Overall improvement suggestions\n\n";
        }
        
        $message .= "🔗 VIEW YOUR DETAILED RESULTS:\n";
        $message .= "$results_url\n\n";
        
        $message .= "💡 Why view online?\n";
        $message .= "• Beautiful, easy-to-read formatting\n";
        $message .= "• Interactive sections and ratings\n";
        $message .= "• Mobile-friendly design\n";
        $message .= "• Always accessible with your unique link\n\n";
        
        $message .= "🔒 Privacy: Your results link is private and secure.\n";
        $message .= "📱 Mobile-friendly: View on any device, anytime.\n\n";
        
        $message .= "Questions? Reply to this email for support.\n\n";
        $message .= "Thank you for using $site_name!\n";
        $message .= "🏠 Making your Airbnb listing shine ✨";
    }
    
    // Send email
    wp_mail($email, $subject, $message);
}

// Only handle the request if this file was called directly (not included from admin)
if (!defined('AIRBNB_ANALYZER_ADMIN_CONTEXT')) {
    handle_brightdata_notification();
}