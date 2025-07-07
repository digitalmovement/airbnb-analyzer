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
        'Analysis Statistics',
        'Statistics',
        'manage_options',
        'airbnb-analyzer-stats',
        'airbnb_analyzer_stats_page'
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

// Statistics page content
function airbnb_analyzer_stats_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    // Get overall statistics
    $total_requests = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $completed_requests = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed'");
    $pending_requests = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
    $error_requests = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'error'");
    $total_views = $wpdb->get_var("SELECT SUM(views) FROM $table_name WHERE status = 'completed'");
    $viewed_results = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'completed' AND views > 0");
    
    // Get expert analysis statistics
    $expert_analysis_requests = $wpdb->get_var("SELECT SUM(expert_analysis_requested) FROM $table_name WHERE expert_analysis_requested > 0");
    $unique_expert_analysis_users = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE expert_analysis_requested > 0");
    $expert_analysis_completion_rate = $completed_requests > 0 ? round(($unique_expert_analysis_users / $completed_requests) * 100, 1) : 0;
    
    // Get batch processing statistics
    $pending_batches = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE expert_batch_status = 'in_progress'");
    $completed_batches = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE expert_batch_status = 'completed'");
    $error_batches = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE expert_batch_status = 'error'");
    $total_batches = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE expert_batch_id IS NOT NULL");
    $batch_completion_rate = $total_batches > 0 ? round(($completed_batches / $total_batches) * 100, 1) : 0;
    $emails_sent = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE expert_analysis_email_sent = 1");
    
    // Get average processing time for completed batches
    $avg_processing_time = $wpdb->get_var(
        "SELECT AVG(TIMESTAMPDIFF(MINUTE, expert_batch_submitted_at, expert_batch_completed_at)) 
         FROM $table_name 
         WHERE expert_batch_status = 'completed' 
         AND expert_batch_submitted_at IS NOT NULL 
         AND expert_batch_completed_at IS NOT NULL"
    );
    $avg_processing_time = $avg_processing_time ? round($avg_processing_time, 0) : 0;
    
    // Get recent batch activity
    $recent_batches = $wpdb->get_results(
        "SELECT snapshot_id, listing_url, email, expert_batch_status, expert_batch_submitted_at, expert_batch_completed_at, expert_analysis_email_sent 
         FROM $table_name 
         WHERE expert_batch_id IS NOT NULL 
         ORDER BY expert_batch_submitted_at DESC 
         LIMIT 10"
    );
    
    // Get token usage statistics (if available)
    $total_tokens_used = $wpdb->get_var(
        "SELECT SUM(JSON_EXTRACT(expert_analysis_data, '$.output_tokens')) 
         FROM $table_name 
         WHERE expert_analysis_data IS NOT NULL 
         AND JSON_VALID(expert_analysis_data) = 1 
         AND JSON_EXTRACT(expert_analysis_data, '$.output_tokens') IS NOT NULL"
    );
    $total_tokens_used = $total_tokens_used ? intval($total_tokens_used) : 0;
    
    // Get recent completed analyses
    $recent_analyses = $wpdb->get_results(
        "SELECT snapshot_id, listing_url, email, views, last_viewed, date_created, date_completed, expert_analysis_requested 
         FROM $table_name 
         WHERE status = 'completed' 
         ORDER BY date_completed DESC 
         LIMIT 10"
    );
    
    // Get top viewed results
    $top_viewed = $wpdb->get_results(
        "SELECT snapshot_id, listing_url, email, views, last_viewed, date_completed 
         FROM $table_name 
         WHERE status = 'completed' AND views > 0 
         ORDER BY views DESC 
         LIMIT 10"
    );
    
    // Calculate engagement rate
    $engagement_rate = $completed_requests > 0 ? round(($viewed_results / $completed_requests) * 100, 1) : 0;
    $average_views = $completed_requests > 0 ? round($total_views / $completed_requests, 1) : 0;
    
    ?>
    <div class="wrap">
        <h1>📊 Analysis Statistics Dashboard</h1>
        
        <?php
        // Display processing results if redirected from process pending action
        if (isset($_GET['message'])) {
            $message = urldecode($_GET['message']);
            $processed = intval($_GET['processed'] ?? 0);
            $errors = intval($_GET['errors'] ?? 0);
            
            $class = ($errors > 0) ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . $class . ' is-dismissible" style="margin: 20px 0;"><p>' . esc_html($message) . '</p></div>';
        }
        ?>
        
        <div class="dashboard-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Total Requests</h3>
                <div style="font-size: 2em; font-weight: bold; color: #0073aa;"><?php echo $total_requests; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Completed</h3>
                <div style="font-size: 2em; font-weight: bold; color: #46b450;"><?php echo $completed_requests; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Pending</h3>
                <div style="font-size: 2em; font-weight: bold; color: #ffb900;"><?php echo $pending_requests; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Errors</h3>
                <div style="font-size: 2em; font-weight: bold; color: #dc3232;"><?php echo $error_requests; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Total Views</h3>
                <div style="font-size: 2em; font-weight: bold; color: #7c3aed;"><?php echo $total_views; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Engagement Rate</h3>
                <div style="font-size: 2em; font-weight: bold; color: #059669;"><?php echo $engagement_rate; ?>%</div>
                <small style="color: #666;"><?php echo $viewed_results; ?>/<?php echo $completed_requests; ?> viewed</small>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Expert Analysis</h3>
                <div style="font-size: 2em; font-weight: bold; color: #667eea;"><?php echo $expert_analysis_requests ?: 0; ?></div>
                <small style="color: #666;"><?php echo $unique_expert_analysis_users ?: 0; ?> unique users</small>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Expert Usage Rate</h3>
                <div style="font-size: 2em; font-weight: bold; color: #764ba2;"><?php echo $expert_analysis_completion_rate; ?>%</div>
                <small style="color: #666;">of completed analyses</small>
            </div>
        </div>
        
        <?php if ($total_batches > 0): ?>
        <h2 style="margin-top: 40px;">🚀 Batch Processing Statistics</h2>
        <div class="batch-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Total Batches</h3>
                <div style="font-size: 2em; font-weight: bold; color: #667eea;"><?php echo $total_batches; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Pending Batches</h3>
                <div style="font-size: 2em; font-weight: bold; color: <?php echo $pending_batches > 0 ? '#ff9800' : '#46b450'; ?>;"><?php echo $pending_batches; ?></div>
                <?php if ($pending_batches > 0): ?>
                    <small style="color: #666;">Currently processing</small>
                <?php endif; ?>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Completed Batches</h3>
                <div style="font-size: 2em; font-weight: bold; color: #46b450;"><?php echo $completed_batches; ?></div>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Batch Success Rate</h3>
                <div style="font-size: 2em; font-weight: bold; color: <?php echo $batch_completion_rate >= 90 ? '#46b450' : ($batch_completion_rate >= 70 ? '#ff9800' : '#dc3232'); ?>;"><?php echo $batch_completion_rate; ?>%</div>
                <small style="color: #666;"><?php echo $completed_batches; ?>/<?php echo $total_batches; ?> successful</small>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Avg Processing Time</h3>
                <div style="font-size: 2em; font-weight: bold; color: #7c3aed;"><?php echo $avg_processing_time; ?></div>
                <small style="color: #666;">minutes</small>
            </div>
            
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Emails Sent</h3>
                <div style="font-size: 2em; font-weight: bold; color: #059669;"><?php echo $emails_sent; ?></div>
                <small style="color: #666;">notifications delivered</small>
            </div>
            
            <?php if ($total_tokens_used > 0): ?>
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Total Tokens</h3>
                <div style="font-size: 2em; font-weight: bold; color: #6366f1;"><?php echo number_format($total_tokens_used); ?></div>
                <small style="color: #666;">output tokens generated</small>
            </div>
            <?php endif; ?>
            
            <?php if ($error_batches > 0): ?>
            <div class="stat-card" style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;">
                <h3 style="margin: 0; color: #666;">Failed Batches</h3>
                <div style="font-size: 2em; font-weight: bold; color: #dc3232;"><?php echo $error_batches; ?></div>
                <small style="color: #666;">require attention</small>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($pending_batches > 0 || $recent_batches): ?>
        <h2 style="margin-top: 40px;">📋 Recent Batch Activity</h2>
        <?php if ($pending_batches > 0): ?>
        <div style="margin: 20px 0; padding: 15px; background: #fff3e0; border-radius: 8px; border-left: 4px solid #ff9800;">
            <h4 style="margin: 0 0 10px 0; color: #e65100;">⏳ Pending Batches</h4>
            <p style="margin: 0;">There are currently <strong><?php echo $pending_batches; ?></strong> batch(es) being processed by Claude AI. These will complete automatically within 24 hours and users will receive email notifications.</p>
            <p style="margin: 10px 0 0 0;"><small>📡 Automatic status checking runs every 15 minutes</small></p>
        </div>
        <?php endif; ?>
        
        <?php if ($recent_batches): ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Listing URL</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Completed</th>
                    <th>Email Sent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_batches as $batch): ?>
                    <tr>
                        <td><?php echo esc_html($batch->email); ?></td>
                        <td><a href="<?php echo esc_url($batch->listing_url); ?>" target="_blank" title="<?php echo esc_attr($batch->listing_url); ?>"><?php echo esc_html(wp_trim_words($batch->listing_url, 5, '...')); ?></a></td>
                        <td>
                            <?php
                            $status_colors = array(
                                'in_progress' => '#ff9800',
                                'completed' => '#46b450',
                                'error' => '#dc3232',
                                'canceled' => '#666'
                            );
                            $status_color = isset($status_colors[$batch->expert_batch_status]) ? $status_colors[$batch->expert_batch_status] : '#666';
                            $status_text = ucfirst(str_replace('_', ' ', $batch->expert_batch_status));
                            ?>
                            <span style="color: <?php echo $status_color; ?>; font-weight: bold;">
                                <?php echo esc_html($status_text); ?>
                            </span>
                        </td>
                        <td><?php echo $batch->expert_batch_submitted_at ? esc_html(date('M j, H:i', strtotime($batch->expert_batch_submitted_at))) : '-'; ?></td>
                        <td><?php echo $batch->expert_batch_completed_at ? esc_html(date('M j, H:i', strtotime($batch->expert_batch_completed_at))) : '-'; ?></td>
                        <td><?php echo $batch->expert_analysis_email_sent ? '✅ Yes' : '❌ No'; ?></td>
                        <td>
                            <a href="<?php echo home_url('/airbnb-analysis-results/?id=' . $batch->snapshot_id); ?>" target="_blank" class="button button-small">View Results</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($pending_requests > 0): ?>
        <h2 style="margin-top: 40px;">⚠️ Legacy Processing</h2>
        <div style="margin: 20px 0; padding: 20px; background: #fff3e0; border-radius: 8px; border-left: 4px solid #ff9800;">
            <h4 style="margin: 0 0 15px 0;">Process Pending Requests</h4>
            <p>You have <strong><?php echo $pending_requests; ?></strong> pending legacy request(s) that need processing. These may be from cache clears or incomplete analyses.</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 15px 0;">
                <input type="hidden" name="action" value="airbnb_analyzer_process_pending">
                <?php wp_nonce_field('airbnb_analyzer_process_pending', 'process_pending_nonce'); ?>
                <button type="submit" class="button button-primary" style="background: #ff9800; border-color: #ff9800;">
                    🔄 Process All Pending Requests
                </button>
                <small style="display: block; margin-top: 8px; color: #666;">
                    This will reanalyze existing BrightData responses without making new API calls.
                </small>
            </form>
        </div>
        <?php endif; ?>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 30px;">
            <!-- Recent Completed Analyses -->
            <div>
                <h2>🔬 Recent Completed Analyses</h2>
                <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <table class="wp-list-table widefat fixed striped" style="border: none;">
                        <thead>
                            <tr>
                                <th>Listing</th>
                                <th>Email</th>
                                <th>Views</th>
                                <th>Expert</th>
                                <th>Completed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_analyses): ?>
                                <?php foreach ($recent_analyses as $analysis): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url($analysis->listing_url); ?>" target="_blank" title="<?php echo esc_attr($analysis->listing_url); ?>">
                                                <?php echo esc_html(parse_url($analysis->listing_url, PHP_URL_HOST) . '/.../rooms/...'); ?>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html($analysis->email); ?></td>
                                        <td>
                                            <span style="background: <?php echo $analysis->views > 0 ? '#46b450' : '#ccc'; ?>; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em;">
                                                <?php echo $analysis->views; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($analysis->expert_analysis_requested > 0): ?>
                                                <span style="background: #667eea; color: white; padding: 2px 8px; border-radius: 12px; font-size: 0.8em;">
                                                    ✨ <?php echo $analysis->expert_analysis_requested; ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #ccc;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M j, g:i A', strtotime($analysis->date_completed)); ?></td>
                                        <td>
                                            <a href="<?php echo site_url("/airbnb-analysis-results/?id=" . urlencode($analysis->snapshot_id)); ?>" 
                                               target="_blank" class="button button-small">
                                                View Results
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6">No completed analyses found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Top Viewed Results -->
            <div>
                <h2>👁️ Most Viewed Results</h2>
                <div style="background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <table class="wp-list-table widefat fixed striped" style="border: none;">
                        <thead>
                            <tr>
                                <th>Listing</th>
                                <th>Views</th>
                                <th>Last Viewed</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($top_viewed): ?>
                                <?php foreach ($top_viewed as $result): ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url($result->listing_url); ?>" target="_blank" title="<?php echo esc_attr($result->listing_url); ?>">
                                                <?php echo esc_html(parse_url($result->listing_url, PHP_URL_HOST) . '/.../rooms/...'); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span style="background: #7c3aed; color: white; padding: 4px 12px; border-radius: 15px; font-weight: bold;">
                                                <?php echo $result->views; ?>
                                            </span>
                                        </td>
                                        <td><?php echo $result->last_viewed ? date('M j, g:i A', strtotime($result->last_viewed)) : 'Never'; ?></td>
                                        <td>
                                            <a href="<?php echo site_url("/airbnb-analysis-results/?id=" . urlencode($result->snapshot_id)); ?>" 
                                               target="_blank" class="button button-small">
                                                View Results
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4">No viewed results found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px; padding: 20px; background: #f0f8ff; border-radius: 8px; border-left: 4px solid #0073aa;">
            <h3 style="margin: 0 0 10px 0;">📈 Key Insights</h3>
            <ul style="margin: 0; padding-left: 20px;">
                <li><strong>Engagement Rate:</strong> <?php echo $engagement_rate; ?>% of completed analyses are viewed by users</li>
                <li><strong>Average Views:</strong> <?php echo $average_views; ?> views per completed analysis</li>
                <li><strong>Success Rate:</strong> <?php echo $total_requests > 0 ? round(($completed_requests / $total_requests) * 100, 1) : 0; ?>% of requests complete successfully</li>
                <li><strong>Expert Analysis Adoption:</strong> <?php echo $expert_analysis_completion_rate; ?>% of users request expert analysis</li>
                <?php if ($pending_requests > 0): ?>
                <li><strong>Note:</strong> <?php echo $pending_requests; ?> requests are still processing</li>
                <?php endif; ?>
            </ul>
        </div>
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

