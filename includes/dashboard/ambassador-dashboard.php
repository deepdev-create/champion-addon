<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dashboard Caching Helper Functions
 */

/**
 * Get cached data or fetch fresh if cache expired or refresh requested
 * 
 * @param string $cache_key Cache key
 * @param callable $callback Function to fetch fresh data
 * @param int $user_id User ID for cache key
 * @param int $expiration Cache expiration in seconds (default 5 minutes)
 * @param bool $force_refresh Force refresh (skip cache)
 * @return mixed Cached or fresh data
 */
function champion_get_cached_dashboard_data( $cache_key, $callback, $user_id, $expiration = 300, $force_refresh = false ) {
    $full_key = 'champion_dash_' . $cache_key . '_' . $user_id;
    
    // Check if refresh requested
    if ( $force_refresh || isset( $_GET['champion_refresh'] ) ) {
        delete_transient( $full_key );
    }
    
    // Try to get from cache
    $cached = get_transient( $full_key );
    if ( $cached !== false && ! $force_refresh ) {
        return $cached;
    }
    
    // Fetch fresh data
    $data = call_user_func( $callback );
    
    // Cache it
    set_transient( $full_key, $data, $expiration );
    
    return $data;
}

/**
 * Clear all dashboard cache for a user
 * 
 * @param int $user_id User ID
 */
function champion_clear_dashboard_cache( $user_id ) {
    global $wpdb;
    $user_id = (int) $user_id;
    
    // Delete all transients for this user
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_champion_dash_' ) . '%' . $wpdb->esc_like( '_' . $user_id ) . '%'
    ));
    
    // Also delete timeout transients
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like( '_transient_timeout_champion_dash_' ) . '%' . $wpdb->esc_like( '_' . $user_id ) . '%'
    ));
}




function champion_get_ambassador_commission_totals( $ambassador_id ) {
    global $wpdb;

    $ambassador_id = (int) $ambassador_id;
    if ( $ambassador_id <= 0 ) {
        return [
            'lifetime' => 0,
            'this_month' => 0,
            'paid' => 0,
        ];
    }

    $posts = $wpdb->posts;
    $postmeta = $wpdb->postmeta;
    $month_start = strtotime( date('Y-m-01 00:00:00') );

    // Get all orders where champion_ambassador_id = $ambassador_id
    // INNER JOIN ensures only posts with the meta key exist
    $query = $wpdb->prepare(
        "SELECT p.ID, pm_amb.meta_value as ambassador_id, pm_comm.meta_value as commission, p.post_date
         FROM {$posts} p
         INNER JOIN {$postmeta} pm_amb ON (p.ID = pm_amb.post_id AND pm_amb.meta_key = 'champion_ambassador_id')
         LEFT JOIN {$postmeta} pm_comm ON (p.ID = pm_comm.post_id AND pm_comm.meta_key = 'champion_commission_amount')
         WHERE p.post_type = 'shop_order'
         AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded')
         AND pm_amb.meta_value = %d",
        $ambassador_id
    );

    $results = $wpdb->get_results( $query );

    $lifetime = 0;
    $this_month = 0;

    if ( ! empty( $results ) ) {
        foreach ( $results as $row ) {
            $commission = (float) $row->commission;
            if ( $commission <= 0 ) {
                continue;
            }

            $lifetime += $commission;

            // Check if order is this month
            $order_timestamp = strtotime( $row->post_date );
            if ( $order_timestamp >= $month_start ) {
                $this_month += $commission;
            }
        }
    }

    /**
     * Paid amount (bonus payouts)
     * Use milestone payout history (paid=1) instead of a user meta that is not maintained reliably.
     */
    $paid = (float) champion_get_ambassador_paid_total_from_milestones( $ambassador_id );

    return [
        'lifetime'   => round( $lifetime, 2 ),
        'this_month' => round( $this_month, 2 ),
        'paid'       => round( $paid, 2 ),
    ];
}


function champion_get_customer_orders_stats( $ambassador_id ) {
    $ambassador_id = (int) $ambassador_id;
    if ( $ambassador_id <= 0 ) {
        return ['orders' => 0, 'revenue' => 0];
    }

    // Get attached customers
    $users = get_users([
        'meta_key'   => 'champion_attached_ambassador',
        'meta_value' => $ambassador_id,
        'fields'     => 'ID',
    ]);

    if ( empty($users) ) {
        return ['orders' => 0, 'revenue' => 0];
    }

    $order_count = 0;
    $revenue     = 0.0;

    foreach ( $users as $uid ) {
        $orders = wc_get_orders([
            'customer_id' => $uid,
            'status'      => ['processing','completed'],
            'limit'       => -1,
        ]);

        foreach ( $orders as $order ) {
            $order_count++;
            $revenue += (float) $order->get_total();
        }
    }

    return [
        'orders'  => $order_count,
        'revenue' => $revenue,
    ];
}

function champion_get_customer_commission_totals_stats( $ambassador_id ) {
    global $wpdb;

    $ambassador_id = (int) $ambassador_id;
    if ( $ambassador_id <= 0 ) {
        return [ 'lifetime' => 0.0, 'this_month' => 0.0 ];
    }

    // First, get all customer IDs attached to this ambassador
    $users_query = $wpdb->prepare(
        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'champion_attached_ambassador' AND meta_value = %d",
        $ambassador_id
    );
    $customer_ids = $wpdb->get_col( $users_query );

    if ( empty( $customer_ids ) ) {
        return [ 'lifetime' => 0.0, 'this_month' => 0.0 ];
    }

    $posts = $wpdb->posts;
    $postmeta = $wpdb->postmeta;
    $month_start = strtotime( date('Y-m-01 00:00:00') );

    // Get orders for these customers with their commission amounts
    $placeholders = implode( ',', array_fill( 0, count( $customer_ids ), '%d' ) );
    $query = $wpdb->prepare(
        "SELECT p.ID, pm_comm.meta_value as commission, p.post_date
         FROM {$posts} p
         LEFT JOIN {$postmeta} pm_comm ON (p.ID = pm_comm.post_id AND pm_comm.meta_key = 'champion_commission_amount')
         WHERE p.post_type = 'shop_order'
         AND p.post_status IN ('wc-processing', 'wc-completed', 'wc-refunded')
         AND p.post_author IN ({$placeholders})",
        ...$customer_ids
    );

    $results = $wpdb->get_results( $query );

    $lifetime   = 0.0;
    $this_month = 0.0;

    if ( ! empty( $results ) ) {
        foreach ( $results as $row ) {
            $commission = (float) $row->commission;
            if ( $commission <= 0 ) {
                continue;
            }

            $lifetime += $commission;

            // Check if order is this month
            $order_timestamp = strtotime( $row->post_date );
            if ( $order_timestamp >= $month_start ) {
                $this_month += $commission;
            }
        }
    }

    return [
        'lifetime'   => round( $lifetime, 2 ),
        'this_month' => round( $this_month, 2 ),
    ];
}



