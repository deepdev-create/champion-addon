<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Customer_Orders_Milestones {
    private static $instance = null;
    private $wpdb;
    private $child_customer_table;
    private $customer_milestone_table;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->child_customer_table = $wpdb->prefix . 'champion_child_customer_counters';
        $this->customer_milestone_table = $wpdb->prefix . 'champion_customer_milestones';

        add_action( 'woocommerce_order_status_completed', array($this, 'on_customer_order_completed' ), 20, 1 );
        add_action( 'woocommerce_order_status_refunded', array($this, 'champion_on_customer_order_refunded' ), 10, 2 );
    }

    public function create_customer_tables(){
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql3 = "CREATE TABLE IF NOT EXISTS {$this->child_customer_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            parent_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            qualifying_orders INT(11) NOT NULL DEFAULT 0,
            last_order_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY child_affiliate_id (child_affiliate_id),
            KEY parent_affiliate_id (parent_affiliate_id)
        ) $charset_collate;";

        $sql4 = "CREATE TABLE IF NOT EXISTS {$this->customer_milestone_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            block_index INT(11) NOT NULL DEFAULT 0,
            milestone_children INT(11) NOT NULL DEFAULT 0,
            awarded_at DATETIME NULL,
            note VARCHAR(255) DEFAULT '' NOT NULL,
            coupon_id BIGINT(20) DEFAULT 0,
            paid tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY parent_affiliate_id (parent_affiliate_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql3);
        dbDelta($sql4);
    }

    /**
     * Called on order complete. If order placed by an ambassador (child), increment counter for their parent.
     */
    public function on_customer_order_completed($order_id) {

        if ( ! class_exists('WC_Order') ) return;
        $order = wc_get_order($order_id);
        if ( ! $order ) return;

        $opts = Champion_Helpers::instance()->get_opts();
        $min_amount = floatval( $opts['child_customer_order_min_amount'] );

        $total = floatval( $order->get_total() );
        if ( $total < $min_amount ) return;

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        // integrator can provide champion_is_user_ambassador filter
        $is_cus = get_user_meta( $user_id, 'is_customer', true );
        if ( ! $is_cus ) return;

        $parent = intval( get_user_meta( $user_id, 'champion_parent_customer', true ) );
        if ( $parent <= 0 ) return;

        // increment
        $this->customer_increment_child_counter( $user_id, $parent, $order_id, $total );
    }

    public function champion_on_customer_order_refunded( $order_id, $refund_id ) {
        if ( ! class_exists('WC_Order') ) return;
        $order = wc_get_order($order_id);
        if ( ! $order ) return;

        $opts = Champion_Helpers::instance()->get_opts();
        $min_amount = floatval( $opts['child_customer_order_min_amount'] );

        $total = floatval( $order->get_total() );
        if ( $total < $min_amount ) return;

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        // integrator can provide champion_is_user_ambassador filter
        $is_cus = get_user_meta( $user_id, 'is_customer', true );
        if ( ! $is_cus ) return;

        $parent = intval( get_user_meta( $user_id, 'champion_parent_customer', true ) );
        if ( $parent <= 0 ) return;

        // increment
        $this->customer_decrement_child_counter( $user_id, $parent, $order_id, $total );
    
    }

    public function customer_increment_child_counter( $child_id, $parent_id, $order_id = 0, $order_total = 0.0 ) {
        
        // per-parent counter table
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->child_customer_table} WHERE child_affiliate_id = %d AND parent_affiliate_id = %d",
                $child_id,
                $parent_id
            )
        );

        if ( $row ) {
            $new_count = intval( $row->qualifying_orders ) + 1;

            $this->wpdb->update(
                $this->child_customer_table,
                array(
                    'qualifying_orders' => $new_count,
                    'last_order_at'     => current_time( 'mysql' ),
                ),
                array( 'id' => $row->id ),
                array( '%d', '%s' ),
                array( '%d' )
            );

        } else {
            
            $new_count = 1;

            $this->wpdb->insert(
                $this->child_customer_table,
                array(
                    'child_affiliate_id'  => $child_id,
                    'parent_affiliate_id' => $parent_id,
                    'qualifying_orders'   => 1,
                    'last_order_at'       => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%s' )
            );
        }

        
        $completed = (int) get_user_meta( $child_id, 'champion_customer_completed_orders', true );
        update_user_meta( $child_id, 'champion_customer_completed_orders', $completed + 1 );

        // $100 bonus ke rule ke hisaab se parent milestones check
        $opts     = Champion_Helpers::instance()->get_opts();
        $required = intval( $opts['child_customer_required_order'] ); // default: 5

        if ( $new_count >= $required ) {
            $this->customer_evaluate_parent_milestones( $parent_id );
        }
    }

    public function customer_decrement_child_counter( $child_id, $parent_id, $order_id = 0, $order_total = 0.0 ){

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->child_customer_table} WHERE child_affiliate_id = %d AND parent_affiliate_id = %d",
                $child_id,
                $parent_id
            )
        );

        if ( $row ) {
            $new_count = intval( $row->qualifying_orders ) - 1;

            $this->wpdb->update(
                $this->child_customer_table,
                array(
                    'qualifying_orders' => $new_count,
                    'last_order_at'     => current_time( 'mysql' ),
                ),
                array( 'id' => $row->id ),
                array( '%d', '%s' ),
                array( '%d' )
            );

        }

        $completed = (int) get_user_meta( $child_id, 'champion_customer_completed_orders', true );
        update_user_meta( $child_id, 'champion_customer_completed_orders', $completed - 1 );

        // $100 bonus ke rule ke hisaab se parent milestones check
        $opts     = Champion_Helpers::instance()->get_opts();
        $required = intval( $opts['child_customer_required_order'] ); // default: 5

        if ( $new_count < $required ) {
            $this->customer_evaluate_parent_milestones_on_refunded( $parent_id );
        }
    }

    public function customer_evaluate_parent_milestones( $parent_id ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $block = intval( $opts['child_customer_block_size'] );
        $bonus = floatval( $opts['child_customer_bonus_amount'] );
        $required = intval( $opts['child_customer_required_order'] );

        // count distinct children meeting required
        $count = intval( $this->wpdb->get_var( $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->child_customer_table} WHERE parent_affiliate_id = %d AND qualifying_orders >= %d", $parent_id, $required) ) );
        if ( $count <= 0 ) return;

        $awarded_blocks = intval( $this->wpdb->get_var( $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->customer_milestone_table} WHERE parent_affiliate_id = %d", $parent_id) ) );

        $available_blocks = intval( floor( $count / $block ) );
        $new_blocks = $available_blocks - $awarded_blocks;
        if ( $new_blocks <= 0 ) return;

        for ( $i = 0; $i < $new_blocks; $i++ ) {
            $block_index = $awarded_blocks + $i + 1;
            $this->wpdb->insert( $this->customer_milestone_table, array(
                'parent_affiliate_id' => $parent_id,
                'amount' => $bonus,
                'block_index' => $block_index,
                'milestone_children' => $block,
                'awarded_at' => current_time('mysql'),
                'note' => 'auto-awarded 10x5'
            ) );

            // If dev test is generating orders, suppress immediate payout/award.
            // Milestone rows remain unpaid; monthly payout/manual payout can handle later.
            /*if ( get_transient( 'champion_suppress_awards' ) ) {
                // No-op: do not award now.
            } else {
                do_action( 'champion_award_milestone', $parent_id, $bonus, $block_index );
            }*/

        }
    }

    public function customer_evaluate_parent_milestones_on_refunded( $parent_id ){
        // Get the latest unpaid milestone (highest block_index)
        $last_id = $this->wpdb->get_var(
          $this->wpdb->prepare(
              "SELECT id
               FROM {$this->customer_milestone_table}
               WHERE parent_affiliate_id = %d
               AND paid = 0
               ORDER BY block_index DESC
               LIMIT 1",
              $parent_id
          )
        );

        // Nothing to delete
        if ( ! $last_id ) {
          return;
        }

        // Delete the row
        $this->wpdb->delete(
          $this->customer_milestone_table,
          array( 'id' => $last_id ),
          array( '%d' )
        );
    }

}