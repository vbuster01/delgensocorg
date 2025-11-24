<?php
/**
 * PMPro Grace Period Testing Tool
 *
 * This testing snippet provides admin tools to:
 * 1. View member grace period status
 * 2. Check saved original expiration dates
 * 3. Manually trigger grace period for testing
 * 4. Simulate expiration emails
 * 5. Filter and search members by various criteria
 *
 * Features:
 * - Pre-Grace Expiration date tracking
 * - Status and level filtering
 * - Search functionality
 * - Column sorting
 *
 * Add this to Code Snippets for testing purposes only.
 */

// Only allow admin access to these functions
if (!function_exists('pmpro_grace_period_test_has_access')) {
    function pmpro_grace_period_test_has_access() {
        return current_user_can('manage_options');
    }
}

/**
 * Add admin menu page for testing
 */
function pmpro_grace_period_test_add_menu() {
    $page_hook = add_menu_page(
        'PMPro Grace Period Tester',
        'Grace Period Test',
        'manage_options',
        'pmpro-grace-period-test',
        'pmpro_grace_period_test_admin_page',
        'dashicons-buddicons-groups',
        90
    );
    
    // Add JS on our admin page only
    add_action('admin_print_scripts-' . $page_hook, 'pmpro_grace_period_test_enqueue_resources');
}
add_action('admin_menu', 'pmpro_grace_period_test_add_menu');

/**
 * Enqueue JS resources
 */
function pmpro_grace_period_test_enqueue_resources() {
    // Add debug log entry
    error_log('PMPro Grace Period Tester - Loading admin page resources');
    
    // Enqueue jQuery
    wp_enqueue_script('jquery');
    
    // Add datatables
    wp_enqueue_script('jquery-datatables', 'https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js', array('jquery'), '1.13.4', true);
    wp_enqueue_style('jquery-datatables-style', 'https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css', array(), '1.13.4');
}

/**
 * Render the admin page
 */