function champion_get_ambassador_paid_total_from_milestones( $parent_id ) {
    global $wpdb;

    $parent_id = (int) $parent_id;
    if ( $parent_id <= 0 ) {
        return 0.0;
    }

    $table = $wpdb->prefix . 'champion_milestones';

    $sum = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT SUM(amount) FROM {$table} WHERE parent_affiliate_id = %d AND paid = 1",
            $parent_id
        )
    );

    return $sum ? (float) $sum : 0.0;
}


function champion_get_milestone_payout_history( $parent_id, $limit = 50 ) {
    global $wpdb;

    $parent_id = intval( $parent_id );
    $limit     = max( 1, intval( $limit ) );

    $table = $wpdb->prefix . 'champion_milestones';

    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, amount, block_index, milestone_children, awarded_at, paid, coupon_id, note
             FROM {$table}
             WHERE parent_affiliate_id = %d
             ORDER BY id DESC
             LIMIT %d",
            $parent_id,
            $limit
        )
    );
}



/**
 * Helper: Get referral & invite links for an ambassador
 *
 * Uses:
 * - user meta `champion_ref_code` (fallback: user ID)
 * Filterable via `champion_ambassador_referral_links`.
 */
if (!function_exists('champion_get_ambassador_referral_links')) {
    function champion_get_ambassador_referral_links($user_id)
    {
        $ref_code = get_user_meta($user_id, 'champion_ref_code', true);
        if (empty($ref_code)) {
            $ref_code = $user_id;
        }

        // Customer referral link – you can change base URL if your logic is different
        $customer_ref_link = add_query_arg('ref', $ref_code, site_url('sign-in/'));

        // Ambassador invite link – matches your doc: /ambassador-register/?invite=CODE
        $ambassador_invite_link = add_query_arg('invite', $ref_code, site_url('/ambassador-register/'));

        $data = [
            'ref_code'               => $ref_code,
            'customer_ref_link'      => $customer_ref_link,
            'ambassador_invite_link' => $ambassador_invite_link,
        ];

        return apply_filters('champion_ambassador_referral_links', $data, $user_id);
    }
}

/**
 * Helper: Get total bonus earned by an ambassador
 *
 * Default: user meta `champion_total_bonus`
 * Filter: `champion_ambassador_total_bonus`
 */
if (!function_exists('champion_get_ambassador_total_bonus')) {
    function champion_get_ambassador_total_bonus($user_id)
    {
        $total_bonus = get_user_meta($user_id, 'champion_total_bonus', true);

        if ($total_bonus === '' || $total_bonus === null) {
            $total_bonus = 0;
        }

        $total_bonus = (float) $total_bonus;

        return apply_filters('champion_ambassador_total_bonus', $total_bonus, $user_id);
    }
}

/**
 * NEW 10x10x5 HELPERS - Get child ambassador qualification progress
 */
if (!function_exists('champion_get_child_qualification_progress')) {
    /**
     * For a child ambassador: how many qualifying customers do they have?
     * Returns array with customer details and progress
     */
    function champion_get_child_qualification_progress($child_ambassador_id, $parent_ambassador_id) {
        if ( ! class_exists('Champion_Customer_Milestones') ) {
            return array('customers_qualified' => 0, 'customers' => array(), 'is_qualified' => false);
        }

        $cm = Champion_Customer_Milestones::instance();
        $customer_records = $cm->get_customer_order_counts($child_ambassador_id, $parent_ambassador_id);
        $qualifying_customers = $cm->count_qualifying_customers_for_child($child_ambassador_id, $parent_ambassador_id);

        $opts = Champion_Helpers::instance()->get_opts();
        $orders_required = intval( $opts['child_orders_required'] ) ?: 5;
        $block_size = intval( $opts['block_size'] ) ?: 10;

        $customers = array();
        if ($customer_records) {
            foreach ($customer_records as $record) {
                $user = get_user_by('id', $record->customer_id);
                $customers[] = array(
                    'id' => $record->customer_id,
                    'name' => $user ? $user->display_name : 'User #' . $record->customer_id,
                    'orders' => $record->qualifying_orders,
                    'orders_required' => $orders_required,
                    'qualified' => $record->qualifying_orders >= $orders_required,
                );
            }
        }

        $is_qualified = intval($qualifying_customers) >= $block_size;

        return array(
            'customers_qualified' => intval($qualifying_customers),
            'customers_total' => count($customers),
            'customers' => $customers,
            'is_qualified' => $is_qualified,
            'progress_percent' => min(100, ($qualifying_customers / $block_size) * 100),
        );
    }
}

if (!function_exists('champion_get_parent_bonus_progress')) {
    /**
     * For a parent ambassador: how many qualified children do they have available?
     * Returns milestone progress toward next bonus (Tier 3)
     * 
     * Uses setting: block_size (qualified children required for parent bonus)
     * Returns: available children, used children, total qualified, progress %
     */
    function champion_get_parent_bonus_progress($parent_ambassador_id) {
        if ( ! class_exists('Champion_Customer_Milestones') ) {
            return array(
                'qualified_children_available' => 0,
                'qualified_children_used' => 0,
                'qualified_children_all' => 0,
                'progress_percent' => 0,
                'children' => array(),
            );
        }

        $opts = Champion_Helpers::instance()->get_opts();
        $block_size = intval( $opts['block_size'] ) ?: 10;

        $cm = Champion_Customer_Milestones::instance();
        $available = $cm->get_available_qualified_children($parent_ambassador_id);
        $all_qualified = $cm->get_qualified_children($parent_ambassador_id);

        $available_ids = array();
        if ($available) {
            foreach ($available as $child) {
                $available_ids[] = $child->child_ambassador_id;
            }
        }

        $used_count = intval(count($all_qualified)) - intval(count($available));
        $available_count = intval(count($available));

        $children = array();
        if ($all_qualified) {
            foreach ($all_qualified as $child) {
                $is_available = in_array($child->child_ambassador_id, $available_ids, true);
                $user = get_user_by('id', $child->child_ambassador_id);
                $children[] = array(
                    'id' => $child->child_ambassador_id,
                    'name' => $user ? $user->display_name : 'User #' . $child->child_ambassador_id,
                    'qualified_at' => $child->qualified_at,
                    'available' => $is_available,
                );
            }
        }

        return array(
            'qualified_children_available' => $available_count,
            'qualified_children_used' => $used_count,
            'qualified_children_all' => intval(count($all_qualified)),
            'progress_percent' => min(100, ($available_count / $block_size) * 100),
            'can_earn_bonus' => $available_count >= $block_size,
            'children' => $children,
        );
    }
}

/**
 * Helper: Get referred ambassadors
 *
 * Default behaviour:
 * - Tries user meta `champion_referred_ambassadors` (array of user IDs)
 * - If empty, also looks for users with meta `champion_parent_ambassador` = $user_id
 *
 * Each item returns:
 * - id, name, email, joined, completed_orders, qualified (bool)
 *
 * Filter: `champion_ambassador_referred_ambassadors`
 */
