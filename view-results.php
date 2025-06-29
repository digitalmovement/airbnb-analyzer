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

// Get analysis data
$response_data = maybe_unserialize($request->response_data);
$listing_data = isset($response_data['listing_data']) ? $response_data['listing_data'] : null;
$analysis = isset($response_data['analysis']) ? $response_data['analysis'] : null;

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
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #FF5A5F 0%, #FF3B41 100%); color: white; padding: 40px 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 2.5em; font-weight: 300; }
        .content { padding: 30px; }
        .listing-info { margin-bottom: 30px; padding: 20px; background: #f9f9f9; border-radius: 8px; }
        .analysis-section { margin: 30px 0; padding: 25px; background: #f9f9f9; border-radius: 12px; border-left: 5px solid #FF5A5F; }
        .claude-section { background: white; margin: 20px 0; padding: 20px; border-radius: 8px; border-left: 4px solid #4CAF50; }
        .rating-badge { display: inline-block; background: #4CAF50; color: white; padding: 5px 12px; border-radius: 20px; font-weight: bold; margin: 5px 0; }
        .rating-badge.low { background: #f44336; }
        .rating-badge.medium { background: #ff9800; }
        .suggestions { list-style: none; padding: 0; }
        .suggestions li { background: #e3f2fd; padding: 10px 15px; margin: 8px 0; border-radius: 6px; border-left: 3px solid #2196f3; }
        .suggestions li:before { content: "💡 "; }
        .footer { background: #333; color: white; text-align: center; padding: 30px; }
        .btn { display: inline-block; background: #FF5A5F; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>🏠 Airbnb Analysis Results</h1>
        <p>AI-powered listing optimization report</p>
    </div>

    <div class="content">
        <div class="listing-info">
            <h2><?php echo esc_html($listing_data['title'] ?? 'Unknown Listing'); ?></h2>
            <p><strong>URL:</strong> <a href="<?php echo esc_url($request->listing_url); ?>" target="_blank"><?php echo esc_html($request->listing_url); ?></a></p>
            <?php if (!empty($listing_data['photos'][0])): ?>
            <img src="<?php echo esc_url($listing_data['photos'][0]); ?>" style="max-width: 100%; height: 300px; object-fit: cover; border-radius: 8px;">
            <?php endif; ?>
        </div>

        <?php if (isset($analysis['claude_analysis'])): ?>
        <div class="analysis-section">
            <h2>🤖 AI Analysis</h2>
            
            <?php if (isset($analysis['claude_analysis']['title'])): ?>
            <div class="claude-section">
                <h3>📝 Title Analysis</h3>
                <?php $title = $analysis['claude_analysis']['title']; $rating = intval($title['rating'] ?? 0); ?>
                <div class="rating-badge <?php echo $rating >= 8 ? '' : ($rating >= 6 ? 'medium' : 'low'); ?>">
                    Rating: <?php echo $rating; ?>/10
                </div>
                <p><?php echo esc_html($title['feedback'] ?? ''); ?></p>
                <?php if (!empty($title['alternative_titles'])): ?>
                <ul class="suggestions">
                    <?php foreach ($title['alternative_titles'] as $alt): ?>
                    <li><?php echo esc_html($alt); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if (isset($analysis['claude_analysis']['description'])): ?>
            <div class="claude-section">
                <h3>📄 Description Analysis</h3>
                <?php $desc = $analysis['claude_analysis']['description']; $rating = intval($desc['rating'] ?? 0); ?>
                <div class="rating-badge <?php echo $rating >= 8 ? '' : ($rating >= 6 ? 'medium' : 'low'); ?>">
                    Rating: <?php echo $rating; ?>/10
                </div>
                <p><strong>First Impression:</strong> <?php echo esc_html($desc['first_impression'] ?? ''); ?></p>
                <p><strong>Overall:</strong> <?php echo esc_html($desc['overall_feedback'] ?? ''); ?></p>
                <?php if (!empty($desc['suggestions'])): ?>
                <ul class="suggestions">
                    <?php foreach ($desc['suggestions'] as $suggestion): ?>
                    <li><?php echo esc_html($suggestion); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
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