<?php
/**
 * Settings functionality for AirBnB Listing Analyzer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add settings page
function airbnb_analyzer_add_settings_page() {
    add_options_page(
        'AirBnB Analyzer Settings',
        'AirBnB Analyzer',
        'manage_options',
        'airbnb-analyzer-settings',
        'airbnb_analyzer_settings_page'
    );
}
add_action('admin_menu', 'airbnb_analyzer_add_settings_page');

/**
 * Register plugin settings
 */
function airbnb_analyzer_register_settings() {
    register_setting('airbnb_analyzer_options', 'airbnb_analyzer_claude_api_key');
    register_setting('airbnb_analyzer_options', 'airbnb_analyzer_recaptcha_site_key');
    register_setting('airbnb_analyzer_options', 'airbnb_analyzer_recaptcha_secret_key');
    register_setting('airbnb_analyzer_options', 'airbnb_analyzer_enable_debugging', array(
        'type' => 'boolean',
        'default' => false,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
    register_setting('airbnb_analyzer_options', 'airbnb_analyzer_debug_level', array(
        'type' => 'string',
        'default' => 'basic',
        'sanitize_callback' => 'sanitize_text_field',
    ));
    register_setting('airbnb_analyzer_options', 'airbnb_analyzer_debug_log_enabled', array(
        'type' => 'boolean',
        'default' => false,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ));
}
add_action('admin_init', 'airbnb_analyzer_register_settings');

// Settings page content
function airbnb_analyzer_settings_page() {
    ?>
    <div class="wrap">
        <h1>AirBnB Analyzer Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('airbnb_analyzer_options'); ?>
            <?php do_settings_sections('airbnb_analyzer_options'); ?>
            
            <h2>API Settings</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Claude API Key</th>
                    <td>
                        <input type="password" name="airbnb_analyzer_claude_api_key" value="<?php echo esc_attr(get_option('airbnb_analyzer_claude_api_key')); ?>" class="regular-text" />
                        <p class="description">Enter your Claude API key to enable AI-powered listing analysis.</p>
                    </td>
                </tr>
            </table>
            
            <h2>reCAPTCHA Settings</h2>
            <p>Get your reCAPTCHA v2 keys from <a href="https://www.google.com/recaptcha/admin" target="_blank">Google reCAPTCHA</a>.</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Site Key</th>
                    <td>
                        <input type="text" name="airbnb_analyzer_recaptcha_site_key" value="<?php echo esc_attr(get_option('airbnb_analyzer_recaptcha_site_key')); ?>" class="regular-text" />
                        <p class="description">Enter your reCAPTCHA site key.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Secret Key</th>
                    <td>
                        <input type="password" name="airbnb_analyzer_recaptcha_secret_key" value="<?php echo esc_attr(get_option('airbnb_analyzer_recaptcha_secret_key')); ?>" class="regular-text" />
                        <p class="description">Enter your reCAPTCHA secret key.</p>
                    </td>
                </tr>
            </table>
            
            <h2>Debugging Options</h2>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Enable Debugging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="airbnb_analyzer_enable_debugging" value="1" <?php checked(get_option('airbnb_analyzer_enable_debugging'), true); ?> />
                            Show debug information for troubleshooting
                        </label>
                        <p class="description">When enabled, debug information will be shown on the listing analysis page.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Debug Level</th>
                    <td>
                        <select name="airbnb_analyzer_debug_level">
                            <option value="basic" <?php selected(get_option('airbnb_analyzer_debug_level'), 'basic'); ?>>Basic - Show raw API data</option>
                            <option value="advanced" <?php selected(get_option('airbnb_analyzer_debug_level'), 'advanced'); ?>>Advanced - Show API data and request details</option>
                            <option value="full" <?php selected(get_option('airbnb_analyzer_debug_level'), 'full'); ?>>Full - Show all debug information</option>
                        </select>
                        <p class="description">Select how much debug information to display.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Debug Log</th>
                    <td>
                        <label>
                            <input type="checkbox" name="airbnb_analyzer_debug_log_enabled" value="1" <?php checked(get_option('airbnb_analyzer_debug_log_enabled'), true); ?> />
                            Log debug information to file
                        </label>
                        <p class="description">When enabled, debug information will be logged to a file in the plugin directory.</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(); ?>
        </form>
        
        <hr>
        
        <h2>Debug Testing Tool</h2>
        <p>Use this tool to test the API and view debug information for a specific Airbnb listing.</p>
        
        <div id="airbnb-analyzer-debug-tool">
            <div class="form-group">
                <label for="debug-listing-url">Airbnb Listing URL:</label>
                <input type="text" id="debug-listing-url" class="regular-text" placeholder="https://www.airbnb.com/rooms/12345678" />
                <button type="button" id="debug-fetch-btn" class="button button-primary">Fetch Debug Data</button>
            </div>
            
            <div id="debug-results" style="display: none; margin-top: 20px;">
                <h3>Debug Results</h3>
                
                <div class="debug-section">
                    <div class="debug-header">
                        <h4>Raw API Response</h4>
                        <button type="button" class="button copy-debug-data" data-section="raw-data">Copy to Clipboard</button>
                    </div>
                    <div class="debug-content">
                        <pre id="debug-raw-data" style="max-height: 300px; overflow: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;"></pre>
                    </div>
                </div>
                
                <div class="debug-section">
                    <div class="debug-header">
                        <h4>Extracted Data</h4>
                        <button type="button" class="button copy-debug-data" data-section="extracted-data">Copy to Clipboard</button>
                    </div>
                    <div class="debug-content">
                        <pre id="debug-extracted-data" style="max-height: 300px; overflow: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;"></pre>
                    </div>
                </div>
                
                <div class="debug-section">
                    <div class="debug-header">
                        <h4>Request Details</h4>
                        <button type="button" class="button copy-debug-data" data-section="request-details">Copy to Clipboard</button>
                    </div>
                    <div class="debug-content">
                        <pre id="debug-request-details" style="max-height: 300px; overflow: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;"></pre>
                    </div>
                </div>
                
                <div class="debug-section">
                    <div class="debug-header">
                        <h4>All Debug Data</h4>
                        <button type="button" class="button copy-debug-data" data-section="all-data">Copy to Clipboard</button>
                    </div>
                    <div class="debug-content">
                        <pre id="debug-all-data" style="max-height: 300px; overflow: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;"></pre>
                    </div>
                </div>
            </div>
            
            <div id="debug-error" style="display: none; margin-top: 20px; color: #d63638; padding: 10px; background: #fcf0f1; border-left: 4px solid #d63638;"></div>
            <div id="debug-loading" style="display: none; margin-top: 20px;">
                <span class="spinner is-active" style="float: none; margin: 0 10px 0 0;"></span>
                Fetching data...
            </div>
        </div>
        
        <hr>
        
        <h2>Debug Log</h2>
        <?php
        $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
        $log_exists = file_exists($log_file);
        $log_size = $log_exists ? size_format(filesize($log_file)) : '0 KB';
        ?>
        
        <div class="debug-log-info">
            <p>
                Log file: <code><?php echo esc_html($log_file); ?></code><br>
                Size: <span id="log-size"><?php echo esc_html($log_size); ?></span>
            </p>
            
            <div class="debug-log-actions">
                <button type="button" id="view-log-btn" class="button" <?php echo !$log_exists ? 'disabled' : ''; ?>>View Log</button>
                <button type="button" id="download-log-btn" class="button" <?php echo !$log_exists ? 'disabled' : ''; ?>>Download Log</button>
                <button type="button" id="clear-log-btn" class="button" <?php echo !$log_exists ? 'disabled' : ''; ?>>Clear Log</button>
            </div>
        </div>
        
        <div id="debug-log-content" style="display: none; margin-top: 20px;">
            <h3>Log Contents</h3>
            <pre id="log-content" style="max-height: 500px; overflow: auto; background: #f5f5f5; padding: 10px; border: 1px solid #ddd;"></pre>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Debug tool functionality
        $('#debug-fetch-btn').on('click', function() {
            var listingUrl = $('#debug-listing-url').val();
            if (!listingUrl) {
                alert('Please enter an Airbnb listing URL');
                return;
            }
            
            $('#debug-results').hide();
            $('#debug-error').hide();
            $('#debug-loading').show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'airbnb_analyzer_debug_fetch',
                    url: listingUrl,
                    nonce: '<?php echo wp_create_nonce('airbnb_analyzer_debug_nonce'); ?>'
                },
                success: function(response) {
                    $('#debug-loading').hide();
                    
                    if (response.success) {
                        $('#debug-raw-data').text(JSON.stringify(response.data.raw_data, null, 2));
                        $('#debug-extracted-data').text(JSON.stringify(response.data.extracted_data, null, 2));
                        $('#debug-request-details').text(JSON.stringify(response.data.request_details, null, 2));
                        $('#debug-all-data').text(JSON.stringify(response.data, null, 2));
                        $('#debug-results').show();
                    } else {
                        $('#debug-error').text(response.data.message).show();
                    }
                },
                error: function() {
                    $('#debug-loading').hide();
                    $('#debug-error').text('An error occurred while fetching the data.').show();
                }
            });
        });
        
        // Copy to clipboard functionality
        $('.copy-debug-data').on('click', function() {
            var section = $(this).data('section');
            var content = '';
            
            switch(section) {
                case 'raw-data':
                    content = $('#debug-raw-data').text();
                    break;
                case 'extracted-data':
                    content = $('#debug-extracted-data').text();
                    break;
                case 'request-details':
                    content = $('#debug-request-details').text();
                    break;
                case 'all-data':
                    content = $('#debug-all-data').text();
                    break;
            }
            
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(content).select();
            document.execCommand('copy');
            $temp.remove();
            
            var originalText = $(this).text();
            $(this).text('Copied!');
            
            setTimeout(function() {
                $('.copy-debug-data[data-section="' + section + '"]').text(originalText);
            }, 2000);
        });
        
        // Debug log functionality
        $('#view-log-btn').on('click', function() {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'airbnb_analyzer_view_log',
                    nonce: '<?php echo wp_create_nonce('airbnb_analyzer_log_nonce'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#log-content').text(response.data.content);
                        $('#debug-log-content').show();
                    } else {
                        alert(response.data.message);
                    }
                },
                error: function() {
                    alert('An error occurred while fetching the log file.');
                }
            });
        });
        
        $('#download-log-btn').on('click', function() {
            window.location.href = ajaxurl + '?action=airbnb_analyzer_download_log&nonce=<?php echo wp_create_nonce('airbnb_analyzer_log_nonce'); ?>';
        });
        
        $('#clear-log-btn').on('click', function() {
            if (confirm('Are you sure you want to clear the debug log?')) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'airbnb_analyzer_clear_log',
                        nonce: '<?php echo wp_create_nonce('airbnb_analyzer_log_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#log-size').text('0 KB');
                            $('#debug-log-content').hide();
                            $('#view-log-btn, #download-log-btn, #clear-log-btn').prop('disabled', true);
                            alert('Debug log cleared successfully.');
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function() {
                        alert('An error occurred while clearing the log file.');
                    }
                });
            }
        });
    });
    </script>
    
    <style>
    .debug-section {
        margin-bottom: 20px;
    }
    
    .debug-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }
    
    .debug-header h4 {
        margin: 0;
    }
    
    .debug-log-actions {
        margin-top: 10px;
    }
    
    .debug-log-actions .button {
        margin-right: 10px;
    }
    
    #debug-loading {
        display: flex;
        align-items: center;
    }
    </style>
    <?php
}

