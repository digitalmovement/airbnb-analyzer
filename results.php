<?php
/**
 * Results Display Page for AirBnB Listing Analyzer
 * This page displays analysis results in a rich format and tracks views
 */

// Load WordPress
require_once('../../../wp-config.php');

// Load our includes
require_once(AIRBNB_ANALYZER_PATH . 'includes/brightdata-api.php');
require_once(AIRBNB_ANALYZER_PATH . 'includes/analyzer.php');

// Get the snapshot ID from URL
$snapshot_id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

if (empty($snapshot_id)) {
    wp_die('Invalid request. No analysis ID provided.');
}

// Get the analysis request
$request = brightdata_get_request($snapshot_id);

if (!$request) {
    wp_die('Analysis not found. The link may be invalid or expired.');
}

// Check if analysis is completed
if ($request->status !== 'completed') {
    wp_die('Analysis is not yet completed. Please check back later or contact support.');
}

// Get the analysis data
$response_data = maybe_unserialize($request->response_data);
$listing_data = isset($response_data['listing_data']) ? $response_data['listing_data'] : null;
$analysis = isset($response_data['analysis']) ? $response_data['analysis'] : null;

if (!$listing_data || !$analysis) {
    wp_die('Analysis data not found. Please contact support.');
}

// Track the page view
track_results_view($snapshot_id);

