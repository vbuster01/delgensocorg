<?php
/**
 * PMPro Donations Reporting
 *
 * Custom donations report for Paid Memberships Pro that displays donation data
 * with advanced filtering and export capabilities.
 * 
 * Features:
 * - Display total donations received with detailed transaction history
 * - Filter by month and year (Fiscal Year: July-June or Calendar Year: Jan-Dec)
 * - Filter by specific user email address
 * - View member details including membership level
 * - Export filtered results to CSV
 * - Dashboard widget for quick access
 * 
 * Permissions:
 * Accessible to Administrators and Membership Managers (if using the PMPro 
 * Membership Manager Role Add On). To restrict to Administrators only, remove 
 * the pmpro_memberships_menu capability check from permission checks.
 * 
 * @author Graham Godfrey <gp54g@mac.com>
 * @version 3.0
 * @updated 2025-11-23
 **/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Function to fetch total donation data
function site_donations_get_total($month = null, $year = null, $year_type = 'fiscal', $user_email = null) {
    global $wpdb;
    
    $where_conditions = array("om.meta_key = 'donation_amount'", "om.meta_value > 0");
    $join_clauses = array();
    
    // Add user email filter if provided
    if (!empty($user_email)) {
        $join_clauses[] = "LEFT JOIN {$wpdb->users} u ON o.user_id = u.ID";
        $where_conditions[] = $wpdb->prepare("u.user_email = %s", $user_email);
    }
    
    // Build base query
    $query = "
        SELECT SUM(om.meta_value) 
        FROM {$wpdb->prefix}pmpro_membership_ordermeta om
        JOIN {$wpdb->prefix}pmpro_membership_orders o ON om.pmpro_membership_order_id = o.id
    ";
    
    // Add joins
    if (!empty($join_clauses)) {
        $query .= " " . implode(" ", $join_clauses);
    }
    
    // Add where conditions
    $query .= " WHERE " . implode(" AND ", $where_conditions);
    
    // Add year filter
    if (!empty($year)) {
        if ($year_type === 'fiscal') {
            $year_start = $year . '-07-01 00:00:00';
            $year_end = ($year + 1) . '-06-30 23:59:59';
        } else {
            $year_start = $year . '-01-01 00:00:00';
            $year_end = $year . '-12-31 23:59:59';
        }
        $query .= $wpdb->prepare(" AND o.timestamp >= %s AND o.timestamp <= %s", $year_start, $year_end);
    }
    
    // Add month filter
    if (!empty($month)) {
        $query .= $wpdb->prepare(" AND MONTH(o.timestamp) = %d", $month);
    }

    $total_donations = $wpdb->get_var($query);
    return $total_donations ? $total_donations : 0;
}

/**
 * Fetch all donations for the selected period including member details and membership level
 */
function site_donations_get_list($month = null, $year = null, $year_type = 'fiscal', $user_email = null) {
    global $wpdb;
    
    $where_conditions = array("om.meta_key = 'donation_amount'", "om.meta_value > 0");
    
    // Add user email filter if provided
    if (!empty($user_email)) {
        $where_conditions[] = $wpdb->prepare("u.user_email = %s", $user_email);
    }
    
    $query = "
        SELECT om.meta_value, o.timestamp, o.user_id, u.user_login, u.user_email,
               um1.meta_value as first_name, um2.meta_value as last_name,
               ml.name as membership_level
        FROM {$wpdb->prefix}pmpro_membership_ordermeta om
        JOIN {$wpdb->prefix}pmpro_membership_orders o ON om.pmpro_membership_order_id = o.id
        LEFT JOIN {$wpdb->users} u ON o.user_id = u.ID
        LEFT JOIN {$wpdb->usermeta} um1 ON u.ID = um1.user_id AND um1.meta_key = 'first_name'
        LEFT JOIN {$wpdb->usermeta} um2 ON u.ID = um2.user_id AND um2.meta_key = 'last_name'
        LEFT JOIN {$wpdb->prefix}pmpro_membership_levels ml ON o.membership_id = ml.id
        WHERE " . implode(" AND ", $where_conditions);
    
    // Add year filter
    if (!empty($year)) {
        if ($year_type === 'fiscal') {
            $year_start = $year . '-07-01 00:00:00';
            $year_end = ($year + 1) . '-06-30 23:59:59';
        } else {
            $year_start = $year . '-01-01 00:00:00';
            $year_end = $year . '-12-31 23:59:59';
        }
        $query .= $wpdb->prepare(" AND o.timestamp >= %s AND o.timestamp <= %s", $year_start, $year_end);
    }
    
    // Add month filter
    if (!empty($month)) {
        $query .= $wpdb->prepare(" AND MONTH(o.timestamp) = %d", $month);
    }

    $query .= " ORDER BY o.timestamp DESC";

    return $wpdb->get_results($query);
}

