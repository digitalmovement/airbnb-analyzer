<?php
/**
 * Test Email Functionality
 * This file can be used to test if email sending is working properly
 */

// Include WordPress
require_once('../../../wp-config.php');

// Test basic email sending
function test_basic_email() {
    $subject = 'Test Email - ' . get_bloginfo('name');
    $message = "This is a test email to verify that email functionality is working correctly.\n\n";
    $message .= "Sent at: " . date('Y-m-d H:i:s') . "\n";
    $message .= "From: " . get_bloginfo('name');
    
    $headers = array(
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        'Content-Type: text/plain; charset=UTF-8'
    );
    
    $result = wp_mail(get_option('admin_email'), $subject, $message, $headers);
    
    if ($result) {
        echo "✅ Email sent successfully to " . get_option('admin_email') . "\n";
    } else {
        echo "❌ Email failed to send\n";
    }
    
    return $result;
}

// Test expert analysis email
function test_expert_analysis_email() {
    // Mock analysis data
    $analysis_data = array(
        'content' => "# Test Expert Analysis\n\nThis is a test of the expert analysis email functionality.\n\n## Key Recommendations\n\n- Improve your listing title\n- Add more photos\n- Update pricing strategy\n\n**Generated at:** " . date('Y-m-d H:i:s'),
        'generated_at' => current_time('mysql'),
        'model_used' => 'Claude 3.5 Sonnet (Test)',
        'output_tokens' => 1500,
        'batch_processing' => true
    );
    
    $result = airbnb_analyzer_send_expert_analysis_email(
        get_option('admin_email'),
        'https://www.airbnb.com/rooms/test123',
        $analysis_data,
        null,
        'test_snapshot_id_' . time()
    );
    
    if ($result) {
        echo "✅ Expert analysis email sent successfully\n";
    } else {
        echo "❌ Expert analysis email failed to send\n";
    }
    
    return $result;
}

// Run tests if accessed via browser
if (isset($_GET['test'])) {
    header('Content-Type: text/plain');
    
    echo "=== Email Functionality Test ===\n\n";
    
    echo "WordPress Admin Email: " . get_option('admin_email') . "\n";
    echo "Site Name: " . get_bloginfo('name') . "\n\n";
    
    echo "1. Testing basic email functionality...\n";
    test_basic_email();
    
    echo "\n2. Testing expert analysis email...\n";
    test_expert_analysis_email();
    
    echo "\n=== Test Complete ===\n";
    echo "Check your email inbox for test messages.\n";
} else {
 