<?php
/**
 * Admin functionality for AirBnB Listing Analyzer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Add admin menu
function airbnb_analyzer_add_admin_menu() {
    add_menu_page(
        'AirBnB Analyzer',
        'AirBnB Analyzer',
        'manage_options',
        'airbnb-analyzer',
        'airbnb_analyzer_admin_page',
        'dashicons-chart-area',
        30
    );
    
    add_submenu_page(
        'airbnb-analyzer',
        'Email List',
        'Email List',
        'manage_options',
        'airbnb-analyzer-emails',
        'airbnb_analyzer_emails_page'
    );
    
    add_submenu_page(
        'airbnb-analyzer',
        'Export Emails',
        'Export Emails',
        'manage_options',
        'airbnb-analyzer-export',
        'airbnb_analyzer_export_page'
    );
}
add_action('admin_menu', 'airbnb_analyzer_add_admin_menu');

// Admin page content
function airbnb_analyzer_admin_page() {
    ?>
    <div class="wrap">
        <h1>AirBnB Analyzer Dashboard</h1>
        <p>Welcome to the AirBnB Analyzer plugin dashboard.</p>
        
        <h2>Usage</h2>
        <p>Use the shortcode <code>[airbnb_analyzer]</code> on any page to display the AirBnB listing analyzer form.</p>
        
        <h2>Statistics</h2>
        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
        $total_emails = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $recent_emails = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date_added DESC LIMIT 5");
        ?>
        <p>Total email submissions: <strong><?php echo $total_emails; ?></strong></p>
        
        <?php if ($recent_emails): ?>
            <h3>Recent Submissions</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Listing URL</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_emails as $email): ?>
                        <tr>
                            <td><?php echo esc_html($email->email); ?></td>
                            <td><a href="<?php echo esc_url($email->listing_url); ?>" target="_blank"><?php echo esc_html($email->listing_url); ?></a></td>
                            <td><?php echo esc_html($email->date_added); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p><a href="<?php echo admin_url('admin.php?page=airbnb-analyzer-emails'); ?>" class="button">View All Emails</a></p>
        <?php endif; ?>
    </div>
    <?php
}

// Emails page content
function airbnb_analyzer_emails_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
    
    // Handle pagination
    $per_page = 20;
    $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    
    // Get total count
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_items / $per_page);
    
    // Get emails for current page
    $emails = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name ORDER BY date_added DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));
    
    ?>
    <div class="wrap">
        <h1>Email Submissions</h1>
        
        <p>
            <a href="<?php echo admin_url('admin.php?page=airbnb-analyzer-export'); ?>" class="button">Export to CSV</a>
        </p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Email</th>
                    <th>Listing URL</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($emails): ?>
                    <?php foreach ($emails as $email): ?>
                        <tr>
                            <td><?php echo esc_html($email->id); ?></td>
                            <td><?php echo esc_html($email->email); ?></td>
                            <td><a href="<?php echo esc_url($email->listing_url); ?>" target="_blank"><?php echo esc_html($email->listing_url); ?></a></td>
                            <td><?php echo esc_html($email->date_added); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4">No email submissions found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if ($total_pages > 1): ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php echo $total_items; ?> items</span>
                    <span class="pagination-links">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ));
                        ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

// Export page content
function airbnb_analyzer_export_page() {
    ?>
    <div class="wrap">
        <h1>Export Email Submissions</h1>
        <p>Click the button below to export all email submissions to a CSV file.</p>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="airbnb_analyzer_export_csv">
            <?php wp_nonce_field('airbnb_analyzer_export_csv', 'airbnb_analyzer_export_nonce'); ?>
            <?php submit_button('Export to CSV', 'primary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

// Handle CSV export
add_action('admin_post_airbnb_analyzer_export_csv', 'airbnb_analyzer_export_csv');

function airbnb_analyzer_export_csv() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    
    // Verify nonce
    if (!isset($_POST['airbnb_analyzer_export_nonce']) || !wp_verify_nonce($_POST['airbnb_analyzer_export_nonce'], 'airbnb_analyzer_export_csv')) {
        wp_die('Invalid nonce. Please try again.');
    }
    
    // Get emails from database
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_emails';
    $emails = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date_added DESC");
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="airbnb-analyzer-emails.csv"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add header row
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