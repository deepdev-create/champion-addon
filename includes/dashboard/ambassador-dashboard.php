<?php
if (!defined('ABSPATH')) {
    exit;
}




function champion_get_ambassador_commission_totals( $ambassador_id ) {

    $ambassador_id = (int) $ambassador_id;
    if ( $ambassador_id <= 0 ) {
        return [
            'lifetime' => 0,
            'this_month' => 0,
            'paid' => 0,
        ];
    }

    $args = [
        'limit'      => -1,
        'status'     => ['processing', 'completed', 'refunded'],
        'meta_query' => [
            [
                'key'   => 'champion_ambassador_id',
                'value' => $ambassador_id,
            ]
        ],
        'return' => 'objects',
    ];

    $orders = wc_get_orders( $args );

    $lifetime = 0;
    $this_month = 0;

    $month_start = strtotime( date('Y-m-01 00:00:00') );

    foreach ( $orders as $order ) {

        if ( is_a( $order, 'WC_Order_Refund' ) ) {
            continue;
        }

        $commission = (float) $order->get_meta( 'champion_commission_amount', true );
        if ( $commission <= 0 ) {
            continue;
        }

        $lifetime += $commission;

        $created = $order->get_date_created();
        if ( $created && $created->getTimestamp() >= $month_start ) {
            $this_month += $commission;
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
    $ambassador_id = (int) $ambassador_id;

    if ( $ambassador_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
        return [ 'lifetime' => 0.0, 'this_month' => 0.0 ];
    }

    // Only customers attached to this ambassador
    $users = get_users([
        'meta_key'   => 'champion_attached_ambassador',
        'meta_value' => $ambassador_id,
        'fields'     => 'ID',
    ]);

    if ( empty( $users ) ) {
        return [ 'lifetime' => 0.0, 'this_month' => 0.0 ];
    }

    $lifetime    = 0.0;
    $this_month  = 0.0;
    $month_start = strtotime( date('Y-m-01 00:00:00') );

    foreach ( $users as $uid ) {

        $orders = wc_get_orders([
            'customer_id' => $uid,
            'status'      => ['processing', 'completed', 'refunded'],
            'limit'       => -1,
            'return'      => 'objects',
        ]);

        foreach ( $orders as $order ) {
            if ( is_a( $order, 'WC_Order_Refund' ) ) {
                continue;
            }

            // Commission is persisted on the order when Champion attribution runs
            $commission = (float) $order->get_meta( 'champion_commission_amount', true );
            if ( $commission <= 0 ) {
                continue;
            }

            $lifetime += $commission;

            $created = $order->get_date_created();
            if ( $created && $created->getTimestamp() >= $month_start ) {
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
        $customer_ref_link = add_query_arg('ref', $ref_code, site_url('/'));

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

                $completed_orders = (int) get_user_meta($amb_id, 'champion_completed_orders', true);
                $qualified        = $completed_orders >= 5; // As per your $500 bonus rule

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


        $links          = champion_get_ambassador_referral_links($user_id);
        $total_bonus    = champion_get_ambassador_total_bonus($user_id);
        $ambassadors    = champion_get_referred_ambassadors($user_id);
        $customers      = champion_get_referred_customers($user_id);
        $customer_stats = champion_get_customer_orders_stats($user_id);
        $commissions    = champion_get_ambassador_commissions($user_id, 200);
        $bonus_progress = champion_get_bonus_progress($user_id);

        $commission_totals = champion_get_ambassador_commission_totals( $user_id );

        $customer_commission_totals = champion_get_customer_commission_totals_stats( $user_id );



        // Use computed bonus if meta is empty
        if ($total_bonus <= 0 && $bonus_progress['total_bonus_computed'] > 0) {
            $total_bonus = $bonus_progress['total_bonus_computed'];
        }

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

        $orders = wc_get_orders( array(
            'limit'  => 300, // keep reasonable; increase if needed
            'status' => array('completed','wc-processing'),
            'orderby'=> 'date',
            'order'  => 'DESC',
            'return' => 'objects',
        ) );

        $uid = get_current_user_id();
        $customer_commission_orders = array_filter($orders, function($order) use ($uid){
            if ( ! $order instanceof WC_Order ) return false;

            $amb1 = (int) $order->get_meta('champion_ambassador_id', true);
            $amb2 = (int) $order->get_meta('champion_customer_ref_ambassador_id', true);
            $paid_exists = $order->get_meta('champion_commission_paid', true);
            // (ambassador match) AND paid meta exists
            return ( ($amb1 === $uid) || ($amb2 === $uid) ) && ($paid_exists !== '' && $paid_exists !== null);
        });

        ob_start();
        ?>

<div class="champion-dashboard-wrapper">
    <!-- Referral Links + QR + Share -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div>
                <div class="champion-card-title"><?php echo esc_html__('Ambassador Dashboard', 'champion-addon'); ?></div>
                <div class="champion-card-subtitle">
                    <?php echo esc_html__('Share your links, track your referrals and bonuses in one place.', 'champion-addon'); ?>
                </div>
            </div>
        </div>

        <div class="champion-flex">
            <div class="champion-col">
                <div class="champion-copy-wrap">
                    <div class="champion-copy-label"><?php echo esc_html__('Customer Referral Link', 'champion-addon'); ?></div>
                    <div class="champion-copy-box">
                        <span class="champion-copy-text"><?php echo esc_html($links['customer_ref_link']); ?></span>
                        <button class="champion-copy-btn" type="button"
                            data-copy="<?php echo esc_attr($links['customer_ref_link']); ?>">
                            <?php echo esc_html__('Copy', 'champion-addon'); ?>
                        </button>
                    </div>
                </div>

                <div class="champion-copy-wrap">
                    <div class="champion-copy-label"><?php echo esc_html__('Ambassador Invite Link', 'champion-addon'); ?></div>
                    <div class="champion-copy-box">
                        <span class="champion-copy-text"><?php echo esc_html($links['ambassador_invite_link']); ?></span>
                        <button class="champion-copy-btn" type="button"
                            data-copy="<?php echo esc_attr($links['ambassador_invite_link']); ?>">
                            <?php echo esc_html__('Copy', 'champion-addon'); ?>
                        </button>
                    </div>
                </div>
                <?php /*
                <div class="champion-share-list">
                    <span class="champion-tag-muted"><?php echo esc_html__('Share quickly:', 'champion-addon'); ?></span>
                    <a href="<?php echo esc_url($whatsapp_url); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                    <a href="<?php echo esc_url($facebook_url); ?>" target="_blank" rel="noopener noreferrer">Facebook</a>
                    <a href="<?php echo esc_url($twitter_url); ?>" target="_blank" rel="noopener noreferrer">X / Twitter</a>
                    <a href="<?php echo esc_url($telegram_url); ?>" target="_blank" rel="noopener noreferrer">Telegram</a>
                    <a href="<?php echo esc_url($email_url); ?>" target="_blank" rel="noopener noreferrer">Email</a>
                </div>
                */ ?>

            </div>
            
            <?php /*
            <div class="champion-col" style="max-width:260px;text-align:center;">
                <div class="champion-copy-label"><?php echo esc_html__('QR Code (Customer Link)', 'champion-addon'); ?></div>
                <img src="<?php echo esc_url($qr_code_src); ?>" alt="<?php esc_attr_e('Referral QR Code', 'champion-addon'); ?>" />
            </div>
            */ ?>


        </div>
    </div>

    <!-- Overview Stats & Bonus Progress -->
    <div class="champion-card">
        <div class="champion-card-header">
            <div class="champion-card-title"><?php echo esc_html__('Overview', 'champion-addon'); ?></div>
        </div>

        <div class="champion-stats-row">
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Referred Ambassadors', 'champion-addon'); ?></div>
                <div class="champion-stat-value"><?php echo esc_html(count($ambassadors)); ?></div>
            </div>
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Qualified Ambassadors', 'champion-addon'); ?></div>
                <div class="champion-stat-value"><?php echo esc_html($bonus_progress['qualified_count']); ?></div>
                <div class="champion-stat-sub">
                    <?php
                    printf(
                        esc_html__('%1$s per bonus block', 'champion-addon'),
                        intval($bonus_progress['cycle_size'])
                    );
                    ?>
                </div>
            </div>
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Referred Customers', 'champion-addon'); ?></div>
                <div class="champion-stat-value"><?php echo esc_html(count($customers)); ?></div>
            </div>
            <div class="champion-stat-box">
                <div class="champion-stat-label"><?php echo esc_html__('Total Bonuses Earned', 'champion-addon'); ?></div>
                <div class="champion-stat-value">
                    <?php echo wp_kses_post(wc_price($total_bonus)); ?>
                </div>
            </div>
        </div>

        <div style="margin-top:18px;">
            <div class="champion-copy-label">
                <?php
                printf(
                    esc_html__('Current $%1$s bonus progress (%2$s/%3$s qualified ambassadors in this block)', 'champion-addon'),
                    number_format($bonus_progress['bonus_amount'], 0),
                    intval($bonus_progress['current_cycle_qualified']),
                    intval($bonus_progress['cycle_size'])
                );
                ?>
            </div>
            <div class="champion-progress-bar-outer">
                <div class="champion-progress-bar-inner" style="width: <?php echo esc_attr($bonus_progress['progress_percent']); ?>%;"></div>
            </div>
            <div class="champion-progress-label">
                <span>
                    <?php
                    printf(
                        esc_html__('%1$s completed bonus block(s)', 'champion-addon'),
                        intval($bonus_progress['completed_cycles'])
                    );
                    ?>
                </span>
                <span><?php echo esc_html(round($bonus_progress['progress_percent'])); ?>%</span>
            </div>
            <div style="margin-top:18px;">
            <?php 

            $payouts = champion_get_milestone_payout_history( $user_id, 50 );

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
                            $status = 'Paid (WPLoyalty)';
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

          <?php /*
          <div class="champion-metric">
            <div class="champion-metric-label">Paid Till Date</div>
            <div class="champion-metric-value">
              <?php //echo wc_price( 0 ); ?>
            </div>
          </div> */ ?>


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

<script>
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
        return ob_get_clean();
    }
}

// Shortcode registration is still:
 add_shortcode('champion_ambassador_dashboard', 'champion_render_ambassador_dashboard');



// Add menu item
/*add_filter('woocommerce_account_menu_items', function($items) {
    $items['champion-dashboard'] = 'Ambassador Dashboard';
    return $items;
});*/

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

    $items['champion-dashboard'] = __( 'Ambassador Dashboard', 'champion-addon' );

    return $items;
}, 20 );


// Menu endpoint
add_action('init', function() {
    add_rewrite_endpoint('champion-dashboard', EP_ROOT | EP_PAGES);
});

// Content for endpoint
add_action('woocommerce_account_champion-dashboard_endpoint', function() {
    echo do_shortcode('[champion_ambassador_dashboard]');
});
