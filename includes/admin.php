<?php
/**
 * Admin functionality for AirBnB Listing Analyzer
 */

// Add admin menu
function airbnb_analyzer_add_admin_menu() {
    add_submenu_page(
        'options-general.php',
        'AirBnB Analyzer Emails',
        'AirBnB Analyzer Emails',
        'manage_options',
        'airbnb-analyzer-emails',
        'airbnb_analyzer_emails_page'
    );
}
add_action('admin_menu', 'airbnb_analyzer_add_admin_menu');

// Emails page content
function airbnb_analyzer_emails_page() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
    
    // Handle CSV export
    if (isset($_GET['export']) && $_GET['export'] == 'csv') {
        airbnb_analyzer_export_emails_csv();
        exit;
    }
    
    // Get emails from database
    $emails = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date_added DESC");
    
    ?>
    <div class="wrap">
        <h1>AirBnB Analyzer Emails</h1>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <a href="<?php echo esc_url(add_query_arg('export', 'csv')); ?>" class="button">Export to CSV</a>
            </div>
            <br class="clear">
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Listing URL</th>
                    <th>Date Added</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($emails)) : ?>
                    <tr>
                        <td colspan="4">No emails found.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($emails as $email) : ?>
                        <tr>
                            <td><?php echo esc_html($email->id); ?></td>
                            <td><?php echo esc_html($email->email); ?></td>
                            <td><?php echo esc_html($email->listing_url); ?></td>
                            <td><?php echo esc_html($email->date_added); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Export emails as CSV
function airbnb_analyzer_export_emails_csv() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
    
    // Get emails from database
    $emails = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date_added DESC");
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="airbnb-analyzer-emails.csv"');
    
    // Create a file pointer
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, array('ID', 'Email', 'Listing URL', 'Date Added'));
    
    // Add data rows
    foreach ($emails as $email) {
        fputcsv($output, array(
            $email->id,
            $email->email,
            $email->listing_url,
            $email->date_added
        ));
    }
    
    // Close the file pointer
    fclose($output);
    exit;
}

/**
 * Render the settings page
 */
function airbnb_analyzer_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('airbnb_analyzer_options');
            do_settings_sections('airbnb_analyzer_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Claude API Key</th>
                    <td>
                        <input type="text" name="airbnb_analyzer_claude_api_key" value="<?php echo esc_attr(get_option('airbnb_analyzer_claude_api_key')); ?>" class="regular-text" />
                        <p class="description">Enter your Claude API key to enable AI analysis.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Enable Debugging</th>
                    <td>
                        <input type="checkbox" name="airbnb_analyzer_enable_debugging" value="1" <?php checked(get_option('airbnb_analyzer_enable_debugging'), true); ?> />
                        <p class="description">Show raw API data and extracted data for debugging purposes.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
} 