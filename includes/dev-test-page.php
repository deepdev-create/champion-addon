<?php
/**
 * Champion Dev Test Tools
 *
 * WARNING: Developer/staging only. Creates real users + real orders.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Champion_Dev_Test_Page' ) ) {

class Champion_Dev_Test_Page {

    const OPT_CREATED_USERS  = 'champion_dev_test_created_users';
    const OPT_CREATED_ORDERS = 'champion_dev_test_created_orders';

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
    }

    public static function register_page() {
        // Only show if DEBUG constant is enabled
        if ( ! defined( 'CHAMPION_DEBUG_DASHBOARD' ) || CHAMPION_DEBUG_DASHBOARD !== 1 ) {
            return;
        }

        $cap = apply_filters( 'champion_dev_test_capability', 'manage_woocommerce' );

        add_submenu_page(
            'woocommerce',
            'Champion Dev Test',
            'Champion Dev Test',
            $cap,
            'champion-dev-test',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'No permission.' );
        }

        $message = '';
        $error   = '';

        // Handle actions.
        if ( isset( $_POST['champion_dev_action'] ) && check_admin_referer( 'champion_dev_test_action', 'champion_dev_test_nonce' ) ) {
            $action = sanitize_text_field( wp_unslash( $_POST['champion_dev_action'] ) );

            if ( $action === 'create' ) {
                $parent_id           = isset( $_POST['champion_parent_id'] ) ? intval( $_POST['champion_parent_id'] ) : 0;
                $child_count         = isset( $_POST['champion_child_count'] ) ? intval( $_POST['champion_child_count'] ) : 0;
                $product_id          = isset( $_POST['champion_product_id'] ) ? intval( $_POST['champion_product_id'] ) : 0;
                $orders_per_child_in = isset( $_POST['champion_orders_per_child'] ) ? intval( $_POST['champion_orders_per_child'] ) : 0;
                $total_override_in   = isset( $_POST['champion_order_total_override'] ) ? floatval( $_POST['champion_order_total_override'] ) : 0;
                $mark_parent_amb     = ! empty( $_POST['champion_mark_parent_amb'] );

                if ( $parent_id <= 0 || $child_count <= 0 || $product_id <= 0 ) {
                    $error = 'Please select a parent user, a valid product, and a positive child count.';
                } else {
                    $result = self::create_test_data(
                        $parent_id,
                        $child_count,
                        $product_id,
                        $orders_per_child_in,
                        $total_override_in,
                        $mark_parent_amb
                    );

                    if ( ! empty( $result['error'] ) ) {
                        $error = $result['error'];
                    } else {
                        $message = sprintf(
                            'Created %d child ambassadors and %d completed orders (per child: %d).',
                            intval( $result['child_created'] ),
                            intval( $result['orders_created'] ),
                            intval( $result['orders_per_child'] )
                        );
                    }
                }
            }

            if ( $action === 'clear' ) {
                $result = self::clear_test_data();

                if ( ! empty( $result['error'] ) ) {
                    $error = $result['error'];
                } else {
                    $message = sprintf(
                        'Cleared test data: deleted %d orders, deleted %d users, cleared counters/milestones.',
                        intval( $result['orders_deleted'] ),
                        intval( $result['users_deleted'] )
                    );
                    
                    if ( ! empty( $result['warnings'] ) ) {
                        $message .= ' Warnings: ' . implode( ', ', $result['warnings'] );
                    }
                }
            }

            if ( $action === 'force_payout' ) {
                if ( class_exists( 'Champion_Payouts' ) ) {
                    Champion_Payouts::instance()->process_monthly_payouts();
                    $message = 'Monthly payout triggered successfully (manual run).';
                } else {
                    $error = 'Champion_Payouts class not found.';
                }
            }
        }

        $users    = self::get_users_for_dropdown();
        $products = self::get_products_list();

        // Defaults
        $default_child_count      = 10;
        $default_orders_per_child = 5;
        $default_min_amount       = 0;
        $amb_meta_key             = 'is_ambassador';

        if ( class_exists( 'Champion_Helpers' ) ) {
            $opts = Champion_Helpers::instance()->get_opts();
            if ( ! empty( $opts['block_size'] ) ) {
                $default_child_count = max( 1, intval( $opts['block_size'] ) );
            }
            if ( ! empty( $opts['child_orders_required'] ) ) {
                $default_orders_per_child = max( 1, intval( $opts['child_orders_required'] ) );
            }
            if ( isset( $opts['child_order_min_amount'] ) ) {
                $default_min_amount = floatval( $opts['child_order_min_amount'] );
            }
            if ( ! empty( $opts['ambassador_usermeta'] ) ) {
                $amb_meta_key = $opts['ambassador_usermeta'];
            }
        }


        if (
            isset( $_POST['champion_force_customer_commission_payout'] ) &&
            check_admin_referer( 'champion_force_customer_commission_payout' )
        ) {
            if ( class_exists( 'Champion_Payouts' ) ) {
                Champion_Payouts::instance()->process_monthly_customer_commission_payouts();
                echo '<div class="updated"><p>Customer commission payout executed.</p></div>';
            }
        }


        ?>
        <style>
            .champion-dev-test-wrapper {
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

            .champion-section.dev-test .champion-section-header {
                background: #fff3cd;
                border-bottom-color: #ffc107;
                color: #856404;
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

            .champion-warning-box {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 15px 20px;
                margin-bottom: 25px;
                border-radius: 4px;
            }

            .champion-warning-box strong {
                color: #856404;
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
                margin-right: 10px;
            }

            .champion-submit-area .button-primary:hover {
                background-color: #005a87;
            }

            .champion-submit-area .button {
                margin-right: 10px;
            }

            .champion-info-box {
                background: #f0f7ff;
                border-left: 4px solid #0073aa;
                padding: 15px 20px;
                margin-top: 20px;
                border-radius: 4px;
            }

            .champion-info-box h3 {
                margin-top: 0;
                margin-bottom: 10px;
                color: #0073aa;
                font-size: 16px;
            }

            .champion-info-box ol {
                margin: 10px 0;
                padding-left: 25px;
            }

            .champion-info-box li {
                margin-bottom: 8px;
                line-height: 1.6;
            }

            .champion-info-box code {
                background: #fff;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }
        </style>

        <div class="wrap champion-dev-test-wrapper">
            <h1>🧪 Champion Dev Test Tools</h1>

            <div class="champion-warning-box">
                <strong>⚠️ WARNING:</strong> Developer/staging only. This will create real users and real orders. Use with caution!
            </div>

            <?php if ( ! empty( $error ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php elseif ( ! empty( $message ) ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'champion_dev_test_action', 'champion_dev_test_nonce' ); ?>

                <div class="champion-section dev-test">
                    <div class="champion-section-header">
                        <span class="champion-section-header-icon">⚙️</span>
                        <span>Test Data Generation</span>
                    </div>
                    <div class="champion-section-content">
                        <p style="color: #666; margin-top: 0;">Create test child ambassadors, customers, and orders for the 10x10x5 milestone system</p>
                        <table class="champion-settings-table">
                            <tbody>

                            <tr>
                                <th><label for="champion_parent_id">Parent Ambassador</label></th>
                                <td>
                                    <select name="champion_parent_id" id="champion_parent_id" style="min-width: 250px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                                        <option value="0">— Select User (Ambassadors marked) —</option>
                                        <?php
                                        foreach ( $users as $user ) {
                                            $is_amb = apply_filters( 'champion_is_user_ambassador', false, $user->ID );
                                            $label  = $user->display_name;
                                            if ( $is_amb ) $label .= ' [AMB]';

                                            printf(
                                                '<option value="%d">%s (ID: %d)</option>',
                                                intval( $user->ID ),
                                                esc_html( $label ),
                                                intval( $user->ID )
                                            );
                                        }
                                        ?>
                                    </select>
                                    <p class="description">[AMB] means your addon currently detects this user as an ambassador.</p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="champion_mark_parent_amb">Mark parent as ambassador if missing</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="champion_mark_parent_amb" id="champion_mark_parent_amb" value="1" />
                                        Force parent to be ambassador (role + meta "<?php echo esc_html( $amb_meta_key ); ?>") before generating data.
                                    </label>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="champion_child_count">Number of child ambassadors</label></th>
                                <td>
                                    <input type="number" name="champion_child_count" id="champion_child_count" min="1" max="200" value="<?php echo esc_attr( $default_child_count ); ?>" />
                                    <p class="description">Default pulled from Champion settings (block_size = <?php echo esc_html( $default_child_count ); ?>).</p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="champion_orders_per_child">Orders per child</label></th>
                                <td>
                                    <input type="number" name="champion_orders_per_child" id="champion_orders_per_child" min="1" max="200" value="<?php echo esc_attr( $default_orders_per_child ); ?>" />
                                    <p class="description">Default pulled from Champion settings (child_orders_required = <?php echo esc_html( $default_orders_per_child ); ?>).</p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="champion_order_total_override">Order total override (optional)</label></th>
                                <td>
                                    <input type="number" step="0.01" min="0" name="champion_order_total_override" id="champion_order_total_override" value="<?php echo esc_attr( $default_min_amount > 0 ? number_format( $default_min_amount, 2 ) : '' ); ?>" placeholder="<?php echo esc_attr( $default_min_amount > 0 ? number_format( $default_min_amount, 2 ) : '50.00' ); ?>" />
                                    <p class="description">
                                        Default pulled from Champion settings (child_order_min_amount = <?php echo esc_html( number_format( $default_min_amount, 2 ) ); ?>).
                                        If set, each created order will be adjusted to this exact total (example: 49, 50, 51).
                                        If empty, order total will be based on product price/qty meeting min amount.
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th><label for="champion_product_id">Product</label></th>
                                <td>
                                    <select name="champion_product_id" id="champion_product_id" style="min-width: 250px; padding: 8px 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                                        <option value="0">— Select Product —</option>
                                        <?php
                                        foreach ( $products as $product ) {
                                            printf(
                                                '<option value="%d">%s (ID: %d)</option>',
                                                intval( $product->get_id() ),
                                                esc_html( $product->get_name() ),
                                                intval( $product->get_id() )
                                            );
                                        }
                                        ?>
                                    </select>
                                </td>
                            </tr>

                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="champion-submit-area">
                    <button type="submit" class="button button-primary" name="champion_dev_action" value="create">
                        Create test child ambassadors & orders
                    </button>

                    <button type="submit" class="button" name="champion_dev_action" value="force_payout">
                        Force Trigger Monthly Payout
                    </button>

                    <button type="submit" class="button button-secondary" name="champion_dev_action" value="clear"
                            onclick="return confirm('This will delete test users/orders created by this tool and clear milestone/counter tables. Continue?');">
                        Clear Test Data
                    </button>
                </div>
            </form>

            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field( 'champion_force_customer_commission_payout' ); ?>
                <input type="hidden" name="champion_force_customer_commission_payout" value="1" />
                <button class="button button-secondary">
                    Force Trigger Customer Commission Payout
                </button>
            </form>

            <div class="champion-info-box">
                <h3>📋 What this tool does</h3>
                <ol>
                    <li>Creates N child ambassador users (role + meta).</li>
                    <li>Links them to the selected parent via <code>champion_parent_ambassador</code>.</li>
                    <li>Adds them to parent meta <code>champion_referred_ambassadors</code> (dashboard reflects immediately).</li>
                    <li>Creates completed WooCommerce orders per child (fires status hooks).</li>
                </ol>
            </div>

        </div>
        <?php
    }

    protected static function create_test_data( $parent_id, $child_count, $product_id, $orders_per_child_in, $total_override_in, $mark_parent_amb ) {
        if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_create_order' ) ) {
            return array( 'error' => 'WooCommerce is not active or its functions are not available.' );
        }

        $parent_id   = intval( $parent_id );
        $child_count = max( 1, intval( $child_count ) );
        $product_id  = intval( $product_id );

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return array( 'error' => 'Selected product could not be loaded.' );
        }

        // Determine settings defaults - aligned with 10x10x5 structure
        // TIER 1: Orders required per customer (default from settings or 5)
        $orders_per_customer = 5;
        // TIER 2: Customers required per child ambassador (default from settings or 10)
        $customers_per_child = 10;
        // TIER 1: Minimum order amount $ (default from settings or 0)
        $min_amount          = 0;
        $amb_meta_key        = 'is_ambassador';

        if ( class_exists( 'Champion_Helpers' ) ) {
            $opts = Champion_Helpers::instance()->get_opts();
            
            // TIER 1: Read from settings - how many orders per customer to qualify
            if ( ! empty( $opts['child_orders_required'] ) ) {
                $orders_per_customer = max( 1, intval( $opts['child_orders_required'] ) );
            }
            
            // TIER 2 & TIER 3: Read from settings - customers per child (Tier 2) = children per bonus (Tier 3)
            if ( ! empty( $opts['block_size'] ) ) {
                $customers_per_child = max( 1, intval( $opts['block_size'] ) );
            }
            
            // TIER 1: Read from settings - minimum order amount for qualifying
            if ( isset( $opts['child_order_min_amount'] ) ) {
                $min_amount = floatval( $opts['child_order_min_amount'] );
            }
            if ( ! empty( $opts['ambassador_usermeta'] ) ) {
                $amb_meta_key = $opts['ambassador_usermeta'];
            }
        }

        // Overrides - allow test to specify custom values
        if ( intval( $orders_per_child_in ) > 0 ) {
            $orders_per_customer = max( 1, intval( $orders_per_child_in ) );
        }

        $order_total_override = floatval( $total_override_in );
        if ( $order_total_override < 0 ) $order_total_override = 0;

        // Optionally mark parent ambassador (role + meta).
        if ( $mark_parent_amb ) {
            update_user_meta( $parent_id, $amb_meta_key, 1 );
            $u = get_user_by( 'id', $parent_id );
            if ( $u instanceof WP_User ) {
                $u->set_role( 'ambassador' );
            }
        }

        $price = floatval( $product->get_price() );
        if ( $price <= 0 ) {
            return array( 'error' => 'Selected product has zero price. Please choose a priced product.' );
        }

        // Quantity per order (only used when no total override).
        $quantity = 1;
        if ( $order_total_override <= 0 && $min_amount > 0 ) {
            $quantity = max( 1, (int) ceil( $min_amount / $price ) );
        }

        // Dev test: suppress immediate award/payout while generating orders.
        set_transient( 'champion_suppress_awards', 1, 30 * MINUTE_IN_SECONDS );

        // Disable WooCommerce email notifications
        add_filter( 'woocommerce_order_created_notice_email_enabled', '__return_false' );

        // Batch insert tracking (instead of options update per item)
        $batch_created_users  = array();
        $batch_created_orders = array();

        $children_created = 0;
        $orders_created   = 0;

        // 10x10x5 TEST DATA GENERATION STRUCTURE:
        // Loop 1: Create N child ambassadors (from $child_count)
        // Loop 2: For each child, create M customers (from $customers_per_child = block_size)
        // Loop 3: For each customer, create K orders (from $orders_per_customer = child_orders_required)
        //
        // Example with defaults (block_size=10, child_orders_required=5):
        // - Parent → 10 children → 100 customers (10 per child) → 500 orders (5 per customer)
        //
        // Example with test settings (block_size=2, child_orders_required=2):
        // - Parent → 2 children → 4 customers (2 per child) → 8 orders (2 per customer)
        
        for ( $i = 1; $i <= $child_count; $i++ ) {
            $child_user_id = self::create_child_user( $parent_id, $i, $amb_meta_key );
            if ( ! $child_user_id ) continue;

            $children_created++;
            $batch_created_users[] = $child_user_id;

            // Create 10 customers for this child ambassador
            for ( $c = 1; $c <= $customers_per_child; $c++ ) {
                $customer_id = self::create_test_customer( $parent_id, $child_user_id, $c, $batch_created_users );
                if ( ! $customer_id ) continue;

                // Each customer places 5 orders
                for ( $j = 1; $j <= $orders_per_customer; $j++ ) {
                    $order_id = self::create_completed_order_for_user( $customer_id, $product, $quantity, $order_total_override, $batch_created_orders );
                    if ( $order_id ) $orders_created++;
                }
            }
        }

        // Batch save created users at the end
        if ( ! empty( $batch_created_users ) ) {
            $existing = get_option( self::OPT_CREATED_USERS, array() );
            if ( ! is_array( $existing ) ) $existing = array();
            $all = array_merge( $existing, $batch_created_users );
            update_option( self::OPT_CREATED_USERS, array_values( array_unique( $all ) ), false );
        }

        // Batch save created orders at the end
        if ( ! empty( $batch_created_orders ) ) {
            $existing = get_option( self::OPT_CREATED_ORDERS, array() );
            if ( ! is_array( $existing ) ) $existing = array();
            $all = array_merge( $existing, $batch_created_orders );
            update_option( self::OPT_CREATED_ORDERS, array_values( array_unique( $all ) ), false );
        }

        remove_filter( 'woocommerce_order_created_notice_email_enabled', '__return_false' );
        delete_transient( 'champion_suppress_awards' );

        return array(
            'child_created'    => $children_created,
            'orders_created'   => $orders_created,
            'orders_per_child' => $orders_per_customer,
        );
    }

    /**
     * Create a test customer and attach them to a child ambassador.
     */
    protected static function create_test_customer( $parent_id, $child_ambassador_id, $index, &$batch_users = null ) {
        $run_id   = time();
        $username = sprintf( 'champ_cust_%d_%d_%d', $parent_id, $run_id, $index );
        $email    = sprintf( 'champ_cust_%d_%d_%d@example.com', $parent_id, $run_id, $index );
        
        $password = wp_generate_password( 12, true );

        $user_id = wp_insert_user( array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => 'customer',
        ) );

        if ( is_wp_error( $user_id ) ) return false;

        // Attach to child ambassador
        update_user_meta( $user_id, 'champion_attached_ambassador', $child_ambassador_id );

        // Track created users (batch mode: add to array, save later)
        if ( is_array( $batch_users ) ) {
            $batch_users[] = intval( $user_id );
        } else {
            $created = get_option( self::OPT_CREATED_USERS, array() );
            if ( ! is_array( $created ) ) $created = array();
            $created[] = intval( $user_id );
            update_option( self::OPT_CREATED_USERS, array_values( array_unique( $created ) ), false );
        }

        return $user_id;
    }

    protected static function create_child_user( $parent_id, $index, $amb_meta_key ) {
        $parent_id = intval( $parent_id );
        $index     = intval( $index );

        $run_id   = time(); // unique per run
        $username = sprintf( 'champ_child_%d_%d', $run_id, $index );
        $email    = sprintf( 'champ_child_%d_%d_%d@example.com', $parent_id, $run_id, $index );
        
        $password = wp_generate_password( 12, true );

        $user_id = wp_insert_user( array(
            'user_login' => $username,
            'user_email' => $email,
            'user_pass'  => $password,
            'role'       => 'ambassador',
        ) );

        if ( is_wp_error( $user_id ) ) return false;

        // ambassador meta
        update_user_meta( $user_id, $amb_meta_key, 1 );

        // parent link
        update_user_meta( $user_id, 'champion_parent_ambassador', $parent_id );

        // dashboard shortcut list
        $referred = get_user_meta( $parent_id, 'champion_referred_ambassadors', true );
        if ( ! is_array( $referred ) ) $referred = array();
        if ( ! in_array( $user_id, $referred, true ) ) {
            $referred[] = $user_id;
            update_user_meta( $parent_id, 'champion_referred_ambassadors', $referred );
        }

        // Track created users for safe cleanup
        $created = get_option( self::OPT_CREATED_USERS, array() );
        if ( ! is_array( $created ) ) $created = array();
        $created[] = intval( $user_id );
        update_option( self::OPT_CREATED_USERS, array_values( array_unique( $created ) ), false );

        return $user_id;
    }

    protected static function create_completed_order_for_user( $user_id, $product, $quantity, $order_total_override, &$batch_orders = null ) {
        try {
            $order = wc_create_order();
            if ( is_wp_error( $order ) ) return false;

            $user_id  = intval( $user_id );
            $quantity = max( 1, intval( $quantity ) );

            $order->set_customer_id( $user_id );
            $order->add_product( $product, $quantity );

            // Adjust total if override is given (exact testing: 49 / 50 / 51 etc.)
            if ( floatval( $order_total_override ) > 0 ) {
                $order->calculate_totals();
                $target_total = floatval( $order_total_override );
                
                // Get the line items and adjust the first product's total to match target
                $items = $order->get_items( 'line_item' );
                if ( ! empty( $items ) ) {
                    $first_item = reset( $items );
                    // Set the item total to match target (this ensures order total = target)
                    $first_item->set_total( $target_total );
                    $first_item->set_subtotal( $target_total );
                    $first_item->save();
                } else {
                    // Fallback: use fee adjustment if no items (shouldn't happen)
                    $current_total = floatval( $order->get_total() );
                    $diff = $target_total - $current_total;
                    if ( abs( $diff ) > 0.0001 && $diff > 0 ) {
                        $fee = new WC_Order_Item_Fee();
                        $fee->set_name( 'Dev Test Adjustment' );
                        $fee->set_amount( $diff );
                        $fee->set_total( $diff );
                        $fee->set_tax_status( 'none' );
                        $order->add_item( $fee );
                    }
                }
            }

            $order->calculate_totals();
            $order->save();

            // Fire Woo hooks properly
            $order->update_status(
                'completed',
                'Champion dev test: auto-completed order for milestone engine',
                true
            );

            $order_id = $order->get_id();

            // Track created orders (batch mode: add to array, save later)
            if ( is_array( $batch_orders ) ) {
                $batch_orders[] = intval( $order_id );
            } else {
                $created = get_option( self::OPT_CREATED_ORDERS, array() );
                if ( ! is_array( $created ) ) $created = array();
                $created[] = intval( $order_id );
                update_option( self::OPT_CREATED_ORDERS, array_values( array_unique( $created ) ), false );
            }

            return $order_id;

        } catch ( Exception $e ) {
            return false;
        }
    }

    protected static function clear_test_data() {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return array( 'error' => 'WooCommerce functions not available.' );
        }

        // Increase execution time for bulk operations
        @set_time_limit( 300 ); // 5 minutes max
        @ini_set( 'max_execution_time', 300 );

        global $wpdb;

        $orders_deleted = 0;
        $users_deleted  = 0;
        $errors = array();

        // 1) Delete tracked orders (bulk delete for performance)
        $order_ids = get_option( self::OPT_CREATED_ORDERS, array() );
        if ( is_array( $order_ids ) && ! empty( $order_ids ) ) {
            // Sanitize IDs
            $order_ids = array_map( 'intval', $order_ids );
            $order_ids = array_filter( $order_ids, function( $id ) { return $id > 0; } );
            
            if ( ! empty( $order_ids ) ) {
                // Use bulk SQL delete for better performance
                $ids_placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
                
                // Delete order items first
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ($ids_placeholders)",
                    ...$order_ids
                ) );
                
                // Delete order item meta
                $wpdb->query( $wpdb->prepare(
                    "DELETE om FROM {$wpdb->prefix}woocommerce_order_itemmeta om
                     INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON om.order_item_id = oi.order_item_id
                     WHERE oi.order_id IN ($ids_placeholders)",
                    ...$order_ids
                ) );
                
                // Delete order meta
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}postmeta WHERE post_id IN ($ids_placeholders)",
                    ...$order_ids
                ) );
                
                // Delete order posts
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}posts WHERE ID IN ($ids_placeholders)",
                    ...$order_ids
                ) );
                
                $orders_deleted = count( $order_ids );
            }
        }
        update_option( self::OPT_CREATED_ORDERS, array(), false );

        // 2) Delete tracked users (still use wp_delete_user for safety and proper cleanup)
        $user_ids = get_option( self::OPT_CREATED_USERS, array() );
        $processed_user_ids = array();
        
        if ( is_array( $user_ids ) && ! empty( $user_ids ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            
            foreach ( $user_ids as $uid ) {
                $uid = intval( $uid );
                if ( $uid <= 0 ) continue;

                // Only delete if it matches our pattern (extra safety)
                $u = get_user_by( 'id', $uid );
                if ( $u && strpos( $u->user_email, '@example.com' ) !== false && 
                     ( strpos( $u->user_login, 'champ_child_' ) === 0 || strpos( $u->user_login, 'champ_cust_' ) === 0 ) ) {
                    $deleted = wp_delete_user( $uid );
                    if ( $deleted ) {
                        $users_deleted++;
                        $processed_user_ids[] = $uid;
                    }
                }
            }
        }
        
        // Fallback: If no tracked users found, search by pattern
        if ( $users_deleted === 0 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            
            // Find test users by pattern
            $test_users = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}users 
                 WHERE user_email LIKE %s 
                 AND (user_login LIKE %s OR user_login LIKE %s)
                 LIMIT 500",
                '%@example.com',
                'champ_child_%',
                'champ_cust_%'
            ) );
            
            if ( ! empty( $test_users ) ) {
                foreach ( $test_users as $test_user ) {
                    $uid = intval( $test_user->ID );
                    if ( in_array( $uid, $processed_user_ids, true ) ) continue;
                    
                    $u = get_user_by( 'id', $uid );
                    if ( $u && strpos( $u->user_email, '@example.com' ) !== false && 
                         ( strpos( $u->user_login, 'champ_child_' ) === 0 || strpos( $u->user_login, 'champ_cust_' ) === 0 ) ) {
                        $deleted = wp_delete_user( $uid );
                        if ( $deleted ) {
                            $users_deleted++;
                            $processed_user_ids[] = $uid;
                        }
                    }
                }
            }
        }
        
        // Also delete orders for test users if we found any
        if ( ! empty( $processed_user_ids ) && $orders_deleted === 0 ) {
            $user_ids_placeholders = implode( ',', array_fill( 0, count( $processed_user_ids ), '%d' ) );
            
            // Find orders for these test users
            $test_order_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->prefix}posts p
                 INNER JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'shop_order'
                 AND pm.meta_key = '_customer_user'
                 AND pm.meta_value IN ($user_ids_placeholders)
                 LIMIT 1000",
                ...$processed_user_ids
            ) );
            
            if ( ! empty( $test_order_ids ) ) {
                $test_order_ids = array_map( 'intval', $test_order_ids );
                $ids_placeholders = implode( ',', array_fill( 0, count( $test_order_ids ), '%d' ) );
                
                // Delete order items
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id IN ($ids_placeholders)",
                    ...$test_order_ids
                ) );
                
                // Delete order item meta
                $wpdb->query( $wpdb->prepare(
                    "DELETE om FROM {$wpdb->prefix}woocommerce_order_itemmeta om
                     INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON om.order_item_id = oi.order_item_id
                     WHERE oi.order_id IN ($ids_placeholders)",
                    ...$test_order_ids
                ) );
                
                // Delete order meta
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}postmeta WHERE post_id IN ($ids_placeholders)",
                    ...$test_order_ids
                ) );
                
                // Delete order posts
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}posts WHERE ID IN ($ids_placeholders)",
                    ...$test_order_ids
                ) );
                
                $orders_deleted += count( $test_order_ids );
            }
        }
        
        update_option( self::OPT_CREATED_USERS, array(), false );

        // 3) Clear counters/milestones tables (dev only)
        $tables = array(
            $wpdb->prefix . 'champion_child_counters',
            $wpdb->prefix . 'champion_milestones',
            $wpdb->prefix . 'champion_customer_orders',
            $wpdb->prefix . 'champion_qualified_children',
            $wpdb->prefix . 'champion_child_milestone_used',
            $wpdb->prefix . 'champion_child_customer_counters',
            $wpdb->prefix . 'champion_customer_milestones',
        );

        foreach ( $tables as $table ) {
            // Check if table exists before truncating
            $table_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
                DB_NAME,
                $table
            ) );
            
            if ( $table_exists ) {
                $result = $wpdb->query( "TRUNCATE TABLE {$table}" );
                if ( $result === false ) {
                    $errors[] = "Failed to truncate table: {$table}";
                }
            }
        }

        $result = array(
            'orders_deleted' => $orders_deleted,
            'users_deleted'  => $users_deleted,
        );
        
        if ( ! empty( $errors ) ) {
            $result['warnings'] = $errors;
        }

        return $result;
    }

    protected static function get_users_for_dropdown() {
        return get_users( array(
            'number' => 500,
            'role' => 'ambassador',
            'fields' => array( 'ID', 'display_name', 'user_email', 'roles' ),
        ) );
    }

    protected static function get_products_list() {
        if ( ! function_exists( 'wc_get_products' ) ) return array();

        return wc_get_products( array(
            'status'  => array( 'publish' ),
            'limit'   => 100,
            'orderby' => 'title',
            'order'   => 'ASC',
        ) );
    }
}

Champion_Dev_Test_Page::init();

}
