<?php
/**
 * Developer Test Tools for Champion Addon.
 *
 * WARNING: Only for staging/developer use.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Champion_Dev_Test_Page' ) ) {

    class Champion_Dev_Test_Page {

        /**
         * Bootstrap
         */
        public static function init() {
            add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
        }

        /**
         * Register the test tools page in WP admin.
         */
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

        /**
         * Render the test page + handle form submit.
         */
        public static function render_page() {
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( esc_html__( 'You do not have permission to access this page.', 'champion-addon' ) );
            }

            $message = '';
            $error   = '';

            // Handle form submit.
            if (
                isset( $_POST['champion_dev_test_run'] ) &&
                check_admin_referer( 'champion_dev_test_run', 'champion_dev_test_nonce' )
            ) {
                $parent_id   = isset( $_POST['champion_parent_id'] ) ? intval( $_POST['champion_parent_id'] ) : 0;
                $child_count = isset( $_POST['champion_child_count'] ) ? intval( $_POST['champion_child_count'] ) : 0;
                $product_id  = isset( $_POST['champion_product_id'] ) ? intval( $_POST['champion_product_id'] ) : 0;

                if ( $parent_id <= 0 || $child_count <= 0 || $product_id <= 0 ) {
                    $error = 'Please select a parent ambassador, a valid product and a positive child count.';
                } else {
                    $result = self::create_test_data( $parent_id, $child_count, $product_id );

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

            // Load dropdown data.
            $users    = self::get_users_for_dropdown();
            $products = self::get_products_list();

            ?>
            <div class="wrap">
                <h1>Champion Dev Test Tools</h1>

                <p><strong>WARNING:</strong> This page is only for developer testing on staging environments. It will create real users and real orders.</p>

                <?php if ( ! empty( $error ) ) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
                <?php elseif ( ! empty( $message ) ) : ?>
                    <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field( 'champion_dev_test_run', 'champion_dev_test_nonce' ); ?>

                    <table class="form-table" role="presentation">
                        <tbody>
                        <tr>
                            <th scope="row">
                                <label for="champion_parent_id">Parent Ambassador</label>
                            </th>
                            <td>
                                <select name="champion_parent_id" id="champion_parent_id">
                                    <option value="0">— Select User (Ambassadors marked) —</option>
                                    <?php
                                    if ( ! empty( $users ) ) {
                                        foreach ( $users as $user ) {
                                            $is_amb = apply_filters( 'champion_is_user_ambassador', false, $user->ID );
                                            $label  = $user->display_name;

                                            if ( $is_amb ) {
                                                $label .= ' [AMB]';
                                            }

                                            printf(
                                                '<option value="%d">%s (ID: %d)</option>',
                                                intval( $user->ID ),
                                                esc_html( $label ),
                                                intval( $user->ID )
                                            );
                                        }
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    Users marked with <strong>[AMB]</strong> are detected as ambassadors by the Champion addon.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="champion_child_count">Number of child ambassadors</label>
                            </th>
                            <td>
                                <input type="number"
                                       name="champion_child_count"
                                       id="champion_child_count"
                                       min="1"
                                       max="100"
                                       value="10"
                                />
                                <p class="description">How many random child ambassadors should be created and attached to the selected parent.</p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="champion_product_id">Product</label>
                            </th>
                            <td>
                                <select name="champion_product_id" id="champion_product_id">
                                    <option value="0">— Select Product —</option>
                                    <?php
                                    if ( ! empty( $products ) ) {
                                        foreach ( $products as $product ) {
                                            printf(
                                                '<option value="%d">%s (ID: %d)</option>',
                                                intval( $product->get_id() ),
                                                esc_html( $product->get_name() ),
                                                intval( $product->get_id() )
                                            );
                                        }
                                    }
                                    ?>
                                </select>
                                <p class="description">This product will be used to generate completed orders for each child ambassador.</p>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <?php submit_button( 'Create test child ambassadors & orders', 'primary', 'champion_dev_test_run' ); ?>
                </form>

                <hr />
                <h2>What this tool does</h2>
                <ol>
                    <li>Creates <strong>N</strong> random child ambassador users.</li>
                    <li>Marks them as ambassadors and links them to the selected parent via <code>champion_parent_ambassador</code> meta.</li>
                    <li>Creates multiple <strong>completed WooCommerce orders</strong> for each child using the selected product.</li>
                    <li>This triggers your existing Champion addon logic (child counters, milestones, payouts etc.).</li>
                </ol>
            </div>
            <?php
        }

        /**
         * Create child ambassadors and completed orders.
         *
         * @param int $parent_id
         * @param int $child_count
         * @param int $product_id
         *
         * @return array
         */
        protected static function create_test_data( $parent_id, $child_count, $product_id ) {
            if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_create_order' ) ) {
                return array(
                    'error' => 'WooCommerce is not active or its functions are not available.',
                );
            }

            $parent_id   = intval( $parent_id );
            $child_count = max( 1, intval( $child_count ) );
            $product_id  = intval( $product_id );

            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                return array(
                    'error' => 'Selected product could not be loaded.',
                );
            }

            // Orders per child & min amount from Champion settings.
            $orders_per_child = 5;
            $min_amount       = 0;

            if ( class_exists( 'Champion_Helpers' ) ) {
                $opts = Champion_Helpers::instance()->get_opts();
                if ( ! empty( $opts['child_orders_required'] ) ) {
                    $orders_per_child = max( 1, intval( $opts['child_orders_required'] ) );
                }
                if ( isset( $opts['child_order_min_amount'] ) ) {
                    $min_amount = floatval( $opts['child_order_min_amount'] );
                }
            }

            $price = floatval( $product->get_price() );
            if ( $price <= 0 ) {
                return array(
                    'error' => 'Selected product has zero price. Please choose a priced product.',
                );
            }

            // Quantity per order to meet min amount.
            $quantity = 1;
            if ( $min_amount > 0 ) {
                $quantity = max( 1, (int) ceil( $min_amount / $price ) );
            }

            $children_created = 0;
            $orders_created   = 0;

            for ( $i = 1; $i <= $child_count; $i++ ) {
                $user_id = self::create_child_user( $parent_id, $i );
                if ( ! $user_id ) {
                    continue;
                }

                $children_created++;

                for ( $j = 1; $j <= $orders_per_child; $j++ ) {
                    $order_id = self::create_completed_order_for_user( $user_id, $product, $quantity );
                    if ( $order_id ) {
                        $orders_created++;
                    }
                }
            }

            return array(
                'child_created'    => $children_created,
                'orders_created'   => $orders_created,
                'orders_per_child' => $orders_per_child,
            );
        }

        /**
         * Create a random child ambassador user and attach to parent.
         *
         * @param int $parent_id
         * @param int $index
         *
         * @return int|false user ID
         */
        protected static function create_child_user( $parent_id, $index ) {
            $parent_id = intval( $parent_id );
            $index     = intval( $index );

            $username = sprintf( 'champ_child_%d_%d', time(), $index );
            $email    = sprintf( 'champ_child_%d_%d@example.com', $parent_id, $index );
            $password = wp_generate_password( 12, true );

            $user_id = wp_insert_user(
                array(
                    'user_login' => $username,
                    'user_email' => $email,
                    'user_pass'  => $password,
                    'role'       => 'ambassador', // role-based detection
                )
            );

            if ( is_wp_error( $user_id ) ) {
                return false;
            }

            // Mark as ambassador via usermeta key used by Champion.
            $meta_key = 'is_ambassador';
            if ( class_exists( 'Champion_Helpers' ) ) {
                $opts = Champion_Helpers::instance()->get_opts();
                if ( ! empty( $opts['ambassador_usermeta'] ) ) {
                    $meta_key = $opts['ambassador_usermeta'];
                }
            }
            update_user_meta( $user_id, $meta_key, 1 );

            // Attach parent ambassador.
            update_user_meta( $user_id, 'champion_parent_ambassador', $parent_id );

            // Also add this child into parent's referred ambassadors list
            // so the dashboard picks them up immediately.
            $referred = get_user_meta( $parent_id, 'champion_referred_ambassadors', true );
            if ( ! is_array( $referred ) ) {
                $referred = array();
            }
            if ( ! in_array( $user_id, $referred, true ) ) {
                $referred[] = $user_id;
                update_user_meta( $parent_id, 'champion_referred_ambassadors', $referred );
            }

            return $user_id;
        }

        /**
         * Create a completed WooCommerce order for the given user & product.
         *
         * @param int        $user_id
         * @param WC_Product $product
         * @param int        $quantity
         *
         * @return int|false order ID
         */
        protected static function create_completed_order_for_user( $user_id, $product, $quantity ) {
            try {
                $order = wc_create_order();
                if ( is_wp_error( $order ) ) {
                    return false;
                }

                $user_id  = intval( $user_id );
                $quantity = max( 1, intval( $quantity ) );

                if ( $user_id > 0 ) {
                    $order->set_customer_id( $user_id );
                }

                $order->add_product( $product, $quantity );
                $order->calculate_totals();
                $order->save();

                // This fires all status transition hooks, including
                // woocommerce_order_status_completed → Champion_Milestones::on_order_completed().
                $order->update_status(
                    'completed',
                    'Champion dev test: auto-completed order for milestone engine',
                    true
                );

                return $order->get_id();
            } catch ( Exception $e ) {
                return false;
            }
        }

        /**
         * Get users for dropdown (all users; mark ambassadors).
         *
         * @return WP_User[]
         */
        protected static function get_users_for_dropdown() {
            $args = array(
                'number' => 500,
                'fields' => array( 'ID', 'display_name', 'user_email', 'roles' ),
            );

            return get_users( $args );
        }

        /**
         * Get WooCommerce products for dropdown.
         *
         * @return WC_Product[]
         */
        protected static function get_products_list() {
            if ( ! function_exists( 'wc_get_products' ) ) {
                return array();
            }

            $products = wc_get_products(
                array(
                    'status' => array( 'publish' ),
                    'limit'  => 100,
                    'orderby'=> 'title',
                    'order'  => 'ASC',
                )
            );

            return $products;
        }
    }

    Champion_Dev_Test_Page::init();
}