/**
 * Get list of all users who have made donations
 */
function site_donations_get_users() {
    global $wpdb;
    $query = "
        SELECT DISTINCT u.user_email, u.user_login,
               um1.meta_value as first_name, um2.meta_value as last_name
        FROM {$wpdb->prefix}pmpro_membership_ordermeta om
        JOIN {$wpdb->prefix}pmpro_membership_orders o ON om.pmpro_membership_order_id = o.id
        LEFT JOIN {$wpdb->users} u ON o.user_id = u.ID
        LEFT JOIN {$wpdb->usermeta} um1 ON u.ID = um1.user_id AND um1.meta_key = 'first_name'
        LEFT JOIN {$wpdb->usermeta} um2 ON u.ID = um2.user_id AND um2.meta_key = 'last_name'
        WHERE om.meta_key = 'donation_amount' 
        AND om.meta_value > 0
        AND u.user_email IS NOT NULL
        ORDER BY u.user_email ASC
    ";
    
    return $wpdb->get_results($query);
}

// Add a Custom Report to the Memberships > Reports Screen in Paid Memberships Pro.
global $pmpro_reports;
$pmpro_reports['my_donations'] = __('Donations Received', 'pmpro');

/**
 * Handle CSV export of donation data
 */
function site_donations_csv_export() {
    if (!isset($_GET['report']) || $_GET['report'] != 'my_donations' || !isset($_GET['export']) || $_GET['export'] != 'csv') {
        return;
    }
    
    // Check for proper user permissions
    if (!current_user_can('manage_options') && !current_user_can('pmpro_memberships_menu')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'pmpro'));
    }
    
    $month = isset($_GET['month']) ? intval($_GET['month']) : null;
    $year = isset($_GET['year']) ? intval($_GET['year']) : null;
    $year_type = isset($_GET['year_type']) ? sanitize_text_field($_GET['year_type']) : 'fiscal';
    $user_email = isset($_GET['user_email']) && !empty($_GET['user_email']) ? sanitize_email($_GET['user_email']) : null;
    
    // Get donations data
    $donations = site_donations_get_list($month, $year, $year_type, $user_email);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pmpro-donations-' . date('Y-m-d') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, array(
        __('Member ID', 'pmpro'),
        __('Username', 'pmpro'),
        __('Email', 'pmpro'),
        __('First Name', 'pmpro'),
        __('Last Name', 'pmpro'),
        __('Membership Level', 'pmpro'),
        __('Amount', 'pmpro'),
        __('Date', 'pmpro')
    ));
    
    // Add data rows
    if (!empty($donations)) {
        foreach ($donations as $donation) {
            fputcsv($output, array(
                $donation->user_id,
                $donation->user_login,
                $donation->user_email,
                $donation->first_name,
                $donation->last_name,
                $donation->membership_level,
                $donation->meta_value,
                $donation->timestamp
            ));
        }
    }
    
    fclose($output);
    exit();
}
add_action('admin_init', 'site_donations_csv_export');

