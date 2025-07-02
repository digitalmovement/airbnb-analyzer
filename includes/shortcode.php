<?php
/**
 * Shortcode functionality for AirBnB Listing Analyzer
 */

// Register shortcode
add_shortcode('airbnb_analyzer', 'airbnb_analyzer_shortcode');
add_shortcode('airbnb_analysis_results', 'airbnb_analysis_results_shortcode');

/**
 * Shortcode handler for AirBnB Analyzer
 */
function airbnb_analyzer_shortcode($atts) {
    // Parse attributes
    $atts = shortcode_atts(array(
        'title' => 'AirBnB Listing Analyzer',
    ), $atts);
    
    // Start output buffering
    ob_start();
    
    // Display analyzer form
    ?>
    <div class="airbnb-analyzer-container">
        <h2><?php echo esc_html($atts['title']); ?></h2>
        
        <!-- Step 1: URL Input Form -->
        <div class="airbnb-analyzer-step" id="airbnb-analyzer-step-1">
            <div class="airbnb-analyzer-form">
                <div class="form-group">
                    <label for="airbnb-listing-url">Enter AirBnB Listing URL:</label>
                    <input type="url" id="airbnb-listing-url" placeholder="https://www.airbnb.com/rooms/12345678" required>
                </div>
                <button type="button" class="airbnb-analyzer-button" id="airbnb-analyzer-next-step">Continue</button>
            </div>
        </div>
        
        <!-- Step 2: Email and CAPTCHA -->
        <div class="airbnb-analyzer-step" id="airbnb-analyzer-step-2" style="display: none;">
            <div class="airbnb-analyzer-form">
                <h3>Almost there!</h3>
                <p>Please enter your email address and complete the CAPTCHA to continue.</p>
                
                <div class="form-group">
                    <label for="airbnb-analyzer-email">Email Address:</label>
                    <input type="email" id="airbnb-analyzer-email" placeholder="your@email.com" required>
                </div>
                
                <div class="form-group">
                    <div id="airbnb-analyzer-captcha" class="g-recaptcha" data-sitekey="<?php echo esc_attr(get_option('airbnb_analyzer_recaptcha_site_key')); ?>"></div>
                </div>
                
                <button type="button" class="airbnb-analyzer-button" id="airbnb-analyzer-submit">Analyze Listing</button>
                <button type="button" class="airbnb-analyzer-button airbnb-analyzer-back-button" id="airbnb-analyzer-back">Back</button>
            </div>
        </div>
        
        <!-- Loading indicator -->
        <div class="airbnb-analyzer-loading" style="display: none;">
            <p>Analyzing your listing... This may take a few moments.</p>
            <div class="airbnb-analyzer-spinner"></div>
        </div>
        
        <!-- Results container -->
        <div id="airbnb-analyzer-results" class="airbnb-analyzer-content" style="display: none;"></div>
    </div>
    <?php
    
    // Return the buffered content
    return ob_get_clean();
}

/**
 * Shortcode handler for displaying analysis results
 */
function airbnb_analysis_results_shortcode($atts) {
    // Parse attributes
    $atts = shortcode_atts(array(
        'id' => '',
    ), $atts);
    
    // Get ID from shortcode attribute or URL parameter
    $snapshot_id = !empty($atts['id']) ? $atts['id'] : (isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '');
    
    if (empty($snapshot_id)) {
        return '<div class="airbnb-analyzer-error">No analysis ID provided.</div>';
    }
    
    // Start output buffering
    ob_start();
    
    // Include the view-results.php logic but capture its output
    // We'll set a flag so view-results.php knows to return content instead of outputting directly
    define('AIRBNB_ANALYZER_SHORTCODE_MODE', true);
    $_GET['id'] = $snapshot_id; // Ensure the ID is available for view-results.php
    
    // Include view-results.php content
    include(plugin_dir_path(__FILE__) . '../view-results.php');
    
    // Return the buffered content
    return ob_get_clean();
}