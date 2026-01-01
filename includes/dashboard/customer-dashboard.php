<?php
if (!defined('ABSPATH')) {
    exit;
}

add_filter( 'woocommerce_account_menu_items', function( $items ) {
    
    if ( ! is_user_logged_in() ) {
        return $items;
    }

    $user_id = get_current_user_id();

    $is_customer = apply_filters(
        'champion_is_user_customer',
        false,
        $user_id
    );

    if ( ! $is_customer ) {
        return $items;
    }

    $new_items = array();
    
    foreach ( $items as $key => $label ) {

        // Insert BEFORE Logout
        if ( 'customer-logout' === $key ) {
            $new_items['champion-customer-dashboard'] = __( 'Customer Dashboard', 'champion-addon' );
        }

        $new_items[ $key ] = $label;
    }

    return $new_items;

}, 20 );

add_action( 'init', function () {
    add_rewrite_endpoint( 'champion-customer-dashboard', EP_ROOT | EP_PAGES );
});

add_action(
    'woocommerce_account_champion-customer-dashboard_endpoint',
    function () {
        echo do_shortcode( '[champion_customer_dashboard]' );
    }
);

if (!function_exists('champion_get_customer_referral_links')) {
    function champion_get_customer_referral_links($user_id)
    {
        $ref_code = get_user_meta($user_id, 'champion_ref_code', true);
        if (empty($ref_code)) {
            $ref_code = $user_id;
        }

        // Customer referral link – you can change base URL if your logic is different
        $customer_ref_link = add_query_arg('ref', $ref_code, site_url('/sign-up/'));

        $data = [
            'ref_code'               => $ref_code,
            'customer_ref_link'      => $customer_ref_link,
            //'ambassador_invite_link' => $ambassador_invite_link,
        ];

        return apply_filters('champion_customer_referral_links', $data, $user_id);
    }
}

/**
 * Helper: Get referred ambassadors
 *
 * Default behaviour:
 * - Tries user meta `champion_referred_customers` (array of user IDs)
 * - If empty, also looks for users with meta `champion_parent_ambassador` = $user_id
 *
 * Each item returns:
 * - id, name, email, joined, completed_orders, qualified (bool)
 *
 * Filter: `champion_customer_get_referred_customers`
 */
