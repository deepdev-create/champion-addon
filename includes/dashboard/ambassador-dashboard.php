<?php
if (!defined('ABSPATH')) {
    exit;
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
                $last_date  = $last_order ? $last_order->get_date_created()->date_i18n(get_option('date_format')) : '';

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
    function champion_get_ambassador_commissions($user_id, $limit = 20)
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

        $data = [];

        foreach ($orders as $order) {
            /** @var WC_Order $order */
            $order_id      = $order->get_id();
            $commission    = get_post_meta($order_id, 'champion_commission_amount', true);
            $commission    = $commission !== '' ? (float) $commission : 0;
            $status        = wc_get_order_status_name($order->get_status());
            $total         = $order->get_total();
            $order_date    = $order->get_date_created() ? $order->get_date_created()->date_i18n(get_option('date_format')) : '';
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
        if (!in_array('ambassador', (array) $user->roles, true)) {
            // You can relax this if ambassadors are normal customers
            // return '<p>' . esc_html__('You are not registered as an ambassador.', 'champion-addon') . '</p>';
        }

        $links          = champion_get_ambassador_referral_links($user_id);
        $total_bonus    = champion_get_ambassador_total_bonus($user_id);
        $ambassadors    = champion_get_referred_ambassadors($user_id);
        $customers      = champion_get_referred_customers($user_id);
        $commissions    = champion_get_ambassador_commissions($user_id, 20);
        $bonus_progress = champion_get_bonus_progress($user_id);

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

        ob_start();
        ?>

<style>
.champion-dashboard-wrapper {
    max-width: 1100px;
    margin: 0 auto 40px;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.champion-card {
    background: #ffffff;
    padding: 20px;
    border-radius: 14px;
    margin-bottom: 25px;
    box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06);
}
.champion-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}
.champion-card-title {
    font-size: 20px;
    font-weight: 700;
}
.champion-card-subtitle {
    font-size: 13px;
    color: #6b7280;
}
.champion-flex {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}
.champion-col {
    flex: 1 1 250px;
}
.champion-copy-wrap {
    margin-bottom: 12px;
}
.champion-copy-label {
    font-size: 13px;
    color: #6b7280;
    margin-bottom: 4px;
}
.champion-copy-box {
    display: flex;
    align-items: center;
    background: #f3f4f6;
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 13px;
}
.champion-copy-text {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.champion-copy-btn {
    margin-left: auto;
    border: none;
    background: #111827;
    color: #ffffff;
    font-size: 11px;
    padding: 6px 10px;
    border-radius: 999px;
    cursor: pointer;
}
.champion-copy-btn:active {
    transform: scale(0.98);
}
.champion-stats-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}
.champion-stat-box {
    flex: 1 1 160px;
    background: #f9fafb;
    border-radius: 12px;
    padding: 14px;
    text-align: center;
}
.champion-stat-label {
    font-size: 12px;
    color: #6b7280;
}
.champion-stat-value {
    font-size: 26px;
    font-weight: 700;
    margin-top: 4px;
}
.champion-stat-sub {
    font-size: 11px;
    color: #9ca3af;
    margin-top: 2px;
}
.champion-progress-bar-outer {
    width: 100%;
    background: #e5e7eb;
    border-radius: 999px;
    height: 12px;
    overflow: hidden;
}
.champion-progress-bar-inner {
    height: 100%;
    border-radius: 999px;
    background: linear-gradient(90deg, #22c55e, #16a34a);
    width: 0%;
}
.champion-progress-label {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    margin-top: 6px;
    color: #4b5563;
}
.champion-share-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
.champion-share-list a {
    font-size: 12px;
    padding: 6px 10px;
    border-radius: 999px;
    border: 1px solid #e5e7eb;
    text-decoration: none;
}
.champion-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.champion-table th,
.champion-table td {
    padding: 8px 8px;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}
.champion-table th {
    font-weight: 600;
    background: #f9fafb;
}
.champion-badge {
    display: inline-block;
    padding: 3px 8px;
    font-size: 11px;
    border-radius: 999px;
}
.champion-badge-success {
    background: #dcfce7;
    color: #166534;
}
.champion-badge-muted {
    background: #f3f4f6;
    color: #4b5563;
}
.champion-tag-muted {
    font-size: 11px;
    color: #9ca3af;
}
@media (max-width: 768px) {
    .champion-card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
}
</style>

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

                <div class="champion-share-list">
                    <span class="champion-tag-muted"><?php echo esc_html__('Share quickly:', 'champion-addon'); ?></span>
                    <a href="<?php echo esc_url($whatsapp_url); ?>" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                    <a href="<?php echo esc_url($facebook_url); ?>" target="_blank" rel="noopener noreferrer">Facebook</a>
                    <a href="<?php echo esc_url($twitter_url); ?>" target="_blank" rel="noopener noreferrer">X / Twitter</a>
                    <a href="<?php echo esc_url($telegram_url); ?>" target="_blank" rel="noopener noreferrer">Telegram</a>
                    <a href="<?php echo esc_url($email_url); ?>" target="_blank" rel="noopener noreferrer">Email</a>
                </div>
            </div>

            <div class="champion-col" style="max-width:260px;text-align:center;">
                <div class="champion-copy-label"><?php echo esc_html__('QR Code (Customer Link)', 'champion-addon'); ?></div>
                <img src="<?php echo esc_url($qr_code_src); ?>" alt="<?php esc_attr_e('Referral QR Code', 'champion-addon'); ?>" />
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
                        <th style="width: 25%;">Milestone</th>
                        <th style="width: 25%;">Amount</th>
                        <th style="width: 20%;">Status</th>
                        <th style="width: 30%;">Awarded</th>
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
add_filter('woocommerce_account_menu_items', function($items) {
    $items['champion-dashboard'] = 'Ambassador Dashboard';
    return $items;
});

// Menu endpoint
add_action('init', function() {
    add_rewrite_endpoint('champion-dashboard', EP_ROOT | EP_PAGES);
});

// Content for endpoint
add_action('woocommerce_account_champion-dashboard_endpoint', function() {
    echo do_shortcode('[champion_ambassador_dashboard]');
});
