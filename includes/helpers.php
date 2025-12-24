<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Helpers {
    private static $instance = null;
    const OPT_KEY = 'champion_addon_opts';

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function set_defaults() {
        $defaults = $this->defaults();
        if ( false === get_option(self::OPT_KEY) ) {
            add_option(self::OPT_KEY, $defaults);
        } else {
            // ensure keys exist
            $current = get_option(self::OPT_KEY, array());
            $merged = array_merge($this->defaults(), (array)$current);
            update_option(self::OPT_KEY, $merged);
        }
    }

    /**
     * Simple debug logger for Champion Addon.
     *
     * Logs only when WP_DEBUG is enabled OR CHAMPION_ADDON_DEBUG is defined true.
     * Never throws; safe to call in cron/admin/ajax.
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    function champion_addon_log( $message, $context = array() ) {
        if ( class_exists( 'Champion_Helpers' ) && method_exists( 'Champion_Helpers', 'log' ) ) {
            Champion_Helpers::log( $message, $context );
            return;
        }

        // Absolute fallback (should rarely happen).
        $line = '[Champion Addon] ' . (string) $message;
        if ( ! empty( $context ) && is_array( $context ) ) {
            $json = wp_json_encode( $context );
            if ( $json ) {
                $line .= ' | ' . $json;
            }
        }
        error_log( $line );
    }



    public function defaults() {
        return array(
            'bonus_amount' => 500.00,
            'block_size' => 10,
            'child_orders_required' => 5,
            'child_order_min_amount' => 50.00,
            'attachment_window_days' => 30,
            'affiliate_order_meta_keys' => array('wcusage_affiliate_user','wcu_select_coupon_user','_ca_affiliate_id','_referrer','_referral','affiliate_id'),
            'ambassador_usermeta' => 'is_ambassador',
            'parent_usermeta' => 'champion_parent_ambassador',
            'award_via_wployalty' => false,
            'min_payout_amount' => 100.00,
            'fraud_check_same_ip' => true,
            'fraud_check_self_referral' => true,
            
            'customer_ref_cookie_days'          => 30,  // first-touch tracking validity (doc)
            'customer_conversion_window_days'   => 30,  // must purchase within 1 month to attach (doc)
            'customer_attachment_months'        => 6,   // attachment duration (admin can set 6-12)
            'customer_attachment_max_months'    => 12,  // safety cap
            // Customer order commission (Ambassador -> Customers)
            'customer_order_commission_type'  => 'percent', // percent|fixed
            'customer_order_commission_value' => 0,         // default 0 to avoid guessing


        );
    }

    public function get_opts() {
        $o = get_option(self::OPT_KEY, $this->defaults());
        return array_merge($this->defaults(), (array)$o);
    }

    /**
     * Debug logger (static).
     *
     * Enabled when:
     * - CHAMPION_ADDON_DEBUG is true, OR
     * - WP_DEBUG is true, OR
     * - award_via_wployalty setting is enabled (for payout diagnostics)
     *
     * @param string $message
     * @param array  $context
     * @return void
     */
    public static function log( $message, $context = array() ) {

        $enabled = false;

        if ( defined( 'CHAMPION_ADDON_DEBUG' ) && CHAMPION_ADDON_DEBUG ) {
            $enabled = true;
        } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $enabled = true;
        } else {
            // If WPLoyalty payout is enabled in plugin settings, enable logs for diagnostics.
            $defaults = ( method_exists( __CLASS__, 'defaults' ) ) ? ( new self() )->defaults() : array();
            $opts     = get_option( self::OPT_KEY, $defaults );
            if ( is_array( $opts ) && ! empty( $opts['award_via_wployalty'] ) ) {
                $enabled = true;
            }
        }

        if ( ! $enabled ) {
            return;
        }

        $line = '[Champion Addon] ' . (string) $message;

        if ( ! empty( $context ) && is_array( $context ) ) {
            $json = wp_json_encode( $context );
            if ( $json ) {
                $line .= ' | ' . $json;
            }
        }

        error_log( $line );
    }


    /**
     * Robust detection of affiliate id for an order.
     * Checks built-in list, and allows integrators to override via filter 'champion_get_affiliate_for_order'
     */
    public function get_affiliate_for_order( $order ) {
        // integrator filter first
        $id = apply_filters('champion_get_affiliate_for_order', 0, $order);
        if ( intval($id) > 0 ) return intval($id);


        // Coupon Affiliates PRO (wcusage) meta key
        $wcusage_aff = (int) $order->get_meta('wcusage_affiliate_user', true);
        if ( $wcusage_aff > 0 ) {
            return $wcusage_aff;
        }


        $opts = $this->get_opts();
        $keys = (array) $opts['affiliate_order_meta_keys'];

        foreach ( $keys as $key ) {
            if ( method_exists($order, 'get_meta') ) {
                $val = $order->get_meta($key);
            } else {
                $val = get_post_meta($order->get_id(), $key, true);
            }
            if ( $val ) {
                $id = intval($val);
                if ( $id > 0 ) return $id;
            }
        }

        // fallback: try meta fields that contain 'affiliate' or 'referrer'
        $all_meta = get_post_meta( $order->get_id() );
        foreach ( $all_meta as $k => $v ) {
            if ( stripos($k, 'affiliate') !== false || stripos($k, 'referrer') !== false || stripos($k, 'wcusage') !== false ) {
                $maybe = intval( is_array($v) ? $v[0] : $v );
                if ( $maybe > 0 ) return $maybe;
            }
        }

        return 0;
    }

    public function money( $val ) {
        if ( function_exists('wc_price') ) return wc_price( (float) $val );
        return '$' . number_format((float)$val, 2);
    }
}
