<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Milestones {
    private static $instance = null;
    private $wpdb;
    private $child_table;
    private $milestone_table;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->child_table = $wpdb->prefix . 'champion_child_counters';
        $this->milestone_table = $wpdb->prefix . 'champion_milestones';

        // DEPRECATED: Order processing now handled by Champion_Customer_Milestones class
        // which implements the new 10x10x5 three-tier system
        // add_action('woocommerce_order_status_completed', array($this, 'on_order_completed'), 20, 1);
        // add_action( 'woocommerce_order_status_refunded', array($this, 'champion_on_order_refunded'), 10, 2 );
    }

    public function create_tables(){
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->child_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            parent_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            qualifying_orders INT(11) NOT NULL DEFAULT 0,
            last_order_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY child_affiliate_id (child_affiliate_id),
            KEY parent_affiliate_id (parent_affiliate_id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->milestone_table} (
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
        dbDelta($sql1);
        dbDelta($sql2);
    }

    /**
     * Called on order complete. If order placed by an ambassador (child), increment counter for their parent.
     */
    public function on_order_completed($order_id) {
        if ( ! class_exists('WC_Order') ) return;
        $order = wc_get_order($order_id);
        if ( ! $order ) return;

        $opts = Champion_Helpers::instance()->get_opts();
        $min_amount = floatval( $opts['child_order_min_amount'] );
        $total = floatval( $order->get_total() );
        if ( $total < $min_amount ) return;

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        // integrator can provide champion_is_user_ambassador filter
        $is_amb = apply_filters('champion_is_user_ambassador', false, $user_id);
        if ( ! $is_amb ) return;

        $parent_meta = $opts['parent_usermeta'];
        $parent = intval( get_user_meta( $user_id, $parent_meta, true ) );
        if ( $parent <= 0 ) return;

        // increment
        $this->increment_child_counter( $user_id, $parent, $order_id, $total );
    }

    public function champion_on_order_refunded( $order_id, $refund_id ) {
        if ( ! class_exists('WC_Order') ) return;
        $order = wc_get_order($order_id);
        if ( ! $order ) return;

        $opts = Champion_Helpers::instance()->get_opts();
        $min_amount = floatval( $opts['child_order_min_amount'] );
        $total = floatval( $order->get_total() );
        if ( $total < $min_amount ) return;

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        // integrator can provide champion_is_user_ambassador filter
        $is_amb = apply_filters('champion_is_user_ambassador', false, $user_id);
        if ( ! $is_amb ) return;

        $parent_meta = $opts['parent_usermeta'];
        $parent = intval( get_user_meta( $user_id, $parent_meta, true ) );
        if ( $parent <= 0 ) return;

        // increment
        $this->decrement_child_counter( $user_id, $parent, $order_id, $total );
    
    }

    public function increment_child_counter( $child_id, $parent_id, $order_id = 0, $order_total = 0.0 ) {
        
        // per-parent counter table
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->child_table} WHERE child_affiliate_id = %d AND parent_affiliate_id = %d",
                $child_id,
                $parent_id
            )
        );

        if ( $row ) {
            $new_count = intval( $row->qualifying_orders ) + 1;

            $this->wpdb->update(
                $this->child_table,
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
                $this->child_table,
                array(
                    'child_affiliate_id'  => $child_id,
                    'parent_affiliate_id' => $parent_id,
                    'qualifying_orders'   => 1,
                    'last_order_at'       => current_time( 'mysql' ),
                ),
                array( '%d', '%d', '%d', '%s' )
            );
        }

        
        $completed = (int) get_user_meta( $child_id, 'champion_completed_orders', true );
        update_user_meta( $child_id, 'champion_completed_orders', $completed + 1 );

        // $500 bonus ke rule ke hisaab se parent milestones check
        $opts     = Champion_Helpers::instance()->get_opts();
        $required = intval( $opts['child_orders_required'] ); // default: 5

        if ( $new_count >= $required ) {
            $this->evaluate_parent_milestones( $parent_id );
        }
    }

    public function decrement_child_counter( $child_id, $parent_id, $order_id = 0, $order_total = 0.0 ){
    
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->child_table} WHERE child_affiliate_id = %d AND parent_affiliate_id = %d",
                $child_id,
                $parent_id
            )
        );

        if ( $row ) {
            $new_count = intval( $row->qualifying_orders ) - 1;

            $this->wpdb->update(
                $this->child_table,
                array(
                    'qualifying_orders' => $new_count,
                    'last_order_at'     => current_time( 'mysql' ),
                ),
                array( 'id' => $row->id ),
                array( '%d', '%s' ),
                array( '%d' )
            );

        }

        $completed = (int) get_user_meta( $child_id, 'champion_completed_orders', true );
        update_user_meta( $child_id, 'champion_completed_orders', $completed - 1 );

        // $500 bonus ke rule ke hisaab se parent milestones check
        $opts     = Champion_Helpers::instance()->get_opts();
        $required = intval( $opts['child_orders_required'] ); // default: 5

        if ( $new_count < $required ) {
            $this->evaluate_parent_milestones_on_refunded( $parent_id );
        }
        
    }

    public function evaluate_parent_milestones( $parent_id ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $block = intval( $opts['block_size'] );
        $bonus = floatval( $opts['bonus_amount'] );
        $required = intval( $opts['child_orders_required'] );

        // count distinct children meeting required
        $count = intval( $this->wpdb->get_var( $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->child_table} WHERE parent_affiliate_id = %d AND qualifying_orders >= %d", $parent_id, $required) ) );
        if ( $count <= 0 ) return;

        $awarded_blocks = intval( $this->wpdb->get_var( $this->wpdb->prepare("SELECT COUNT(*) FROM {$this->milestone_table} WHERE parent_affiliate_id = %d", $parent_id) ) );

        $available_blocks = intval( floor( $count / $block ) );
        $new_blocks = $available_blocks - $awarded_blocks;
        if ( $new_blocks <= 0 ) return;

        for ( $i = 0; $i < $new_blocks; $i++ ) {
            $block_index = $awarded_blocks + $i + 1;
            $this->wpdb->insert( $this->milestone_table, array(
                'parent_affiliate_id' => $parent_id,
                'amount' => $bonus,
                'block_index' => $block_index,
                'milestone_children' => $block,
                'awarded_at' => current_time('mysql'),
                'note' => 'auto-awarded 10x5'
            ) );

            

            // Don't award immediately - let monthly payout process handle it
            // The milestone is created with awarded_at timestamp, monthly payout will process it
            // Monthly payout (cron on 15th or manual trigger) will call do_action('champion_award_milestone')

        }
    }

    public function evaluate_parent_milestones_on_refunded( $parent_id ){
        // Get the latest unpaid milestone (highest block_index)
        $last_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT id
                FROM {$this->milestone_table}
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
        $this->wpdb->delete( $this->milestone_table,
          array( 'id' => $last_id ),
          array( '%d' )
        );
    }

    /* Admin getters */
    public function get_milestones($limit=200) {
        return $this->wpdb->get_results("SELECT * FROM {$this->milestone_table} ORDER BY awarded_at DESC LIMIT " . intval($limit));
    }

    public function get_child_counters($parent=0) {
        if ( $parent ) {
            return $this->wpdb->get_results( $this->wpdb->prepare("SELECT * FROM {$this->child_table} WHERE parent_affiliate_id = %d", $parent) );
        }
        return $this->wpdb->get_results( "SELECT * FROM {$this->child_table} ORDER BY last_order_at DESC LIMIT 500" );
    }
}
