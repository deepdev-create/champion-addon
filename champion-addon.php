<?php
/**
 * Plugin Name: Champion Ambassador & Loyalty Addon
 * Description: Addon integrating Coupon Affiliates + WPLoyalty. Handles 10x5 milestones, child counters, attachments, payouts and optional direct WPLoyalty credit.
 * Version: 1.1.0
 * Author: getbudnaked
 * License: GPLv2+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'CHAMPION_ADDON_VERSION', '1.1.0' );
define( 'CHAMPION_ADDON_PATH', plugin_dir_path( __FILE__ ) );
define( 'CHAMPION_ADDON_URL', plugin_dir_url( __FILE__ ) );


require_once CHAMPION_ADDON_PATH . 'includes/helpers.php';
require_once CHAMPION_ADDON_PATH . 'includes/milestones.php';
require_once CHAMPION_ADDON_PATH . 'includes/attachment.php';
require_once CHAMPION_ADDON_PATH . 'includes/wployalty-integration.php';
require_once CHAMPION_ADDON_PATH . 'includes/payouts.php';
require_once CHAMPION_ADDON_PATH . 'includes/admin-settings.php';
require_once CHAMPION_ADDON_PATH . 'includes/dashboard/ambassador-dashboard.php';
require_once CHAMPION_ADDON_PATH . 'includes/dashboard/ambassador-signup.php';
require_once CHAMPION_ADDON_PATH . 'includes/dev-test-page.php';
require_once CHAMPION_ADDON_PATH . 'includes/customer-referral-coupon.php';
require_once CHAMPION_ADDON_PATH . 'includes/customer-referral-link.php';
require_once CHAMPION_ADDON_PATH . 'includes/customer-commission.php';
require_once CHAMPION_ADDON_PATH . 'includes/wp-enqueue-scripts.php';
require_once CHAMPION_ADDON_PATH . 'includes/dashboard/customer-dashboard.php';
require_once CHAMPION_ADDON_PATH . 'includes/customer-milestones.php';
require_once CHAMPION_ADDON_PATH . 'includes/customer-orders-milestones.php';
require_once CHAMPION_ADDON_PATH . 'includes/customer-test-page.php';
require_once CHAMPION_ADDON_PATH . 'includes/user-flow.php';





// instantiate singletons
Champion_Helpers::instance();
Champion_Milestones::instance();
Champion_Customer_Milestones::instance();
Champion_Customer_Orders_Milestones::instance();
Champion_Attachment::instance();
Champion_WPLoyalty::instance();
Champion_Payouts::instance();
Champion_Admin::instance();
Champion_Customer_Referral_Coupon::instance();
Champion_Customer_Referral_Link::instance();
Champion_Customer_Commission::instance();



/**
 * Activation: create DB tables, set defaults and schedule monthly payout job
 */
register_activation_hook( __FILE__, function() {
    Champion_Milestones::instance()->create_tables();
    Champion_Customer_Milestones::instance()->create_customer_tables();
    Champion_Customer_Orders_Milestones::instance()->create_customer_tables();
    Champion_Helpers::instance()->set_defaults();

    if ( ! wp_next_scheduled( 'champion_monthly_payout_event' ) ) {
        // schedule on 15th of every month at 02:00 UTC (approx)
        $timestamp = strtotime( date('Y-m-15 02:00:00') );
        if ( $timestamp <= time() ) $timestamp = strtotime('+1 month', $timestamp);
        wp_schedule_event( $timestamp, 'monthly', 'champion_monthly_payout_event' );
    }
});

/**
 * Deactivation: clear scheduled event
 */
register_deactivation_hook( __FILE__, function() {
    $timestamp = wp_next_scheduled( 'champion_monthly_payout_event' );
    if ( $timestamp ) wp_unschedule_event( $timestamp, 'champion_monthly_payout_event' );
});

/**
 * Safety check: Create tables if they don't exist (for new environments)
 */
add_action( 'plugins_loaded', function() {
    global $wpdb;
    
    $tables_to_check = array(
        $wpdb->prefix . 'champion_ambassadors',
        $wpdb->prefix . 'champion_child_milestones',
        $wpdb->prefix . 'champion_parent_milestones',
        $wpdb->prefix . 'champion_customer_orders',
        $wpdb->prefix . 'champion_qualified_children',
        $wpdb->prefix . 'champion_child_milestone_used',
    );
    
    $missing_tables = false;
    foreach ( $tables_to_check as $table ) {
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $missing_tables = true;
            break;
        }
    }
    
    if ( $missing_tables ) {
        Champion_Milestones::instance()->create_tables();
        Champion_Customer_Milestones::instance()->create_customer_tables();
        Champion_Helpers::instance()->set_defaults();
    }
}, 5 );

/**
 * Cron handling - run monthly payout flow
 */
add_action( 'champion_monthly_payout_event', function() {
    Champion_Payouts::instance()->process_monthly_payouts();
    Champion_Payouts::instance()->process_customer_monthly_payouts();
});

function champion_add_ambassador_role() {
    add_role(
        'ambassador',
        'Ambassador',
        [
            'read' => true,
        ]
    );
}
register_activation_hook( __FILE__, 'champion_add_ambassador_role' );

// Mark user as Ambassador for milestones / child counting
add_filter('champion_is_user_ambassador', function( $is, $user_id ){

    $user_id = (int) $user_id;
    if ( $user_id <= 0 ) return false;

    // 1. Explicit Champion ambassador flag
    $flag = get_user_meta( $user_id, 'is_ambassador', true );
    if ( $flag === 'yes' || $flag === 1 ) {
        return true;
    }

    // 2. Role-based ambassador (Champion role OR Coupon Affiliates role)
    $user = get_user_by( 'id', $user_id );
    if ( $user ) {
        $roles = (array) $user->roles;

        // Champion ambassador role
        if ( in_array( 'ambassador', $roles, true ) ) {
            return true;
        }

        // Coupon Affiliates PRO ambassador role
        if ( in_array( 'coupon_affiliate_ambassador', $roles, true ) ) {
            return true;
        }
    }

    // 3. Has Champion referral code → valid ambassador
    $ref_code = get_user_meta( $user_id, 'champion_ref_code', true );
    if ( ! empty( $ref_code ) ) {
        return true;
    }

    return false;
    
}, 10, 2);

add_filter( 'champion_is_user_customer', function ( $is_customer, $user_id ) {

    if ( ! $user_id ) {
        return false;
    }

    // WooCommerce customer = user with customer role
    $user = get_userdata( $user_id );

    if ( ! $user ) {
        return false;
    }

    return in_array( 'customer', (array) $user->roles, true );

}, 10, 2 );


function champion_create_ambassador_table() {
    
    global $wpdb;
    $table = $wpdb->prefix . 'ambassadors';

    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        ref_code VARCHAR(50) NOT NULL,
        invited_by BIGINT(20) UNSIGNED DEFAULT 0,
        total_points INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'champion_create_ambassador_table');



