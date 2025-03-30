<?php
/**
 * PMPro Grace Period with Enhanced Expiration Emails
 *
 * This Code Recipe combines and replaces:
 * 1. The 28-day grace period functionality
 * 2. The 28 & 10-day email reminder system
 * 
 * Features:
 * - Hooks into Extra Expiration Warning Emails Add-On
 * - Disables default expiration email before grace period
 * - Sends custom expiration warning emails at 28 and 10 days before expiration
 * - Saves the initial expiration date before entering grace period
 * - Uses the initial expiration date to calculate renewal dates if member renews during grace period
 * - Sends the default expiration email at the end of the grace period
 * - Processes normal expiration if member doesn't renew during grace period
 *
 * Requires the Extra Expiration Warning Emails Add-On:
 * https://www.paidmembershipspro.com/add-ons/extra-expiration-warning-emails-add-on/
 *
 * Add this Code Recipe to a PMPro Customization Plugin:
 * https://www.paidmembershipspro.com/create-a-plugin-for-pmpro-customizations/
 */

// Define constants
define('GRACE_PERIOD_DAYS', 28);
define('FIRST_WARNING_DAYS', 28);
define('SECOND_WARNING_DAYS', 10);

/**
 * Configure the expiration warning emails (28 and 10 days)
 * This hooks into the Extra Expiration Warning Emails Add-On
 */
function custom_pmproeewe_email_frequency_and_templates($settings) {
    $settings = array(
        FIRST_WARNING_DAYS => 'membership_expiring_' . FIRST_WARNING_DAYS,
        SECOND_WARNING_DAYS => 'membership_expiring_' . SECOND_WARNING_DAYS,
    );
    return $settings;
}
add_filter('pmproeewe_email_frequency_and_templates', 'custom_pmproeewe_email_frequency_and_templates', 10, 1);

/**
 * Add custom email templates for the warning emails
 */
function custom_add_expiration_warning_templates($templates) {
    $templates['membership_expiring_' . FIRST_WARNING_DAYS] = array(
        'subject'     => "Your membership at !!sitename!! will end soon.",
        'description' => "Membership Expiring " . FIRST_WARNING_DAYS,
        'body'        => '<p>Thank you for your membership to !!sitename!!. This is just a reminder that your membership will end on !!enddate!!.</p><p>Account: !!display_name!! (!!user_email!!)</p><p>Membership Level: !!membership_level_name!!</p><p>Log in to your membership account here: !!login_link!!</p>',
        'help_text'   => "The first email sent to members when their expiration date is approaching."
    );
    
    $templates['membership_expiring_' . SECOND_WARNING_DAYS] = array(
        'subject'     => "Your membership at !!sitename!! will end soon.",
        'description' => "Membership Expiring " . SECOND_WARNING_DAYS,
        'body'        => '<p>Thank you for your membership to !!sitename!!. This is just a reminder that your membership will end on !!enddate!!.</p><p>Account: !!display_name!! (!!user_email!!)</p><p>Membership Level: !!membership_level_name!!</p><p>Log in to your membership account here: !!login_link!!</p>',
        'help_text'   => "The second email sent to members when their expiration date is approaching."
    );
    
    return $templates;
}
add_filter('pmproet_templates', 'custom_add_expiration_warning_templates');

/**
 * Disable the default expiration email that would be sent when a member first expires
 * (before the grace period). We'll send this email at the end of the grace period instead.
 */
function custom_disable_expiration_email($emails, $email) {
    // Only disable the expiration email
    if ($email->template == 'membership_expired') {
        // Check if the user is entering grace period, not exiting it
        $user_id = $email->data['user_id'];
        $is_exiting_grace = get_user_meta($user_id, 'pmpro_exiting_grace_period', true);
        
        // If not exiting grace period, disable the email
        if (empty($is_exiting_grace)) {
            return array();
        }
        
        // If exiting grace, remove the flag and allow the email to be sent
        delete_user_meta($user_id, 'pmpro_exiting_grace_period');
    }
    
    return $emails;
}
add_filter('pmpro_emails_to_send', 'custom_disable_expiration_email', 10, 2);

/**
 * Handle grace period implementation and save original expiration date
 */
function custom_pmpro_membership_post_membership_expiry($user_id, $level_id) {
    // Make sure we aren't already in a grace period for this level
    $grace_level = get_user_meta($user_id, 'pmpro_grace_level', true);
    
    if (empty($grace_level) || $grace_level !== $level_id) {
        // Get user's membership information to check for existing enddate
        $user_level = pmpro_getMembershipLevelForUser($user_id);
        
        if (!empty($user_level) && !empty($user_level->enddate)) {
            // Save the original expiration date before extending with grace period
            $original_enddate = $user_level->enddate;
            update_user_meta($user_id, 'pmpro_original_enddate', $original_enddate);
        }
        
        // Give them their level back with grace period expiration
        $grace_level_data = array();
        $grace_level_data['user_id'] = $user_id;
        $grace_level_data['membership_id'] = $level_id;
        $grace_level_data['enddate'] = date('Y-m-d H:i:s', strtotime('+' . GRACE_PERIOD_DAYS . ' days', current_time('timestamp')));
        
        // Change membership level (extends with grace period)
        $changed = pmpro_changeMembershipLevel($grace_level_data, $user_id);
        
        // Flag that user is in grace period with this level
        update_user_meta($user_id, 'pmpro_grace_level', $level_id);
        
        // Also save grace period end date for reference
        update_user_meta($user_id, 'pmpro_grace_enddate', $grace_level_data['enddate']);
    }
}
add_action('pmpro_membership_post_membership_expiry', 'custom_pmpro_membership_post_membership_expiry', 10, 2);

