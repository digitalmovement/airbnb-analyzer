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
                            Show debugging information in analysis results
                        </label>
                        <p class="description">When enabled, debugging information will be displayed at the bottom of analysis results.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Debug Level</th>
                    <td>
                        <select name="airbnb_analyzer_debug_level">
                            <option value="basic" <?php selected(get_option('airbnb_analyzer_debug_level'), 'basic'); ?>>Basic (API response and extracted data)</option>
                            <option value="advanced" <?php selected(get_option('airbnb_analyzer_debug_level'), 'advanced'); ?>>Advanced (includes API request details and parsing steps)</option>
                            <option value="full" <?php selected(get_option('airbnb_analyzer_debug_level'), 'full'); ?>>Full (all available debugging information)</option>
                        </select>
                        <p class="description">Select the level of detail for debugging information.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Debug Logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="airbnb_analyzer_debug_log_enabled" value="1" <?php checked(get_option('airbnb_analyzer_debug_log_enabled'), true); ?> />
                            Log debugging information to file
                        </label>
                        <p class="description">When enabled, debugging information will be logged to a file in the plugin directory.</p>
                        <?php if (get_option('airbnb_analyzer_debug_log_enabled')): ?>
                            <div class="debug-log-info" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">
                                <p><strong>Debug Log Location:</strong> <?php echo AIRBNB_ANALYZER_PATH . 'debug.log'; ?></p>
                                <?php
                                $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
                                if (file_exists($log_file)) {
                                    $log_size = size_format(filesize($log_file));
                                    $log_modified = date('Y-m-d H:i:s', filemtime($log_file));
                                    echo "<p><strong>Log Size:</strong> {$log_size} | <strong>Last Modified:</strong> {$log_modified}</p>";
                                    echo '<p><a href="' . admin_url('admin-post.php?action=airbnb_analyzer_download_log&_wpnonce=' . wp_create_nonce('download_debug_log')) . '" class="button">Download Log</a> ';
                                    echo '<a href="' . admin_url('admin-post.php?action=airbnb_analyzer_clear_log&_wpnonce=' . wp_create_nonce('clear_debug_log')) . '" class="button" onclick="return confirm(\'Are you sure you want to clear the debug log?\');">Clear Log</a></p>';
                                } else {
                                    echo "<p>No log file exists yet.</p>";
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            
            <h2>Test Debugging</h2>
            <p>Enter an Airbnb listing URL to test the debugging functionality:</p>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Airbnb Listing URL</th>
                    <td>
                        <input type="url" id="test-listing-url" class="regular-text" placeholder="https://www.airbnb.com/rooms/12345" />
                        <button type="button" id="test-debug-button" class="button">Test Debug</button>
                        <p class="description">This will fetch and display the raw data for the listing without saving any analysis.</p>
                    </td>
                </tr>
            </table>
            
            <div id="debug-test-results" style="display: none; margin-top: 20px; padding: 15px; background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Debug Test Results</h3>
                <div class="debug-toggle">
                    <button type="button" class="button toggle-raw-data">Toggle Raw API Data</button>
                    <button type="button" class="button toggle-extracted-data">Toggle Extracted Data</button>
                    <button type="button" class="button toggle-request-details">Toggle Request Details</button>
                </div>
                
                <div class="debug-section raw-data" style="display:none; margin-top: 15px;">
                    <h4>Raw API Data</h4>
                    <pre id="raw-api-data" style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow: auto; max-height: 500px;"></pre>
                </div>
                
                <div class="debug-section extracted-data" style="display:none; margin-top: 15px;">
                    <h4>Extracted Data</h4>
                    <pre id="extracted-data" style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow: auto; max-height: 500px;"></pre>
                </div>
                
                <div class="debug-section request-details" style="display:none; margin-top: 15px;">
                    <h4>Request Details</h4>
                    <pre id="request-details" style="background: #fff; padding: 15px; border: 1px solid #ddd; overflow: auto; max-height: 500px;"></pre>
                </div>
                
                <div class="copy-buttons" style="margin-top: 15px;">
                    <button type="button" class="button copy-all-debug">Copy All Debug Data</button>
                    <span class="copy-success" style="display: none; margin-left: 10px; color: green;">Copied to clipboard!</span>
                </div>
            </div>
            
            <?php submit_button(); ?>
        </form>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Test debug button click
        $('#test-debug-button').on('click', function() {
            var listingUrl = $('#test-listing-url').val();
            if (!listingUrl) {
                alert('Please enter a valid Airbnb listing URL');
                return;
            }
            
            $(this).prop('disabled', true).text('Loading...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'airbnb_analyzer_test_debug',
                    nonce: '<?php echo wp_create_nonce('airbnb_analyzer_test_debug'); ?>',
                    listing_url: listingUrl
                },
                success: function(response) {
                    $('#test-debug-button').prop('disabled', false).text('Test Debug');
                    
                    if (response.success) {
                        $('#raw-api-data').text(JSON.stringify(response.data.raw_data, null, 2));
                        $('#extracted-data').text(JSON.stringify(response.data.extracted_data, null, 2));
                        $('#request-details').text(JSON.stringify(response.data.request_details, null, 2));
                        $('#debug-test-results').show();
                    } else {
                        alert(response.data.message || 'An error occurred');
                    }
                },
                error: function() {
                    $('#test-debug-button').prop('disabled', false).text('Test Debug');
                    alert('An error occurred. Please try again.');
                }
            });
        });
        
        // Toggle debug sections
        $('.toggle-raw-data').on('click', function() {
            $('.debug-section.raw-data').toggle();
        });
        
        $('.toggle-extracted-data').on('click', function() {
            $('.debug-section.extracted-data').toggle();
        });
        
        $('.toggle-request-details').on('click', function() {
            $('.debug-section.request-details').toggle();
        });
        
        // Copy all debug data
        $('.copy-all-debug').on('click', function() {
            var allData = {
                raw_data: JSON.parse($('#raw-api-data').text()),
                extracted_data: JSON.parse($('#extracted-data').text()),
                request_details: JSON.parse($('#request-details').text())
            };
            
            var textArea = document.createElement('textarea');
            textArea.value = JSON.stringify(allData, null, 2);
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            
            $('.copy-success').fadeIn().delay(2000).fadeOut();
        });
    });
    </script>
    <?php
}

