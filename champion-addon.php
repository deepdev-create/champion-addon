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





// instantiate singletons
Champion_Helpers::instance();
Champion_Milestones::instance();
Champion_Attachment::instance();
Champion_WPLoyalty::instance();
Champion_Payouts::instance();
Champion_Admin::instance();

/**
 * Activation: create DB tables, set defaults and schedule monthly payout job
 */
register_activation_hook( __FILE__, function() {
    Champion_Milestones::instance()->create_tables();
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
 * Cron handling - run monthly payout flow
 */
add_action( 'champion_monthly_payout_event', function() {
    Champion_Payouts::instance()->process_monthly_payouts();
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
add_filter( 'champion_is_user_ambassador', function( $is, $user_id ) {

    if ( $is ) {
        return $is;
    }

    $user_id = intval( $user_id );
    if ( $user_id <= 0 ) {
        return false;
    }

    // Check ambassador meta from addon options
    if ( class_exists( 'Champion_Helpers' ) ) {
        $opts     = Champion_Helpers::instance()->get_opts();
        $meta_key = isset( $opts['ambassador_usermeta'] ) ? $opts['ambassador_usermeta'] : 'is_ambassador';

        $flag = get_user_meta( $user_id, $meta_key, true );
        if ( ! empty( $flag ) ) {
            return true;
        }
    }

    // Fallback: role check
    $user = get_user_by( 'id', $user_id );
    if ( $user && in_array( 'ambassador', (array) $user->roles, true ) ) {
        return true;
    }

    return false;
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
