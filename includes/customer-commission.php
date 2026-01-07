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


       $opts = class_exists('Champion_Helpers') ? Champion_Helpers::instance()->get_opts() : [];

        $type  = ! empty($opts['customer_order_commission_type']) ? (string) $opts['customer_order_commission_type'] : 'percent';
        $value = isset($opts['customer_order_commission_value']) ? (float) $opts['customer_order_commission_value'] : 0;

        $order_total = (float) $order->get_total();

        // 1. Default: Champion commission calculation
        $commission = 0.0;
        if ( $value > 0 ) {
            if ( $type === 'fixed' ) {
                $commission = $value;
            } else {
                $commission = ( $order_total * $value ) / 100;
            }
        }

        // 2. Coupon Affiliates PRO override (mirror plugin commission)
        $wcusage_comm = $order->get_meta( 'wcusage_total_commission', true );
        if ( $wcusage_comm !== '' && $wcusage_comm !== null ) {
            $commission = (float) $wcusage_comm;
        }

        /**
         * Final override hook
         */
        $commission = (float) apply_filters(
            'champion_customer_order_commission_amount',
            $commission,
            $order,
            $ambassador_id
        );




        // Persist meta used by dashboard history
        $order->update_meta_data( 'champion_ambassador_id', $ambassador_id );
        $order->update_meta_data( 'champion_commission_amount', number_format( $commission, 2, '.', '' ) );
        $order->update_meta_data( 'champion_commission_source', 'customer_referral' );

        $order->save();
    }

    private function resolve_ambassador_for_order( $order ) {

        // 1) From our customer referral modules
        $amb = (int) $order->get_meta( 'champion_customer_ref_ambassador_id', true );
        if ( $amb > 0 ) {
            // Check if this is a child ambassador - if so, commission goes to parent
            $parent_amb = $this->get_parent_ambassador_for_commission( $amb );
            if ( $parent_amb > 0 ) {
                return $parent_amb;
            }
            return $amb;
        }

        // 2) From Coupon Affiliates PRO order meta via helper
        if ( class_exists('Champion_Helpers') ) {
            $amb = (int) Champion_Helpers::instance()->get_affiliate_for_order( $order );
            if ( $amb > 0 ) {
                // Check if this is a child ambassador - if so, commission goes to parent
                $parent_amb = $this->get_parent_ambassador_for_commission( $amb );
                if ( $parent_amb > 0 ) {
                    return $parent_amb;
                }
                return $amb;
            }
        }

        // 3) From customer attachment (if order is tied to a user)
        $customer_id = (int) $order->get_user_id();
        if ( $customer_id > 0 ) {
            // If method exists, check validity window. Else fallback to meta.
            if ( class_exists('Champion_Attachment') && method_exists( Champion_Attachment::instance(), 'is_customer_attached_valid' ) ) {
                $amb = (int) Champion_Attachment::instance()->is_customer_attached_valid( $customer_id );
                if ( $amb > 0 ) {
                    // Check if this is a child ambassador - if so, commission goes to parent
                    $parent_amb = $this->get_parent_ambassador_for_commission( $amb );
                    if ( $parent_amb > 0 ) {
                        return $parent_amb;
                    }
                    return $amb;
                }
            }

            $amb = (int) get_user_meta( $customer_id, 'champion_attached_ambassador', true );
            if ( $amb > 0 ) {
                // Check if this is a child ambassador - if so, commission goes to parent
                $parent_amb = $this->get_parent_ambassador_for_commission( $amb );
                if ( $parent_amb > 0 ) {
                    return $parent_amb;
                }
                return $amb;
            }
        }

        return 0;
    }

    /**
     * Get parent ambassador for commission.
     * If the attached ambassador is a child ambassador, return their parent.
     * Otherwise return 0 (commission goes to the attached ambassador directly).
     */
    private function get_parent_ambassador_for_commission( $child_ambassador_id ) {
        $child_ambassador_id = (int) $child_ambassador_id;
        if ( $child_ambassador_id <= 0 ) {
            return 0;
        }

        // Check if this ambassador is a child (has a parent)
        $opts = class_exists('Champion_Helpers') ? Champion_Helpers::instance()->get_opts() : [];
        $parent_meta = ! empty( $opts['parent_usermeta'] ) ? $opts['parent_usermeta'] : 'champion_parent_ambassador';
        
        $parent_id = (int) get_user_meta( $child_ambassador_id, $parent_meta, true );
        
        // Only return parent if it exists and is a valid ambassador
        if ( $parent_id > 0 && apply_filters( 'champion_is_user_ambassador', false, $parent_id ) ) {
            return $parent_id;
        }

        return 0;
    }

    public function handle_refund( $order_id, $refund_id = 0 ) {
        
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $ambassador_id = (int) $order->get_meta( 'champion_ambassador_id', true );
        if ( $ambassador_id <= 0 ) return;

        $order_total = (float) $order->get_total();
        $refunded    = (float) $order->get_total_refunded(); // total refunded so far

        // Original commission baseline (preferred)
        $orig = $order->get_meta( 'champion_commission_amount_original', true );
        $orig_commission = (float) $orig;

        // Fallback if original not stored (older orders)
        if ( $orig === '' || $orig === null ) {
            $orig_commission = (float) $order->get_meta( 'champion_commission_amount', true );
        }

        // If totals invalid, safest: do not change commission
        if ( $order_total <= 0 ) return;

        // Remaining ratio after refunds (clamped)
        $remaining = max( 0.0, $order_total - $refunded );
        $ratio = $remaining / $order_total;
        if ( $ratio < 0 ) $ratio = 0;
        if ( $ratio > 1 ) $ratio = 1;

        $new_commission = $orig_commission * $ratio;

        $order->update_meta_data( 'champion_commission_amount', number_format( $new_commission, 2, '.', '' ) );

        if ( $refunded > 0 ) {
            $order->update_meta_data( 'champion_commission_refunded', 1 );
            $order->update_meta_data( 'champion_commission_refund_total', number_format( $refunded, 2, '.', '' ) );
        }

        $order->save();
    }

}