/**
 * This function runs when grace period expires to properly expire membership
 * and send the expiration email
 */
function custom_check_grace_period_expiration() {
    global $wpdb;
    
    // Get users in grace period
    $grace_period_users = $wpdb->get_results("
        SELECT user_id, meta_value as level_id 
        FROM $wpdb->usermeta 
        WHERE meta_key = 'pmpro_grace_level'
    ");
    
    if (!empty($grace_period_users)) {
        foreach ($grace_period_users as $user) {
            $user_id = $user->user_id;
            $level_id = $user->level_id;
            
            // Get current user level information
            $current_level = pmpro_getMembershipLevelForUser($user_id);
            
            // Check if user's current level matches the one in grace period
            if (!empty($current_level) && $current_level->id == $level_id) {
                // Get grace period end date
                $grace_enddate = get_user_meta($user_id, 'pmpro_grace_enddate', true);
                
                // Check if grace period has expired
                if (!empty($grace_enddate) && strtotime($grace_enddate) <= current_time('timestamp')) {
                    // Flag that we're exiting grace period (so expiration email will be sent)
                    update_user_meta($user_id, 'pmpro_exiting_grace_period', '1');
                    
                    // Cancel the membership level
                    pmpro_changeMembershipLevel(0, $user_id);
                    
                    // Clean up meta
                    delete_user_meta($user_id, 'pmpro_grace_level');
                    delete_user_meta($user_id, 'pmpro_grace_enddate');
                    
                    // We don't delete the original_enddate in case it's needed for reporting
                }
            }
        }
    }
}
add_action('pmpro_cron_expiration_warnings', 'custom_check_grace_period_expiration');

/**
 * When a member renews during grace period, use the original expiration date
 * as the basis for calculating the new expiration date
 */
function custom_calculate_expiration_date($enddate, $user_id, $level_id, $startdate) {
    // Check if this is a renewal during grace period
    $grace_level = get_user_meta($user_id, 'pmpro_grace_level', true);
    
    if (!empty($grace_level) && $grace_level == $level_id) {
        // Get the original expiration date
        $original_enddate = get_user_meta($user_id, 'pmpro_original_enddate', true);
        
        if (!empty($original_enddate)) {
            // Get level information for period calculation
            $level = pmpro_getLevel($level_id);
            
            if (!empty($level)) {
                // Calculate new expiration based on original date and level settings
                if ($level->expiration_number > 0) {
                    // Get the proper period
                    if ($level->expiration_period == 'Hour')
                        $timestamp = strtotime('+' . $level->expiration_number . ' hours', strtotime($original_enddate));
                    elseif ($level->expiration_period == 'Day')
                        $timestamp = strtotime('+' . $level->expiration_number . ' days', strtotime($original_enddate));
                    elseif ($level->expiration_period == 'Week')
                        $timestamp = strtotime('+' . $level->expiration_number . ' weeks', strtotime($original_enddate));
                    elseif ($level->expiration_period == 'Month')
                        $timestamp = strtotime('+' . $level->expiration_number . ' months', strtotime($original_enddate));
                    elseif ($level->expiration_period == 'Year')
                        $timestamp = strtotime('+' . $level->expiration_number . ' years', strtotime($original_enddate));
                    
                    // Make sure timestamp is in the future
                    if ($timestamp > current_time('timestamp')) {
                        $enddate = date('Y-m-d H:i:s', $timestamp);
                    }
                }
            }
            
            // Clean up grace period meta data since they've renewed
            delete_user_meta($user_id, 'pmpro_grace_level');
            delete_user_meta($user_id, 'pmpro_grace_enddate');
            delete_user_meta($user_id, 'pmpro_original_enddate');
        }
    }
    
    return $enddate;
}
add_filter('pmpro_calculate_enddate', 'custom_calculate_expiration_date', 10, 4);

/**
 * Add admin column to show grace period status in members list
 */
function custom_add_grace_period_column($columns) {
    $columns['grace_status'] = 'Grace Period';
    return $columns;
}
add_filter('pmpro_manage_memberslist_columns', 'custom_add_grace_period_column');

/**
 * Fill grace period column with status information
 */
function custom_show_grace_period_status($column_name, $user_id) {
    if ($column_name == 'grace_status') {
        $grace_level = get_user_meta($user_id, 'pmpro_grace_level', true);
        $grace_enddate = get_user_meta($user_id, 'pmpro_grace_enddate', true);
        
        if (!empty($grace_level) && !empty($grace_enddate)) {
            $days_left = ceil((strtotime($grace_enddate) - current_time('timestamp')) / (60 * 60 * 24));
            echo '<span style="color: #FF6600;">In Grace Period</span><br/>';
            echo 'Days Left: ' . $days_left;
        } else {
            echo '-';
        }
    }
}
add_action('pmpro_manage_memberslist_custom_column', 'custom_show_grace_period_status', 10, 2);