function pmpro_grace_period_test_admin_page() {
    // Security check
    if (!pmpro_grace_period_test_has_access()) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    
    // Get action and user ID from URL
    $action = isset($_GET['test_action']) ? sanitize_text_field($_GET['test_action']) : '';
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

    // Process actions if we have a user ID
    if ($user_id > 0) {
        if ($action === 'trigger_grace') {
            pmpro_grace_period_test_trigger_grace($user_id);
            echo '<div class="notice notice-success is-dismissible"><p>Grace period has been triggered for User ID ' . $user_id . '.</p></div>';
        } elseif ($action === 'reset_grace') {
            pmpro_grace_period_test_reset_grace($user_id);
            echo '<div class="notice notice-warning is-dismissible"><p>Grace period data has been reset for User ID ' . $user_id . '.</p></div>';
        } elseif ($action === 'simulate_first_email') {
            pmpro_grace_period_test_send_email($user_id, 28);
            echo '<div class="notice notice-info is-dismissible"><p>Simulated 28-day expiration warning email for User ID ' . $user_id . '.</p></div>';
        } elseif ($action === 'simulate_second_email') {
            pmpro_grace_period_test_send_email($user_id, 10);
            echo '<div class="notice notice-info is-dismissible"><p>Simulated 10-day expiration warning email for User ID ' . $user_id . '.</p></div>';
        } elseif ($action === 'simulate_expired_email') {
            pmpro_grace_period_test_send_expired_email($user_id);
            echo '<div class="notice notice-info is-dismissible"><p>Simulated expired membership email for User ID ' . $user_id . '.</p></div>';
        } elseif ($action === 'simulate_grace_expiry') {
            pmpro_grace_period_test_simulate_grace_expiry($user_id);
            echo '<div class="notice notice-error is-dismissible"><p>Simulated grace period expiry for User ID ' . $user_id . '.</p></div>';
        }
    }
    
    // Get PMPro members
    $members = pmpro_grace_period_test_get_members();
    ?>
    <div class="wrap">
        <h1>PMPro Grace Period Testing Tool</h1>
        
        <div class="notice notice-warning">
            <p><strong>Warning:</strong> This tool is for testing purposes only. It can modify membership data.</p>
        </div>
        
        <h2>Member Status and Testing</h2>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="filter-grace-status">Filter by Status:</label>
                <select id="filter-grace-status">
                    <option value="">All Members</option>
                    <option value="in-grace">In Grace Period</option>
                    <option value="not-in-grace">Not In Grace Period</option>
                </select>
                
                <label for="filter-level" style="margin-left: 15px;">Filter by Level:</label>
                <select id="filter-level">
                    <option value="">All Levels</option>
                    <?php
                    // Get all membership levels for filtering
                    $all_levels = pmpro_getAllLevels(true, true);
                    if (!empty($all_levels)) {
                        foreach ($all_levels as $level) {
                            echo '<option value="' . esc_attr($level->id) . '">' . esc_html($level->name) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            <div class="alignright">
                <input type="text" id="member-search" placeholder="Search members..." />
            </div>
            <br class="clear">
        </div>
        
        <table id="pmpro-grace-members-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>User ID</th>
                    <th>Username</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Level</th>
                    <th>Expiration Date</th>
                    <th>Pre-Grace Expiration</th>
                    <th>Grace Period Status</th>
                    <th>Original Expiration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($members)) : ?>
                    <?php foreach ($members as $member) : ?>
                        <tr>
                            <td><?php echo $member->ID; ?></td>
                            <td><?php echo $member->user_login; ?></td>
                            <td><?php echo $member->display_name; ?></td>
                            <td><?php echo $member->user_email; ?></td>
                            <td>
                                <?php
                                $level = pmpro_getMembershipLevelForUser($member->ID);
                                echo !empty($level) && !empty($level->id) ? $level->name . ' (ID: ' . $level->id . ')' : 'None';
                                ?>
                            </td>
                            <td>
                                <?php
                                global $wpdb;
                                // Get the actual end date from the database
                                $enddate = $wpdb->get_var($wpdb->prepare(
                                    "SELECT enddate FROM $wpdb->pmpro_memberships_users 
                                    WHERE user_id = %d AND status = 'active' 
                                    ORDER BY id DESC LIMIT 1",
                                    $member->ID
                                ));
                                
                                echo !empty($enddate) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($enddate)) : 'N/A';
                                ?>
                            </td>
                            <td>
                                <?php
                                // Get the pre-grace expiration date (current exp date + grace period days)
                                $pre_grace_exp_date = '';
                                if (!empty($enddate)) {
                                    // If in grace period, show when the grace period started
                                    $grace_level = get_user_meta($member->ID, 'pmpro_grace_level', true);
                                    if (!empty($grace_level)) {
                                        $grace_enddate = get_user_meta($member->ID, 'pmpro_grace_enddate', true);
                                        if (!empty($grace_enddate)) {
                                            $pre_grace_exp_date = date('Y-m-d H:i:s', strtotime($grace_enddate . ' - 28 days'));
                                        }
                                    } else {
                                        // If not in grace period, calculate when it would start
                                        $pre_grace_exp_date = $enddate;
                                    }
                                }
                                echo !empty($pre_grace_exp_date) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($pre_grace_exp_date)) : 'N/A';
                                ?>
                            </td>
                            <td>
                                <?php
                                $grace_level = get_user_meta($member->ID, 'pmpro_grace_level', true);
                                $grace_enddate = get_user_meta($member->ID, 'pmpro_grace_enddate', true);
                                
                                if (!empty($grace_level) && !empty($grace_enddate)) {
                                    $days_left = ceil((strtotime($grace_enddate) - current_time('timestamp')) / (60 * 60 * 24));
                                    echo '<span style="color: #FF6600;">In Grace Period</span><br/>';
                                    echo 'Days Left: ' . $days_left . '<br/>';
                                    echo 'Ends: ' . date_i18n(get_option('date_format'), strtotime($grace_enddate));
                                } else {
                                    echo 'Not in grace period';
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                $original_enddate = get_user_meta($member->ID, 'pmpro_original_enddate', true);
                                echo !empty($original_enddate) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($original_enddate)) : 'N/A';
                                ?>
                            </td>
                            <td>
                                <div class="row-actions">
                                    <?php if (!empty($level)) : ?>
                                        <span class="trigger">
                                            <a href="<?php echo admin_url('admin.php?page=pmpro-grace-period-test&test_action=trigger_grace&user_id=' . $member->ID); ?>" 
                                               onclick="return confirm('Are you sure you want to trigger grace period for this user?');">
                                                Trigger Grace Period
                                            </a> | 
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($grace_level)) : ?>
                                        <span class="reset">
                                            <a href="<?php echo admin_url('admin.php?page=pmpro-grace-period-test&test_action=reset_grace&user_id=' . $member->ID); ?>" 
                                               onclick="return confirm('Are you sure you want to reset grace period data for this user?');">
                                                Reset Grace Data
                                            </a> | 
                                        </span>
                                        
                                        <span class="simulate-expiry">
                                            <a href="<?php echo admin_url('admin.php?page=pmpro-grace-period-test&test_action=simulate_grace_expiry&user_id=' . $member->ID); ?>" 
                                               onclick="return confirm('Are you sure you want to simulate grace period expiry for this user? This will cancel their membership.');">
                                                Simulate Grace Expiry
                                            </a> | 
                                        </span>
                                    <?php endif; ?>
                                    
                                    <span class="email">
                                        <a href="<?php echo admin_url('admin.php?page=pmpro-grace-period-test&test_action=simulate_first_email&user_id=' . $member->ID); ?>">
                                            Send 28-Day Email
                                        </a> | 
                                    </span>
                                    
                                    <span class="email">
                                        <a href="<?php echo admin_url('admin.php?page=pmpro-grace-period-test&test_action=simulate_second_email&user_id=' . $member->ID); ?>">
                                            Send 10-Day Email
                                        </a> | 
                                    </span>
                                    
                                    <span class="email">
                                        <a href="<?php echo admin_url('admin.php?page=pmpro-grace-period-test&test_action=simulate_expired_email&user_id=' . $member->ID); ?>">
                                            Send Expired Email
                                        </a>
                                    </span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="9">No members found.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <h3>How to Use This Testing Tool</h3>
        <ol>
            <li><strong>Trigger Grace Period</strong> - This will simulate the membership expiring and entering grace period. It saves the current expiration date as the original expiration date.</li>
            <li><strong>Reset Grace Data</strong> - This clears all grace period meta data for a user (but does not change their membership status).</li>
            <li><strong>Simulate Grace Expiry</strong> - This simulates what happens when the grace period ends (cancels membership and sends expiry email).</li>
            <li><strong>Send Test Emails</strong> - These options let you manually trigger the warning and expiration emails.</li>
        </ol>
        
        <h3>Filtering &amp; Searching</h3>
        <ul>
            <li><strong>Status Filter</strong> - Filter members by grace period status</li>
            <li><strong>Level Filter</strong> - Filter members by membership level</li>
            <li><strong>Search</strong> - Search by name, username, email, or user ID</li>
            <li><strong>Column Sorting</strong> - Click any column header to sort by that field</li>
        </ul>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Initialize DataTables
        var membersTable = $('#pmpro-grace-members-table').DataTable({
            "order": [[0, "desc"]],
            "pageLength": 25,
            "lengthMenu": [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
            "columnDefs": [
                { "orderable": false, "targets": 9 } // Disable sorting on actions column
            ],
            "dom": '<"top"fl>rt<"bottom"ip><"clear">',
            "language": {
                "search": "Quick Search:",
                "lengthMenu": "Show _MENU_ members per page",
                "info": "Showing _START_ to _END_ of _TOTAL_ members"
            }
        });
        
        // Custom filtering
        $('#filter-grace-status').on('change', function() {
            var status = $(this).val();
            
            if (status === 'in-grace') {
                membersTable.column(7).search('In Grace Period', true, false).draw();
            } else if (status === 'not-in-grace') {
                membersTable.column(7).search('Not in grace period', true, false).draw();
            } else {
                membersTable.column(7).search('').draw();
            }
        });
        
        // Level filtering
        $('#filter-level').on('change', function() {
            var level = $(this).val();
            
            if (level) {
                membersTable.column(4).search('\\(ID: ' + level + '\\)', true, false).draw();
            } else {
                membersTable.column(4).search('').draw();
            }
        });
        
        // Global search box
        $('#member-search').keyup(function() {
            membersTable.search($(this).val()).draw();
        });
    });
    </script>
    <?php
}