// Handle debug log download
add_action('admin_post_airbnb_analyzer_download_log', 'airbnb_analyzer_download_debug_log');

function airbnb_analyzer_download_debug_log() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    
    // Verify nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'download_debug_log')) {
        wp_die('Invalid nonce. Please try again.');
    }
    
    $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
    
    if (file_exists($log_file)) {
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="airbnb-analyzer-debug.log"');
        header('Content-Length: ' . filesize($log_file));
        readfile($log_file);
        exit;
    } else {
        wp_die('Debug log file does not exist.');
    }
}

// Handle debug log clearing
add_action('admin_post_airbnb_analyzer_clear_log', 'airbnb_analyzer_clear_debug_log');

function airbnb_analyzer_clear_debug_log() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    
    // Verify nonce
    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'clear_debug_log')) {
        wp_die('Invalid nonce. Please try again.');
    }
    
    $log_file = AIRBNB_ANALYZER_PATH . 'debug.log';
    
    if (file_exists($log_file)) {
        file_put_contents($log_file, '');
    }
    
    wp_redirect(admin_url('options-general.php?page=airbnb-analyzer-settings&cleared=1'));
    exit;
}

// Add AJAX handler for test debug
add_action('wp_ajax_airbnb_analyzer_test_debug', 'airbnb_analyzer_test_debug');

function airbnb_analyzer_test_debug() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'You do not have sufficient permissions to access this feature.'));
    }
    
    // Verify nonce
    check_ajax_referer('airbnb_analyzer_test_debug', 'nonce');
    
    // Get listing URL
    $listing_url = isset($_POST['listing_url']) ? sanitize_text_field($_POST['listing_url']) : '';
    
    if (empty($listing_url)) {
        wp_send_json_error(array('message' => 'Please provide a valid Airbnb listing URL'));
    }
    
    // Extract listing ID from URL
    if (preg_match('/\/rooms\/(\d+)/', $listing_url, $matches)) {
        $listing_id = $matches[1];
    } elseif (preg_match('/\/h\/([^\/\?]+)/', $listing_url, $matches)) {
        // Handle /h/ style URLs
        $listing_slug = $matches[1];
        // We need to make an initial request to get the actual listing ID
        $response = wp_remote_get($listing_url);
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Error fetching listing: ' . $response->get_error_message()));
        }
        $body = wp_remote_retrieve_body($response);
        if (preg_match('/\"id\":\"StayListing:(\d+)\"/', $body, $id_matches)) {
            $listing_id = $id_matches[1];
        } else {
            wp_send_json_error(array('message' => 'Could not extract listing ID from URL'));
        }
    } else {
        wp_send_json_error(array('message' => 'Invalid Airbnb listing URL format'));
    }
    
    // Construct API URL
    $api_url = construct_airbnb_api_url($listing_id);
    
    // Use WordPress HTTP API to fetch listing data
    $args = array(
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'Accept' => 'application/json',
            'X-Airbnb-API-Key' => 'd306zoyjsyarp7ifhu67rjxn52tv0t20' // This is a public key used by Airbnb's website
        )
    );
    
    $request_time = microtime(true);
    $response = wp_remote_get($api_url, $args);
    $response_time = microtime(true) - $request_time;
    
    if (is_wp_error($response)) {
        wp_send_json_error(array('message' => 'Error fetching listing data: ' . $response->get_error_message()));
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        wp_send_json_error(array('message' => 'Error fetching listing data: HTTP ' . $status_code));
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error(array('message' => 'Error parsing API response: ' . json_last_error_msg()));
    }
    
    // Parse the API response
    $listing_data = parse_airbnb_api_response($data, $listing_id);
    
    // Prepare request details
    $request_details = array(
        'listing_id' => $listing_id,
        'api_url' => $api_url,
        'request_time' => date('Y-m-d H:i:s'),
        'response_time_seconds' => round($response_time, 2),
        'status_code' => $status_code,
        'headers' => wp_remote_retrieve_headers($response)->getAll(),
        'php_version' => PHP_VERSION,
        'wordpress_version' => get_bloginfo('version'),
        'plugin_version' => '1.0.0'
    );
    
    // Return debug data
    wp_send_json_success(array(
        'raw_data' => $data,
        'extracted_data' => $listing_data,
        'request_details' => $request_details
    ));
}

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