// Get site info for branding
$site_name = get_bloginfo('name');
$site_url = home_url();

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Airbnb Analysis Results - <?php echo esc_html($listing_data['title'] ?? 'Unknown Listing'); ?></title>
    
    <!-- Load WordPress styles and our custom styles -->
    <?php wp_head(); ?>
    <link rel="stylesheet" href="<?php echo AIRBNB_ANALYZER_URL; ?>css/results.css">
    
    <!-- Load jQuery and our scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo AIRBNB_ANALYZER_URL; ?>js/script.js"></script>
    
    <!-- AJAX variables for expert analysis -->
    <script type="text/javascript">
        var airbnb_analyzer_ajax = {
            ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('airbnb_analyzer_nonce'); ?>'
        };
    </script>
    
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background: #f5f5f5;
            color: #333;
        }
        
        .results-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            min-height: 100vh;
        }
        
        .results-header {
            background: linear-gradient(135deg, #FF5A5F 0%, #FF3B41 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .results-header h1 {
            margin: 0 0 10px 0;
            font-size: 2.5em;
            font-weight: 300;
        }
        
        .results-header .subtitle {
            font-size: 1.2em;
            opacity: 0.9;
            margin: 0;
        }
        
        .listing-info {
            background: white;
            padding: 30px;
            border-bottom: 1px solid #eee;
        }
        
        .listing-title {
            font-size: 1.8em;
            margin: 0 0 15px 0;
            color: #333;
        }
        
        .listing-url {
            color: #666;
            font-size: 0.9em;
            word-break: break-all;
        }
        
        .listing-photo {
            text-align: center;
            margin: 20px 0;
        }
        
        .listing-photo img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .analysis-content {
            padding: 30px;
        }
        
        .analysis-section {
            margin-bottom: 40px;
            background: #f9f9f9;
            border-radius: 12px;
            padding: 25px;
            border-left: 5px solid #FF5A5F;
        }
        
        .analysis-section h2 {
            margin: 0 0 20px 0;
            color: #333;
            font-size: 1.5em;
        }
        
        .claude-analysis .claude-section {
            background: white;
            margin: 20px 0;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #4CAF50;
        }
        
        .claude-section h3 {
            margin: 0 0 15px 0;
            color: #2c5aa0;
            font-size: 1.3em;
        }
        
        .rating-badge {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .rating-badge.low {
            background: #f44336;
        }
        
        .rating-badge.medium {
            background: #ff9800;
        }
        
        .suggestions-list {
            list-style: none;
            padding: 0;
            margin: 15px 0;
        }
        
        .suggestions-list li {
            background: #e3f2fd;
            padding: 10px 15px;
            margin: 8px 0;
            border-radius: 6px;
            border-left: 3px solid #2196f3;
        }
        
        .suggestions-list li:before {
            content: "💡 ";
            margin-right: 8px;
        }
        
        .footer {
            background: #333;
            color: white;
            text-align: center;
            padding: 30px;
            margin-top: 40px;
        }
        
        .back-to-site {
            display: inline-block;
            background: #FF5A5F;
            color: white;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .back-to-site:hover {
            background: #FF3B41;
            color: white;
        }
        
        @media (max-width: 768px) {
            .results-header {
                padding: 30px 20px;
            }
            
            .results-header h1 {
                font-size: 2em;
            }
            
            .listing-info, .analysis-content {
                padding: 20px;
            }
            
            .analysis-section {
                padding: 20px;
            }
        }
    </style>
</head>
<body>

<div class="results-container">
    <!-- Header -->
    <div class="results-header">
        <h1>🏠 Airbnb Analysis Results</h1>
        <p class="subtitle">Comprehensive AI-powered listing optimization report</p>
    </div>

    <!-- Listing Information -->
    <div class="listing-info">
        <h2 class="listing-title"><?php echo esc_html($listing_data['title'] ?? 'Unknown Listing'); ?></h2>
        <p class="listing-url">
            <strong>Analyzed URL:</strong> 
            <a href="<?php echo esc_url($request->listing_url); ?>" target="_blank" rel="noopener">
                <?php echo esc_html($request->listing_url); ?>
            </a>
        </p>
        
        <?php if (!empty($listing_data['photos']) && is_array($listing_data['photos'])): ?>
        <div class="listing-photo">
            <img src="<?php echo esc_url($listing_data['photos'][0]); ?>" alt="Listing Photo">
        </div>
        <?php endif; ?>
    </div>

    <!-- Analysis Content -->
    <div class="analysis-content">
        
        <?php if (isset($analysis['claude_analysis']) && is_array($analysis['claude_analysis'])): ?>
        <div class="analysis-section claude-analysis">
            <h2>🤖 AI-Powered Analysis</h2>
            
            <?php if (isset($analysis['claude_analysis']['title'])): ?>
            <div class="claude-section">
                <h3>📝 Title Analysis</h3>
                <?php 
                $title_data = $analysis['claude_analysis']['title'];
                $rating = isset($title_data['rating']) ? intval($title_data['rating']) : 0;
                $rating_class = $rating >= 8 ? 'high' : ($rating >= 6 ? 'medium' : 'low');
                ?>
                <div class="rating-badge <?php echo $rating_class; ?>">
                    Rating: <?php echo $rating; ?>/10
                </div>
                <p><strong>Feedback:</strong> <?php echo esc_html($title_data['feedback'] ?? 'N/A'); ?></p>
                
                <?php if (isset($title_data['alternative_titles']) && is_array($title_data['alternative_titles'])): ?>
                <div>
                    <strong>💡 Suggested Alternative Titles:</strong>
                    <ul class="suggestions-list">
                        <?php foreach ($title_data['alternative_titles'] as $alt_title): ?>
                        <li><?php echo esc_html($alt_title); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($analysis['claude_analysis']['description'])): ?>
            <div class="claude-section">
                <h3>📄 Description Analysis</h3>
                <?php 
                $desc_data = $analysis['claude_analysis']['description'];
                $rating = isset($desc_data['rating']) ? intval($desc_data['rating']) : 0;
                $rating_class = $rating >= 8 ? 'high' : ($rating >= 6 ? 'medium' : 'low');
                ?>
                <div class="rating-badge <?php echo $rating_class; ?>">
                    Rating: <?php echo $rating; ?>/10
                </div>
                <p><strong>First Impression:</strong> <?php echo esc_html($desc_data['first_impression'] ?? 'N/A'); ?></p>
                <p><strong>Overall Feedback:</strong> <?php echo esc_html($desc_data['overall_feedback'] ?? 'N/A'); ?></p>
                
                <?php if (isset($desc_data['suggestions']) && is_array($desc_data['suggestions'])): ?>
                <div>
                    <strong>💡 Improvement Suggestions:</strong>
                    <ul class="suggestions-list">
                        <?php foreach ($desc_data['suggestions'] as $suggestion): ?>
                        <li><?php echo esc_html($suggestion); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($analysis['claude_analysis']['host'])): ?>
            <div class="claude-section">
                <h3>👤 Host Profile Analysis</h3>
                <?php 
                $host_data = $analysis['claude_analysis']['host'];
                $rating = isset($host_data['rating']) ? intval($host_data['rating']) : 0;
                $rating_class = $rating >= 8 ? 'high' : ($rating >= 6 ? 'medium' : 'low');
                ?>
                <div class="rating-badge <?php echo $rating_class; ?>">
                    Rating: <?php echo $rating; ?>/10
                </div>
                <p><strong>Feedback:</strong> <?php echo esc_html($host_data['feedback'] ?? 'N/A'); ?></p>
                
                <?php if (isset($host_data['suggestions']) && is_array($host_data['suggestions'])): ?>
                <div>
                    <strong>💡 Profile Improvement Suggestions:</strong>
                    <ul class="suggestions-list">
                        <?php foreach ($host_data['suggestions'] as $suggestion): ?>
                        <li><?php echo esc_html($suggestion); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($analysis['claude_analysis']['amenities'])): ?>
            <div class="claude-section">
                <h3>🏠 Amenities Analysis</h3>
                <?php 
                $amenities_data = $analysis['claude_analysis']['amenities'];
                $rating = isset($amenities_data['rating']) ? intval($amenities_data['rating']) : 0;
                $rating_class = $rating >= 8 ? 'high' : ($rating >= 6 ? 'medium' : 'low');
                ?>
                <div class="rating-badge <?php echo $rating_class; ?>">
                    Rating: <?php echo $rating; ?>/10
                </div>
                <p><strong>Feedback:</strong> <?php echo esc_html($amenities_data['feedback'] ?? 'N/A'); ?></p>
                
                <?php if (isset($amenities_data['suggestions']) && is_array($amenities_data['suggestions'])): ?>
                <div>
                    <strong>💡 Amenity Suggestions:</strong>
                    <ul class="suggestions-list">
                        <?php foreach ($amenities_data['suggestions'] as $suggestion): ?>
                        <li><?php echo esc_html($suggestion); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
        </div>
        <?php endif; ?>
        
        <!-- Basic Analysis Results -->
        <?php if (is_array($analysis) && !empty($analysis)): ?>
        <div class="analysis-section">
            <h2>📊 Basic Analysis Results</h2>
            
            <?php foreach ($analysis as $key => $section): ?>
                <?php if ($key !== 'claude_analysis' && $key !== 'has_claude_analysis' && is_array($section) && isset($section['category'])): ?>
                <div style="margin: 20px 0; padding: 15px; background: white; border-radius: 8px;">
                    <h3><?php echo esc_html($section['category'] ?? 'Analysis'); ?></h3>
                    <p><strong>Score:</strong> <?php echo esc_html($section['score'] ?? 'N/A'); ?>/<?php echo esc_html($section['max_score'] ?? '10'); ?></p>
                    <p><strong>Status:</strong> <?php echo esc_html(ucfirst($section['status'] ?? 'unknown')); ?></p>
                    <p><?php echo esc_html($section['message'] ?? ''); ?></p>
                    
                    <?php if (isset($section['recommendations']) && is_array($section['recommendations'])): ?>
                    <div>
                        <strong>Recommendations:</strong>
                        <ul style="margin: 10px 0; padding-left: 20px;">
                            <?php foreach ($section['recommendations'] as $rec): ?>
                            <li><?php echo esc_html($rec); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Expert Analysis Section -->
    <div class="expert-analysis-section" style="margin: 40px 30px; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; text-align: center; color: white;">
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
                <small style="opacity: 0.8;">This may take 30-60 seconds</small>
            </p>
        </div>
        
        <!-- Processing Status -->
        <div id="expert-analysis-processing" style="display: none; margin-top: 20px; padding: 20px; background: rgba(255,255,255,0.1); border-radius: 8px; border: 2px solid rgba(255,255,255,0.3);"></div>
        
        <!-- Error State -->
        <div id="expert-analysis-error" style="display: none; margin-top: 20px; padding: 15px; background: rgba(255,255,255,0.1); border-radius: 8px;">
            <p id="expert-analysis-error-message" style="color: #ffcccb; margin: 0;"></p>
            <button id="expert-analysis-retry" style="margin-top: 10px; background: transparent; border: 2px solid white; color: white; padding: 8px 16px; border-radius: 20px; cursor: pointer;">
                Try Again
            </button>
        </div>
    </div>
    
    <!-- Expert Analysis Results -->
    <div id="expert-analysis-results" style="display: none; margin: 40px 30px; padding: 30px; background: #f8f9fa; border-radius: 12px; border-left: 5px solid #667eea;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
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

    <!-- Add spinner animation CSS -->
    <style>
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

    <!-- Footer -->
    <div class="footer">
        <p>Analysis completed on <?php echo date('F j, Y \a\t g:i A', strtotime($request->date_created)); ?></p>
        <p>Reference ID: <?php echo esc_html($snapshot_id); ?></p>
        <a href="<?php echo esc_url($site_url); ?>" class="back-to-site">← Back to <?php echo esc_html($site_name); ?></a>
    </div>
</div>

<?php wp_footer(); ?>
</body>
</html>

<?php
/**
 * Track that this results page was viewed
 */
function track_results_view($snapshot_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    // Increment view count
    $wpdb->query($wpdb->prepare(
        "UPDATE $table_name SET views = COALESCE(views, 0) + 1, last_viewed = NOW() WHERE snapshot_id = %s",
        $snapshot_id
    ));
    
    // Log the view
    if (function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Results page viewed for snapshot ID: $snapshot_id", 'Results View');
    }
}