// Add a small amount of CSS to help with date sorting
function pmpro_grace_period_test_admin_css() {
    ?>
    <style type="text/css">
        .date-column {
            min-width: 150px;
        }
        #pmpro-grace-members-table th.sorting {
            cursor: pointer;
        }
        #pmpro-grace-members-table th.sorting:hover {
            background-color: #f0f0f0;
        }
    </style>
    <?php
}
add_action('admin_head-toplevel_page_pmpro-grace-period-test', 'pmpro_grace_period_test_admin_css');

/**
 * Get all PMPro members
 */
function pmpro_grace_period_test_get_members() {
    global $wpdb;
    
    // Debug log entry
    error_log("PMPro Grace Period Tester - Retrieving member list");
    
    // Get users with PMPro membership levels
    $sql = "SELECT DISTINCT u.* FROM $wpdb->users u 
            LEFT JOIN $wpdb->pmpro_memberships_users mu ON u.ID = mu.user_id AND mu.status = 'active'
            LEFT JOIN $wpdb->usermeta grace ON u.ID = grace.user_id AND grace.meta_key = 'pmpro_grace_level'
            WHERE mu.membership_id IS NOT NULL OR grace.meta_value IS NOT NULL
            ORDER BY u.ID DESC";
    
    $members = $wpdb->get_results($sql);
    
    error_log("PMPro Grace Period Tester - Found " . count($members) . " members");
    
    return $members;
}

