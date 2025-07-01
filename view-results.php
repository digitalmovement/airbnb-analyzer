<?php
/**
 * Results Display Page for AirBnB Listing Analyzer
 */

// Load WordPress
require_once('../../../wp-config.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/brightdata-api.php');

// Get snapshot ID
$snapshot_id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

if (empty($snapshot_id)) {
    wp_die('Invalid request. No analysis ID provided.');
}

// Get request data
$request = brightdata_get_request($snapshot_id);
if (!$request || $request->status !== 'completed') {
    wp_die('Analysis not found or not completed.');
}

// Get analysis data - it's stored as JSON, not serialized
$response_data = json_decode($request->response_data, true);
$listing_data = isset($response_data['listing_data']) ? $response_data['listing_data'] : null;
$analysis = isset($response_data['analysis']) ? $response_data['analysis'] : null;

// Debug: Check if we have data
if (empty($response_data)) {
    wp_die('No analysis data found. Response data: ' . esc_html($request->response_data));
}

if (empty($listing_data)) {
    wp_die('No listing data found. Available keys: ' . esc_html(implode(', ', array_keys($response_data ?: []))));
}

// Track view
global $wpdb;
$table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests'; 
$wpdb->query($wpdb->prepare("UPDATE $table_name SET views = COALESCE(views, 0) + 1, last_viewed = NOW() WHERE snapshot_id = %s", $snapshot_id));

?>
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
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🏠 Airbnb Analysis Results</h1>
        <p>Comprehensive listing optimization report</p>
    </div>

    <div class="content">
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
                // Prioritize listing_title field, then fallback to others
                $title_to_show = '';
                if (!empty($listing_data['listing_title'])) {
                    $title_to_show = $listing_data['listing_title'];
                } elseif (!empty($listing_data['title'])) {
                    $title_to_show = $listing_data['title'];
                } elseif (!empty($listing_data['name'])) {
                    $title_to_show = $listing_data['name'];
                }
                
                if (!empty($title_to_show)): ?>
                <div class="content-preview">
                    <h4>Your Title:</h4>
                    <p><strong>"<?php echo esc_html($title_to_show); ?>"</strong></p>
                    <small>Length: <?php echo strlen($title_to_show); ?> characters</small>
                </div>
            <?php endif; ?>
            <?php elseif (strpos($category_lower, 'description') !== false && !empty($listing_data['description'])): ?>
                <div class="content-preview">
                    <h4>Your Description:</h4>
                    <p><?php echo esc_html(substr($listing_data['description'], 0, 300)); ?><?php echo strlen($listing_data['description']) > 300 ? '...' : ''; ?></p>
                    <small>Length: <?php echo strlen($listing_data['description']); ?> characters</small>
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
                    <p>⭐ <strong>Rating:</strong> <?php echo esc_html($listing_data['ratings'] ?? $listing_data['rating'] ?? 'N/A'); ?>/5</p>
                    <p>📝 <strong>Review Count:</strong> <?php echo esc_html($listing_data['property_number_of_reviews'] ?? $listing_data['review_count'] ?? 'N/A'); ?> reviews</p>
                    <?php if (isset($listing_data['is_guest_favorite'])): ?>
                        <p>💖 <strong>Guest Favorite:</strong> <?php echo $listing_data['is_guest_favorite'] ? 'Yes' : 'No'; ?></p>
                    <?php endif; ?>
                </div>
            <?php elseif (strpos($category_lower, 'amenities') !== false || strpos($category_lower, 'climate') !== false || strpos($category_lower, 'safety') !== false): ?>
                <div class="content-preview">
                    <h4>Available Amenities:</h4>
                    <?php 
                    $amenity_list = [];
                    
                    // Parse amenities: handle both nested (group->items) and flat arrays
                    if (!empty($listing_data['amenities']) && is_array($listing_data['amenities'])) {
                        // Detect if first element has 'items' key (nested format)
                        $first_element = $listing_data['amenities'][0] ?? null;
                        if (is_array($first_element) && isset($first_element['items'])) {
                            // Nested format
                            foreach ($listing_data['amenities'] as $amenity_group) {
                                if (isset($amenity_group['items']) && is_array($amenity_group['items'])) {
                                    foreach ($amenity_group['items'] as $item) {
                                        if (isset($item['name']) && !empty($item['name'])) {
                                            $amenity_list[] = $item['name'];
                                        }
                                    }
                                }
                            }
                        } else {
                            // Flat format: each element is either string or array with 'name'
                            foreach ($listing_data['amenities'] as $amenity) {
                                if (is_string($amenity)) {
                                    $amenity_list[] = $amenity;
                                } elseif (is_array($amenity) && isset($amenity['name'])) {
                                    $amenity_list[] = $amenity['name'];
                                }
                            }
                        }
                    }
                    
                    if (!empty($amenity_list)): ?>
                        <div style="max-height: 150px; overflow-y: auto;">
                            <p><?php echo esc_html(implode(' • ', array_slice($amenity_list, 0, 20))); ?><?php echo count($amenity_list) > 20 ? ' • ...' : ''; ?></p>
                            <small><?php echo count($amenity_list); ?> amenities total</small>
                        </div>
                    <?php else: ?>
                        <p><em>No amenities found</em></p>
                        <details style="margin-top: 10px;">
                            <summary style="cursor: pointer; color: #666; font-size: 12px;">Debug: Raw amenities data</summary>
                            <pre style="font-size: 10px; color: #999; max-height: 100px; overflow: auto;"><?php echo esc_html(print_r($listing_data['amenities'] ?? 'No amenities key', true)); ?></pre>
                        </details>
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
    </div>

    <div class="footer">
        <p>Analysis completed: <?php echo date('F j, Y', strtotime($request->date_created)); ?></p>
        <p>Reference: <?php echo esc_html($snapshot_id); ?></p>
        <a href="<?php echo home_url(); ?>" class="btn">← Back to Site</a>
    </div>
</div>

</body>
</html> 