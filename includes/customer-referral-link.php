<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Customer_Referral_Link {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action( 'init', [ $this, 'capture_ref_param' ], 5 );

        // Attach on purchase confirmation statuses
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_attach_from_link' ], 25, 1 );
        add_action( 'woocommerce_order_status_completed',  [ $this, 'maybe_attach_from_link' ], 25, 1 );
    }

    /**
     * Capture ?ref=CODE and store first touch.
     * Doc: first visit tracking validity = 30 days.
     */
    public function capture_ref_param() {
        if ( empty($_GET['ref']) ) return;

        $ref = sanitize_text_field( wp_unslash($_GET['ref']) );
        if ( $ref === '' ) return;

        $ref_user_id = $this->resolve_ref_to_user_id( $ref );
		if ( $ref_user_id <= 0 ) return;


        $opts = class_exists('Champion_Helpers') ? Champion_Helpers::instance()->get_opts() : [];
        $cookie_days = ! empty($opts['customer_ref_cookie_days']) ? (int) $opts['customer_ref_cookie_days'] : 30;
        if ( $cookie_days <= 0 ) $cookie_days = 30;

        $ttl = time() + ( DAY_IN_SECONDS * $cookie_days );

        // Store ref and timestamp
        setcookie( 'champion_ref', $ref, $ttl, COOKIEPATH, COOKIE_DOMAIN );
        setcookie( 'champion_ref_ts', (string) time(), $ttl, COOKIEPATH, COOKIE_DOMAIN );

        // Woo session for reliability
        if ( function_exists('WC') && WC() && WC()->session ) {
            WC()->session->set( 'champion_ref', $ref );
            WC()->session->set( 'champion_ref_ts', (int) time() );
        }
    }

    private function get_ref_from_storage() {
        // Prefer Woo session
        if ( function_exists('WC') && WC() && WC()->session ) {
            $ref = WC()->session->get('champion_ref');
            $ts  = WC()->session->get('champion_ref_ts');

            if ( ! empty($ref) && ! empty($ts) ) {
                return [ sanitize_text_field($ref), (int) $ts ];
            }
        }

        // Fallback cookie
        if ( ! empty($_COOKIE['champion_ref']) && ! empty($_COOKIE['champion_ref_ts']) ) {
            $ref = sanitize_text_field( wp_unslash($_COOKIE['champion_ref']) );
            $ts  = (int) sanitize_text_field( wp_unslash($_COOKIE['champion_ref_ts']) );
            return [ $ref, $ts ];
        }

        return [ '', 0 ];
    }

    /**
	 * Resolve ref to a user id.
	 * Note: we intentionally do NOT check "is ambassador" here.
	 * Ambassador validation happens at attach time.
	 */
	public function resolve_ref_to_user_id( $ref ) {
	    $ref = trim( (string) $ref );
	    if ( $ref === '' ) return 0;

	    // numeric ref => user id
	    if ( ctype_digit($ref) ) {
	        $uid = (int) $ref;
	        return get_user_by('id', $uid) ? $uid : 0;
	    }

	    // code ref => user_meta champion_ref_code
	    $users = get_users([
	        'meta_key'   => 'champion_ref_code',
	        'meta_value' => $ref,
	        'number'     => 1,
	        'fields'     => 'ID',
	    ]);

	    return ! empty($users) ? (int) $users[0] : 0;
	}


    /**
     * Attach customer if:
     * - customer is logged-in (we need user_id for meta attachment)
     * - not already attached (or valid)
     * - purchase within conversion window (30 days) from first touch timestamp
     * - coupon flow did not already attach them (Phase 2)
     */
    public function maybe_attach_from_link( $order_id ) {
        if ( ! $order_id || ! class_exists('WC_Order') ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $customer_id = (int) $order->get_user_id();
        if ( $customer_id <= 0 ) {
            // Guest checkout: no permanent attachment possible with current dashboard/meta model.
            // We intentionally do nothing here to avoid inconsistent states.
            return;
        }

        if ( ! class_exists('Champion_Attachment') ) return;

        // If already attached and valid, do nothing
        $valid = (int) Champion_Attachment::instance()->is_customer_attached_valid( $customer_id );
        if ( $valid > 0 ) return;

        // If coupon flow already tagged this order, do nothing
        $m = $order->get_meta('champion_customer_ref_method', true);
        if ( $m === 'coupon' ) return;

        // Read first touch
        [ $ref, $ts ] = $this->get_ref_from_storage();
        if ( $ref === '' || $ts <= 0 ) return;

       $ambassador_id = (int) $this->resolve_ref_to_user_id( $ref );
		if ( $ambassador_id <= 0 ) return;

		// Must be ambassador (validate here, not at cookie-capture)
		if ( ! apply_filters('champion_is_user_ambassador', false, $ambassador_id) ) return;


        // Conversion window check (doc: must buy within 1 month)
        $opts = class_exists('Champion_Helpers') ? Champion_Helpers::instance()->get_opts() : [];
        $conv_days = ! empty($opts['customer_conversion_window_days']) ? (int) $opts['customer_conversion_window_days'] : 30;
        if ( $conv_days <= 0 ) $conv_days = 30;

        $now = time();
        if ( ($now - $ts) > ($conv_days * DAY_IN_SECONDS) ) {
            return;
        }

        $res = Champion_Attachment::instance()->attach_customer_to_ambassador(
            $customer_id,
            $ambassador_id,
            'link',
            [
                'order_id'        => (int) $order_id,
                'first_touch_ts'  => (int) $ts,
                'attach_ts'       => (int) $now,
            ]
        );

        if ( empty($res['success']) ) return;

        // Minimal order meta for reporting/debug
        $order->update_meta_data( 'champion_customer_ref_method', 'link' );
        $order->update_meta_data( 'champion_customer_ref_ambassador_id', $ambassador_id );
        $order->update_meta_data( 'champion_customer_ref_first_touch_ts', (int) $ts );
        $order->save();
    }
}