/**
 * AJAX handler for fetching debug data
 */
function airbnb_analyzer_debug_fetch_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'airbnb_analyzer_debug_nonce')) {
        wp_send_json_error(array('message' => 'Invalid security token. Please refresh the page and try again.'));
    }
    
    // Check if URL is provided
    if (!isset($_POST['url']) || empty($_POST['url'])) {
        wp_send_json_error(array('message' => 'Please enter a valid Airbnb listing URL.'));
    }
    
    $url = sanitize_text_field($_POST['url']);
    
    // Extract listing ID from URL - updated to handle international domains
    if (preg_match('/airbnb\.[a-z\.]+\/rooms\/(\d+)/', $url, $matches)) {
        $listing_id = $matches[1];
    } elseif (preg_match('/\/rooms\/(\d+)/', $url, $matches)) {
        $listing_id = $matches[1];
    } else {
        wp_send_json_error(array('message' => 'Invalid Airbnb listing URL format. Please use a URL like https://www.airbnb.com/rooms/12345'));
    }
    
    // Use a simpler API endpoint
    $api_url = "https://www.airbnb.com/api/v2/pdp_listing_details/{$listing_id}?_format=for_rooms_show&key=d306zoyjsyarp7ifhu67rjxn52tv0t20";
    
    // Use WordPress HTTP API to fetch listing data
    $args = array(
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json',
            'Accept-Language' => 'en-US,en;q=0.9',
            'X-Airbnb-API-Key' => 'd306zoyjsyarp7ifhu67rjxn52tv0t20',
            'X-Airbnb-OAuth-Token' => '',
            'X-CSRF-Token' => '',
            'X-CSRF-Without-Token' => '1',
            'Origin' => 'https://www.airbnb.com',
            'Referer' => 'https://www.airbnb.com/'
        ),
        'timeout' => 30,
        'sslverify' => false
    );
    
    $response = wp_remote_get($api_url, $args);
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Error fetching data: ' . $response->get_error_message()));
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wp_send_json_error(array('message' => 'Error fetching data: HTTP ' . $status_code));
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => 'Error parsing API response: ' . json_last_error_msg()));
    }
    
    // Process the data to extract listing information
    $extracted_data = array(
        'id' => $listing_id,
        'title' => '',
        'description' => '',
        'photos' => array(),
        'price' => 0,
        'price_currency' => '',
        'location' => '',
        'bedrooms' => 0,
        'bathrooms' => 0,
        'beds' => 0,
        'max_guests' => 0,
        'amenities' => array(),
        'host_name' => '',
        'host_is_superhost' => false,
        'rating' => 0,
        'review_count' => 0,
        'property_type' => '',
        'house_rules' => ''
    );
    
    $parsing_steps = array();
    
    // Parse the API response
    try {
        if (isset($data['data']['presentation']['stayProductDetailPage']['sections']['sections'])) {
            $sections = $data['data']['presentation']['stayProductDetailPage']['sections']['sections'];
            
            foreach ($sections as $section) {
                // Extract title from TITLE_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'TITLE_DEFAULT') {
                    if (isset($section['section']['title'])) {
                        $extracted_data['title'] = $section['section']['title'];
                        $parsing_steps[] = "Extracted title from TITLE_DEFAULT section";
                    }
                    
                    if (isset($section['section']['subtitle'])) {
                        $extracted_data['location'] = $section['section']['subtitle'];
                        $parsing_steps[] = "Extracted location from TITLE_DEFAULT section";
                    }
                }
                
                // Extract description from DESCRIPTION_DEFAULT section
                if (isset($section['sectionId']) && $section['sectionId'] === 'DESCRIPTION_DEFAULT') {
                    if (isset($section['section']['htmlDescription']['htmlText'])) {
                        $extracted_data['description'] = $section['section']['htmlDescription']['htmlText'];
                        $parsing_steps[] = "Extracted description from DESCRIPTION_DEFAULT section";
                    }
                }
                
                // Extract photos from PHOTO_TOUR_SCROLLABLE_MODAL section
                if (isset($section['sectionId']) && $section['sectionId'] === 'PHOTO_TOUR_SCROLLABLE_MODAL') {
                    if (isset($section['section']['mediaItems']) && is_array($section['section']['mediaItems'])) {
                        foreach ($section['section']['mediaItems'] as $mediaItem) {
                            if (isset($mediaItem['baseUrl'])) {
                                $extracted_data['photos'][] = $mediaItem['baseUrl'];
                            }
                        }
                        $parsing_steps[] = "Extracted " . count($extracted_data['photos']) . " photos from PHOTO_TOUR_SCROLLABLE_MODAL section";
                    }
                }
                
                // Extract other data from various sections
                // ... (similar to the main API function)
            }
        }
    } catch (Exception $e) {
        $parsing_steps[] = "Error parsing API response: " . $e->getMessage();
    }
    
    // Return the raw data and extracted information for debugging
    wp_send_json_success(array(
        'api_url' => $api_url,
        'raw_data' => $data,
        'extracted_data' => $extracted_data,
        'parsing_steps' => $parsing_steps,
        'request_details' => array(
            'listing_id' => $listing_id,
            'status_code' => $status_code,
            'headers' => wp_remote_retrieve_headers($response)->getAll()
        )
    ));
}
add_action('wp_ajax_airbnb_analyzer_debug_fetch', 'airbnb_analyzer_debug_fetch_callback');

