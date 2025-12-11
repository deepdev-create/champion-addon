<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Attachment {
    private static $instance = null;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        // Attach on thankyou for logged-in users; integrator should set parent_usermeta during referral flow
        add_action('woocommerce_thankyou', array($this,'maybe_attach_customer_on_order'), 20, 1);
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
