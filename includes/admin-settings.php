<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Admin {
    private static $instance = null;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        add_action('admin_menu', array($this,'menu'));
        add_action('admin_init', array($this,'register_settings'));
    }

    public function menu(){
        add_submenu_page('woocommerce', 'Champion Addon', 'Champion Addon', 'manage_woocommerce', 'champion-addon', array($this,'page'));
    }

    public function register_settings(){
        register_setting(Champion_Helpers::instance()::OPT_KEY, Champion_Helpers::instance()::OPT_KEY);
    }

    public function page(){
        if ( ! current_user_can('manage_woocommerce') ) return;
        $opts = Champion_Helpers::instance()->get_opts();
        $payouts = Champion_Payouts::instance();
        $milestones = $payouts->get_milestones(200);
        $m_engine = Champion_Milestones::instance();
        $counters = $m_engine->get_child_counters();
        ?>
        <div class="wrap">
            <h1>Champion Addon</h1>

            <h2>Settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields(Champion_Helpers::instance()::OPT_KEY); $options = get_option(Champion_Helpers::instance()::OPT_KEY, Champion_Helpers::instance()->defaults()); ?>
                <table class="form-table">
                    <tr><th>Bonus amount</th><td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[bonus_amount]" value="<?php echo esc_attr($options['bonus_amount']); ?>" /></td></tr>
                    <tr><th>Block size (ambassadors per bonus)</th><td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[block_size]" value="<?php echo esc_attr($options['block_size']); ?>" /></td></tr>
                    <tr><th>Child orders required</th><td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_orders_required]" value="<?php echo esc_attr($options['child_orders_required']); ?>" /></td></tr>
                    <tr><th>Child order min amount</th><td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_order_min_amount]" value="<?php echo esc_attr($options['child_order_min_amount']); ?>" /></td></tr>
                    <tr><th>Attachment window days</th><td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[attachment_window_days]" value="<?php echo esc_attr($options['attachment_window_days']); ?>" /></td></tr>
                    <tr><th>Award via WPLoyalty (direct credit)</th><td><input type="checkbox" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[award_via_wployalty]" value="1" <?php checked( ! empty($options['award_via_wployalty']) ); ?> /></td></tr>
                    <tr><th>Min payout amount</th><td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[min_payout_amount]" value="<?php echo esc_attr($options['min_payout_amount']); ?>" /></td></tr>

                    <!-- Customer Commission Settings (New Fields) -->
                    <tr>
                        <th>Customer order commission type</th>
                        <td>
                            <select name="<?php echo Champion_Helpers::OPT_KEY; ?>[customer_order_commission_type]">
                              <option value="percent" <?php selected($opts['customer_order_commission_type'] ?? 'percent', 'percent'); ?>>Percent</option>
                              <option value="fixed" <?php selected($opts['customer_order_commission_type'] ?? 'percent', 'fixed'); ?>>Fixed</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th>Customer order commission value</th>
                        <td>
                           <input type="number" step="0.01" name="<?php echo Champion_Helpers::OPT_KEY; ?>[customer_order_commission_value]" value="<?php echo esc_attr($opts['customer_order_commission_value'] ?? 0); ?>" />                        
                       </td>
                    </tr>

                    <!-- New fields for Customer Commission Payout -->
                    <tr>
                        <th>Customer commission minimum payout</th>
                        <td><input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[customer_commission_minimum_payout]" value="<?php echo esc_attr($opts['customer_commission_minimum_payout'] ?? 100); ?>" /></td>
                    </tr>

                    <tr>
                        <th>Customer commission payout method</th>
                        <td>
                            <select name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[customer_commission_payout_method]">
                                <option value="coupon" <?php selected($opts['customer_commission_payout_method'] ?? 'coupon', 'coupon'); ?>>Coupon</option>
                                <option value="wployalty" <?php selected($opts['customer_commission_payout_method'] ?? 'coupon', 'wployalty'); ?>>WPLoyalty Points</option>
                            </select>
                        </td>
                    </tr>

                    <tr class="row-separator">
                        <td colspan="2" style="padding: 12px 0; border-top: 2px solid #b5b5b5;"></td>
                    </tr>

                    <tr class="section-title">
                        <td colspan="2" style="padding: 10px 0; font-weight: 600; font-size: 1.3em; color: #1d2327;">Customer to Customer Referral Bonus Settings</td>
                    </tr>

                    <tr>
                        <th>Customer min order amount</th>
                        <td>
                            <input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_customer_order_min_amount]" value="<?php echo esc_attr($options['child_customer_order_min_amount']); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th>Required customer order</th>
                        <td>
                            <input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_customer_required_order]" value="<?php echo esc_attr($options['child_customer_required_order']); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th>Customer block size</th>
                        <td>
                            <input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_customer_block_size]" value="<?php echo esc_attr($options['child_customer_block_size']); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th>Customer bonus amount</th>
                        <td>
                            <input name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_customer_bonus_amount]" value="<?php echo esc_attr($options['child_customer_bonus_amount']); ?>" />
                        </td>
                    </tr>

                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Milestones (recent)</h2>
            <?php
            if ( ! empty($milestones) ) {
                echo '<table class="widefat"><thead><tr><th>ID</th><th>Parent</th><th>Amount</th><th>Block</th><th>Awarded at</th><th>Coupon</th><th>Action</th></tr></thead><tbody>';
                foreach ($milestones as $r) {
                    $coupon_link = $r->coupon_id ? ('<a href="'.admin_url('post.php?post='.$r->coupon_id.'&action=edit').'">#'.$r->coupon_id.'</a>') : '—';
                    $payout_action = $r->coupon_id ? 'Paid' : '<form method="post" action="'.admin_url('admin-post.php').'"><input type="hidden" name="action" value="champion_manual_payout" /><input type="hidden" name="milestone_id" value="'.intval($r->id).'" /><input type="submit" class="button" value="Create coupon (manual)" /></form>';
                    echo '<tr>';
                    echo '<td>'.intval($r->id).'</td>';
                    echo '<td>'.intval($r->parent_affiliate_id).'</td>';
                    echo '<td>'.esc_html($r->amount).'</td>';
                    echo '<td>'.intval($r->block_index).'</td>';
                    echo '<td>'.esc_html($r->awarded_at).'</td>';
                    echo '<td>'.$coupon_link.'</td>';
                    echo '<td>'.$payout_action.'</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No milestones yet.</p>';
            }
            ?>

            <h2>Child Counters (recent)</h2>
            <?php
            if ( ! empty($counters) ) {
                echo '<table class="widefat"><thead><tr><th>ID</th><th>Child</th><th>Parent</th><th>Count</th><th>Last order</th></tr></thead><tbody>';
                foreach ($counters as $c) {
                    echo '<tr>';
                    echo '<td>'.intval($c->id).'</td>';
                    echo '<td>'.intval($c->child_affiliate_id).'</td>';
                    echo '<td>'.intval($c->parent_affiliate_id).'</td>';
                    echo '<td>'.intval($c->qualifying_orders).'</td>';
                    echo '<td>'.esc_html($c->last_order_at).'</td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No child counters yet.</p>';
            }
            ?>
        </div>
        <?php
    }
}

// Instantiate the class
Champion_Admin::instance();
