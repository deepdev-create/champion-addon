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
        $default_orders_per_child = 5;
        $default_min_amount       = 0;
        $amb_meta_key             = 'is_ambassador';

        if ( class_exists( 'Champion_Helpers' ) ) {
            $opts = Champion_Helpers::instance()->get_opts();
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

        ?>
        <div class="wrap">
            <h1>Champion Dev Test Tools</h1>
            <p><strong>WARNING:</strong> Developer/staging only. This will create real users and real orders.</p>

            <?php if ( ! empty( $error ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php elseif ( ! empty( $message ) ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field( 'champion_dev_test_action', 'champion_dev_test_nonce' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>

                    <tr>
                        <th scope="row"><label for="champion_parent_id">Parent Ambassador</label></th>
                        <td>
                            <select name="champion_parent_id" id="champion_parent_id">
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
                        <th scope="row"><label for="champion_mark_parent_amb">Mark parent as ambassador if missing</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="champion_mark_parent_amb" id="champion_mark_parent_amb" value="1" />
                                Force parent to be ambassador (role + meta "<?php echo esc_html( $amb_meta_key ); ?>") before generating data.
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="champion_child_count">Number of child ambassadors</label></th>
                        <td>
                            <input type="number" name="champion_child_count" id="champion_child_count" min="1" max="200" value="10" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="champion_orders_per_child">Orders per child</label></th>
                        <td>
                            <input type="number" name="champion_orders_per_child" id="champion_orders_per_child" min="1" max="200" value="<?php echo esc_attr( $default_orders_per_child ); ?>" />
                            <p class="description">Default pulled from Champion settings (child_orders_required = <?php echo esc_html( $default_orders_per_child ); ?>).</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="champion_order_total_override">Order total override (optional)</label></th>
                        <td>
                            <input type="number" step="0.01" min="0" name="champion_order_total_override" id="champion_order_total_override" value="" placeholder="<?php echo esc_attr( $default_min_amount > 0 ? $default_min_amount : '50.00' ); ?>" />
                            <p class="description">
                                If set, each created order will be adjusted to this exact total (example: 49, 50, 51).
                                If empty, order total will be based on product price/qty meeting min amount.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="champion_product_id">Product</label></th>
                        <td>
                            <select name="champion_product_id" id="champion_product_id">
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

                <p>
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
                </p>

                <hr />

                <h2>What this tool does</h2>
                <ol>
                    <li>Creates N child ambassador users (role + meta).</li>
                    <li>Links them to the selected parent via <code>champion_parent_ambassador</code>.</li>
                    <li>Adds them to parent meta <code>champion_referred_ambassadors</code> (dashboard reflects immediately).</li>
                    <li>Creates completed WooCommerce orders per child (fires status hooks).</li>
                </ol>

            </form>
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

        // Determine settings defaults.
        $orders_per_child = 5;
        $min_amount       = 0;
        $amb_meta_key     = 'is_ambassador';

        if ( class_exists( 'Champion_Helpers' ) ) {
            $opts = Champion_Helpers::instance()->get_opts();
            if ( ! empty( $opts['child_orders_required'] ) ) {
                $orders_per_child = max( 1, intval( $opts['child_orders_required'] ) );
            }
            if ( isset( $opts['child_order_min_amount'] ) ) {
                $min_amount = floatval( $opts['child_order_min_amount'] );
            }
            if ( ! empty( $opts['ambassador_usermeta'] ) ) {
                $amb_meta_key = $opts['ambassador_usermeta'];
            }
        }

        // Overrides
        if ( intval( $orders_per_child_in ) > 0 ) {
            $orders_per_child = max( 1, intval( $orders_per_child_in ) );
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

        $children_created = 0;
        $orders_created   = 0;

        for ( $i = 1; $i <= $child_count; $i++ ) {
            $user_id = self::create_child_user( $parent_id, $i, $amb_meta_key );
            if ( ! $user_id ) continue;

            $children_created++;

            for ( $j = 1; $j <= $orders_per_child; $j++ ) {
                $order_id = self::create_completed_order_for_user( $user_id, $product, $quantity, $order_total_override );
                if ( $order_id ) $orders_created++;
            }
        }

        return array(
            'child_created'    => $children_created,
            'orders_created'   => $orders_created,
            'orders_per_child' => $orders_per_child,
        );
    }

    protected static function create_child_user( $parent_id, $index, $amb_meta_key ) {
        $parent_id = intval( $parent_id );
        $index     = intval( $index );

        $username = sprintf( 'champ_child_%d_%d', time(), $index );
        $email    = sprintf( 'champ_child_%d_%d@example.com', $parent_id, $index );
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

    protected static function create_completed_order_for_user( $user_id, $product, $quantity, $order_total_override ) {
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
                $current_total = floatval( $order->get_total() );
                $target_total  = floatval( $order_total_override );
                $diff          = $target_total - $current_total;

                if ( abs( $diff ) > 0.0001 ) {
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name( 'Dev Test Adjustment' );
                    $fee->set_amount( $diff );
                    $fee->set_total( $diff );
                    $fee->set_tax_status( 'none' );
                    $order->add_item( $fee );
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

            // Track created orders for safe cleanup
            $created = get_option( self::OPT_CREATED_ORDERS, array() );
            if ( ! is_array( $created ) ) $created = array();
            $created[] = intval( $order_id );
            update_option( self::OPT_CREATED_ORDERS, array_values( array_unique( $created ) ), false );

            return $order_id;

        } catch ( Exception $e ) {
            return false;
        }
    }

    protected static function clear_test_data() {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return array( 'error' => 'WooCommerce functions not available.' );
        }

        global $wpdb;

        $orders_deleted = 0;
        $users_deleted  = 0;

        // 1) Delete tracked orders
        $order_ids = get_option( self::OPT_CREATED_ORDERS, array() );
        if ( is_array( $order_ids ) ) {
            foreach ( $order_ids as $oid ) {
                $oid = intval( $oid );
                if ( $oid <= 0 ) continue;

                // Hard delete order post + items
                wp_delete_post( $oid, true );
                $orders_deleted++;
            }
        }
        update_option( self::OPT_CREATED_ORDERS, array(), false );

        // 2) Delete tracked users
        $user_ids = get_option( self::OPT_CREATED_USERS, array() );
        if ( is_array( $user_ids ) ) {
            foreach ( $user_ids as $uid ) {
                $uid = intval( $uid );
                if ( $uid <= 0 ) continue;

                // Only delete if it matches our pattern (extra safety)
                $u = get_user_by( 'id', $uid );
                if ( $u && strpos( $u->user_email, '@example.com' ) !== false && strpos( $u->user_login, 'champ_child_' ) === 0 ) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                    wp_delete_user( $uid );
                    $users_deleted++;
                }
            }
        }
        update_option( self::OPT_CREATED_USERS, array(), false );

        // 3) Clear counters/milestones tables (dev only)
        $child_table     = $wpdb->prefix . 'champion_child_counters';
        $milestones_table= $wpdb->prefix . 'champion_milestones';

        // Tables may not exist on very fresh installs; ignore errors.
        $wpdb->query( "TRUNCATE TABLE {$child_table}" );
        $wpdb->query( "TRUNCATE TABLE {$milestones_table}" );

        return array(
            'orders_deleted' => $orders_deleted,
            'users_deleted'  => $users_deleted,
        );
    }

    protected static function get_users_for_dropdown() {
        return get_users( array(
            'number' => 500,
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
