<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', function () {

    // Only on My Account pages
    if ( ! function_exists('is_account_page') || ! is_account_page() ) {
        return;
    }

    // Check for Champion Dashboard endpoint
    global $wp;

    if ( !isset( $wp->query_vars['champion-dashboard'] ) && !isset( $wp->query_vars['champion-customer-dashboard'] ) ) {
        return;
    }

    wp_enqueue_style(
        'champion-dashboard-css',
        CHAMPION_ADDON_URL . 'assets/css/champion-dashboard.css',
        array(),
        '1.0.0'
    );

}, 20 );
