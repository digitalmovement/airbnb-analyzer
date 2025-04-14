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
            
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
} 