if (!function_exists('champion_customer_get_referred_customers')) {
    function champion_customer_get_referred_customers($user_id)
    {
        

        $referred_ids = get_user_meta($user_id, 'champion_referred_customers', true);
        if (!is_array($referred_ids)) {
            $referred_ids = [];
        }

        // Fallback: query users with parent_ambassador meta
        if (empty($referred_ids)) {
            $query = new WP_User_Query([
                'meta_key'   => 'champion_parent_customer',
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

                $completed_orders = (int) get_user_meta($amb_id, 'champion_customer_completed_orders', true);
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

        return apply_filters('champion_customer_referred_customers', $ambassadors, $user_id);
    }
}

/**
 * Helper: Compute $100 bonus progress
 */
if (!function_exists('champion_customer_get_bonus_progress')) {
    function champion_customer_get_bonus_progress($user_id)
    {
        $customers = champion_customer_get_referred_customers($user_id);

        $qualified_count = 0;
        foreach ($customers as $cus) {
            if (!empty($cus['qualified'])) {
                $qualified_count++;
            }
        }

        $cycle_size  = 10;
        $bonus_amount = (float) get_option('champion_bonus_amount', 100);

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

        return apply_filters('champion_customer_bonus_progress', $data, $user_id);
    }
}

/**
 * Helper: Get total bonus earned by an ambassador
 *
 * Default: user meta `champion_cus_total_bonus`
 * Filter: `champion_customer_total_bonus`
 */
if (!function_exists('champion_get_customer_total_bonus')) {
    function champion_get_customer_total_bonus($user_id)
    {
        $total_bonus = get_user_meta($user_id, 'champion_cus_total_bonus', true);

        if ($total_bonus === '' || $total_bonus === null) {
            $total_bonus = 0;
        }

        $total_bonus = (float) $total_bonus;

        return apply_filters('champion_customer_total_bonus', $total_bonus, $user_id);
    }
}

function champion_get_customer_milestone_payout_history( $parent_id, $limit = 50 ) {
    global $wpdb;

    $parent_id = intval( $parent_id );
    $limit     = max( 1, intval( $limit ) );

    $table = $wpdb->prefix . 'champion_customer_milestones';

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

add_shortcode( 'champion_customer_dashboard', 'champion_render_customer_dashboard' );
if ( ! function_exists( 'champion_render_customer_dashboard' ) ) {

    function champion_render_customer_dashboard() {

        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__('Please log in to view your Customer Dashboard.', 'champion-addon') . '</p>';
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        if ( ! apply_filters('champion_is_user_customer', false, $user_id) ) {
          return '<p>' . esc_html__('You are not registered as an customer.', 'champion-addon') . '</p>';
        }

        $links          = champion_get_customer_referral_links($user_id);
        $customers      = champion_customer_get_referred_customers($user_id);
        $bonus_progress = champion_customer_get_bonus_progress($user_id);
        $total_bonus    = champion_get_customer_total_bonus($user_id);
        
        // Use computed bonus if meta is empty
        if ($total_bonus <= 0 && $bonus_progress['total_bonus_computed'] > 0) {
            $total_bonus = $bonus_progress['total_bonus_computed'];
        }

        ob_start();
        ?>
        <div class="champion-dashboard-wrapper">
            <!-- Referral Links + QR + Share -->
            <div class="champion-card">
                <div class="champion-card-header">
                    <div>
                        <div class="champion-card-title"><?php echo esc_html__('Customer Dashboard', 'champion-addon'); ?></div>
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
                    </div>
                </div>
            </div>

            <!-- Overview Stats & Bonus Progress -->
            <div class="champion-card">
                <div class="champion-card-header">
                    <div class="champion-card-title"><?php echo esc_html__('Overview', 'champion-addon'); ?></div>
                </div>

                <div class="champion-stats-row">
                    <div class="champion-stat-box">
                        <div class="champion-stat-label"><?php echo esc_html__('Referred Customers', 'champion-addon'); ?></div>
                        <div class="champion-stat-value"><?php echo esc_html(count($customers)); ?></div>
                    </div>
                    <div class="champion-stat-box">
                        <div class="champion-stat-label"><?php echo esc_html__('Qualified Customers', 'champion-addon'); ?></div>
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

                            $payouts = champion_get_customer_milestone_payout_history( $user_id, 50 );

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
            <!-- Referred Customers Table -->
            <div class="champion-card">
                <div class="champion-card-header">
                    <div class="champion-card-title"><?php echo esc_html__('Referred Customers', 'champion-addon'); ?></div>
                    <div class="champion-card-subtitle">
                        <?php echo esc_html__('These Customers joined using your invite link and count toward your $500 bonuses.', 'champion-addon'); ?>
                    </div>
                </div>

                <?php if (!empty($customers)) : ?>
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
                            <?php foreach ($customers as $cus) : ?>
                                <tr>
                                    <td><?php echo esc_html($cus['name']); ?></td>
                                    <td><?php echo esc_html($cus['email']); ?></td>
                                    <td>
                                        <?php
                                        echo $cus['joined']
                                            ? esc_html(date_i18n(get_option('date_format'), strtotime($cus['joined'])))
                                            : '';
                                        ?>
                                    </td>
                                    <td><?php echo esc_html(intval($cus['completed_orders'])); ?></td>
                                    <td>
                                        <?php if (!empty($cus['qualified'])) : ?>
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
                        <?php echo esc_html__('No customers referred yet. Share your invite link to start building your team.', 'champion-addon'); ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
         return ob_get_clean();
    }
}