/**
 * Trigger grace period for a user manually
 */
function pmpro_grace_period_test_trigger_grace($user_id) {
    global $wpdb;
    
    // Debug log entry
    error_log("PMPro Grace Period Tester - Triggering grace period for user ID: $user_id");
    
    // Get user's active membership from the database
    $membership = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $wpdb->pmpro_memberships_users 
        WHERE user_id = %d AND status = 'active' 
        ORDER BY id DESC LIMIT 1",
        $user_id
    ));
    
    if (!empty($membership)) {
        // Save the original expiration date
        if (!empty($membership->enddate)) {
            $original_enddate = $membership->enddate;
            update_user_meta($user_id, 'pmpro_original_enddate', $original_enddate);
            error_log("PMPro Grace Period Tester - Saved original enddate: $original_enddate for user ID: $user_id");
        }
        
        // Calculate new grace period end date
        $grace_enddate = date('Y-m-d H:i:s', strtotime('+28 days', current_time('timestamp')));
        error_log("PMPro Grace Period Tester - New grace enddate: $grace_enddate for user ID: $user_id");
        
        // Set up grace period data
        $grace_level_data = array(
            'user_id' => $user_id,
            'membership_id' => $membership->membership_id,
            'enddate' => $grace_enddate
        );
        
        // Update membership with grace period
        $changed = pmpro_changeMembershipLevel($grace_level_data, $user_id);
        error_log("PMPro Grace Period Tester - Membership level change result: " . ($changed ? 'success' : 'failure'));
        
        // Set grace period flags
        update_user_meta($user_id, 'pmpro_grace_level', $membership->membership_id);
        update_user_meta($user_id, 'pmpro_grace_enddate', $grace_enddate);
        
        return true;
    } else {
        error_log("PMPro Grace Period Tester - No active membership found for user ID: $user_id");
    }
    
    return false;
}

/**
 * Reset grace period data for a user
 */
function pmpro_grace_period_test_reset_grace($user_id) {
    // Debug log entry
    error_log("PMPro Grace Period Tester - Resetting grace period data for user ID: $user_id");
    
    $grace_level = get_user_meta($user_id, 'pmpro_grace_level', true);
    $grace_enddate = get_user_meta($user_id, 'pmpro_grace_enddate', true);
    $original_enddate = get_user_meta($user_id, 'pmpro_original_enddate', true);
    
    error_log("PMPro Grace Period Tester - User $user_id had grace_level: $grace_level, grace_enddate: $grace_enddate, original_enddate: $original_enddate");
    
    delete_user_meta($user_id, 'pmpro_grace_level');
    delete_user_meta($user_id, 'pmpro_grace_enddate');
    delete_user_meta($user_id, 'pmpro_original_enddate');
    delete_user_meta($user_id, 'pmpro_exiting_grace_period');
    
    error_log("PMPro Grace Period Tester - Successfully deleted grace period meta for user ID: $user_id");
    
    return true;
}

/**
 * Simulate grace period expiry for a user
 */
function pmpro_grace_period_test_simulate_grace_expiry($user_id) {
    // Debug log entry
    error_log("PMPro Grace Period Tester - Simulating grace period expiry for user ID: $user_id");
    
    // Get current grace period data
    $grace_level = get_user_meta($user_id, 'pmpro_grace_level', true);
    $grace_enddate = get_user_meta($user_id, 'pmpro_grace_enddate', true);
    $original_enddate = get_user_meta($user_id, 'pmpro_original_enddate', true);
    
    error_log("PMPro Grace Period Tester - User $user_id grace period data: level=$grace_level, enddate=$grace_enddate, original=$original_enddate");
    
    // Set flag that we're exiting grace period (so expiration email will be sent)
    update_user_meta($user_id, 'pmpro_exiting_grace_period', '1');
    error_log("PMPro Grace Period Tester - Set exiting_grace_period flag for user ID: $user_id");
    
    // Cancel membership
    $result = pmpro_changeMembershipLevel(0, $user_id);
    error_log("PMPro Grace Period Tester - Changed membership level to 0 (cancelled) for user ID: $user_id. Result: " . ($result ? 'success' : 'failure'));
    
    // Clean up meta
    delete_user_meta($user_id, 'pmpro_grace_level');
    delete_user_meta($user_id, 'pmpro_grace_enddate');
    
    // We don't delete original_enddate for reporting purposes
    error_log("PMPro Grace Period Tester - Completed grace period expiry simulation for user ID: $user_id");
    
    return true;
}