// Handle processing pending requests
add_action('admin_post_airbnb_analyzer_process_pending', 'airbnb_analyzer_process_pending_requests');

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
 * Process all pending requests by reanalyzing existing BrightData responses
 */
function airbnb_analyzer_process_pending_requests() {
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }
    
    // Verify nonce
    if (!isset($_POST['process_pending_nonce']) || !wp_verify_nonce($_POST['process_pending_nonce'], 'airbnb_analyzer_process_pending')) {
        wp_die('Invalid nonce. Please try again.');
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'airbnb_analyzer_brightdata_requests';
    
    // Get all pending requests that have raw response data
    $pending_requests = $wpdb->get_results(
        "SELECT * FROM $table_name WHERE status = 'pending' AND raw_response_data IS NOT NULL ORDER BY date_created ASC"
    );
    
    $processed_count = 0;
    $error_count = 0;
    $errors = array();
    
    foreach ($pending_requests as $request) {
        try {
            // Parse the raw BrightData response
            $brightdata_response = json_decode($request->raw_response_data, true);
            
            if (empty($brightdata_response)) {
                $error_count++;
                $errors[] = "Snapshot {$request->snapshot_id}: No raw data available";
                continue;
            }
            
            // Convert Brightdata data to analyzer format
            $listing_data = brightdata_format_for_analyzer($brightdata_response);
            
            if (empty($listing_data)) {
                $error_count++;
                $errors[] = "Snapshot {$request->snapshot_id}: Could not format listing data";
                continue;
            }
            
            // Analyze the listing
            $analysis = null;
            if (!empty(get_option('airbnb_analyzer_claude_api_key'))) {
                $analysis = airbnb_analyzer_analyze_listing_with_claude($listing_data);
            } else {
                $analysis = airbnb_analyzer_analyze_listing($listing_data);
            }
            
            // Update request status to completed
            $updated = $wpdb->update(
                $table_name,
                array(
                    'status' => 'completed',
                    'response_data' => json_encode(array(
                        'listing_data' => $listing_data,
                        'analysis' => $analysis
                    )),
                    'date_completed' => current_time('mysql')
                ),
                array('snapshot_id' => $request->snapshot_id),
                array('%s', '%s', '%s'),
                array('%s')
            );
            
            if ($updated !== false) {
                $processed_count++;
                
                // Send updated analysis via email
                if (function_exists('send_analysis_email')) {
                    send_analysis_email($request->email, $request->listing_url, $analysis, null, $request->snapshot_id);
                }
            } else {
                $error_count++;
                $errors[] = "Snapshot {$request->snapshot_id}: Database update failed";
            }
            
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Snapshot {$request->snapshot_id}: " . $e->getMessage();
        }
    }
    
    // Prepare success message
    $message = "Processing complete! ";
    if ($processed_count > 0) {
        $message .= "Successfully processed {$processed_count} request(s). ";
    }
    if ($error_count > 0) {
        $message .= "Failed to process {$error_count} request(s). ";
    }
    
    // Add query args for the redirect
    $redirect_url = add_query_arg(array(
        'page' => 'airbnb-analyzer-stats',
        'processed' => $processed_count,
        'errors' => $error_count,
        'message' => urlencode($message)
    ), admin_url('admin.php'));
    
    // Log any errors
    if (!empty($errors) && function_exists('airbnb_analyzer_debug_log')) {
        airbnb_analyzer_debug_log("Pending request processing errors: " . implode('; ', $errors), 'Admin Process Pending');
    }
    
    wp_redirect($redirect_url);
    exit;
}

// Note: The settings page function is defined in settings.php