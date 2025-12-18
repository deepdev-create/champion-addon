<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Customer_Referral_Coupon {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        // Attach on purchase confirmation statuses (store-dependent)
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_attach_from_coupon' ], 20, 1 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'maybe_attach_from_coupon' ], 20, 1 );
    }

    public function maybe_attach_from_coupon( $order_id ) {
        if ( ! $order_id || ! class_exists('WC_Order') ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Only logged-in customers can be attached as "customer" (per your existing patterns + points doc)
        $customer_id = (int) $order->get_user_id();
        if ( $customer_id <= 0 ) return;

        if ( class_exists('Champion_Attachment') && method_exists( Champion_Attachment::instance(), 'is_customer_attached_valid' ) ) {
            $valid = (int) Champion_Attachment::instance()->is_customer_attached_valid( $customer_id );
            if ( $valid > 0 ) return;
        }


        // Detect affiliate/ambassador from Coupon Affiliates PRO order meta (your helper already supports keys)
        if ( ! class_exists('Champion_Helpers') ) return;

        $ambassador_id = (int) Champion_Helpers::instance()->get_affiliate_for_order( $order );
        if ( $ambassador_id <= 0 ) return;

        // Ensure it's an ambassador (your project-wide filter)
        if ( ! apply_filters('champion_is_user_ambassador', false, $ambassador_id) ) return;

        // One-time attach using single source-of-truth
        if ( ! class_exists('Champion_Attachment') ) return;

        $res = Champion_Attachment::instance()->attach_customer_to_ambassador(
            $customer_id,
            $ambassador_id,
            'coupon',
            [
                'order_id'  => (int) $order_id,
                'attach_ts' => time(),
            ]
        );

        if ( empty($res['success']) ) {
            return;
        }

        // Minimal order meta for reporting/debug
        $order->update_meta_data( 'champion_customer_ref_method', 'coupon' );
        $order->update_meta_data( 'champion_customer_ref_ambassador_id', $ambassador_id );
        $order->save();
    }
}