if (!function_exists('champion_get_referred_ambassadors')) {
    function champion_get_referred_ambassadors($user_id)
    {
        $referred_ids = get_user_meta($user_id, 'champion_referred_ambassadors', true);
        if (!is_array($referred_ids)) {
            $referred_ids = [];
        }

        // Fallback: query users with parent_ambassador meta
        if (empty($referred_ids)) {
            $query = new WP_User_Query([
                'meta_key'   => 'champion_parent_ambassador',
                'meta_value' => $user_id,
                'number'     => 100,
                'fields'     => 'ID',
            ]);
            $referred_ids = $query->get_results();
        }

        $ambassadors = [];

        if (!empty($referred_ids)) {
            foreach ($referred_ids as $amb_id) {
                $user = get_user_by('id', $amb_id);
                if (!$user) {
                    continue;
                }

                // Child ambassador qualification: check if they have enough qualifying customers
                $opts_ref = Champion_Helpers::instance()->get_opts();
                $block_size = intval( $opts_ref['block_size'] ) ?: 10;
                
                // Get their qualifying customers count
                $qual_customers = 0;
                if ( class_exists('Champion_Customer_Milestones') ) {
                    $cm = Champion_Customer_Milestones::instance();
                    $qual_customers = $cm->count_qualifying_customers_for_child($amb_id, $user_id);
                }
                
                $qualified = intval($qual_customers) >= $block_size;

                $ambassadors[] = [
                    'id'               => $amb_id,
                    'name'             => $user->display_name,
                    'email'            => $user->user_email,
                    'joined'           => $user->user_registered,
                    'completed_orders' => $completed_orders,
                    'qualified'        => $qualified,
                ];
            }
        }

        return apply_filters('champion_ambassador_referred_ambassadors', $ambassadors, $user_id);
    }
}

/**
 * Helper: Get referred customers
 *
 * Default:
 * - Users with meta `champion_attached_ambassador` = $user_id
 *
 * Each item: id, name, email, total_orders, total_spent, last_order_date
 *
 * Filter: `champion_ambassador_referred_customers`
 */
if (!function_exists('champion_get_referred_customers')) {
    function champion_get_referred_customers($user_id)
    {
        $customers = [];

        // Find customers attached to this ambassador
        $query = new WP_User_Query([
          'meta_key'   => 'champion_attached_ambassador',
          'meta_value' => $user_id,
          'number'     => 100,
          'fields'     => 'all',
        ]);


        $users = $query->get_results();

        if (!empty($users)) {
            foreach ($users as $cust) {
                $customer_id = $cust->ID;

                // Pull simple stats using WooCommerce customer functions
                $wc_customer = new WC_Customer($customer_id);

                $total_orders = method_exists($wc_customer, 'get_order_count') ? (int) $wc_customer->get_order_count() : 0;
                $total_spent  = method_exists($wc_customer, 'get_total_spent') ? (float) $wc_customer->get_total_spent() : 0;

                // Get last order
                $last_order = wc_get_customer_last_order($customer_id);

                // If Woo returns a refund object as "last order", switch to its parent order
                if ( $last_order && is_a($last_order, 'WC_Order_Refund') && method_exists($last_order, 'get_parent_id') ) {
                    $parent_id = (int) $last_order->get_parent_id();
                    if ( $parent_id > 0 ) {
                        $last_order = wc_get_order( $parent_id );
                    }
                }

                $last_date = '';
                if ( $last_order ) {
                    $dc = $last_order->get_date_created();
                    $last_date = $dc ? $dc->date_i18n( get_option('date_format') ) : '';
                }



                $customers[] = [
                    'id'           => $customer_id,
                    'name'         => $cust->display_name,
                    'email'        => $cust->user_email,
                    'total_orders' => $total_orders,
                    'total_spent'  => $total_spent,
                    'last_order'   => $last_date,
                ];
            }
        }

        return apply_filters('champion_ambassador_referred_customers', $customers, $user_id);
    }
}

/**
 * Helper: Get commission/order history for an ambassador
 *
 * Default:
 * - Orders with meta `champion_ambassador_id` = $user_id
 * - Commission amount from meta `champion_commission_amount`
 *
 * Filter: `champion_ambassador_commission_orders`
 */
if (!function_exists('champion_get_ambassador_commissions')) {
    function champion_get_ambassador_commissions($user_id, $limit = 200)
    {
        if (!class_exists('WC_Order')) {
            return [];
        }

        $orders = wc_get_orders([
            'limit'      => $limit,
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => [
                [
                    'key'   => 'champion_ambassador_id',
                    'value' => $user_id,
                ],
            ],
        ]);

        $uid = get_current_user_id();

         $orders = array_filter($orders, function($order) use ($uid){
            if ( ! $order instanceof WC_Order ) return false;

            $amb1 = (int) $order->get_meta('champion_ambassador_id', true);
            
            return ( ($amb1 === $uid) );
        });

        $data = [];

        foreach ($orders as $order) {
            /** @var WC_Order $order */


            // Skip refund objects to prevent dashboard fatals after full refunds
            if ( is_a($order, 'WC_Order_Refund') ) {
                continue;
            }
            if ( method_exists($order, 'get_type') && $order->get_type() === 'shop_order_refund' ) {
                continue;
            }
        
            $order_id      = $order->get_id();
            $commission    = get_post_meta($order_id, 'champion_commission_amount', true);
            $commission    = $commission !== '' ? (float) $commission : 0;
            $status        = wc_get_order_status_name($order->get_status());
            $total         = $order->get_total();
            
            $dc = $order->get_date_created();
            $order_date = $dc ? $dc->date_i18n( get_option('date_format') ) : '';

            
            $customer_name = $order->get_formatted_billing_full_name();

            $data[] = [
                'order_id'   => $order_id,
                'date'       => $order_date,
                'customer'   => $customer_name,
                'total'      => $total,
                'commission' => $commission,
                'status'     => $status,
            ];
        }

        return apply_filters('champion_ambassador_commission_orders', $data, $user_id);
    }
}

/**
 * Helper: Compute $500 bonus progress
 *
 * Uses:
 * - Referred ambassadors from champion_get_referred_ambassadors()
 * - Qualified = completed_orders >= 5
 * - Bonus amount from option `champion_bonus_amount` (default 500)
 *
 * Returns:
 * - qualified_count
 * - cycle_size (10)
 * - current_cycle_qualified
 * - completed_cycles
 * - bonus_amount
 * - total_bonus_computed
 * - progress_percent
 */
