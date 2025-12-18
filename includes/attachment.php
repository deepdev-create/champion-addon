<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Attachment {
    private static $instance = null;

    // Customer attachment meta
    const META_ATTACHED_AMBASSADOR      = 'champion_attached_ambassador';
    const META_ATTACHMENT_START         = 'champion_attachment_start';
    const META_ATTACHMENT_EXPIRES       = 'champion_attachment_expires';
    const META_ATTACHMENT_SOURCE        = 'champion_attachment_source';
    const META_ATTACHMENT_ORDER_ID      = 'champion_attachment_order_id';


    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Single source of truth:
     * Attach a CUSTOMER to an AMBASSADOR for X months.
     *
     * Rules:
     * - one-time attach (do not override existing)
     * - self-referral protection
     * - link-based requires first-touch timestamp within conversion window
     * - coupon-based attach allowed on first order (no first-touch required)
     *
     * @param int   $customer_id
     * @param int   $ambassador_id
     * @param string $source   'coupon'|'link'|any
     * @param array $args {
     *   @type int    $order_id          Optional. First conversion order id.
     *   @type int    $first_touch_ts    Optional. Unix timestamp of first touch (?ref click).
     *   @type int    $attach_ts         Optional. Unix timestamp to use as "now".
     * }
     * @return array { success(bool), reason(string), expires(string|null) }
     */
    public function attach_customer_to_ambassador( $customer_id, $ambassador_id, $source, $args = array() ) {

        $customer_id   = (int) $customer_id;
        $ambassador_id = (int) $ambassador_id;
        $source        = is_string($source) ? sanitize_text_field($source) : '';

        $order_id       = ! empty($args['order_id']) ? (int) $args['order_id'] : 0;
        $first_touch_ts = ! empty($args['first_touch_ts']) ? (int) $args['first_touch_ts'] : 0;
        $attach_ts      = ! empty($args['attach_ts']) ? (int) $args['attach_ts'] : time();

        if ( $customer_id <= 0 || $ambassador_id <= 0 ) {
            return array('success' => false, 'reason' => 'invalid_ids', 'expires' => null);
        }

        // Self-referral protection
        if ( $customer_id === $ambassador_id ) {
            return array('success' => false, 'reason' => 'self_referral', 'expires' => null);
        }

        // One-time attach rule
        $existing = (int) get_user_meta( $customer_id, self::META_ATTACHED_AMBASSADOR, true );
        if ( $existing > 0 ) {
            return array('success' => false, 'reason' => 'already_attached', 'expires' => null);
        }

        // Must be an ambassador (use existing filter in your codebase)
        $is_amb = (bool) apply_filters( 'champion_is_user_ambassador', false, $ambassador_id );
        if ( ! $is_amb ) {
            return array('success' => false, 'reason' => 'not_ambassador', 'expires' => null);
        }

        // Load options safely
        $opts = class_exists('Champion_Helpers') ? Champion_Helpers::instance()->get_opts() : array();

        $conversion_days = ! empty($opts['customer_conversion_window_days']) ? (int) $opts['customer_conversion_window_days'] : 30;
        if ( $conversion_days <= 0 ) $conversion_days = 30;

        // Link-based requires first-touch within conversion window
        if ( $source === 'link' ) {
            if ( $first_touch_ts <= 0 ) {
                return array('success' => false, 'reason' => 'missing_first_touch', 'expires' => null);
            }
            $max_age = $conversion_days * DAY_IN_SECONDS;
            if ( ($attach_ts - $first_touch_ts) > $max_age ) {
                return array('success' => false, 'reason' => 'conversion_window_expired', 'expires' => null);
            }
        }

        // Attachment duration 6–12 months (configurable)
        $months = ! empty($opts['customer_attachment_months']) ? (int) $opts['customer_attachment_months'] : 6;
        $max_m  = ! empty($opts['customer_attachment_max_months']) ? (int) $opts['customer_attachment_max_months'] : 12;

        if ( $months < 6 ) $months = 6;
        if ( $months > $max_m ) $months = $max_m;

        $start_mysql  = current_time('mysql'); // WP local time
        $expires_ts   = strtotime( "+{$months} months", $attach_ts );
        $expires_mysql = date_i18n( 'Y-m-d H:i:s', $expires_ts );

        update_user_meta( $customer_id, self::META_ATTACHED_AMBASSADOR, $ambassador_id );
        update_user_meta( $customer_id, self::META_ATTACHMENT_START, $start_mysql );
        update_user_meta( $customer_id, self::META_ATTACHMENT_EXPIRES, $expires_mysql );
        update_user_meta( $customer_id, self::META_ATTACHMENT_SOURCE, $source );

        if ( $order_id > 0 ) {
            update_user_meta( $customer_id, self::META_ATTACHMENT_ORDER_ID, $order_id );
        }

        return array('success' => true, 'reason' => 'attached', 'expires' => $expires_mysql);
    }

    


    public function __construct(){
      // Legacy attachment flow disabled.
      // Customer attachment must use attach_customer_to_ambassador() from the new Customer Referral Flow (Phase 2/3).
    }

    /**
     * Attach a customer to an ambassador when they place first qualifying order.
     * This includes some light fraud checks (self-referral).
     */
    public function maybe_attach_customer_on_order($order_id) {
        if ( ! class_exists('WC_Order') ) return;
        $order = wc_get_order($order_id);
        if ( ! $order ) return;
        $user_id = $order->get_user_id();
        if ( ! $user_id ) return; // only logged-in customers for attachment by this implementation

        $opts = Champion_Helpers::instance()->get_opts();
        $parent_meta = $opts['parent_usermeta'];
        $parent_raw = get_user_meta( $user_id, $parent_meta, true );
        $parent = intval( $parent_raw );

        if ( $parent <= 0 ) return;

        // Prevent self-referral
        if ( ! empty($opts['fraud_check_self_referral']) && intval($parent) === intval($user_id) ) {
            return;
        }

        // If already attached, do nothing
        if ( get_user_meta( $user_id, 'champion_attached_ambassador', true ) ) return;

        // optional IP check - compare user registration IP vs parent last order IP (best-effort)
        if ( ! empty($opts['fraud_check_same_ip']) ) {
            $user_reg_ip = get_user_meta($user_id, 'registration_ip', true);
            // some systems don't save reg ip - ignore if not available
            if ( $user_reg_ip ) {
                // try to find last order of parent and compare IP if stored (best-effort)
                $parent_last_order_id = wc_get_customer_last_order( $parent, 'completed' );
                if ( $parent_last_order_id ) {
                    $parent_ip = get_post_meta( $parent_last_order_id, '_customer_ip_address', true );
                    if ( $parent_ip && $user_reg_ip && $parent_ip === $user_reg_ip ) {
                        // suspicious - do not attach
                        return;
                    }
                }
            }
        }

        // Mark attachment
        update_user_meta( $user_id, 'champion_attached_ambassador', $parent );
        update_user_meta( $user_id, 'champion_attachment_start', current_time('mysql') );
        update_user_meta( $user_id, 'champion_attachment_expires', date('Y-m-d H:i:s', strtotime('+'. intval($opts['attachment_window_days']) .' days')) );

        // record optional history - helpful for admin
        add_user_meta( $user_id, 'champion_attachment_history', array(
            'parent' => $parent,
            'attached_at' => current_time('mysql'),
            'order_id' => $order_id
        ) );
    }
}
