<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Customer_Commission {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        // Ensure referral modules had a chance to set meta first (they run on same statuses)
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_set_commission_meta' ], 50, 1 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'maybe_set_commission_meta' ], 50, 1 );

        // Refund handling
        add_action( 'woocommerce_order_status_refunded', [ $this, 'handle_refund' ], 20, 1 );
    }

    public function maybe_set_commission_meta( $order_id ) {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Do not overwrite if already set (backward compatible)
        $existing_amb = (int) $order->get_meta( 'champion_ambassador_id', true );
        if ( $existing_amb > 0 ) return;

        $ambassador_id = $this->resolve_ambassador_for_order( $order );
        if ( $ambassador_id <= 0 ) return;

        // Only allow valid ambassadors (consistent with project-wide gate)
        if ( ! apply_filters('champion_is_user_ambassador', false, $ambassador_id) ) return;

        $opts = class_exists('Champion_Helpers') ? Champion_Helpers::instance()->get_opts() : [];

        $type  = ! empty($opts['customer_order_commission_type']) ? (string) $opts['customer_order_commission_type'] : 'percent';
        $value = isset($opts['customer_order_commission_value']) ? (float) $opts['customer_order_commission_value'] : 0;

        $order_total = (float) $order->get_total();

        $commission = 0.0;
        if ( $value > 0 ) {
            if ( $type === 'fixed' ) {
                $commission = $value;
            } else {
                // percent
                $commission = ( $order_total * $value ) / 100;
            }
        }

        /**
         * Filter: allow overrides (e.g., exclude shipping/tax, tiered commission, etc.)
         */
        $commission = (float) apply_filters( 'champion_customer_order_commission_amount', $commission, $order, $ambassador_id );

        // Persist meta used by dashboard history
        $order->update_meta_data( 'champion_ambassador_id', $ambassador_id );
        $order->update_meta_data( 'champion_commission_amount', number_format( $commission, 2, '.', '' ) );
        $order->update_meta_data( 'champion_commission_source', 'customer_referral' );

        $order->save();
    }

    private function resolve_ambassador_for_order( $order ) {

        // 1) From our customer referral modules
        $amb = (int) $order->get_meta( 'champion_customer_ref_ambassador_id', true );
        if ( $amb > 0 ) return $amb;

        // 2) From Coupon Affiliates PRO order meta via helper
        if ( class_exists('Champion_Helpers') ) {
            $amb = (int) Champion_Helpers::instance()->get_affiliate_for_order( $order );
            if ( $amb > 0 ) return $amb;
        }

        // 3) From customer attachment (if order is tied to a user)
        $customer_id = (int) $order->get_user_id();
        if ( $customer_id > 0 ) {
            // If method exists, check validity window. Else fallback to meta.
            if ( class_exists('Champion_Attachment') && method_exists( Champion_Attachment::instance(), 'is_customer_attached_valid' ) ) {
                $amb = (int) Champion_Attachment::instance()->is_customer_attached_valid( $customer_id );
                if ( $amb > 0 ) return $amb;
            }

            $amb = (int) get_user_meta( $customer_id, 'champion_attached_ambassador', true );
            if ( $amb > 0 ) return $amb;
        }

        return 0;
    }

    public function handle_refund( $order_id ) {
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $ambassador_id = (int) $order->get_meta( 'champion_ambassador_id', true );
        if ( $ambassador_id <= 0 ) return;

        // Refunds reduce commission (doc); simplest: zero it out
        $order->update_meta_data( 'champion_commission_amount', number_format( 0, 2, '.', '' ) );
        $order->update_meta_data( 'champion_commission_refunded', 1 );
        $order->save();
    }
}