if (!function_exists('champion_get_bonus_progress')) {
    function champion_get_bonus_progress($user_id)
    {
        $ambassadors = champion_get_referred_ambassadors($user_id);

        $qualified_count = 0;
        foreach ($ambassadors as $amb) {
            if (!empty($amb['qualified'])) {
                $qualified_count++;
            }
        }

        $cycle_size  = 10;
        $bonus_amount = (float) get_option('champion_bonus_amount', 500);

        $completed_cycles        = (int) floor($qualified_count / $cycle_size);
        $current_cycle_qualified = $qualified_count % $cycle_size;

        $total_bonus_computed = $completed_cycles * $bonus_amount;
        $progress_percent     = $cycle_size > 0 ? min(100, ($current_cycle_qualified / $cycle_size) * 100) : 0;

        $data = [
            'qualified_count'        => $qualified_count,
            'cycle_size'             => $cycle_size,
            'current_cycle_qualified'=> $current_cycle_qualified,
            'completed_cycles'       => $completed_cycles,
            'bonus_amount'           => $bonus_amount,
            'total_bonus_computed'   => $total_bonus_computed,
            'progress_percent'       => $progress_percent,
        ];

        return apply_filters('champion_ambassador_bonus_progress', $data, $user_id);
    }
}

/**
 * MAIN RENDER FUNCTION: Ambassador Dashboard
 *
 * Shortcode: [champion_ambassador_dashboard]
 */
