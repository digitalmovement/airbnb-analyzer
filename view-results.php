<?php
/**
 * Results Display Page for AirBnB Listing Analyzer
 */

// Check if we're being called via shortcode
$is_shortcode_mode = defined('AIRBNB_ANALYZER_SHORTCODE_MODE');

if (!$is_shortcode_mode) {
    // Load WordPress only when not in shortcode mode (shortcode already has WordPress loaded)
    require_once('../../../wp-config.php');
    require_once(AIRBNB_ANALYZER_PATH . 'includes/brightdata-api.php');
} else {
    // In shortcode mode, make sure we have the brightdata-api functions
    if (!function_exists('brightdata_get_request')) {
        require_once(plugin_dir_path(__FILE__) . 'brightdata-api.php');
    }
}

// Get snapshot ID
$snapshot_id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

if (empty($snapshot_id)) {
    if ($is_shortcode_mode) {
        echo '<div class="airbnb-analyzer-error">Invalid request. No analysis ID provided.</div>';
        return;
    } else {
        wp_die('Invalid request. No analysis ID provided.');
    }
}

// Get request data
$request = brightdata_get_request($snapshot_id);
if (!$request || $request->status !== 'completed') {
    if ($is_shortcode_mode) {
        echo '<div class="airbnb-analyzer-error">Analysis not found or not completed.</div>';
        return;
    } else {
        wp_die('Analysis not found or not completed.');
    }
}

// Get analysis data - it's stored as JSON, not serialized
$response_data = json_decode($request->response_data, true);
$listing_data = isset($response_data['listing_data']) ? $response_data['listing_data'] : null;
$analysis = isset($response_data['analysis']) ? $response_data['analysis'] : null;

// Debug: Check if we have data
if (empty($response_data)) {
    $error_msg = 'No analysis data found. Response data: ' . esc_html($request->response_data);
    if ($is_shortcode_mode) {
        echo '<div class="airbnb-analyzer-error">' . $error_msg . '</div>';
        return;
    } else {
        wp_die($error_msg);
    }
}

if (empty($listing_data)) {
    $error_msg = 'No listing data found. Available keys: ' . esc_html(implode(', ', array_keys($response_data ?: [])));
    if ($is_shortcode_mode) {
        echo '<div class="airbnb-analyzer-error">' . $error_msg . '</div>';
        return;
    } else {
        wp_die($error_msg);
    }
}

// Track view
global $wpdb;
$table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests'; 
$wpdb->query($wpdb->prepare("UPDATE $table_name SET views = COALESCE(views, 0) + 1, last_viewed = NOW() WHERE snapshot_id = %s", $snapshot_id));