/**
 * Simulate sending an expiration warning email
 */
function pmpro_grace_period_test_send_email($user_id, $days) {
    // Debug log entry
    error_log("PMPro Grace Period Tester - Attempting to send $days-day expiration warning email to user ID: $user_id");
    
    // Skip the plugin check entirely for now - we'll just try to send the email
    // and handle any errors that occur
    
    $user_level = pmpro_getMembershipLevelForUser($user_id);
    if (empty($user_level)) {
        error_log("PMPro Grace Period Tester - ERROR: User ID $user_id does not have an active membership level");
        echo '<div class="notice notice-error"><p>User does not have an active membership level.</p></div>';
        return false;
    }
    
    // Template name
    $template = 'membership_expiring_' . $days;
    error_log("PMPro Grace Period Tester - Using email template: $template");
    
    // Get the user
    $user = get_userdata($user_id);
    
    // Set up data for email
    $email_data = array(
        'user_login' => $user->user_login,
        'user_email' => $user->user_email,
        'display_name' => $user->display_name,
        'user_id' => $user_id,
        'membership_id' => $user_level->id,
        'membership_level_name' => $user_level->name,
        'sitename' => get_option('blogname'),
        'siteemail' => get_option('admin_email'),
        'login_link' => wp_login_url(),
        'enddate' => !empty($user_level->enddate) ? date_i18n(get_option('date_format'), strtotime($user_level->enddate)) : 'Never'
    );
    
    // Get and send the email
    try {
        error_log("PMPro Grace Period Tester - Creating PMProEmail object for template: $template");
        $pmproet_email = new PMProEmail();
        $pmproet_email->email = $user->user_email;
        $pmproet_email->data = $email_data;
        $pmproet_email->template = $template;
        
        error_log("PMPro Grace Period Tester - About to send email to: " . $user->user_email);
        $result = $pmproet_email->sendEmail();
        
        error_log("PMPro Grace Period Tester - Sent $days-day expiration warning email to " . $user->user_email . ". Result: " . ($result ? 'success' : 'failure'));
        
        return $result;
    } catch (Exception $e) {
        error_log("PMPro Grace Period Tester - ERROR: Exception thrown while sending email: " . $e->getMessage());
        echo '<div class="notice notice-error"><p>Error sending email: ' . esc_html($e->getMessage()) . '</p></div>';
        return false;
    }
}

/**
 * Simulate sending an expired membership email
 */
function pmpro_grace_period_test_send_expired_email($user_id) {
    // Debug log
    error_log("PMPro Grace Period Tester - Attempting to send expired membership email to user ID: $user_id");
    
    $user = get_userdata($user_id);
    if (empty($user)) {
        error_log("PMPro Grace Period Tester - ERROR: User ID $user_id not found");
        return false;
    }
    
    $user_level = pmpro_getMembershipLevelForUser($user_id);
    $level_id = !empty($user_level) ? $user_level->id : 0;
    $level_name = !empty($user_level) ? $user_level->name : 'Unknown';
    
    // Set up data for email
    $email_data = array(
        'user_login' => $user->user_login,
        'user_email' => $user->user_email,
        'display_name' => $user->display_name,
        'user_id' => $user_id,
        'membership_id' => $level_id,
        'membership_level_name' => $level_name,
        'sitename' => get_option('blogname'),
        'siteemail' => get_option('admin_email'),
        'login_link' => wp_login_url()
    );
    
    // Get and send the email
    try {
        error_log("PMPro Grace Period Tester - Creating PMProEmail object for template: membership_expired");
        $pmproet_email = new PMProEmail();
        $pmproet_email->email = $user->user_email;
        $pmproet_email->data = $email_data;
        $pmproet_email->template = 'membership_expired';
        
        error_log("PMPro Grace Period Tester - About to send expired email to: " . $user->user_email);
        $result = $pmproet_email->sendEmail();
        
        error_log("PMPro Grace Period Tester - Sent expired membership email to " . $user->user_email . ". Result: " . ($result ? 'success' : 'failure'));
        
        return $result;
    } catch (Exception $e) {
        error_log("PMPro Grace Period Tester - ERROR: Exception thrown while sending expired email: " . $e->getMessage());
        echo '<div class="notice notice-error"><p>Error sending expired email: ' . esc_html($e->getMessage()) . '</p></div>';
        return false;
    }
}