/**
 * AJAX handler for viewing the debug log
 */
function airbnb_analyzer_view_log_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'airbnb_analyzer_log_nonce')) {
        wp_send_json_error(array('message' => 'Invalid security token. Please refresh the page and try again.'));
    }
    
    $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
    
    if (!file_exists($log_file)) {
        wp_send_json_error(array('message' => 'Debug log file does not exist.'));
    }
    
    $content = file_get_contents($log_file);
    
    if ($content === false) {
        wp_send_json_error(array('message' => 'Error reading debug log file.'));
    }
    
    wp_send_json_success(array('content' => $content));
}
add_action('wp_ajax_airbnb_analyzer_view_log', 'airbnb_analyzer_view_log_callback');

/**
 * AJAX handler for downloading the debug log
 */
function airbnb_analyzer_download_log_callback() {
    // Check nonce
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'airbnb_analyzer_log_nonce')) {
        wp_die('Invalid security token. Please refresh the page and try again.');
    }
    
    $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
    
    if (!file_exists($log_file)) {
        wp_die('Debug log file does not exist.');
    }
    
    // Set headers for file download
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="airbnb-analyzer-debug.log"');
    header('Content-Length: ' . filesize($log_file));
    
    // Output file contents
    readfile($log_file);
    exit;
}
add_action('wp_ajax_airbnb_analyzer_download_log', 'airbnb_analyzer_download_log_callback');