// If in shortcode mode, only output the content, not the full HTML structure
if (!$is_shortcode_mode): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Airbnb Analysis Results</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; background: white; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #FF5A5F 0%, #FF3B41 100%); color: white; padding: 40px 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 2.5em; font-weight: 300; }
        .content { padding: 30px; }
        .listing-info { margin-bottom: 30px; padding: 25px; background: #f8f9fa; border-radius: 12px; }
        .overall-score { background: #e8f5e8; padding: 25px; border-radius: 12px; margin-bottom: 30px; text-align: center; }
        .score-circle { display: inline-block; width: 80px; height: 80px; border-radius: 50%; background: #4CAF50; color: white; line-height: 80px; font-size: 24px; font-weight: bold; margin-right: 20px; }
        .score-circle.low { background: #f44336; }
        .score-circle.medium { background: #ff9800; }
        
        .navigation-index { background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 30px; }
        .navigation-index h3 { margin: 0 0 15px 0; color: #333; }
        .nav-links { display: flex; flex-wrap: wrap; gap: 10px; }
        .nav-link { background: #007cba; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-size: 14px; transition: background 0.3s; }
        .nav-link:hover { background: #005a87; color: white; }
        
        .analysis-item { margin: 40px 0; padding: 25px; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .analysis-item h3 { margin: 0 0 20px 0; color: #333; font-size: 1.4em; padding-bottom: 10px; border-bottom: 2px solid #eee; }
        
        .content-preview { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #007cba; }
        .content-preview h4 { margin: 0 0 10px 0; color: #555; font-size: 1em; }
        .photos-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 15px 0; }
        .photo-item { border-radius: 8px; overflow: hidden; }
        .photo-item img { width: 100%; height: 120px; object-fit: cover; }
        
        .rating-badge { display: inline-block; color: white; padding: 6px 12px; border-radius: 20px; font-weight: bold; margin: 10px 0; font-size: 14px; }
        .rating-badge.poor { background: #f44336; }
        .rating-badge.error { background: #f44336; }
        .rating-badge.warning { background: #ff9800; }
        .rating-badge.average { background: #ff9800; }
        .rating-badge.good { background: #4CAF50; }
        .rating-badge.success { background: #4CAF50; }
        .rating-badge.excellent { background: #2e7d32; }
        
        .recommendations { margin-top: 20px; }
        .recommendations h4 { margin: 0 0 10px 0; color: #333; }
        .suggestions { list-style: none; padding: 0; margin: 0; }
        .suggestions li { background: #e3f2fd; padding: 12px 15px; margin: 8px 0; border-radius: 6px; border-left: 3px solid #2196f3; }
        .suggestions li:before { content: "💡 "; margin-right: 8px; }
        
        .footer { background: #333; color: white; text-align: center; padding: 30px; margin-top: 40px; }
        .btn { display: inline-block; background: #FF5A5F; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; }
        
        /* Smooth scrolling */
        html { scroll-behavior: smooth; }
        
        /* Section anchor offset for fixed navigation */
        .analysis-item[id] { scroll-margin-top: 20px; }
        
        /* Error styling */
        .airbnb-analyzer-error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border: 1px solid #f5c6cb; }
        
        /* Expert Analysis Styles */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .expert-analysis-button:hover {
            transform: translateY(-2px);
        }
        
        #expert-analysis-content h1,
        #expert-analysis-content h2,
        #expert-analysis-content h3,
        #expert-analysis-content h4 {
            color: #2c5aa0;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        #expert-analysis-content ul,
        #expert-analysis-content ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        #expert-analysis-content li {
            margin-bottom: 5px;
        }
        
        #expert-analysis-content strong {
            color: #333;
        }
        
        #expert-analysis-content pre {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border-left: 4px solid #667eea;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🏠 Airbnb Analysis Results</h1>
        <p>Comprehensive listing optimization report</p>
    </div>

    <div class="content">
<?php else: ?>
<!-- Shortcode mode: Add inline styles and start content directly -->
<style>
    .airbnb-analyzer-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; }
    .airbnb-analyzer-container .listing-info { margin-bottom: 30px; padding: 25px; background: #f8f9fa; border-radius: 12px; }
    .airbnb-analyzer-container .overall-score { background: #e8f5e8; padding: 25px; border-radius: 12px; margin-bottom: 30px; text-align: center; }
    .airbnb-analyzer-container .score-circle { display: inline-block; width: 80px; height: 80px; border-radius: 50%; background: #4CAF50; color: white; line-height: 80px; font-size: 24px; font-weight: bold; margin-right: 20px; }
    .airbnb-analyzer-container .score-circle.low { background: #f44336; }
    .airbnb-analyzer-container .score-circle.medium { background: #ff9800; }
    .airbnb-analyzer-container .navigation-index { background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 30px; }
    .airbnb-analyzer-container .navigation-index h3 { margin: 0 0 15px 0; color: #333; }
    .airbnb-analyzer-container .nav-links { display: flex; flex-wrap: wrap; gap: 10px; }
    .airbnb-analyzer-container .nav-link { background: #007cba; color: white; padding: 8px 16px; text-decoration: none; border-radius: 6px; font-size: 14px; transition: background 0.3s; }
    .airbnb-analyzer-container .nav-link:hover { background: #005a87; color: white; }
    .airbnb-analyzer-container .analysis-item { margin: 40px 0; padding: 25px; background: white; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
    .airbnb-analyzer-container .analysis-item h3 { margin: 0 0 20px 0; color: #333; font-size: 1.4em; padding-bottom: 10px; border-bottom: 2px solid #eee; }
    .airbnb-analyzer-container .content-preview { background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #007cba; }
    .airbnb-analyzer-container .content-preview h4 { margin: 0 0 10px 0; color: #555; font-size: 1em; }
    .airbnb-analyzer-container .photos-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 15px 0; }
    .airbnb-analyzer-container .photo-item { border-radius: 8px; overflow: hidden; }
    .airbnb-analyzer-container .photo-item img { width: 100%; height: 120px; object-fit: cover; }
    .airbnb-analyzer-container .rating-badge { display: inline-block; color: white; padding: 6px 12px; border-radius: 20px; font-weight: bold; margin: 10px 0; font-size: 14px; }
    .airbnb-analyzer-container .rating-badge.poor { background: #f44336; }
    .airbnb-analyzer-container .rating-badge.error { background: #f44336; }
    .airbnb-analyzer-container .rating-badge.warning { background: #ff9800; }
    .airbnb-analyzer-container .rating-badge.average { background: #ff9800; }
    .airbnb-analyzer-container .rating-badge.good { background: #4CAF50; }
    .airbnb-analyzer-container .rating-badge.success { background: #4CAF50; }
    .airbnb-analyzer-container .rating-badge.excellent { background: #2e7d32; }
    .airbnb-analyzer-container .recommendations { margin-top: 20px; }
    .airbnb-analyzer-container .recommendations h4 { margin: 0 0 10px 0; color: #333; }
    .airbnb-analyzer-container .suggestions { list-style: none; padding: 0; margin: 0; }
    .airbnb-analyzer-container .suggestions li { background: #e3f2fd; padding: 12px 15px; margin: 8px 0; border-radius: 6px; border-left: 3px solid #2196f3; }
    .airbnb-analyzer-container .suggestions li:before { content: "💡 "; margin-right: 8px; }
    .airbnb-analyzer-container .airbnb-analyzer-error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; border: 1px solid #f5c6cb; }
    /* Smooth scrolling */
    html { scroll-behavior: smooth; }
    /* Section anchor offset for fixed navigation */
    .airbnb-analyzer-container .analysis-item[id] { scroll-margin-top: 20px; }
    
    /* Expert Analysis Styles */
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .airbnb-analyzer-container .expert-analysis-button:hover {
        transform: translateY(-2px);
    }
    
    .airbnb-analyzer-container #expert-analysis-content h1,
    .airbnb-analyzer-container #expert-analysis-content h2,
    .airbnb-analyzer-container #expert-analysis-content h3,
    .airbnb-analyzer-container #expert-analysis-content h4 {
        color: #2c5aa0;
        margin-top: 20px;
        margin-bottom: 10px;
    }
    
    .airbnb-analyzer-container #expert-analysis-content ul,
    .airbnb-analyzer-container #expert-analysis-content ol {
        margin: 10px 0;
        padding-left: 20px;
    }
    
    .airbnb-analyzer-container #expert-analysis-content li {
        margin-bottom: 5px;
    }
    
    .airbnb-analyzer-container #expert-analysis-content strong {
        color: #333;
    }
    
    .airbnb-analyzer-container #expert-analysis-content pre {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        overflow-x: auto;
        border-left: 4px solid #667eea;
    }
</style>

<div class="airbnb-analyzer-container">
    <div style="background: linear-gradient(135deg, #FF5A5F 0%, #FF3B41 100%); color: white; padding: 40px 30px; text-align: center; border-radius: 12px; margin-bottom: 30px;">
        <h1 style="margin: 0; font-size: 2.5em; font-weight: 300;">🏠 Airbnb Analysis Results</h1>
        <p style="margin: 10px 0 0 0;">Comprehensive listing optimization report</p>
    </div>
<?php endif; ?>

        <div class="listing-info">
            <h2><?php echo esc_html($listing_data['listing_title'] ?? $listing_data['name'] ?? $listing_data['title'] ?? 'Unknown Listing'); ?></h2>
            <p><strong>URL:</strong> <a href="<?php echo esc_url($request->listing_url); ?>" target="_blank"><?php echo esc_html($request->listing_url); ?></a></p>
            <?php if (!empty($listing_data['photos'][0])): ?>
            <img src="<?php echo esc_url($listing_data['photos'][0]); ?>" style="max-width: 100%; height: 300px; object-fit: cover; border-radius: 8px;">
            <?php endif; ?>
        </div>

        <?php if (isset($analysis['score'])): ?>
        <div class="overall-score">
            <?php 
            $score = intval($analysis['score']);
            $score_class = $score >= 85 ? '' : ($score >= 70 ? 'medium' : 'low');
            ?>
            <div class="score-circle <?php echo $score_class; ?>">
                <?php echo $score; ?>
            </div>
            <div style="display: inline-block; vertical-align: top;">
                <h3 style="margin: 0;">Overall Score: <?php echo $score; ?>/100</h3>
                <p style="margin: 10px 0 0 0;"><?php echo esc_html($analysis['summary'] ?? ''); ?></p>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($analysis['recommendations']) && is_array($analysis['recommendations'])): ?>
        <div class="navigation-index">
            <h3>📋 Analysis Sections</h3>
            <div class="nav-links">
                <?php foreach ($analysis['recommendations'] as $index => $section): ?>
                <?php if (is_array($section) && isset($section['category'])): ?>
                <a href="#section-<?php echo $index; ?>" class="nav-link"><?php echo esc_html($section['category']); ?></a>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <?php foreach ($analysis['recommendations'] as $index => $section): ?>
        <?php if (is_array($section) && isset($section['category'])): ?>
        <div class="analysis-item" id="section-<?php echo $index; ?>">
            <h3><?php echo esc_html($section['category']); ?></h3>
            
            <div class="rating-badge <?php echo esc_attr($section['status'] ?? 'average'); ?>">
                Score: <?php echo esc_html($section['score'] ?? 'N/A'); ?>/<?php echo esc_html($section['max_score'] ?? '10'); ?>
            </div>

            <?php
            // Show the content being analyzed
            $category_lower = strtolower($section['category'] ?? '');
            
            if (strpos($category_lower, 'title') !== false): 
                // Use only listing_title field as specified
                $title_to_show = $listing_data['title'] ?? '';
                
                if (!empty($title_to_show)): ?>
                <div class="content-preview">
                    <h4>Your Title:</h4>
                    <p><strong>"<?php echo esc_html($title_to_show); ?>"</strong></p>
                    <small>Length: <?php echo strlen($title_to_show); ?> characters</small>
                </div>
            <?php endif; ?>
            <?php elseif (strpos($category_lower, 'description') !== false): ?>
                <div class="content-preview">
                    <h4>Description Sections:</h4>
                    <?php 
                    $description_sections = $listing_data['description_by_sections'] ?? null;
                    $fallback_description = $listing_data['description'] ?? '';
                    
                    if (!empty($description_sections) && is_array($description_sections)): ?>
                        <?php foreach ($description_sections as $section): ?>
                            <?php 
                            $title = $section['title'] ?? 'Main Description';
                            $value = trim($section['value'] ?? '');
                            if (empty($value) || strlen($value) < 10) continue;
                            ?>
                            <div style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-radius: 5px;">
                                <strong><?php echo esc_html($title === 'null' || empty($title) ? 'Main Description' : $title); ?>:</strong>
                                <p style="margin: 5px 0 0 0; font-size: 0.9em;">
                                    <?php echo esc_html(strlen($value) > 150 ? substr($value, 0, 150) . '...' : $value); ?>
                                </p>
                                <small style="color: #666;"><?php echo strlen($value); ?> characters</small>
                            </div>
                        <?php endforeach; ?>
                        <small>Total sections: <?php echo count($description_sections); ?></small>
                    <?php elseif (!empty($fallback_description)): ?>
                        <p style="color: #ff9800;">⚠️ Using fallback description (sectioned data not available)</p>
                        <p><?php echo esc_html(substr($fallback_description, 0, 300)); ?><?php echo strlen($fallback_description) > 300 ? '...' : ''; ?></p>
                        <small>Length: <?php echo strlen($fallback_description); ?> characters</small>
                    <?php else: ?>
                        <p><em>No description data available</em></p>
                    <?php endif; ?>
                </div>
            <?php elseif (strpos($category_lower, 'photo') !== false): ?>
                <?php 
                $photos_to_show = $listing_data['images'] ?? $listing_data['photos'] ?? [];
                if (!empty($photos_to_show)): ?>
                <div class="content-preview">
                    <h4>Your Photos (<?php echo count($photos_to_show); ?> total):</h4>
                    <div class="photos-grid">
                        <?php $photo_count = 0; ?>
                        <?php foreach (array_slice($photos_to_show, 0, 6) as $photo): ?>
                            <div class="photo-item">
                                <img src="<?php echo esc_url($photo); ?>" alt="Listing photo">
                            </div>
                            <?php $photo_count++; ?>
                        <?php endforeach; ?>
                        <?php if (count($photos_to_show) > 6): ?>
                            <div style="grid-column: span 2; text-align: center; padding: 20px; background: #f0f0f0; border-radius: 8px; color: #666;">
                                +<?php echo count($photos_to_show) - 6; ?> more photos
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php elseif (strpos($category_lower, 'host') !== false): ?>
                <div class="content-preview">
                    <h4>Host Information:</h4>
                    <?php if (isset($listing_data['is_supperhost'])): ?>
                        <p>✨ <strong>Superhost Status:</strong> <?php echo $listing_data['is_supperhost'] ? 'Yes' : 'No'; ?></p>
                    <?php endif; ?>
                    <?php if (!empty($listing_data['host_response_rate'])): ?>
                        <p>📞 <strong>Response Rate:</strong> <?php echo esc_html($listing_data['host_response_rate']); ?>%</p>
                    <?php endif; ?>
                    <?php if (!empty($listing_data['hosts_year'])): ?>
                        <p>📅 <strong>Hosting Experience:</strong> <?php echo esc_html($listing_data['hosts_year']); ?> years</p>
                    <?php endif; ?>
                    <?php if (!empty($listing_data['host_rating'])): ?>
                        <p>⭐ <strong>Host Rating:</strong> <?php echo esc_html($listing_data['host_rating']); ?>/5</p>
                    <?php endif; ?>
                    <?php if (isset($listing_data['is_guest_favorite'])): ?>
                        <p>💖 <strong>Guest Favorite:</strong> <?php echo $listing_data['is_guest_favorite'] ? 'Yes' : 'No'; ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif (strpos($category_lower, 'review') !== false): ?>
                <div class="content-preview">
                    <h4>Reviews Summary:</h4>
                    <p>⭐ <strong>Overall Rating:</strong> <?php echo esc_html($listing_data['ratings'] ?? $listing_data['rating'] ?? 'N/A'); ?>/5</p>
                    <p>📝 <strong>Review Count:</strong> <?php echo esc_html($listing_data['property_number_of_reviews'] ?? $listing_data['review_count'] ?? 'N/A'); ?> reviews</p>
                    <?php if (isset($listing_data['is_guest_favorite'])): ?>
                        <p>💖 <strong>Guest Favorite:</strong> <?php echo $listing_data['is_guest_favorite'] ? 'Yes' : 'No'; ?></p>
                    <?php endif; ?>
                    
                    <?php if (!empty($listing_data['property_rating_details']) && is_array($listing_data['property_rating_details'])): ?>
                        <h5>Category Ratings:</h5>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px; font-size: 0.9em;">
                            <?php foreach ($listing_data['property_rating_details'] as $category): ?>
                                <?php 
                                $rating_value = floatval($category['value']);
                                $is_value_category = strtolower($category['name']) === 'value';
                                $is_good = $is_value_category ? ($rating_value >= 4.5) : ($rating_value >= 4.9);
                                $color = $is_good ? '#28a745' : ($rating_value >= 4.5 ? '#ffc107' : '#dc3545');
                                ?>
                                <div style="display: flex; justify-content: space-between;">
                                    <span><?php echo esc_html($category['name']); ?>:</span>
                                    <span style="color: <?php echo $color; ?>; font-weight: bold;"><?php echo esc_html($rating_value); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small style="color: #666; margin-top: 10px; display: block;">
                            🎯 Target: 4.9+ for all categories (Value can be 4.5+)
                        </small>
                    <?php else: ?>
                        <p><em>Category ratings not available</em></p>
                        <?php if (current_user_can('manage_options')): ?>
                            <small style="color: #ff9800;">⚠️ Debug: property_rating_details field is empty - regenerate report for category analysis</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php elseif (strpos($category_lower, 'amenities') !== false || strpos($category_lower, 'climate') !== false || strpos($category_lower, 'safety') !== false || strpos($category_lower, 'bathroom') !== false || strpos($category_lower, 'bedroom') !== false || strpos($category_lower, 'entertainment') !== false || strpos($category_lower, 'family') !== false || strpos($category_lower, 'internet') !== false || strpos($category_lower, 'kitchen') !== false || strpos($category_lower, 'parking') !== false || strpos($category_lower, 'services') !== false): ?>
                <div class="content-preview">
                    <?php 
                    // Determine which amenity group to show based on category
                    $target_group = '';
                    if (strpos($category_lower, 'bathroom') !== false) {
                        $target_group = 'bathroom';
                    } elseif (strpos($category_lower, 'bedroom') !== false || strpos($category_lower, 'laundry') !== false) {
                        $target_group = 'bedroom and laundry';
                    } elseif (strpos($category_lower, 'entertainment') !== false) {
                        $target_group = 'entertainment';
                    } elseif (strpos($category_lower, 'family') !== false) {
                        $target_group = 'family';
                    } elseif (strpos($category_lower, 'climate') !== false || strpos($category_lower, 'heating') !== false || strpos($category_lower, 'cooling') !== false) {
                        $target_group = 'heating and cooling';
                    } elseif (strpos($category_lower, 'safety') !== false) {
                        $target_group = 'home safety';
                    } elseif (strpos($category_lower, 'internet') !== false || strpos($category_lower, 'office') !== false) {
                        $target_group = 'internet and office';
                    } elseif (strpos($category_lower, 'kitchen') !== false || strpos($category_lower, 'dining') !== false) {
                        $target_group = 'kitchen and dining';
                    } elseif (strpos($category_lower, 'parking') !== false || strpos($category_lower, 'facilities') !== false) {
                        $target_group = 'parking and facilities';
                    } elseif (strpos($category_lower, 'guest services') !== false || strpos($category_lower, 'services') !== false) {
                        $target_group = 'services';
                    }
                    
                    $amenity_list = [];
                    
                    // Parse amenities: handle both nested (group->items) and flat arrays
                    if (!empty($listing_data['amenities']) && is_array($listing_data['amenities'])) {
                        // Detect if first element has 'items' key (nested format)
                        $first_element = $listing_data['amenities'][0] ?? null;
                        if (is_array($first_element) && isset($first_element['items'])) {
                            // Nested format - look for specific group
                            foreach ($listing_data['amenities'] as $amenity_group) {
                                if (isset($amenity_group['items']) && is_array($amenity_group['items']) && isset($amenity_group['group_name'])) {
                                    // If we have a target group, only show that group's amenities
                                    if ($target_group && strtolower($amenity_group['group_name']) === $target_group) {
                                        foreach ($amenity_group['items'] as $item) {
                                            if (isset($item['name']) && !empty($item['name'])) {
                                                $amenity_list[] = $item['name'];
                                            }
                                        }
                                        break; // Found our target group, no need to continue
                                    } elseif (!$target_group) {
                                        // Show all amenities if no specific group
                                        foreach ($amenity_group['items'] as $item) {
                                            if (isset($item['name']) && !empty($item['name'])) {
                                                $amenity_list[] = $item['name'];
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // Flat format: each element is either string or array with 'name'
                            // For flat format, we can't filter by group, so show all amenities
                            foreach ($listing_data['amenities'] as $amenity) {
                                if (is_string($amenity)) {
                                    $amenity_list[] = $amenity;
                                } elseif (is_array($amenity) && isset($amenity['name'])) {
                                    $amenity_list[] = $amenity['name'];
                                }
                            }
                            
                            // Show a warning for flat format when a specific group is requested
                            if ($target_group && current_user_can('manage_options')) {
                                echo '<small style="color:#ff9800;">⚠️ This report uses old flat amenity format - regenerate for proper categorization</small><br>';
                            }
                        }
                    }
                    
                    if ($target_group) {
                        echo '<h4>' . ucwords($target_group) . ' Amenities:</h4>';
                    } else {
                        echo '<h4>Available Amenities:</h4>';
                    }
                    
                    if (!empty($amenity_list)): ?>
                        <div style="max-height: 150px; overflow-y: auto;">
                            <p><?php echo esc_html(implode(' • ', array_slice($amenity_list, 0, 20))); ?><?php echo count($amenity_list) > 20 ? ' • ...' : ''; ?></p>
                            <small><?php echo count($amenity_list); ?> amenities in this category</small>
                        </div>
                    <?php else: ?>
                        <p><em>No amenities found in this category</em></p>
                        <?php if ($target_group): ?>
                            <small>Looking for amenities in: "<?php echo esc_html($target_group); ?>" group</small>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p><strong><?php echo esc_html($section['message'] ?? ''); ?></strong></p>
            
            <?php if (isset($section['recommendations']) && is_array($section['recommendations']) && !empty($section['recommendations'])): ?>
            <div class="recommendations">
                <h4>💡 Recommendations:</h4>
                <ul class="suggestions">
                    <?php foreach ($section['recommendations'] as $rec): ?>
                    <li><?php echo esc_html($rec); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>

    <?php // ================== EXPERT ANALYSIS SECTION ================== ?>
    <div class="expert-analysis-section" style="margin: 40px 0; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; text-align: center; color: white;">
        <h2 style="color: white; margin-bottom: 15px;">🎯 Get Expert Analysis</h2>
        <p style="font-size: 1.1em; margin-bottom: 20px; opacity: 0.9;">
            Unlock comprehensive AI-powered insights with detailed optimization recommendations, 
            SEO strategies, and ready-to-use content improvements.
        </p>
        
        <button id="expert-analysis-btn" class="expert-analysis-button" style="
            background: white; 
            color: #667eea; 
            border: none; 
            padding: 15px 30px; 
            font-size: 1.1em; 
            font-weight: bold; 
            border-radius: 25px; 
            cursor: pointer; 
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 20px rgba(0,0,0,0.3)'" 
           onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 15px rgba(0,0,0,0.2)'">
            🚀 Generate Expert Analysis
        </button>
        
        <!-- Loading State -->
        <div id="expert-analysis-loading" style="display: none; margin-top: 20px;">
            <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid rgba(255,255,255,0.3); border-top: 4px solid white; border-radius: 50%; animation: spin 1s linear infinite;"></div>
            <p style="margin-top: 15px; font-size: 1.1em;">
                Analyzing your listing with AI...<br>
                <small style="opacity: 0.8;">This may take 1-3 minutes for comprehensive analysis</small>
            </p>
        </div>
        
        <!-- Error State -->
        <div id="expert-analysis-error" style="display: none; margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 8px;">
            <p id="expert-analysis-error-message" style="color: #ffcccb; margin: 0;"></p>
            <button id="expert-analysis-retry" style="margin-top: 10px; background: transparent; border: 2px solid white; color: white; padding: 8px 16px; border-radius: 20px; cursor: pointer;">
                Try Again
            </button>
        </div>
    </div>
    
    <!-- Expert Analysis Results -->
    <div id="expert-analysis-results" style="display: none; margin: 40px 0; padding: 30px; background: #f8f9fa; border-radius: 12px; border-left: 5px solid #667eea;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap;">
            <h2 style="color: #333; margin: 0;">🎯 Expert Analysis Results</h2>
            <span id="expert-analysis-badge" style="background: #28a745; color: white; padding: 5px 12px; border-radius: 15px; font-size: 0.9em; font-weight: bold;">
                ✨ AI Generated
            </span>
        </div>
        <div id="expert-analysis-content" style="
            background: white; 
            padding: 25px; 
            border-radius: 8px; 
            line-height: 1.6; 
            color: #333;
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
        "></div>
        <div style="margin-top: 15px; padding: 15px; background: #e3f2fd; border-radius: 8px; border-left: 4px solid #2196f3;">
            <small style="color: #666;">
                <strong>Generated:</strong> <span id="expert-analysis-timestamp"></span> | 
                <strong>Model:</strong> <span id="expert-analysis-model"></span> |
                <span id="expert-analysis-cached-indicator"></span>
            </small>
        </div>
    </div>

    <?php // ================== ADMIN DEBUG PANEL ================== ?>
    <?php if ( current_user_can( 'manage_options' ) ) : ?>
        <?php 
        // Handle cache clear & regenerate action
        if ( isset( $_GET['rebuild'] ) && $_GET['rebuild'] === '1' ) {
            // Soft-reset the BrightData entry so a cron/async job can rebuild it
            $wpdb->update(
                $table_name,
                array(
                    'status'        => 'pending',
                    'response_data' => null,
                    'date_completed'=> null,
                ),
                array( 'snapshot_id' => $snapshot_id ),
                array( '%s', '%s', '%s' ),
                array( '%s' )
            );
            echo '<div style="margin:20px 0; padding:15px; border-left:4px solid #ff9800; background:#fff3e0;">⚠️ Cache cleared for this snapshot. It is now marked as <strong>pending</strong> and will be regenerated on the next analyzer run.</div>';
        }
        
        // Handle expert analysis cache clear
        if ( isset( $_GET['clear_expert'] ) && $_GET['clear_expert'] === '1' ) {
            $wpdb->update(
                $table_name,
                array(
                    'expert_analysis_data' => null,
                ),
                array( 'snapshot_id' => $snapshot_id ),
                array( '%s' ),
                array( '%s' )
            );
            echo '<div style="margin:20px 0; padding:15px; border-left:4px solid #667eea; background:#f0f8ff;">🎯 Expert analysis cache cleared for this snapshot. Next expert analysis request will generate fresh results.</div>';
        }
        ?>
        <div style="margin:40px 0; padding:25px; border-radius:12px; background:#f1f1f1;">
            <h2 style="margin-top:0;">🔧 Admin Debug Panel</h2>
            <p>You are seeing this because you have administrator capabilities (<code>manage_options</code>).</p>
            <p>
                <a href="<?php echo esc_url( add_query_arg( 'rebuild', '1', remove_query_arg('clear_expert') ) ); ?>" class="button button-danger" style="background:#d63638;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;">⟳ Clear Cache &amp; Regenerate Report</a>
                
                <a href="<?php echo esc_url( add_query_arg( 'clear_expert', '1', remove_query_arg('rebuild') ) ); ?>" class="button" style="background:#667eea;color:#fff;padding:10px 16px;border-radius:4px;text-decoration:none;margin-left:10px;">🎯 Clear Expert Analysis Cache</a>
                
                <?php if ($request->status === 'pending'): ?>
                    <br><br>
                    <strong style="color: #ff9800;">⚠️ This report is currently pending processing.</strong><br>
                    <small>Go to <a href="<?php echo admin_url('admin.php?page=airbnb-analyzer-stats'); ?>">Admin → Statistics</a> to process all pending requests, or wait for automatic processing.</small>
                <?php endif; ?>
            </p>

            <?php
            // Show expert analysis status
            $expert_analysis_data = json_decode($request->expert_analysis_data, true);
            if (!empty($expert_analysis_data)) {
                echo '<div style="margin: 15px 0; padding: 10px; background: #e3f2fd; border-left: 4px solid #2196f3; border-radius: 4px;">';
                echo '<strong>🎯 Expert Analysis Status:</strong> Cached (' . date('M j, Y g:i A', strtotime($expert_analysis_data['generated_at'])) . ')';
                echo '<br><small>Model: ' . esc_html($expert_analysis_data['model_used'] ?? 'Unknown') . ' | Requests: ' . intval($request->expert_analysis_requested) . '</small>';
                echo '</div>';
            } else {
                echo '<div style="margin: 15px 0; padding: 10px; background: #fff3e0; border-left: 4px solid #ff9800; border-radius: 4px;">';
                echo '<strong>🎯 Expert Analysis Status:</strong> Not requested yet';
                if ($request->expert_analysis_requested > 0) {
                    echo ' (cache cleared, ' . intval($request->expert_analysis_requested) . ' previous requests)';
                }
                echo '</div>';
            }
            ?>

            <details style="margin-top:20px;">
                <summary style="cursor:pointer; font-weight:bold;">🎯 View Expert Analysis Prompt (Used for AI Analysis)</summary>
                <pre style="max-height:400px; overflow:auto; background:#000; color:#00ff00; padding:15px; font-size:11px; line-height:1.4; white-space: pre-wrap;">
<?php 
if (function_exists('get_expert_analysis_prompt')) {
    echo esc_html(get_expert_analysis_prompt());
} else {
    echo "Expert analysis prompt function not available.\nThis prompt is used when users click the 'Generate Expert Analysis' button on the results page.";
}
?>
                </pre>
            </details>

            <details style="margin-top:20px;">
                <summary style="cursor:pointer; font-weight:bold;">🔍 View Raw BrightData Response (All Original Fields)</summary>
                <pre style="max-height:400px; overflow:auto; background:#000; color:#0f0; padding:15px; font-size:11px; line-height:1.4;">
<?php 
if (!empty($request->raw_response_data)) {
    echo esc_html(json_encode(json_decode($request->raw_response_data, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} else {
    echo "Raw BrightData response not available for this request.\nThis feature was added later, so only new requests will have raw data.\n\nTo see raw data, submit a new analysis request.";
}
?>
                </pre>
            </details>

            <details style="margin-top:20px;">
                <summary style="cursor:pointer; font-weight:bold;">⚙️ View Processed Response Data (Filtered for Analyzer)</summary>
                <pre style="max-height:400px; overflow:auto; background:#000; color:#0f0; padding:15px; font-size:11px; line-height:1.4;">
<?php echo esc_html( json_encode( $response_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?>
                </pre>
            </details>

            <details style="margin-top:20px;">
                <summary style="cursor:pointer; font-weight:bold;">🗂️ View Parsed Analysis Array (After PHP Processing)</summary>
                <pre style="max-height:400px; overflow:auto; background:#000; color:#0ff; padding:15px; font-size:11px; line-height:1.4;">
<?php echo esc_html( print_r( $analysis, true ) ); ?>
                </pre>
            </details>
            
            <?php if (!empty($expert_analysis_data)): ?>
            <details style="margin-top:20px;">
                <summary style="cursor:pointer; font-weight:bold;">🎯 View Expert Analysis Data (AI Generated Content)</summary>
                <pre style="max-height:400px; overflow:auto; background:#000; color:#ffff00; padding:15px; font-size:11px; line-height:1.4; white-space: pre-wrap;">
<?php echo esc_html($expert_analysis_data['content'] ?? 'No content available'); ?>
                </pre>
                <div style="margin-top: 10px; padding: 10px; background: #e3f2fd; border-radius: 4px;">
                    <small>
                        <strong>Generated:</strong> <?php echo date('F j, Y g:i A', strtotime($expert_analysis_data['generated_at'])); ?><br>
                        <strong>Model:</strong> <?php echo esc_html($expert_analysis_data['model_used'] ?? 'Unknown'); ?><br>
                        <strong>Total Requests:</strong> <?php echo intval($request->expert_analysis_requested); ?>
                    </small>
                </div>
            </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        <p>Analysis completed: <?php echo date('F j, Y', strtotime($request->date_created)); ?></p>
        <p>Reference: <?php echo esc_html($snapshot_id); ?></p>
        <a href="<?php echo home_url(); ?>" class="btn">← Back to Site</a>
    </div>
</div>

<?php if (!$is_shortcode_mode): ?>
<!-- Load jQuery and JavaScript for expert analysis -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script type="text/javascript">
    var airbnb_analyzer_ajax = {
        ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('airbnb_analyzer_nonce'); ?>'
    };
</script>
<script>
// Expert Analysis functionality for results page
jQuery(document).ready(function($) {
    // Handle expert analysis button click
    $('#expert-analysis-btn').on('click', function() {
        requestExpertAnalysis();
    });
    
    // Handle retry button click
    $('#expert-analysis-retry').on('click', function() {
        requestExpertAnalysis();
    });
    
    function requestExpertAnalysis() {
        // Get snapshot ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        const snapshotId = urlParams.get('id');
        
        if (!snapshotId) {
            showError('Unable to identify the analysis. Please refresh the page and try again.');
            return;
        }
        
        // Show loading state
        $('#expert-analysis-btn').hide();
        $('#expert-analysis-error').hide();
        $('#expert-analysis-results').hide();
        $('#expert-analysis-loading').show();
        
        // Make AJAX request
        $.ajax({
            url: airbnb_analyzer_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'expert_analysis_airbnb',
                nonce: airbnb_analyzer_ajax.nonce,
                snapshot_id: snapshotId
            },
            timeout: 180000, // 3 minutes timeout for AI processing (increased)
            success: function(response) {
                $('#expert-analysis-loading').hide();
                
                if (response.success) {
                    displayExpertAnalysis(response.data);
                } else {
                    showError(response.data.message || 'Expert analysis failed. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                $('#expert-analysis-loading').hide();
                
                if (status === 'timeout') {
                    showError('The analysis is taking longer than expected (over 3 minutes). This can happen with very complex listings. Please try again, or contact support if the issue persists.');
                } else {
                    showError('Network error occurred. Please check your connection and try again.');
                }
            }
        });
    }
    
    function displayExpertAnalysis(data) {
        const analysis = data.analysis;
        const isCached = data.cached;
        
        // Format the content for better display
        let formattedContent = analysis.content;
        
        // Convert markdown-style headers to HTML
        formattedContent = formattedContent.replace(/^### (.*$)/gm, '<h3>$1</h3>');
        formattedContent = formattedContent.replace(/^## (.*$)/gm, '<h2>$1</h2>');
        formattedContent = formattedContent.replace(/^# (.*$)/gm, '<h1>$1</h1>');
        
        // Convert **bold** to <strong>
        formattedContent = formattedContent.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        // Convert bullet points
        formattedContent = formattedContent.replace(/^- (.*$)/gm, '<li>$1</li>');
        formattedContent = formattedContent.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
        
        // Convert line breaks to paragraphs
        formattedContent = formattedContent.replace(/\n\n/g, '</p><p>');
        formattedContent = '<p>' + formattedContent + '</p>';
        
        // Clean up empty paragraphs
        formattedContent = formattedContent.replace(/<p><\/p>/g, '');
        formattedContent = formattedContent.replace(/<p>\s*<h/g, '<h');
        formattedContent = formattedContent.replace(/<\/h([1-6])>\s*<\/p>/g, '</h$1>');
        formattedContent = formattedContent.replace(/<p>\s*<ul>/g, '<ul>');
        formattedContent = formattedContent.replace(/<\/ul>\s*<\/p>/g, '</ul>');
        
        // Display the results
        $('#expert-analysis-content').html(formattedContent);
        $('#expert-analysis-timestamp').text(formatTimestamp(analysis.generated_at));
        $('#expert-analysis-model').text(analysis.model_used || 'Claude AI');
        
        if (isCached) {
            $('#expert-analysis-badge').html('📋 Cached Result');
            $('#expert-analysis-cached-indicator').html('<strong>Source:</strong> Cached (previously generated)');
        } else {
            $('#expert-analysis-badge').html('✨ Freshly Generated');
            $('#expert-analysis-cached-indicator').html('<strong>Source:</strong> Just generated');
        }
        
        $('#expert-analysis-results').show();
        
        // Scroll to results
        $('html, body').animate({
            scrollTop: $('#expert-analysis-results').offset().top - 20
        }, 500);
    }
    
    function showError(message) {
        $('#expert-analysis-error-message').text(message);
        $('#expert-analysis-error').show();
        $('#expert-analysis-btn').show();
    }
    
    function formatTimestamp(timestamp) {
        if (!timestamp) return 'Unknown';
        
        const date = new Date(timestamp);
        return date.toLocaleString();
    }
});
</script>
</body>
</html>
<?php else: ?>
<!-- Shortcode mode: Add JavaScript for WordPress with jQuery already loaded -->
<script type="text/javascript">
    // Ensure AJAX variables are available in shortcode mode
    if (typeof airbnb_analyzer_ajax === 'undefined') {
        var airbnb_analyzer_ajax = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('airbnb_analyzer_nonce'); ?>'
        };
    }

    // Expert Analysis functionality for shortcode mode
    jQuery(document).ready(function($) {
        // Handle expert analysis button click
        $('#expert-analysis-btn').on('click', function() {
            requestExpertAnalysis();
        });
        
        // Handle retry button click
        $('#expert-analysis-retry').on('click', function() {
            requestExpertAnalysis();
        });
        
        function requestExpertAnalysis() {
            // Get snapshot ID from URL
            const urlParams = new URLSearchParams(window.location.search);
            const snapshotId = urlParams.get('id');
            
            if (!snapshotId) {
                showError('Unable to identify the analysis. Please refresh the page and try again.');
                return;
            }
            
            // Show loading state
            $('#expert-analysis-btn').hide();
            $('#expert-analysis-error').hide();
            $('#expert-analysis-results').hide();
            $('#expert-analysis-loading').show();
            
            // Make AJAX request
            $.ajax({
                url: airbnb_analyzer_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'expert_analysis_airbnb',
                    nonce: airbnb_analyzer_ajax.nonce,
                    snapshot_id: snapshotId
                },
                timeout: 180000, // 3 minutes timeout for AI processing (increased)
                success: function(response) {
                    $('#expert-analysis-loading').hide();
                    
                    if (response.success) {
                        displayExpertAnalysis(response.data);
                    } else {
                        showError(response.data.message || 'Expert analysis failed. Please try again.');
                    }
                },
                error: function(xhr, status, error) {
                    $('#expert-analysis-loading').hide();
                    
                    if (status === 'timeout') {
                        showError('The analysis is taking longer than expected (over 3 minutes). This can happen with very complex listings. Please try again, or contact support if the issue persists.');
                    } else {
                        showError('Network error occurred. Please check your connection and try again.');
                    }
                }
            });
        }
        
        function displayExpertAnalysis(data) {
            const analysis = data.analysis;
            const isCached = data.cached;
            
            // Format the content for better display
            let formattedContent = analysis.content;
            
            // Convert markdown-style headers to HTML
            formattedContent = formattedContent.replace(/^### (.*$)/gm, '<h3>$1</h3>');
            formattedContent = formattedContent.replace(/^## (.*$)/gm, '<h2>$1</h2>');
            formattedContent = formattedContent.replace(/^# (.*$)/gm, '<h1>$1</h1>');
            
            // Convert **bold** to <strong>
            formattedContent = formattedContent.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
            
            // Convert bullet points
            formattedContent = formattedContent.replace(/^- (.*$)/gm, '<li>$1</li>');
            formattedContent = formattedContent.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
            
            // Convert line breaks to paragraphs
            formattedContent = formattedContent.replace(/\n\n/g, '</p><p>');
            formattedContent = '<p>' + formattedContent + '</p>';
            
            // Clean up empty paragraphs
            formattedContent = formattedContent.replace(/<p><\/p>/g, '');
            formattedContent = formattedContent.replace(/<p>\s*<h/g, '<h');
            formattedContent = formattedContent.replace(/<\/h([1-6])>\s*<\/p>/g, '</h$1>');
            formattedContent = formattedContent.replace(/<p>\s*<ul>/g, '<ul>');
            formattedContent = formattedContent.replace(/<\/ul>\s*<\/p>/g, '</ul>');
            
            // Display the results
            $('#expert-analysis-content').html(formattedContent);
            $('#expert-analysis-timestamp').text(formatTimestamp(analysis.generated_at));
            $('#expert-analysis-model').text(analysis.model_used || 'Claude AI');
            
            if (isCached) {
                $('#expert-analysis-badge').html('📋 Cached Result');
                $('#expert-analysis-cached-indicator').html('<strong>Source:</strong> Cached (previously generated)');
            } else {
                $('#expert-analysis-badge').html('✨ Freshly Generated');
                $('#expert-analysis-cached-indicator').html('<strong>Source:</strong> Just generated');
            }
            
            $('#expert-analysis-results').show();
            
            // Scroll to results smoothly
            $('html, body').animate({
                scrollTop: $('#expert-analysis-results').offset().top - 20
            }, 500);
        }
        
        function showError(message) {
            $('#expert-analysis-error-message').text(message);
            $('#expert-analysis-error').show();
            $('#expert-analysis-btn').show();
        }
        
        function formatTimestamp(timestamp) {
            if (!timestamp) return 'Unknown';
            
            const date = new Date(timestamp);
            return date.toLocaleString();
        }
    });
</script>
</div><!-- Close .airbnb-analyzer-container for shortcode mode -->
<?php endif;