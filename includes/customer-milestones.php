<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Champion_Customer_Milestones - 10x10x5 Three-Tier System
 * 
 * TIER 1 (Customer Qualification):
 * - Customer must place N qualifying orders of $X+ minimum
 * - Setting: child_orders_required (orders per customer, default 5)
 * - Setting: child_order_min_amount (minimum $ per order, default $50)
 * - When customer reaches N qualifying orders → counted as qualifying customer for child
 * 
 * TIER 2 (Child Ambassador Qualification):
 * - Child must bring N qualifying customers to their parent
 * - Setting: block_size (customers required per child, default 10)
 * - When child reaches N qualifying customers → marked as qualified child
 * 
 * TIER 3 (Parent Ambassador Bonus):
 * - Parent receives bonus when they have N qualified children AVAILABLE
 * - Setting: block_size (same as Tier 2, qualified children per bonus, default 10)
 * - Setting: bonus_amount (bonus per parent achievement, default $500)
 * - When parent reaches N qualified available children → awards milestone bonus
 * - Non-reusable: qualified children are marked as "used" after parent's bonus
 * 
 * Database Tables:
 * - champion_customer_orders: Tracks qualifying order counts per customer per child per parent
 * - champion_qualified_children: Marks which children are qualified (10+ customers)
 * - champion_child_milestone_used: Prevents reuse of qualified children (non-reusable constraint)
 */
class Champion_Customer_Milestones {
    private static $instance = null;
    private $wpdb;
    private $customer_orders_table;          // Track per-customer order counts (TIER 1)
    private $qualified_children_table;       // Track which children are qualified (TIER 2)
    private $child_milestone_used_table;     // Track which qualified children were used for parent bonus (TIER 3)

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->customer_orders_table     = $wpdb->prefix . 'champion_customer_orders';
        $this->qualified_children_table  = $wpdb->prefix . 'champion_qualified_children';
        $this->child_milestone_used_table = $wpdb->prefix . 'champion_child_milestone_used';

        // Hook into order completion to track customer orders
        add_action('woocommerce_order_status_completed', array($this, 'on_customer_order_completed'), 15, 1);

