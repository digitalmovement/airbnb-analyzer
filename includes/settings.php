<?php
/**
 * Settings functionality for AirBnB Listing Analyzer
 */

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

// Register settings
function airbnb_analyzer_register_settings() {
    register_setting('airbnb_analyzer_settings', 'airbnb_analyzer_claude_api_key');
}
add_action('admin_init', 'airbnb_analyzer_register_settings');

// Settings page content
function airbnb_analyzer_settings_page() {
    ?>
    <div class="wrap">
        <h1>AirBnB Analyzer Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('airbnb_analyzer_settings'); ?>
            <?php do_settings_sections('airbnb_analyzer_settings'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Claude API Key</th>
                    <td>
                        <input type="password" name="airbnb_analyzer_claude_api_key" value="<?php echo esc_attr(get_option('airbnb_analyzer_claude_api_key')); ?>" class="regular-text" />
                        <p class="description">Enter your Claude API key to enable AI-powered listing analysis.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
} 