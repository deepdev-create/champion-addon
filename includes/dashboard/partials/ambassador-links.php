<?php
/**
 * Ambassador Referral Links Partial
 * 
 * @param int $user_id Current user ID
 * @param array $links Referral links array
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$opts = Champion_Helpers::instance()->get_opts();
$parent_meta = ! empty( $opts['parent_usermeta'] ) ? $opts['parent_usermeta'] : 'champion_parent_ambassador';
$is_child = (int) get_user_meta( $user_id, $parent_meta, true ) > 0;
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

            <!-- Instructions Box -->
            <div class="champion-info-box" style="margin-top: 20px; padding: 15px; background: #f0f7ff; border-left: 4px solid #0073aa; border-radius: 4px;">
                <h4 style="margin-top: 0; margin-bottom: 10px; color: #0073aa; font-size: 14px; font-weight: 600;">
                    <?php echo esc_html__('📋 How to Use Your Links', 'champion-addon'); ?>
                </h4>
                <?php
                if ( $is_child ) {
                    // Child Ambassador Instructions
                    $opts_instructions = Champion_Helpers::instance()->get_opts();
                    $customers_required = intval( $opts_instructions['block_size'] ) ?: 10;
                    $orders_required = intval( $opts_instructions['child_orders_required'] ) ?: 5;
                    ?>
                    <ul style="margin: 0; padding-left: 20px; color: #555; font-size: 13px; line-height: 1.6;">
                        <li><strong>Ambassador Invite Link:</strong> <?php echo esc_html__('Share this to recruit other ambassadors who will become your child ambassadors (for milestone bonuses).', 'champion-addon'); ?></li>
                        <li><strong>Customer Referral Link:</strong> <?php 
                            printf(
                                esc_html__('Share this to recruit customers for the 10x10x5 milestone system. You need %d customers with %d orders each ($50+) to qualify. These customers will count toward your milestone qualification AND your parent ambassador will earn commission on their orders.', 'champion-addon'),
                                $customers_required,
                                $orders_required
                            );
                        ?></li>
                    </ul>
                    <p style="margin: 10px 0 0 0; padding: 10px; background: #fff; border-radius: 4px; font-size: 12px; color: #666;">
                        <strong>💡 Tip:</strong> <?php echo esc_html__('Focus on sharing your Customer Referral Link to reach your milestone goal!', 'champion-addon'); ?>
                    </p>
                    <?php
                } else {
                    // Parent Ambassador Instructions
                    ?>
                    <ul style="margin: 0; padding-left: 20px; color: #555; font-size: 13px; line-height: 1.6;">
                        <li><strong>Ambassador Invite Link:</strong> <?php echo esc_html__('Share this to recruit child ambassadors. When they qualify (10 customers with 5 orders each), you earn milestone bonuses.', 'champion-addon'); ?></li>
                        <li><strong>Customer Referral Link:</strong> <?php echo esc_html__('Share this to recruit customers directly. You will earn commission on their orders, but these customers won\'t count toward milestone bonuses (only customers referred by your child ambassadors count for milestones).', 'champion-addon'); ?></li>
                    </ul>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>
</div>
