<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Payouts {
    private static $instance = null;
    private $milestones_table;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        global $wpdb;
        $this->milestones_table = $wpdb->prefix . 'champion_milestones';

        // default coupon creation handler (lowest priority so direct WPLoyalty can run first)
        add_action('champion_award_milestone', array($this, 'default_award_milestone_handler'), 30, 3);

        add_action('admin_post_champion_manual_payout', array($this, 'handle_manual_payout'));
    }

    /**
     * Default handler: create private coupon for the user and update milestone row (if present)
     */
    public function default_award_milestone_handler($parent_id, $amount, $block_index) {
        // If WPLoyalty option is enabled, we let WPLoyalty handler run first (it was hooked earlier); check if milestone row already has coupon or paid flag
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$this->milestones_table} WHERE parent_affiliate_id = %d AND block_index = %d ORDER BY id DESC LIMIT 1", $parent_id, intval($block_index)) );
        
        // If row already has a coupon OR is marked as paid (eg. via WPLoyalty), skip.
        if ( $row && ( intval( $row->coupon_id ) > 0 || intval( $row->paid ) === 1 ) ) {
            return;
        }

        $user = get_user_by('id', intval($parent_id));
        if ( ! $user ) return;

        $code = 'CHMP-' . $parent_id . '-' . time() . '-' . wp_generate_password(6,false,false);

        $coupon = array(
            'post_title'   => $code,
            'post_content' => 'Champion milestone payout block #' . intval($block_index),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
            'post_type'    => 'shop_coupon'
        );
        $coupon_id = wp_insert_post($coupon);

        if ( is_wp_error($coupon_id) || $coupon_id === 0 ) return;

        update_post_meta($coupon_id, 'discount_type', 'fixed_cart');
        update_post_meta($coupon_id, 'coupon_amount', number_format((float)$amount, 2, '.', ''));
        update_post_meta($coupon_id, 'individual_use', 'yes');
        update_post_meta($coupon_id, 'usage_limit', 1);
        update_post_meta($coupon_id, 'customer_email', $user->user_email);
        update_post_meta($coupon_id, 'description', 'Champion milestone payout to user ID ' . $parent_id);

        if ( $row ) {
            $wpdb->update( $this->milestones_table, array('coupon_id' => intval($coupon_id), 'note' => 'coupon_auto_created', 'paid' => 1), array('id' => $row->id) );
        }
    }

    /**
     * Monthly processor - create coupons for unpaid milestone rows (or call external payout hook).
     */
    public function process_monthly_payouts() {
        global $wpdb;
        $opts = Champion_Helpers::instance()->get_opts();
        $min = floatval($opts['min_payout_amount']);

        $now  = current_time( 'mysql' );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->milestones_table} WHERE paid = 0 AND awarded_at IS NOT NULL AND awarded_at <= %s",
                $now
            )
        );

        

        if ( empty($rows) ) return;

        foreach ( $rows as $r ) {
            // if amount less than min and not allowed, skip
            if ( floatval($r->amount) < $min ) continue;
            // create coupon via default handler
            do_action('champion_award_milestone', intval($r->parent_affiliate_id), floatval($r->amount), intval($r->block_index));
        }

        // optionally send admin email with summary
        $admin_email = get_option('admin_email');
        wp_mail($admin_email, 'Champion monthly payouts run', 'Champion addon processed monthly payout run. See admin.');
    }

    /**
     * Manual payout via admin-post. Simple flow: milestone_id is posted.
     */
    public function handle_manual_payout() {
        if ( ! current_user_can('manage_options') ) wp_die('forbidden');
        if ( empty($_POST['milestone_id']) ) wp_die('missing id');

        $mid = intval($_POST['milestone_id']);
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$this->milestones_table} WHERE id = %d", $mid) );
        
        if ( ! $row ) {
            wp_die('not found');
        }

        // Already paid either via coupon or external (eg. WPLoyalty)
        if ( intval( $row->coupon_id ) > 0 || intval( $row->paid ) === 1 ) {
            wp_safe_redirect( wp_get_referer() );
            exit;
        }

        do_action(
            'champion_award_milestone',
            intval( $row->parent_affiliate_id ),
            floatval( $row->amount ),
            intval( $row->block_index )
        );


        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    public function get_milestones($limit = 200) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$this->milestones_table} ORDER BY awarded_at DESC LIMIT %d", intval($limit)) );
    }
}
