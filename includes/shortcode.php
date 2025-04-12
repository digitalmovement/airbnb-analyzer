<?php
/**
 * Shortcode functionality for AirBnB Listing Analyzer
 */

// Register shortcode
add_shortcode('airbnb_analyzer', 'airbnb_analyzer_shortcode');

/**
 * Shortcode handler for AirBnB Analyzer
 */
function airbnb_analyzer_shortcode($atts) {
    // Parse attributes
    $atts = shortcode_atts(array(
        'title' => 'AirBnB Listing Analyzer',
        'button_text' => 'Analyze Listing'
    ), $atts);
    
    // Start output buffering
    ob_start();
    
    // Display form
    ?>
    <div class="airbnb-analyzer-container">
        <h2><?php echo esc_html($atts['title']); ?></h2>
        
        <div class="airbnb-analyzer-form">
            <form id="airbnb-analyzer-form">
                <div class="form-group">
                    <label for="airbnb-listing-url">Enter AirBnB Listing URL:</label>
                    <input type="url" id="airbnb-listing-url" name="listing_url" 
                           placeholder="https://www.airbnb.com/rooms/12345" required>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="airbnb-analyzer-button">
                        <?php echo esc_html($atts['button_text']); ?>
                    </button>
                </div>
            </form>
        </div>
        
        <div id="airbnb-analyzer-results" class="airbnb-analyzer-results" style="display: none;">
            <div class="airbnb-analyzer-loading" style="display: none;">
                <p>Analyzing your listing... Please wait.</p>
            </div>
            <div class="airbnb-analyzer-content"></div>
        </div>
    </div>
    <?php
    
    // Return buffered content
    return ob_get_clean();
}
?> 