/**
 * AJAX handler for clearing the debug log
 */
function airbnb_analyzer_clear_log_callback() {
    // Check nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'airbnb_analyzer_log_nonce')) {
        wp_send_json_error(array('message' => 'Invalid security token. Please refresh the page and try again.'));
    }
    
    $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
    
    if (!file_exists($log_file)) {
        wp_send_json_error(array('message' => 'Debug log file does not exist.'));
    }
    
    $result = file_put_contents($log_file, '');
    
    if ($result === false) {
        wp_send_json_error(array('message' => 'Error clearing debug log file.'));
    }
    
    wp_send_json_success(array('message' => 'Debug log cleared successfully.'));
}
add_action('wp_ajax_airbnb_analyzer_clear_log', 'airbnb_analyzer_clear_log_callback');

/**
 * Log debug information to file
 * 
 * @param mixed $data The data to log
 * @param string $label Optional label for the log entry
 */
function airbnb_analyzer_debug_log($data, $label = '') {
    if (!get_option('airbnb_analyzer_debug_log_enabled')) {
        return;
    }
    
    $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
    
    $log_entry = '[' . date('Y-m-d H:i:s') . ']';
    if (!empty($label)) {
        $log_entry .= ' [' . $label . ']';
    }
    
    if (is_array($data) || is_object($data)) {
        $log_entry .= ' ' . print_r($data, true);
    } else {
        $log_entry .= ' ' . $data;
    }
    
    file_put_contents($log_file, $log_entry . PHP_EOL, FILE_APPEND);
}
?> 