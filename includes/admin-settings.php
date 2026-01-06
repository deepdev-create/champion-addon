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
        $customer_milestones = Champion_Customer_Milestones::instance();
        ?>
        <style>
            .champion-settings-wrapper {
                max-width: 1200px;
                margin: 20px 0;
            }

            .champion-section {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 25px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }

            .champion-section-header {
                padding: 18px 20px;
                font-size: 16px;
                font-weight: 600;
                border-bottom: 3px solid;
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .champion-section.tier1 .champion-section-header {
                background: #fff3cd;
                border-bottom-color: #ffc107;
                color: #856404;
            }

            .champion-section.tier2 .champion-section-header {
                background: #d4edff;
                border-bottom-color: #0073aa;
                color: #003d5c;
            }

            .champion-section.tier3 .champion-section-header {
                background: #d4edda;
                border-bottom-color: #28a745;
                color: #1e3e21;
            }

            .champion-section.payout .champion-section-header {
                background: #e7e7e7;
                border-bottom-color: #666;
                color: #333;
            }

            .champion-section.commission .champion-section-header {
                background: #f0e6ff;
                border-bottom-color: #7c3aed;
                color: #4c1d95;
            }

            .champion-section-header-icon {
                font-size: 20px;
                line-height: 1;
            }

            .champion-section-content {
                padding: 20px;
            }

            .champion-settings-table {
                width: 100%;
                border-collapse: collapse;
            }

            .champion-settings-table tr {
                border-bottom: 1px solid #f0f0f0;
            }

            .champion-settings-table tr:last-child {
                border-bottom: none;
            }

            .champion-settings-table th {
                text-align: left;
                width: 280px;
                padding: 16px 15px;
                font-weight: 600;
                font-size: 14px;
                color: #333;
            }

            .champion-settings-table td {
                padding: 16px 15px;
            }

            .champion-settings-table input[type="text"],
            .champion-settings-table input[type="number"],
            .champion-settings-table select {
                min-width: 250px;
                padding: 8px 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }

            .champion-settings-table input[type="checkbox"] {
                width: 20px;
                height: 20px;
                cursor: pointer;
            }

            .description {
                display: block;
                color: #666;
                font-size: 13px;
                margin-top: 6px;
                line-height: 1.5;
            }

            .champion-overview {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 30px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }

            .champion-overview h3 {
                margin-top: 0;
                margin-bottom: 15px;
                font-size: 18px;
                color: #333;
            }

            .champion-overview ul {
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .champion-overview li {
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f0;
                line-height: 1.6;
            }

            .champion-overview li:last-child {
                border-bottom: none;
            }

            .champion-overview strong {
                color: #0073aa;
                font-weight: 600;
            }

            .champion-submit-area {
                margin-top: 30px;
                padding: 20px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }

            .champion-submit-area .button-primary {
                background-color: #0073aa;
                border-color: #005a87;
                font-size: 15px;
                padding: 8px 20px;
            }

            .champion-submit-area .button-primary:hover {
                background-color: #005a87;
            }
        </style>

        <div class="wrap champion-settings-wrapper">
            <h1>🏆 Champion Addon - 10x10x5 Three-Tier System</h1>

            <div class="champion-overview">
                <h3>📊 System Overview</h3>
                <ul>
                    <li><strong>Tier 1 (Customer):</strong> Each customer must place <?php echo intval($opts['child_orders_required']); ?> orders of $<?php echo floatval($opts['child_order_min_amount']); ?>+ to qualify</li>
                    <li><strong>Tier 2 (Child Ambassador):</strong> Each child must bring <?php echo intval($opts['block_size']); ?> qualifying customers</li>
                    <li><strong>Tier 3 (Parent):</strong> Parent receives $<?php echo floatval($opts['bonus_amount']); ?> bonus for every <?php echo intval($opts['block_size']); ?> qualified child ambassadors</li>
                    <li><strong>Non-Reusable:</strong> Once a child is counted toward a bonus, they cannot be reused</li>
                </ul>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(Champion_Helpers::instance()::OPT_KEY); $options = get_option(Champion_Helpers::instance()::OPT_KEY, Champion_Helpers::instance()->defaults()); ?>
                
                <!-- TIER 1: Customer Qualification -->
                <div class="champion-section tier1">
                    <div class="champion-section-header">
                        <span class="champion-section-header-icon">🎯</span>
                        <span>TIER 1: Customer Qualification</span>
                    </div>
                    <div class="champion-section-content">
                        <p style="color: #666; margin-top: 0;">Settings for individual customer orders</p>
                        <table class="champion-settings-table">
                            <tr>
                                <th>Orders required per customer</th>
                                <td>
                                    <input type="number" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_orders_required]" value="<?php echo esc_attr($options['child_orders_required']); ?>" />
                                    <p class="description">A customer becomes qualifying after placing this many orders</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Minimum order amount ($)</th>
                                <td>
                                    <input type="number" step="0.01" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[child_order_min_amount]" value="<?php echo esc_attr($options['child_order_min_amount']); ?>" />
                                    <p class="description">Only orders of this amount or higher count as qualifying</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- TIER 2: Child Ambassador Qualification -->
                <div class="champion-section tier2">
                    <div class="champion-section-header">
                        <span class="champion-section-header-icon">👥</span>
                        <span>TIER 2: Child Ambassador Qualification</span>
                    </div>
                    <div class="champion-section-content">
                        <p style="color: #666; margin-top: 0;">Settings for child ambassadors under a parent</p>
                        <table class="champion-settings-table">
                            <tr>
                                <th>Customers required per child ambassador</th>
                                <td>
                                    <input type="number" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[block_size]" value="<?php echo esc_attr($options['block_size']); ?>" />
                                    <p class="description">A child ambassador becomes qualified after bringing this many qualifying customers</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- TIER 3: Parent Bonus -->
                <div class="champion-section tier3">
                    <div class="champion-section-header">
                        <span class="champion-section-header-icon">💰</span>
                        <span>TIER 3: Parent Ambassador Bonus</span>
                    </div>
                    <div class="champion-section-content">
                        <p style="color: #666; margin-top: 0;">Settings for parent ambassador bonuses</p>
                        <table class="champion-settings-table">
                            <tr>
                                <th>Qualified children required for bonus</th>
                                <td>
                                    <input type="number" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[block_size_for_bonus]" value="<?php echo esc_attr($options['block_size']); ?>" disabled style="background-color: #f5f5f5; cursor: not-allowed;" />
                                    <p class="description">Same as "Customers required per child ambassador" above. Parent receives a bonus after having this many qualified children.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Bonus amount per parent</th>
                                <td>
                                    <input type="number" step="0.01" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[bonus_amount]" value="<?php echo esc_attr($options['bonus_amount']); ?>" />
                                    <p class="description">Parent receives this amount when they earn each bonus</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Payout & Attachment Settings -->
                <div class="champion-section payout">
                    <div class="champion-section-header">
                        <span class="champion-section-header-icon">⚙️</span>
                        <span>Payout & Attachment Settings</span>
                    </div>
                    <div class="champion-section-content">
                        <table class="champion-settings-table">
                            <tr>
                                <th>Award via WPLoyalty</th>
                                <td>
                                    <input type="checkbox" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[award_via_wployalty]" value="1" <?php checked( ! empty($options['award_via_wployalty']) ); ?> />
                                    <p class="description">If checked, milestones are awarded as WPLoyalty points. If unchecked, they are awarded as discount coupons.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Minimum payout amount</th>
                                <td>
                                    <input type="number" step="0.01" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[min_payout_amount]" value="<?php echo esc_attr($options['min_payout_amount']); ?>" />
                                    <p class="description">Minimum commission/bonus required to process payout</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Customer attachment window (days)</th>
                                <td>
                                    <input type="number" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[attachment_window_days]" value="<?php echo esc_attr($options['attachment_window_days']); ?>" />
                                    <p class="description">How long a customer remains attached to an ambassador after initial visit</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Customer Commission Settings -->
                <div class="champion-section commission">
                    <div class="champion-section-header">
                        <span class="champion-section-header-icon">💵</span>
                        <span>Customer Commission Settings (Ambassador → Customers)</span>
                    </div>
                    <div class="champion-section-content">
                        <p style="color: #666; margin-top: 0;">Commission earned by ambassadors from their referred customers' orders</p>
                        <table class="champion-settings-table">
                            <tr>
                                <th>Commission type</th>
                                <td>
                                    <select name="<?php echo Champion_Helpers::OPT_KEY; ?>[customer_order_commission_type]">
                                      <option value="percent" <?php selected($opts['customer_order_commission_type'] ?? 'percent', 'percent'); ?>>Percent of Order Total</option>
                                      <option value="fixed" <?php selected($opts['customer_order_commission_type'] ?? 'percent', 'fixed'); ?>>Fixed Amount per Order</option>
                                    </select>
                                    <p class="description">How commission is calculated for each customer order</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Commission value</th>
                                <td>
                                   <input type="number" step="0.01" name="<?php echo Champion_Helpers::OPT_KEY; ?>[customer_order_commission_value]" value="<?php echo esc_attr($opts['customer_order_commission_value'] ?? 0); ?>" />
                                   <p class="description">If percent: enter 5 for 5%. If fixed: enter 2.50 for $2.50 per order</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Commission minimum payout</th>
                                <td>
                                    <input type="number" step="0.01" name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[customer_commission_minimum_payout]" value="<?php echo esc_attr($opts['customer_commission_minimum_payout'] ?? 100); ?>" />
                                    <p class="description">Minimum commission balance before customer can request payout</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Commission payout method</th>
                                <td>
                                    <select name="<?php echo Champion_Helpers::instance()::OPT_KEY; ?>[customer_commission_payout_method]">
                                        <option value="coupon" <?php selected($opts['customer_commission_payout_method'] ?? 'coupon', 'coupon'); ?>>Discount Coupon</option>
                                        <option value="wployalty" <?php selected($opts['customer_commission_payout_method'] ?? 'coupon', 'wployalty'); ?>>WPLoyalty Points</option>
                                    </select>
                                    <p class="description">How commissions are paid out to customers</p>
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
                    </div>
                </div>


                <div class="champion-submit-area">
                    <?php submit_button(); ?>
                </div>
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