        // Hook into refund to reverse counts
        add_action('woocommerce_order_status_refunded', array($this, 'on_customer_order_refunded'), 15, 1);
    }

    /**
     * Create all required tables for 10x10x5 system
     */
    public function create_customer_tables(){
        $charset_collate = $this->wpdb->get_charset_collate();

        // Table 1: Track qualifying orders per customer per child ambassador
        $sql1 = "CREATE TABLE IF NOT EXISTS {$this->customer_orders_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_ambassador_id BIGINT(20) UNSIGNED NOT NULL,
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            parent_ambassador_id BIGINT(20) UNSIGNED NOT NULL,
            qualifying_orders INT(11) NOT NULL DEFAULT 0,
            last_order_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY child_customer_parent (child_ambassador_id, customer_id, parent_ambassador_id),
            KEY customer_id (customer_id),
            KEY parent_ambassador_id (parent_ambassador_id)
        ) $charset_collate;";

        // Table 2: Track which children are qualified (have 10 qualifying customers)
        $sql2 = "CREATE TABLE IF NOT EXISTS {$this->qualified_children_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_ambassador_id BIGINT(20) UNSIGNED NOT NULL,
            parent_ambassador_id BIGINT(20) UNSIGNED NOT NULL,
            qualified_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY child_parent (child_ambassador_id, parent_ambassador_id),
            KEY parent_ambassador_id (parent_ambassador_id)
        ) $charset_collate;";

        // Table 3: Track which qualified children were used for parent milestone bonus (prevents reuse)
        $sql3 = "CREATE TABLE IF NOT EXISTS {$this->child_milestone_used_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_ambassador_id BIGINT(20) UNSIGNED NOT NULL,
            parent_ambassador_id BIGINT(20) UNSIGNED NOT NULL,
            milestone_id BIGINT(20) UNSIGNED NOT NULL,
            used_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY child_parent_milestone (child_ambassador_id, parent_ambassador_id, milestone_id),
            KEY parent_ambassador_id (parent_ambassador_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
    }

    /**
     * Handle customer order completion.
     * Tier 1: Track order count for customer under child ambassador.
     */
    public function on_customer_order_completed( $order_id ) {
        if ( ! class_exists('WC_Order') ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        Champion_Helpers::log( 'on_customer_order_completed: Order ' . $order_id, array(
            'customer_id' => $order->get_customer_id(),
            'total' => $order->get_total(),
        ));

        $opts = Champion_Helpers::instance()->get_opts();
        $min_amount = floatval( $opts['child_order_min_amount'] );
        $order_total = floatval( $order->get_total() );

        // Not a qualifying order amount
        if ( $order_total < $min_amount ) {
            Champion_Helpers::log( 'on_customer_order_completed: Order too small', array(
                'order_id' => $order_id,
                'total' => $order_total,
                'min_amount' => $min_amount,
            ));
            return;
        }

        $customer_id = $order->get_customer_id();
        if ( ! $customer_id ) {
            Champion_Helpers::log( 'on_customer_order_completed: No customer', array(
                'order_id' => $order_id,
            ));
            return;
        }

        // Try to find child ambassador who referred this customer
        $child_ambassador_id = $this->get_child_ambassador_for_customer( $customer_id );
        if ( ! $child_ambassador_id ) {
            Champion_Helpers::log( 'on_customer_order_completed: No child ambassador found', array(
                'customer_id' => $customer_id,
            ));
            return;
        }

        // Get parent of child ambassador
        $parent_meta = $opts['parent_usermeta'];
        $parent_ambassador_id = intval( get_user_meta( $child_ambassador_id, $parent_meta, true ) );
        if ( ! $parent_ambassador_id ) {
            Champion_Helpers::log( 'on_customer_order_completed: No parent ambassador found', array(
                'child_id' => $child_ambassador_id,
                'parent_meta_key' => $parent_meta,
            ));
            return;
        }

        Champion_Helpers::log( 'on_customer_order_completed: Processing', array(
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'child_ambassador_id' => $child_ambassador_id,
            'parent_ambassador_id' => $parent_ambassador_id,
        ));

        // Track this order for the customer under this child
        $this->increment_customer_order_count( $child_ambassador_id, $customer_id, $parent_ambassador_id, $order_id );
    }

    /**
     * Handle customer order refund.
     * Reverse counts through the entire chain.
     */
    public function on_customer_order_refunded( $order_id, $refund_id = 0 ) {
        if ( ! class_exists('WC_Order') ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $opts = Champion_Helpers::instance()->get_opts();
        $min_amount = floatval( $opts['child_order_min_amount'] );
        $order_total = floatval( $order->get_total() );

        // Not a qualifying order amount
        if ( $order_total < $min_amount ) return;

        $customer_id = $order->get_customer_id();
        if ( ! $customer_id ) return;

        // Try to find child ambassador who referred this customer
        $child_ambassador_id = $this->get_child_ambassador_for_customer( $customer_id );
        if ( ! $child_ambassador_id ) return;

        // Get parent of child ambassador
        $parent_meta = $opts['parent_usermeta'];
        $parent_ambassador_id = intval( get_user_meta( $child_ambassador_id, $parent_meta, true ) );
        if ( ! $parent_ambassador_id ) return;

        // Reverse counts
        $this->decrement_customer_order_count( $child_ambassador_id, $customer_id, $parent_ambassador_id, $order_id );
    }

    /**
     * Increment customer order count.
     * When customer reaches 5 qualifying orders, they become a "qualifying customer" for the child.
     * When child reaches 10 qualifying customers, they become a "qualified child" for the parent.
     */
    private function increment_customer_order_count( $child_id, $customer_id, $parent_id, $order_id = 0 ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $customer_orders_required = intval( $opts['child_orders_required'] ); // 5

        Champion_Helpers::log( 'increment_customer_order_count: Start', array(
            'child_id' => $child_id,
            'customer_id' => $customer_id,
            'parent_id' => $parent_id,
            'order_id' => $order_id,
            'customer_orders_required' => $customer_orders_required,
        ));

        // Get or create customer order counter
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->customer_orders_table} WHERE child_ambassador_id = %d AND customer_id = %d AND parent_ambassador_id = %d",
            $child_id, $customer_id, $parent_id
        ));

        $new_count = 1;
        if ( $row ) {
            $new_count = intval( $row->qualifying_orders ) + 1;
            Champion_Helpers::log( 'increment_customer_order_count: UPDATE', array(
                'old_count' => $row->qualifying_orders,
                'new_count' => $new_count,
            ));
            $this->wpdb->update(
                $this->customer_orders_table,
                array(
                    'qualifying_orders' => $new_count,
                    'last_order_at' => current_time('mysql'),
                ),
                array( 'id' => $row->id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        } else {
            Champion_Helpers::log( 'increment_customer_order_count: INSERT', array(
                'child_id' => $child_id,
                'customer_id' => $customer_id,
                'parent_id' => $parent_id,
            ));
            $this->wpdb->insert(
                $this->customer_orders_table,
                array(
                    'child_ambassador_id' => $child_id,
                    'customer_id' => $customer_id,
                    'parent_ambassador_id' => $parent_id,
                    'qualifying_orders' => 1,
                    'last_order_at' => current_time('mysql'),
                ),
                array( '%d', '%d', '%d', '%d', '%s' )
            );
            if ( $this->wpdb->last_error ) {
                Champion_Helpers::log( 'increment_customer_order_count: INSERT ERROR', array(
                    'error' => $this->wpdb->last_error,
                ));
            }
        }

        // If customer reached 5 qualifying orders, evaluate child qualifications
        if ( $new_count >= $customer_orders_required ) {
            $this->evaluate_child_qualifications( $child_id, $parent_id );
        }
    }

    /**
     * Decrement customer order count.
     * When customer drops below 5 qualifying orders, unqualify the child if needed.
     * When child drops below 10 qualifying customers, unqualify the parent milestone.
     */
    private function decrement_customer_order_count( $child_id, $customer_id, $parent_id, $order_id = 0 ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $customer_orders_required = intval( $opts['child_orders_required'] ); // 5

        // Get customer order counter
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->customer_orders_table} WHERE child_ambassador_id = %d AND customer_id = %d AND parent_ambassador_id = %d",
            $child_id, $customer_id, $parent_id
        ));

        if ( ! $row ) return;

        $old_count = intval( $row->qualifying_orders );
        $new_count = max( 0, $old_count - 1 );

        if ( $new_count <= 0 ) {
            // Delete the row
            $this->wpdb->delete(
                $this->customer_orders_table,
                array( 'id' => $row->id ),
                array( '%d' )
            );
        } else {
            $this->wpdb->update(
                $this->customer_orders_table,
                array(
                    'qualifying_orders' => $new_count,
                    'last_order_at' => current_time('mysql'),
                ),
                array( 'id' => $row->id ),
                array( '%d', '%s' ),
                array( '%d' )
            );
        }

        // If customer dropped below 5, unqualify child if needed
        if ( $old_count >= $customer_orders_required && $new_count < $customer_orders_required ) {
            $this->unqualify_child_if_needed( $child_id, $parent_id );
        }
    }

    /**
     * Evaluate if a child ambassador qualifies (has 10 customers with 5+ orders each).
     */
    private function evaluate_child_qualifications( $child_id, $parent_id ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $customers_required = intval( $opts['block_size'] ); // 10

        // Count customers with 5+ qualifying orders
        $qualifying_customers = intval( $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->customer_orders_table} 
             WHERE child_ambassador_id = %d AND parent_ambassador_id = %d AND qualifying_orders >= %d",
            $child_id, $parent_id, $opts['child_orders_required']
        )));

        // Mark as qualified if not already
        if ( $qualifying_customers >= $customers_required ) {
            $already_qualified = $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->qualified_children_table} WHERE child_ambassador_id = %d AND parent_ambassador_id = %d",
                $child_id, $parent_id
            ));

            if ( ! $already_qualified ) {
                $this->wpdb->insert(
                    $this->qualified_children_table,
                    array(
                        'child_ambassador_id' => $child_id,
                        'parent_ambassador_id' => $parent_id,
                        'qualified_at' => current_time('mysql'),
                    ),
                    array( '%d', '%d', '%s' )
                );

                // Evaluate parent milestones
                $this->evaluate_parent_milestones( $parent_id );
            }
        }
    }

    /**
     * Unqualify a child if they drop below 10 qualifying customers.
     */
    private function unqualify_child_if_needed( $child_id, $parent_id ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $customers_required = intval( $opts['block_size'] ); // 10

        // Count current qualifying customers
        $qualifying_customers = intval( $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->customer_orders_table} 
             WHERE child_ambassador_id = %d AND parent_ambassador_id = %d AND qualifying_orders >= %d",
            $child_id, $parent_id, $opts['child_orders_required']
        )));

        // If below threshold and was qualified, remove from qualified table
        if ( $qualifying_customers < $customers_required ) {
            $qualified_id = $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->qualified_children_table} WHERE child_ambassador_id = %d AND parent_ambassador_id = %d",
                $child_id, $parent_id
            ));

            if ( $qualified_id ) {
                $this->wpdb->delete(
                    $this->qualified_children_table,
                    array( 'id' => $qualified_id ),
                    array( '%d' )
                );

                // This may cause parent milestone to drop - evaluate
                $this->evaluate_parent_milestones_on_unqualify( $parent_id );
            }
        }
    }

    /**
     * Evaluate parent milestones.
     * Count qualified children NOT already used, and award if 10 available.
     */
    private function evaluate_parent_milestones( $parent_id ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $qualified_children_required = intval( $opts['block_size'] ); // 10
        $bonus = floatval( $opts['bonus_amount'] ); // 500

        // Count qualified children not yet used
        $available_qualified = intval( $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT qc.child_ambassador_id) FROM {$this->qualified_children_table} qc
             LEFT JOIN {$this->child_milestone_used_table} cm ON qc.child_ambassador_id = cm.child_ambassador_id AND qc.parent_ambassador_id = cm.parent_ambassador_id
             WHERE qc.parent_ambassador_id = %d AND cm.id IS NULL",
            $parent_id
        )));

        if ( $available_qualified < $qualified_children_required ) {
            return; // Not enough available qualified children yet
        }

        // Get next block index
        $last_block_index = intval( $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT MAX(block_index) FROM {$this->wpdb->prefix}champion_milestones WHERE parent_affiliate_id = %d",
            $parent_id
        )));

        $next_block_index = $last_block_index + 1;

        // Create milestone
        $milestone_id = $this->wpdb->insert(
            $this->wpdb->prefix . 'champion_milestones',
            array(
                'parent_affiliate_id' => $parent_id,
                'amount' => $bonus,
                'block_index' => $next_block_index,
                'milestone_children' => $qualified_children_required,
                'awarded_at' => current_time('mysql'),
                'note' => 'auto-awarded 10x10x5',
            ),
            array( '%d', '%f', '%d', '%d', '%s', '%s' )
        );

        if ( ! $milestone_id ) {
            Champion_Helpers::log('Failed to insert milestone for parent ' . $parent_id);
            return;
        }

        // Mark 10 qualified children as used for this milestone
        $qualified_children = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT DISTINCT child_ambassador_id FROM {$this->qualified_children_table}
             WHERE parent_ambassador_id = %d
             AND child_ambassador_id NOT IN (
                SELECT DISTINCT child_ambassador_id FROM {$this->child_milestone_used_table}
                WHERE parent_ambassador_id = %d
             )
             LIMIT %d",
            $parent_id, $parent_id, $qualified_children_required
        ));

        foreach ( $qualified_children as $child_id ) {
            $this->wpdb->insert(
                $this->child_milestone_used_table,
                array(
                    'child_ambassador_id' => $child_id,
                    'parent_ambassador_id' => $parent_id,
                    'milestone_id' => $milestone_id,
                    'used_at' => current_time('mysql'),
                ),
                array( '%d', '%d', '%d', '%s' )
            );
        }

        // Award milestone
        if ( get_transient( 'champion_suppress_awards' ) ) {
            // No-op: test mode
        } else {
            do_action( 'champion_award_milestone', $parent_id, $bonus, $next_block_index );
        }
    }

    /**
     * Called when a child becomes unqualified.
     * Remove the latest unpaid milestone if we drop below threshold.
     */
    private function evaluate_parent_milestones_on_unqualify( $parent_id ) {
        $opts = Champion_Helpers::instance()->get_opts();
        $qualified_children_required = intval( $opts['block_size'] ); // 10

        // Count available qualified children (not used)
        $available_qualified = intval( $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(DISTINCT qc.child_ambassador_id) FROM {$this->qualified_children_table} qc
             LEFT JOIN {$this->child_milestone_used_table} cm ON qc.child_ambassador_id = cm.child_ambassador_id AND qc.parent_ambassador_id = cm.parent_ambassador_id
             WHERE qc.parent_ambassador_id = %d AND cm.id IS NULL",
            $parent_id
        )));

        // If we dropped below threshold, remove latest unpaid milestone
        if ( $available_qualified < $qualified_children_required ) {
            $last_unpaid = $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT id FROM {$this->wpdb->prefix}champion_milestones
                 WHERE parent_affiliate_id = %d AND paid = 0
                 ORDER BY block_index DESC LIMIT 1",
                $parent_id
            ));

            if ( $last_unpaid ) {
                $this->wpdb->delete(
                    $this->wpdb->prefix . 'champion_milestones',
                    array( 'id' => $last_unpaid ),
                    array( '%d' )
                );

                // Clean up used records for this milestone
                $this->wpdb->delete(
                    $this->child_milestone_used_table,
                    array( 'milestone_id' => $last_unpaid ),
                    array( '%d' )
                );
            }
        }
    }

    /**
     * Find which child ambassador referred this customer.
     * Uses champion_attached_ambassador user meta.
     */
    private function get_child_ambassador_for_customer( $customer_id ) {
        $child_id = get_user_meta( $customer_id, 'champion_attached_ambassador', true );
        if ( $child_id && intval($child_id) > 0 ) {
            return intval( $child_id );
        }
        return 0;
    }

    /* Admin getters for dashboard */
    public function get_customer_order_counts( $child_id, $parent_id ) {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->customer_orders_table} WHERE child_ambassador_id = %d AND parent_ambassador_id = %d ORDER BY last_order_at DESC",
            $child_id, $parent_id
        ));
    }

    public function get_qualified_children( $parent_id ) {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->qualified_children_table} WHERE parent_ambassador_id = %d ORDER BY qualified_at DESC",
            $parent_id
        ));
    }

    public function get_available_qualified_children( $parent_id ) {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT qc.* FROM {$this->qualified_children_table} qc
             LEFT JOIN {$this->child_milestone_used_table} cm ON qc.child_ambassador_id = cm.child_ambassador_id AND qc.parent_ambassador_id = cm.parent_ambassador_id
             WHERE qc.parent_ambassador_id = %d AND cm.id IS NULL",
            $parent_id
        ));
    }

    public function count_qualifying_customers_for_child( $child_id, $parent_id ) {
        return intval( $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->customer_orders_table} WHERE child_ambassador_id = %d AND parent_ambassador_id = %d AND qualifying_orders >= %d",
            $child_id, $parent_id, 5
        )));
    }

}