if (!function_exists('champion_render_ambassador_dashboard')) {
    function champion_render_ambassador_dashboard()
    {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your Ambassador Dashboard.', 'champion-addon') . '</p>';
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Basic role check (optional)
        if ( ! apply_filters('champion_is_user_ambassador', false, $user_id) ) {
          return '<p>' . esc_html__('You are not registered as an ambassador.', 'champion-addon') . '</p>';
        }

        // Check if refresh requested
        $force_refresh = isset( $_GET['champion_refresh'] ) && $_GET['champion_refresh'] === '1';
        if ( $force_refresh ) {
            champion_clear_dashboard_cache( $user_id );
        }

        // Get data with caching (5 minutes cache)
        $links = champion_get_cached_dashboard_data( 'links', function() use ($user_id) {
            return champion_get_ambassador_referral_links($user_id);
        }, $user_id, 300, $force_refresh );

        $total_bonus = champion_get_cached_dashboard_data( 'total_bonus', function() use ($user_id) {
            return champion_get_ambassador_total_bonus($user_id);
        }, $user_id, 300, $force_refresh );

        $ambassadors = champion_get_cached_dashboard_data( 'ambassadors', function() use ($user_id) {
            return champion_get_referred_ambassadors($user_id);
        }, $user_id, 300, $force_refresh );

        $customers = champion_get_cached_dashboard_data( 'customers', function() use ($user_id) {
            return champion_get_referred_customers($user_id);
        }, $user_id, 300, $force_refresh );

        $customer_stats = champion_get_cached_dashboard_data( 'customer_stats', function() use ($user_id) {
            return champion_get_customer_orders_stats($user_id);
        }, $user_id, 300, $force_refresh );

        $commissions = champion_get_cached_dashboard_data( 'commissions', function() use ($user_id) {
            return champion_get_ambassador_commissions($user_id, 200);
        }, $user_id, 300, $force_refresh );

        // NEW: Get 10x10x5 milestone progress
        $bonus_progress = champion_get_cached_dashboard_data( 'bonus_progress', function() use ($user_id) {
            return champion_get_parent_bonus_progress($user_id);
        }, $user_id, 300, $force_refresh );

        $commission_totals = champion_get_cached_dashboard_data( 'commission_totals', function() use ($user_id) {
            return champion_get_ambassador_commission_totals( $user_id );
        }, $user_id, 300, $force_refresh );

        $customer_commission_totals = champion_get_cached_dashboard_data( 'customer_commission_totals', function() use ($user_id) {
            return champion_get_customer_commission_totals_stats( $user_id );
        }, $user_id, 300, $force_refresh );

        // Social share URLs
        $share_url   = urlencode($links['customer_ref_link']);
        $share_title = urlencode(get_bloginfo('name') . ' - Join via my ambassador link!');

        $whatsapp_url = 'https://wa.me/?text=' . $share_title . '%20' . $share_url;
        $facebook_url = 'https://www.facebook.com/sharer/sharer.php?u=' . $share_url;
        $twitter_url  = 'https://twitter.com/intent/tweet?text=' . $share_title . '&url=' . $share_url;
        $email_url    = 'mailto:?subject=' . $share_title . '&body=' . $share_url;
        $telegram_url = 'https://t.me/share/url?url=' . $share_url . '&text=' . $share_title;

        // QR code (simple external generator)
        $qr_code_src  = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . $share_url;


        /*
        $customer_commission_orders = wc_get_orders( array(
            'limit'      => -1,
            'status'     => array( 'completed' ),
            'meta_query' => array(
                array(
                    'key'   => 'champion_ambassador_id',
                    'value' => get_current_user_id(),
                ),array(
                    'key'   => 'champion_customer_ref_ambassador_id',
                    'value' => get_current_user_id(),
                ),
                array(
                    'key'     => 'champion_commission_paid',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );*/

        $customer_commission_orders = champion_get_cached_dashboard_data( 'customer_commission_orders', function() use ($user_id) {
            $orders = wc_get_orders( array(
                'limit'  => 300,
                'status' => array('completed','wc-processing'),
                'orderby'=> 'date',
                'order'  => 'DESC',
                'return' => 'objects',
            ) );

            return array_filter($orders, function($order) use ($user_id){
                if ( ! $order instanceof WC_Order ) return false;
                $amb1 = (int) $order->get_meta('champion_ambassador_id', true);
                $amb2 = (int) $order->get_meta('champion_customer_ref_ambassador_id', true);
                $paid_exists = $order->get_meta('champion_commission_paid', true);
                return ( ($amb1 === $user_id) || ($amb2 === $user_id) ) && ($paid_exists !== '' && $paid_exists !== null);
            });
        }, $user_id, 300, $force_refresh );

        // Get payout history with caching
        $payouts = champion_get_cached_dashboard_data( 'payouts', function() use ($user_id) {
            return champion_get_milestone_payout_history( $user_id, 50 );
        }, $user_id, 300, $force_refresh );

        ob_start();
        ?>

<div class="champion-dashboard-wrapper">
    <!-- Refresh Button -->
    <div style="text-align: right; margin-bottom: 15px;">
        <a href="<?php echo esc_url( add_query_arg( 'champion_refresh', '1' ) ); ?>" 
           class="button" 
           style="text-decoration: none; display: inline-block;">
            🔄 <?php echo esc_html__('Refresh Data', 'champion-addon'); ?>
        </a>
    </div>

    <!-- Referral Links Section (Always Visible) -->
    <?php
    $partial_path = CHAMPION_ADDON_PATH . 'includes/dashboard/partials/ambassador-links.php';
    if ( file_exists( $partial_path ) ) {
        include $partial_path;
    } else {
        // Fallback if partial doesn't exist
        ?>
        <div class="champion-card">
            <div class="champion-card-header">
                <div>
                    <div class="champion-card-title"><?php echo esc_html__('Ambassador Dashboard', 'champion-addon'); ?></div>
                    <div class="champion-card-subtitle">
                        <?php echo esc_html__('Share your links, track your referrals and bonuses in one place.', 'champion-addon'); ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    ?>

    <!-- Tabs Navigation -->
    <div class="champion-tabs" style="margin-top: 20px;">
        <div class="champion-tab-nav" style="border-bottom: 2px solid #e0e0e0; margin-bottom: 20px;">
            <button class="champion-tab-btn active" data-tab="milestones" style="padding: 12px 24px; background: none; border: none; border-bottom: 3px solid #0073aa; cursor: pointer; font-size: 16px; font-weight: 600; color: #0073aa;">
                <?php echo esc_html__('Milestones', 'champion-addon'); ?>
            </button>
            <button class="champion-tab-btn" data-tab="commissions" style="padding: 12px 24px; background: none; border: none; border-bottom: 3px solid transparent; cursor: pointer; font-size: 16px; font-weight: 600; color: #666;">
                <?php echo esc_html__('Commissions', 'champion-addon'); ?>
            </button>
        </div>

        <!-- Milestones Tab Content -->
        <div class="champion-tab-content active" id="champion-tab-milestones" data-tab="milestones">
            <!-- Milestone Progress -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div class="champion-card-title"><?php echo esc_html__('Milestone Progress', 'champion-addon'); ?></div>
            <div class="champion-card-subtitle" style="font-size: 13px; margin-top: 5px;">
                <?php 
                $opts_subtitle = Champion_Helpers::instance()->get_opts();
                $block_size_subtitle = intval( $opts_subtitle['block_size'] ) ?: 10;
                $bonus_amount_subtitle = floatval( $opts_subtitle['bonus_amount'] ) ?: 500;
                printf(
                    esc_html__('Track your path: %d Customers per Child → %d Qualified Children → $%s Bonus', 'champion-addon'),
                    $block_size_subtitle,
                    $block_size_subtitle,
                    number_format($bonus_amount_subtitle, 2)
                );
                ?>
            </div>
        </div>

        <div class="champion-stats-row">
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Referred Ambassadors', 'champion-addon'); ?></div>
                <div class="champion-stat-value"><?php echo esc_html(count($ambassadors)); ?></div>
            </div>
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Fully Qualified Children', 'champion-addon'); ?></div>
                <div class="champion-stat-value"><?php echo esc_html($bonus_progress['qualified_children_all']); ?></div>
            </div>
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Available for Next Bonus', 'champion-addon'); ?></div>
                <div class="champion-stat-value" style="color: #0073aa;">
                    <?php 
                    $opts_bonus = Champion_Helpers::instance()->get_opts();
                    $block_size_bonus = intval( $opts_bonus['block_size'] ) ?: 10;
                    echo esc_html($bonus_progress['qualified_children_available']) . '/' . esc_html($block_size_bonus);
                    ?>
                </div>
                <div style="height: 6px; background: #e0e0e0; border-radius: 3px; margin-top: 8px; overflow: hidden;">
                    <div style="height: 100%; background: #0073aa; width: <?php echo esc_attr($bonus_progress['progress_percent']); ?>%; transition: width 0.3s;"></div>
                </div>
            </div>
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Used Children (Bonuses Earned)', 'champion-addon'); ?></div>
                <div class="champion-stat-value" style="color: #28a745;"><?php echo esc_html($bonus_progress['qualified_children_used']); ?></div>
            </div>
        </div>
    </div>

    <!-- Child Ambassadors - Detailed Qualification View -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div class="champion-card-title"><?php echo esc_html__('Child Ambassadors & Customer Progress', 'champion-addon'); ?></div>
            <div class="champion-card-subtitle" style="font-size: 13px; margin-top: 5px;">
                <?php 
                $opts_children = Champion_Helpers::instance()->get_opts();
                $block_size_children = intval( $opts_children['block_size'] ) ?: 10;
                $orders_required_children = intval( $opts_children['child_orders_required'] ) ?: 5;
                printf(
                    esc_html__('Each child needs %d customers with %d+ orders to qualify', 'champion-addon'),
                    $block_size_children,
                    $orders_required_children
                );
                ?>
            </div>
        </div>

        <?php if (!empty($ambassadors)) : ?>
            <div style="overflow-x: auto;">
                <table class="champion-mini-table" style="width: 100%; font-size: 13px; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 2px solid #e0e0e0; background: #f5f5f5;">
                            <th style="padding: 8px; text-align: left;"><?php echo esc_html__('Child Ambassador', 'champion-addon'); ?></th>
                            <th style="padding: 8px; text-align: center;"><?php echo esc_html__('Qual. Customers', 'champion-addon'); ?></th>
                            <th style="padding: 8px; text-align: center;"><?php echo esc_html__('Status', 'champion-addon'); ?></th>
                            <th style="padding: 8px; text-align: left;"><?php echo esc_html__('Top Customers', 'champion-addon'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ambassadors as $amb) : 
                            $child_progress = champion_get_child_qualification_progress($amb['id'], $user_id);
                            $is_qualified = $child_progress['is_qualified'];
                            $qual_count = $child_progress['customers_qualified'];
                        ?>
                            <tr style="border-bottom: 1px solid #e0e0e0;">
                                <td style="padding: 8px;">
                                    <strong><?php echo esc_html($amb['name']); ?></strong><br>
                                    <span style="color: #666; font-size: 11px;">ID: <?php echo intval($amb['id']); ?></span>
                                </td>
                                <td style="padding: 8px; text-align: center;">
                                    <span style="font-size: 16px; font-weight: bold; color: <?php echo $is_qualified ? '#28a745' : '#0073aa'; ?>;">
                                        <?php echo intval($qual_count); ?>/<?php echo intval($opts_children['block_size']) ?: 10; ?>
                                    </span>
                                    <div style="height: 4px; background: #e0e0e0; border-radius: 2px; margin-top: 4px; width: 100%; overflow: hidden;">
                                        <div style="height: 100%; background: <?php echo $is_qualified ? '#28a745' : '#0073aa'; ?>; width: <?php echo esc_attr($child_progress['progress_percent']); ?>%;"></div>
                                    </div>
                                </td>
                                <td style="padding: 8px; text-align: center;">
                                    <?php if ($is_qualified) : ?>
                                        <span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: bold;">✓ QUALIFIED</span>
                                    <?php else : ?>
                                        <span style="background: #ffc107; color: #333; padding: 4px 8px; border-radius: 3px; font-size: 11px;">In Progress</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 8px; color: #666; font-size: 11px;">
                                    <?php 
                                    $cust_sample = array_slice($child_progress['customers'], 0, 3);
                                    if (!empty($cust_sample)) {
                                        $names = wp_list_pluck($cust_sample, 'name');
                                        echo esc_html(implode(', ', $names));
                                        if (count($child_progress['customers']) > 3) {
                                            echo ' ...';
                                        }
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="champion-tag-muted">
                <?php echo esc_html__('You haven\'t referred any child ambassadors yet. Share your ambassador invite link to get started!', 'champion-addon'); ?>
            </p>
        <?php endif; ?>
    </div>

            <!-- Bonus Summary (Milestones Tab) -->
            <div class="champion-card">
                <div class="champion-card-header">
                    <div class="champion-card-title"><?php echo esc_html__('Bonus Summary', 'champion-addon'); ?></div>
                </div>

                <div class="champion-stats-row">
                    <div class="champion-stat-box">
                        <div class="champion-stat-label"><?php echo esc_html__('Total Bonuses Earned', 'champion-addon'); ?></div>
                        <div class="champion-stat-value">
                            <?php echo wp_kses_post(wc_price($total_bonus)); ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top:18px;">
                <?php

            echo '<h3>Bonus Payout History</h3>';

            if ( empty( $payouts ) ) {
                echo '<p>No milestone payouts yet.</p>';
            } else {

                echo '<table class="widefat striped" style="max-width: 1000px;">';
                echo '<thead><tr>
                        <th style="width: 20%;">Milestone</th>
                        <th style="width: 20%;">Amount</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 25%;">Details</th>
                        <th style="width: 20%;">Awarded</th>
                      </tr></thead><tbody>';


                foreach ( $payouts as $row ) {

                    // Status
                    $status = 'Pending';
                    if ( intval( $row->paid ) === 1 ) {
                        if ( intval( $row->coupon_id ) > 0 ) {
                            $status = 'Paid (Coupon)';
                        } else {
                            $status = 'Paid (Points)';
                        }
                    }

                    // Milestone label
                    $milestone_label = 'Block #' . intval( $row->block_index ) . ' (Children: ' . intval( $row->milestone_children ) . ')';

                    // Amount (wc_price returns HTML, so DO NOT esc_html it)
                    if ( function_exists( 'wc_price' ) ) {
                        $amount_html = wp_kses_post( wc_price( (float) $row->amount ) );
                    } else {
                        $amount_html = esc_html( '$' . number_format( (float) $row->amount, 2 ) );
                    }

                    // Awarded date (string from DB)
                    $awarded = ! empty( $row->awarded_at ) ? $row->awarded_at : '-';

                    echo '<tr>';

                    echo '<td>' . esc_html( $milestone_label ) . '</td>';
                    echo '<td>' . $amount_html . '</td>';
                    echo '<td>' . esc_html( $status ) . '</td>';

                    // Details column
                    $details_html = '-';

                    if ( intval( $row->paid ) === 1 ) {

                        // Paid via coupon: show coupon code (ambassador needs the code)
                        if ( intval( $row->coupon_id ) > 0 ) {
                            $coupon_code = get_the_title( intval( $row->coupon_id ) );
                            if ( ! empty( $coupon_code ) ) {
                                $details_html = '<code>' . esc_html( $coupon_code ) . '</code>';
                            } else {
                                $details_html = esc_html__( 'Coupon generated', 'champion-addon' );
                            }
                        } else {
                            // Paid via WPLoyalty: show points + link to loyalty page
                            $points = (int) round( (float) $row->amount );
                            $url    = site_url( '/my-account/loyalty_reward/' );

                            $details_html = sprintf(
                                '%1$s ' . esc_html__( 'points', 'champion-addon' ) . ' — <a href="%2$s" target="_blank" rel="noopener">%3$s</a>',
                                esc_html( $points ),
                                esc_url( $url ),
                                esc_html__( 'View / Redeem', 'champion-addon' )
                            );
                        }
                    }

                    echo '<td>' . wp_kses_post( $details_html ) . '</td>';
                    echo '<td>' . esc_html( $awarded ) . '</td>';



                    echo '</tr>';
                }

                echo '</tbody></table>';
            }
            ?>
        </div>
        </div>
    </div>

    <!-- Referred Ambassadors Table -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div class="champion-card-title"><?php echo esc_html__('Referred Ambassadors', 'champion-addon'); ?></div>
            <div class="champion-card-subtitle">
                <?php echo esc_html__('These ambassadors joined using your invite link and count toward your $500 bonuses.', 'champion-addon'); ?>
            </div>
        </div>

        <?php if (!empty($ambassadors)) : ?>
            <div style="overflow-x:auto;">
                <table class="champion-table">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Name', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Email', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Joined', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Completed Orders', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Status', 'champion-addon'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ambassadors as $amb) : ?>
                        <tr>
                            <td><?php echo esc_html($amb['name']); ?></td>
                            <td><?php echo esc_html($amb['email']); ?></td>
                            <td>
                                <?php
                                echo $amb['joined']
                                    ? esc_html(date_i18n(get_option('date_format'), strtotime($amb['joined'])))
                                    : '';
                                ?>
                            </td>
                            <td><?php echo esc_html(intval($amb['completed_orders'])); ?></td>
                            <td>
                                <?php if (!empty($amb['qualified'])) : ?>
                                    <span class="champion-badge champion-badge-success">
                                        <?php echo esc_html__('Qualified', 'champion-addon'); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="champion-badge champion-badge-muted">
                                        <?php echo esc_html__('In Progress', 'champion-addon'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="champion-tag-muted">
                <?php echo esc_html__('No ambassadors referred yet. Share your invite link to start building your team.', 'champion-addon'); ?>
            </p>
        <?php endif; ?>
        </div>
        </div>
        <!-- End Milestones Tab -->

        <!-- Commissions Tab Content -->
        <div class="champion-tab-content" id="champion-tab-commissions" data-tab="commissions" style="display: none;">
            <!-- Commission Summary -->
            <div class="champion-card">
                <div class="champion-card-header">
                    <div class="champion-card-title"><?php echo esc_html__('Commission Summary', 'champion-addon'); ?></div>
                </div>

                <div class="champion-stats-row">
                    <div class="champion-stat-box">
                        <div class="champion-stat-label"><?php echo esc_html__('Lifetime Commission', 'champion-addon'); ?></div>
                        <div class="champion-stat-value">
                            <?php echo wp_kses_post(wc_price($commission_totals['lifetime'])); ?>
                        </div>
                    </div>
                    <div class="champion-stat-box">
                        <div class="champion-stat-label"><?php echo esc_html__('This Month Commission', 'champion-addon'); ?></div>
                        <div class="champion-stat-value">
                            <?php echo wp_kses_post(wc_price($commission_totals['this_month'])); ?>
                        </div>
                    </div>
                    <div class="champion-stat-box">
                        <div class="champion-stat-label"><?php echo esc_html__('Commission Paid Out', 'champion-addon'); ?></div>
                        <div class="champion-stat-value" style="color: #28a745;">
                            <?php echo wp_kses_post(wc_price($commission_totals['paid'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Referred Customers List -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div class="champion-card-title"><?php echo esc_html__('Referred Customers', 'champion-addon'); ?></div>
            <div class="champion-card-subtitle">
                <?php echo esc_html__('Customers attached to you via your referral link or coupon.', 'champion-addon'); ?>
            </div>
        </div>
        <?php if (!empty($customers)) : ?>
            <div style="overflow-x:auto;">
                <table class="champion-table">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Name', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Email', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Total Orders', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Total Spent', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Last Order', 'champion-addon'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($customers as $cust) : ?>
                        <tr>
                            <td><?php echo esc_html($cust['name']); ?></td>
                            <td><?php echo esc_html($cust['email']); ?></td>
                            <td><?php echo esc_html(intval($cust['total_orders'])); ?></td>
                            <td>
                                <?php
                                if (function_exists('wc_price')) {
                                    echo wp_kses_post(wc_price($cust['total_spent']));
                                } else {
                                    echo esc_html(number_format($cust['total_spent'], 2));
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($cust['last_order']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="champion-tag-muted">
                <?php echo esc_html__('No referred customers yet. Share your customer referral link to start earning commissions.', 'champion-addon'); ?>
            </p>
        <?php endif; ?>
    </div>

    
    <h3 style="margin-top:40px;">Customer Commission Payout History</h3>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Amount</th>
                    <th>Payout Method</th>
                    <th>Reference</th>
                    <th>Paid On</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $customer_commission_orders ) ) : ?>
                    <tr>
                        <td colspan="5">No customer commission payouts yet.</td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $customer_commission_orders as $order ) : ?>

                        <?php
                        $amount     = $order->get_meta( 'champion_commission_amount' );
                        $paid_on    = $order->get_meta( 'champion_commission_paid_on' );
                        $ref        = $order->get_meta( 'champion_commission_payout_ref' );
                        ?>

                        <tr>
                            <td>
                                <a href="<?php echo esc_url( get_edit_post_link( $order->get_id() ) ); ?>">
                                    #<?php echo esc_html( $order->get_id() ); ?>
                                </a>
                            </td>

                            <td>
                                <?php echo wc_price( $amount ); ?>
                            </td>

                            <td>
                                <?php echo ( $ref === 'wployalty' ) ? 'Points' : 'Coupon'; ?>
                            </td>

                            <td>
                                <?php
                                if ( is_numeric( $ref ) ) {
                                    echo 'Coupon #' . intval( $ref );
                                } else {
                                    echo esc_html( ucfirst( $ref ) );
                                }
                                ?>
                            </td>

                            <td>
                                <?php echo esc_html( $paid_on ); ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>


    <div class="champion-card champion-customer-orders-card">
      <div class="champion-card-head">
        <h3>Customer Orders</h3>
        <div class="champion-card-subtitle">Orders and revenue from customers attached to you.</div>
      </div>

      <div class="champion-metrics">
          <div class="champion-metric">
            <div class="champion-metric-label">Orders</div>
            <div class="champion-metric-value">
              <?php echo esc_html( (int) ($customer_stats['orders'] ?? 0) ); ?>
            </div>
          </div>

          <div class="champion-metric">
            <div class="champion-metric-label">Revenue</div>
            <div class="champion-metric-value">
              <?php
                $rev = (float) ($customer_stats['revenue'] ?? 0);
                echo function_exists('wc_price') ? wp_kses_post( wc_price($rev) ) : esc_html('$' . number_format($rev, 2));
              ?>
            </div>
          </div>

          <div class="champion-metric">
            <div class="champion-metric-label">Total Commission</div>
            <div class="champion-metric-value">
              <?php echo wc_price( $customer_commission_totals['lifetime'] ); ?>
            </div>
          </div>

          <div class="champion-metric">
            <div class="champion-metric-label">This Month</div>
            <div class="champion-metric-value">
              <?php echo wc_price( $customer_commission_totals['this_month'] ); ?>
            </div>
          </div>
        </div>
    </div>
    <!-- Order history + commissions -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div class="champion-card-title"><?php echo esc_html__('Order & Commission History', 'champion-addon'); ?></div>
            <div class="champion-card-subtitle">
                <?php echo esc_html__('Recent orders where you earned ambassador commission.', 'champion-addon'); ?>
            </div>
        </div>

        <?php if (!empty($commissions)) : ?>
            <div style="overflow-x:auto;">
                <table class="champion-table">
                    <thead>
                    <tr>
                        <th><?php echo esc_html__('Order', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Date', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Customer', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Order Total', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Your Commission', 'champion-addon'); ?></th>
                        <th><?php echo esc_html__('Status', 'champion-addon'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($commissions as $row) : ?>
                        <tr>
                            <td>#<?php echo esc_html($row['order_id']); ?></td>
                            <td><?php echo esc_html($row['date']); ?></td>
                            <td><?php echo esc_html($row['customer']); ?></td>
                            <td>
                                <?php
                                if (function_exists('wc_price')) {
                                    echo wp_kses_post(wc_price($row['total']));
                                } else {
                                    echo esc_html(number_format($row['total'], 2));
                                }
                                ?>
                            </td>
                            <td>
                                <?php
                                if (function_exists('wc_price')) {
                                    echo wp_kses_post(wc_price($row['commission']));
                                } else {
                                    echo esc_html(number_format($row['commission'], 2));
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html($row['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else : ?>
            <p class="champion-tag-muted">
                <?php echo esc_html__('No commissionable orders found yet.', 'champion-addon'); ?>
            </p>
        <?php endif; ?>
        </div>
        </div>
        <!-- End Commissions Tab -->
    </div>
    <!-- End Tabs -->

</div>
<!-- End Dashboard Wrapper -->

<script>
// Tab Switching
document.addEventListener('DOMContentLoaded', function() {
    var tabButtons = document.querySelectorAll('.champion-tab-btn');
    var tabContents = document.querySelectorAll('.champion-tab-content');
    
    tabButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetTab = this.getAttribute('data-tab');
            
            // Update buttons
            tabButtons.forEach(function(b) {
                b.classList.remove('active');
                b.style.borderBottomColor = 'transparent';
                b.style.color = '#666';
            });
            this.classList.add('active');
            this.style.borderBottomColor = '#0073aa';
            this.style.color = '#0073aa';
            
            // Update content
            tabContents.forEach(function(content) {
                if (content.getAttribute('data-tab') === targetTab) {
                    content.style.display = 'block';
                    content.classList.add('active');
                } else {
                    content.style.display = 'none';
                    content.classList.remove('active');
                }
            });
        });
    });
});

// Copy Button Functionality
document.addEventListener('click', function (e) {
    if (e.target && e.target.classList.contains('champion-copy-btn')) {
        var text = e.target.getAttribute('data-copy');
        if (!text) return;
        navigator.clipboard.writeText(text).then(function () {
            var original = e.target.textContent;
            e.target.textContent = '<?php echo esc_js(__('Copied!', 'champion-addon')); ?>';
            setTimeout(function () {
                e.target.textContent = original;
            }, 1200);
        });
    }
});
</script>

<?php
// DEBUG Section - Only show if constant is enabled
if ( defined( 'CHAMPION_DEBUG_DASHBOARD' ) && CHAMPION_DEBUG_DASHBOARD === 1 ) :
?>
<!-- DEBUG: Meta Relations & Data Flow -->
<hr style="margin-top:60px; border-top: 2px dashed #ccc;">
<div style="background: #f0f0f0; padding: 20px; margin-top: 20px; border-left: 5px solid #cc0000; font-family: monospace; font-size: 11px; max-height: 600px; overflow-y: auto;">
    <h4 style="margin-top:0; color: #cc0000;">🔍 DEBUG: Parent-Child Relationship Chain</h4>
    
    <?php
    global $wpdb;
    $parent_id = intval( $user_id );
    
    // STEP 1: Check parent user meta
    echo "<strong style='color: #0066cc;'>STEP 1: Parent User #{$parent_id} Meta</strong><br>";
    $parent_meta = $wpdb->get_results( $wpdb->prepare(
        "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE 'champion%' ORDER BY meta_key",
        $parent_id
    ));
    
    if ( ! empty( $parent_meta ) ) {
        foreach ( $parent_meta as $meta ) {
            $val = maybe_unserialize( $meta->meta_value );
            if ( is_array( $val ) ) {
                echo "  {$meta->meta_key}: [array with " . count( $val ) . " items]<br>";
                foreach ( $val as $k => $v ) {
                    echo "    - [{$k}] = {$v}<br>";
                }
            } else {
                echo "  {$meta->meta_key}: {$val}<br>";
            }
        }
    } else {
        echo "  ❌ No champion meta keys found!<br>";
    }
    echo "<br>";
    
    // STEP 2: Find children linked to this parent
    echo "<strong style='color: #0066cc;'>STEP 2: Find Children Linked to Parent #{$parent_id}</strong><br>";
    $children_by_meta = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'champion_parent_ambassador' AND meta_value = %d",
        $parent_id
    ));
    
    echo "Children found by champion_parent_ambassador meta: " . count( $children_by_meta ) . "<br>";
    
    if ( ! empty( $children_by_meta ) ) {
        foreach ( $children_by_meta as $child ) {
            echo "  - Child User #{$child->user_id}<br>";
            
            // Check child's meta
            $child_meta = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE 'champion%' ORDER BY meta_key",
                $child->user_id
            ));
            
            if ( ! empty( $child_meta ) ) {
                foreach ( $child_meta as $meta ) {
                    echo "    {$meta->meta_key}: {$meta->meta_value}<br>";
                }
            }
        }
    } else {
        echo "  ❌ No children found with champion_parent_ambassador = {$parent_id}<br>";
    }
    echo "<br>";
    
    // STEP 3: Check what's in the database tables
    echo "<strong style='color: #0066cc;'>STEP 3: Database Tables Status</strong><br>";
    
    // Tier 2: Qualified Children
    $qualified_children = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}champion_qualified_children WHERE parent_ambassador_id = %d",
        $parent_id
    ));
    
    echo "  champion_qualified_children: " . count( $qualified_children ) . " records<br>";
    foreach ( $qualified_children as $qc ) {
        echo "    - Child #{$qc->child_ambassador_id}, qualified_at: {$qc->qualified_at}<br>";
    }
    echo "<br>";
    
    // Tier 1: Customer Orders 
    echo "  champion_customer_orders:<br>";
    $all_customer_orders = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}champion_customer_orders WHERE parent_ambassador_id = %d",
        $parent_id
    ));
    
    echo "    Total records for parent: " . count( $all_customer_orders ) . "<br>";
    if ( ! empty( $all_customer_orders ) ) {
        foreach ( $all_customer_orders as $co ) {
            echo "      - Child #{$co->child_ambassador_id}, Customer #{$co->customer_id}, Orders: {$co->qualifying_orders}<br>";
        }
    }
    echo "<br>";
    
    // Tier 3: Milestones
    echo "  champion_milestones:<br>";
    $milestones = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}champion_milestones WHERE parent_affiliate_id = %d ORDER BY block_index DESC",
        $parent_id
    ));
    
    echo "    Total records for parent: " . count( $milestones ) . "<br>";
    foreach ( $milestones as $ms ) {
        echo "      - Block #{$ms->block_index}, Amount: \${$ms->amount}, Paid: {$ms->paid}<br>";
    }
    echo "<br>";
    
    // STEP 4: Check if children exist at all in system
    echo "<strong style='color: #0066cc;'>STEP 4: All Children in System</strong><br>";
    $all_children_in_system = $wpdb->get_results(
        "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'champion_parent_ambassador' LIMIT 20"
    );
    
    echo "Total children in system: " . count( $all_children_in_system ) . "<br>";
    echo "<br>";
    
    // Settings
    echo "<strong style='color: #0066cc;'>STEP 5: Current Settings</strong><br>";
    $opts = Champion_Helpers::instance()->get_opts();
    echo "block_size: " . intval( $opts['block_size'] ) . "<br>";
    echo "child_orders_required: " . intval( $opts['child_orders_required'] ) . "<br>";
    echo "child_order_min_amount: \$" . floatval( $opts['child_order_min_amount'] ) . "<br>";
    echo "bonus_amount: \$" . floatval( $opts['bonus_amount'] ) . "<br>";
    ?>