/**
 * Create Donations Report widget for the PMPro Reports dashboard
 */
function site_donations_widget() {
    ?>
    <span id="pmpro_report_donations" class="pmpro_report-holder">
        <h2><?php _e('Donations Received', 'pmpro'); ?></h2>
        <p><?php _e('View the total donations received and individual donation entries.', 'pmpro'); ?></p>
        <p class="pmpro_report-button">
            <a class="button button-primary" href="<?php echo admin_url('admin.php?page=pmpro-reports&report=my_donations'); ?>"><?php _e('Details', 'pmpro'); ?></a>
        </p>
    </span>
    <?php
}

// This is the function PMPro is looking for based on the report ID
function pmpro_report_my_donations_widget() {
    site_donations_widget();
}

add_action('pmpro_reports_widget', 'pmpro_report_my_donations_widget');

/**
 * Donations Report page content
 */
function site_donations_page() {
    // Check for proper user permissions
    if (!current_user_can('manage_options') && !current_user_can('pmpro_memberships_menu')) {
       wp_die(__('You do not have sufficient permissions to access this page.', 'pmpro'));
    }
    
    $month = isset($_GET['month']) ? intval($_GET['month']) : null;
    $year = isset($_GET['year']) ? intval($_GET['year']) : null;
    $year_type = isset($_GET['year_type']) ? sanitize_text_field($_GET['year_type']) : 'fiscal';
    $user_email = isset($_GET['user_email']) && !empty($_GET['user_email']) ? sanitize_email($_GET['user_email']) : null;
    
    $total_donations = site_donations_get_total($month, $year, $year_type, $user_email);
    $donations = site_donations_get_list($month, $year, $year_type, $user_email);
    $donor_users = site_donations_get_users();
    
    // Get current year for both types
    $current_month = intval(date('n'));
    $current_year = intval(date('Y'));
    $current_fiscal_year = ($current_month >= 7) ? $current_year : $current_year - 1;
    ?>
    <h2><?php _e('Total Donations Received', 'pmpro'); ?></h2>
    <form method="get" action="">
        <input type="hidden" name="page" value="pmpro-reports" />
        <input type="hidden" name="report" value="my_donations" />
        
        <p>
            <label><?php _e('Year Type:', 'pmpro'); ?></label><br/>
            <label>
                <input type="radio" name="year_type" value="fiscal" <?php checked($year_type, 'fiscal'); ?> onchange="this.form.submit();" />
                <?php _e('Fiscal Year (July - June)', 'pmpro'); ?>
            </label>
            &nbsp;&nbsp;
            <label>
                <input type="radio" name="year_type" value="calendar" <?php checked($year_type, 'calendar'); ?> onchange="this.form.submit();" />
                <?php _e('Calendar Year (January - December)', 'pmpro'); ?>
            </label>
        </p>
        
        <label for="user_email"><?php _e('User Email:', 'pmpro'); ?></label>
        <select name="user_email" id="user_email">
            <option value=""><?php _e('All Users', 'pmpro'); ?></option>
            <?php 
            if (!empty($donor_users)) {
                foreach ($donor_users as $donor) {
                    $display_name = esc_html($donor->user_email);
                    $name_parts = array();
                    if (!empty($donor->first_name)) {
                        $name_parts[] = $donor->first_name;
                    }
                    if (!empty($donor->last_name)) {
                        $name_parts[] = $donor->last_name;
                    }
                    if (!empty($name_parts)) {
                        $full_name = implode(' ', $name_parts);
                        $display_name .= ' (' . esc_html($full_name) . ')';
                    }
                    ?>
                    <option value="<?php echo esc_attr($donor->user_email); ?>" <?php selected($user_email, $donor->user_email); ?>>
                        <?php echo $display_name; ?>
                    </option>
                <?php 
                }
            }
            ?>
        </select>
        
        <label for="month"><?php _e('Month:', 'pmpro'); ?></label>
        <select name="month" id="month">
            <option value=""><?php _e('All', 'pmpro'); ?></option>
            <?php for ($m = 1; $m <= 12; $m++) { ?>
                <option value="<?php echo $m; ?>" <?php selected($month, $m); ?>><?php echo date_i18n('F', mktime(0, 0, 0, $m, 10)); ?></option>
            <?php } ?>
        </select>
        
        <label for="year">
            <?php echo ($year_type === 'fiscal') ? __('Fiscal Year:', 'pmpro') : __('Calendar Year:', 'pmpro'); ?>
        </label>
        <select name="year" id="year">
            <option value=""><?php _e('All', 'pmpro'); ?></option>
            <?php 
            if ($year_type === 'fiscal') {
                for ($y = $current_fiscal_year; $y >= 2022; $y--) { 
                    $year_label = 'FY ' . $y . '-' . ($y + 1);
                    ?>
                    <option value="<?php echo $y; ?>" <?php selected($year, $y); ?>><?php echo $year_label; ?></option>
                <?php }
            } else {
                for ($y = $current_year; $y >= 2022; $y--) { 
                    ?>
                    <option value="<?php echo $y; ?>" <?php selected($year, $y); ?>><?php echo $y; ?></option>
                <?php }
            }
            ?>
        </select>
        <input type="submit" value="<?php _e('Filter', 'pmpro'); ?>" class="button" />
    </form>
    
    <p>
        <?php 
        echo sprintf(
            __('Total Donations: %s', 'pmpro'), 
            '<strong>$' . number_format_i18n($total_donations, 2) . '</strong>'
        ); 
        ?>
    </p>
    
    <p>
        <a href="<?php echo esc_url(add_query_arg(array('export' => 'csv', 'month' => $month, 'year' => $year, 'year_type' => $year_type, 'user_email' => $user_email))); ?>" class="button">
            <?php _e('Export to CSV', 'pmpro'); ?>
        </a>
    </p>
    
    <h3><?php _e('Donation Entries (Descending Order):', 'pmpro'); ?></h3>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php _e('Member ID', 'pmpro'); ?></th>
                <th><?php _e('Username', 'pmpro'); ?></th>
                <th><?php _e('Email', 'pmpro'); ?></th>
                <th><?php _e('Name', 'pmpro'); ?></th>
                <th><?php _e('Membership Level', 'pmpro'); ?></th>
                <th><?php _e('Amount', 'pmpro'); ?></th>
                <th><?php _e('Date', 'pmpro'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php 
            if (!empty($donations)) {
                foreach ($donations as $donation) { 
                    $name = trim($donation->first_name . ' ' . $donation->last_name);
                    if (empty($name)) {
                        $name = __('(not set)', 'pmpro');
                    }
                    $level_name = !empty($donation->membership_level) ? $donation->membership_level : __('(none)', 'pmpro');
                    ?>
                    <tr>
                        <td><?php echo intval($donation->user_id); ?></td>
                        <td><?php echo esc_html($donation->user_login); ?></td>
                        <td><?php echo esc_html($donation->user_email); ?></td>
                        <td><?php echo esc_html($name); ?></td>
                        <td><?php echo esc_html($level_name); ?></td>
                        <td><?php echo '$' . number_format_i18n($donation->meta_value, 2); ?></td>
                        <td><?php echo date_i18n(get_option('date_format'), strtotime($donation->timestamp)); ?></td>
                    </tr>
                <?php }
            } else { ?>
                <tr>
                    <td colspan="7"><?php _e('No donations found for the selected period.', 'pmpro'); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
    <?php
}

/**
 * This function is needed for compatibility with PMPro's function naming convention
 */
function pmpro_report_my_donations_page() {
    site_donations_page();
}

/**
 * Register the new report with PMPro
 */
function site_donations_register_report($reports) {
    $reports['my_donations'] = __('Donations Received', 'pmpro');
    return $reports;
}
add_filter('pmpro_reports', 'site_donations_register_report');