</div>
<?php
endif; // End DEBUG section
?>

<?php
        return ob_get_clean();
    }
}

// Shortcode registration is still:
 add_shortcode('champion_ambassador_dashboard', 'champion_render_ambassador_dashboard');

add_filter( 'woocommerce_account_menu_items', function( $items ) {

    if ( ! is_user_logged_in() ) {
        return $items;
    }

    $user_id = get_current_user_id();

    // Use Champion's ambassador check (role + flag + ref code)
    $is_ambassador = apply_filters(
        'champion_is_user_ambassador',
        false,
        $user_id
    );

    if ( ! $is_ambassador ) {
        return $items;
    }

    $new_items = array();

    foreach ( $items as $key => $label ) {

       // Insert BEFORE logout
       if ( 'customer-logout' === $key ) {
           $new_items['champion-dashboard'] = __( 'Ambassador Dashboard', 'champion-addon' );
       }

       $new_items[ $key ] = $label;
    }

    return $new_items;
}, 20 );


// Menu endpoint
add_action('init', function() {
    add_rewrite_endpoint('champion-dashboard', EP_ROOT | EP_PAGES);
});

// Content for endpoint
add_action('woocommerce_account_champion-dashboard_endpoint', function() {
    echo do_shortcode('[champion_ambassador_dashboard]');
});
