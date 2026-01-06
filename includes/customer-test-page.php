<?php if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Champion_Customer_Test_Page' ) ) {

class Champion_Customer_Test_Page {

	const OPT_CUS_CREATED_USERS  = 'champion_cus_test_created_users';
	const OPT_CUS_CREATED_ORDERS = 'champion_cus_test_created_orders';

	public static function init() {
	    add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	public static function register_page() {
	    $cap = apply_filters( 'champion_customer_test_capability', 'manage_woocommerce' );

	    add_submenu_page(
	        'woocommerce',
	        'Champion Customer Test',
	        'Champion Customer Test',
	        $cap,
	        'champion-customer-test',
	        array( __CLASS__, 'render_page' )
	    );
	}

	public static function render_page() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
		    wp_die( 'No permission.' );
		}

		$message = '';
		$error   = '';

		$users    = self::get_customer_for_dropdown();
		$products = self::get_cus_products_list();

		$default_orders_per_child = 5;
		$default_min_amount       = 0;
        $customer_meta_key        = 'is_customer';

		if ( class_exists( 'Champion_Helpers' ) ) {
		    $opts = Champion_Helpers::instance()->get_opts();
		    if ( ! empty( $opts['child_customer_required_order'] ) ) {
		        $default_orders_per_child = max( 1, intval( $opts['child_customer_required_order'] ) );
		    }
		    if ( isset( $opts['child_customer_order_min_amount'] ) ) {
		        $default_min_amount = floatval( $opts['child_customer_order_min_amount'] );
		    }
		}

		if ( isset( $_POST['champion_customer_action'] ) && check_admin_referer( 'champion_customer_test_action', 'champion_customer_test_nonce' ) ) {
		    $action = sanitize_text_field( wp_unslash( $_POST['champion_customer_action'] ) );

		    if ( $action === 'create' ) {
		        $parent_id           = isset( $_POST['champion_parent_id'] ) ? intval( $_POST['champion_parent_id'] ) : 0;
		        $child_count         = isset( $_POST['champion_child_count'] ) ? intval( $_POST['champion_child_count'] ) : 0;
		        $product_id          = isset( $_POST['champion_product_id'] ) ? intval( $_POST['champion_product_id'] ) : 0;
		        $orders_per_child_in = isset( $_POST['champion_orders_per_child'] ) ? intval( $_POST['champion_orders_per_child'] ) : 0;
		        $total_override_in   = isset( $_POST['champion_order_total_override'] ) ? floatval( $_POST['champion_order_total_override'] ) : 0;
		        

		        if ( $parent_id <= 0 || $child_count <= 0 || $product_id <= 0 ) {
		            $error = 'Please select a parent user, a valid product, and a positive child count.';
		        } else {
		            $result = self::create_customer_test_data( $parent_id, $child_count, $product_id, $orders_per_child_in, $total_override_in );

		            if ( ! empty( $result['error'] ) ) {
		                $error = $result['error'];
		            } else {
		                $message = sprintf(
		                    'Created %d child customer and %d completed orders (per child: %d).',
		                    intval( $result['child_created'] ),
		                    intval( $result['orders_created'] ),
		                    intval( $result['orders_per_child'] )
		                );
		            }
		        }
		    }

		    if ( $action === 'clear' ) {
		        $result = self::customer_clear_test_data();

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
		            Champion_Payouts::instance()->process_customer_monthly_payouts();
		            $message = 'Monthly payout triggered successfully (manual run).';
		        } else {
		            $error = 'Champion_Payouts class not found.';
		        }
		    }
		}

		?>
		<div class="wrap">
		    <h1>Champion Customer Test Tools</h1>
		    <p><strong>WARNING:</strong> Developer/staging only. This will create real users and real orders.</p>

            <h2>What this tool does</h2>
            <ol>
                <li>Creates N child customer users (role + meta).</li>
                <li>Links them to the selected parent via <code>champion_parent_customer</code>.</li>
                <li>Adds them to parent meta <code>champion_referred_customers</code> (dashboard reflects immediately).</li>
                <li>Creates completed WooCommerce orders per child (fires status hooks).</li>
            </ol>
    		<hr />

		    <?php if ( ! empty( $error ) ) : ?>
		        <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		    <?php elseif ( ! empty( $message ) ) : ?>
		        <div class="notice notice-success"><p><?php echo esc_html( $message ); ?></p></div>
		    <?php endif; ?>

		    <form method="post">
		        <?php wp_nonce_field( 'champion_customer_test_action', 'champion_customer_test_nonce' ); ?>

		        <table class="form-table" role="presentation">
		            <tbody>

		            <tr>
		                <th scope="row"><label for="champion_parent_id">Parent Ambassador</label></th>
		                <td>
		                    <select name="champion_parent_id" id="champion_parent_id">
		                        <option value="0">— Select Customer —</option>
		                        <?php
		                        foreach ( $users as $user ) {
		                            $label  = $user->display_name;

		                            printf(
		                                '<option value="%d">%s (ID: %d)</option>',
		                                intval( $user->ID ),
		                                esc_html( $label ),
		                                intval( $user->ID )
		                            );
		                        }
		                        ?>
		                    </select>
		                </td>
		            </tr>

		            <tr>
		                <th scope="row"><label for="champion_child_count">Number of child customer</label></th>
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
		            <button type="submit" class="button button-primary" name="champion_customer_action" value="create">
		                Create test child ambassadors & orders
		            </button>

		            <button type="submit" class="button" name="champion_customer_action" value="force_payout">
		                Force Trigger Monthly Payout
		            </button>

		            <button type="submit" class="button button-secondary" name="champion_customer_action" value="clear"
		                    onclick="return confirm('This will delete test users/orders created by this tool and clear milestone/counter tables. Continue?');">
		                Clear Test Data
		            </button>
		        </p>

		    </form>

		    <!-- <form method="post" style="margin-top:20px;">
		        <?php //wp_nonce_field( 'champion_force_customer_commission_payout' ); ?>
		        <input type="hidden" name="champion_force_customer_commission_payout" value="1" />
		        <button class="button button-secondary">
		            Force Trigger Customer Commission Payout
		        </button>
		    </form> -->

		</div>
		<?php
	}

	protected static function create_customer_test_data( $parent_id, $child_count, $product_id, $orders_per_child_in, $total_override_in ) {

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
	    $cus_meta_key     = 'is_customer';

	    if ( class_exists( 'Champion_Helpers' ) ) {
	        $opts = Champion_Helpers::instance()->get_opts();
	        if ( ! empty( $opts['child_customer_required_order'] ) ) {
	            $orders_per_child = max( 1, intval( $opts['child_customer_required_order'] ) );
	        }
	        if ( isset( $opts['child_customer_order_min_amount'] ) ) {
	            $min_amount = floatval( $opts['child_customer_order_min_amount'] );
	        }
	    }

	    // Overrides
	    if ( intval( $orders_per_child_in ) > 0 ) {
	        $orders_per_child = max( 1, intval( $orders_per_child_in ) );
	    }

	    $order_total_override = floatval( $total_override_in );
	    if ( $order_total_override < 0 ) $order_total_override = 0;

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
	    // Milestones will still be created, but do_action('champion_award_milestone', ...) will be skipped.
	    // This keeps payout controlled by "Force Trigger Monthly Payout".
	    set_transient( 'champion_suppress_awards', 1, 10 * MINUTE_IN_SECONDS );


	    $children_created = 0;
	    $orders_created   = 0;

	    for ( $i = 1; $i <= $child_count; $i++ ) {
	        $user_id = self::create_child_customer( $parent_id, $i, $cus_meta_key );
	        if ( ! $user_id ) continue;

	        $children_created++;

	        for ( $j = 1; $j <= $orders_per_child; $j++ ) {
	            $order_id = self::create_completed_order_for_customer( $user_id, $product, $quantity, $order_total_override );
	            if ( $order_id ) $orders_created++;
	        }
	    }

	    delete_transient( 'champion_suppress_awards' );


	    return array(
	        'child_created'    => $children_created,
	        'orders_created'   => $orders_created,
	        'orders_per_child' => $orders_per_child,
	    );
	}

	protected static function create_child_customer( $parent_id, $index, $cus_meta_key ) {
	    $parent_id = intval( $parent_id );
	    $index     = intval( $index );

	    $run_id   = time(); // unique per run
	    $username = sprintf( 'champ_customer_%d_%d', $run_id, $index );
	    $email    = sprintf( 'champ_customer_%d_%d_%d@example.com', $parent_id, $run_id, $index );
	    
	    $password = wp_generate_password( 12, true );

	    $user_id = wp_insert_user( array(
	        'user_login' => $username,
	        'user_email' => $email,
	        'user_pass'  => $password,
	        'role'       => 'customer',
	    ) );

	    if ( is_wp_error( $user_id ) ) return false;

	    // ambassador meta
	    update_user_meta( $user_id, $cus_meta_key, 1 );

	    // parent link
	    update_user_meta( $user_id, 'champion_parent_customer', $parent_id );

	    // dashboard shortcut list
	    $referred = get_user_meta( $parent_id, 'champion_referred_customers', true );
	    if ( ! is_array( $referred ) ) $referred = array();
	    if ( ! in_array( $user_id, $referred, true ) ) {
	        $referred[] = $user_id;
	        update_user_meta( $parent_id, 'champion_referred_customers', $referred );
	    }

	    // Track created users for safe cleanup
	    $created = get_option( self::OPT_CUS_CREATED_USERS, array() );
	    if ( ! is_array( $created ) ) $created = array();
	    $created[] = intval( $user_id );
	    update_option( self::OPT_CUS_CREATED_USERS, array_values( array_unique( $created ) ), false );

	    return $user_id;
	}

	protected static function create_completed_order_for_customer( $user_id, $product, $quantity, $order_total_override ) {
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

	        // Track created orders for safe cleanup
	        $created = get_option( self::OPT_CUS_CREATED_ORDERS, array() );
	        if ( ! is_array( $created ) ) $created = array();
	        $created[] = intval( $order_id );
	        update_option( self::OPT_CUS_CREATED_ORDERS, array_values( array_unique( $created ) ), false );

	        return $order_id;

	    } catch ( Exception $e ) {
	        return false;
	    }
	}

	protected static function customer_clear_test_data() {

	    error_log('=== CLEAR TEST DATA START ===');

	    if ( ! class_exists( 'WooCommerce' ) ) {
	        error_log('WooCommerce NOT loaded');
	        return array( 'error' => 'WooCommerce not loaded' );
	    }

	    global $wpdb;

	    $orders_deleted = 0;
	    $users_deleted  = 0;

	    // ---- LOAD OPTIONS ----
	    $order_ids = get_option( self::OPT_CUS_CREATED_ORDERS, array() );
	    $user_ids  = get_option( self::OPT_CUS_CREATED_USERS, array() );

	    error_log('Orders option: ' . print_r($order_ids, true));
	    error_log('Users option: ' . print_r($user_ids, true));

	    // ---- DELETE ORDERS ----
	    if ( is_array( $order_ids ) ) {
	        foreach ( $order_ids as $order_id ) {

	            $order_id = (int) $order_id;
	            if ( $order_id <= 0 ) continue;

	            $post = get_post( $order_id );
	            if ( ! $post ) {
	                error_log("Order {$order_id} NOT FOUND");
	                continue;
	            }

	            wp_delete_post( $order_id, true );
	            $orders_deleted++;

	            error_log("Order {$order_id} deleted");
	        }
	    }

	    // ---- DELETE USERS ----
	    if ( is_array( $user_ids ) ) {
	        require_once ABSPATH . 'wp-admin/includes/user.php';

	        foreach ( $user_ids as $user_id ) {

	            $user_id = (int) $user_id;
	            if ( $user_id <= 0 ) continue;

	            // Never delete logged-in user
	            if ( get_current_user_id() === $user_id ) {
	                error_log("Skipping current user {$user_id}");
	                continue;
	            }

	            $user = get_user_by( 'id', $user_id );
	            if ( ! $user ) {
	                error_log("User {$user_id} NOT FOUND");
	                continue;
	            }

	            wp_delete_user( $user_id );
	            $users_deleted++;

	            error_log("User {$user_id} deleted");
	        }
	    }

	    // ---- CLEAR OPTIONS ----
	    delete_option( self::OPT_CUS_CREATED_ORDERS );
	    delete_option( self::OPT_CUS_CREATED_USERS );

	    error_log('Options cleared');

	    // ---- TRUNCATE CUSTOM TABLES (SAFE) ----
	    $child_table      = $wpdb->prefix . 'champion_child_customer_counters';
	    $milestones_table = $wpdb->prefix . 'champion_customer_milestones';

	    $tables = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}champion_%'" );

	    if ( in_array( $child_table, $tables, true ) ) {
	        $wpdb->query( "TRUNCATE TABLE {$child_table}" );
	        error_log("Table truncated: {$child_table}");
	    }

	    if ( in_array( $milestones_table, $tables, true ) ) {
	        $wpdb->query( "TRUNCATE TABLE {$milestones_table}" );
	        error_log("Table truncated: {$milestones_table}");
	    }

	    error_log('=== CLEAR TEST DATA END ===');

	    return array(
	        'orders_deleted' => $orders_deleted,
	        'users_deleted'  => $users_deleted,
	    );
	}
	
	protected static function get_customer_for_dropdown() {
	    return get_users( array(
	    	'role'   => 'customer',
	        'number' => 500,
	        'fields' => array( 'ID', 'display_name', 'user_email', ),
	        'meta_query' => array(
	                    array(
	                        'key'     => 'is_customer',
	                        'compare' => 'NOT EXISTS',
	                    ),
	                ),
	    ) );
	}

	protected static function get_cus_products_list() {
	    if ( ! function_exists( 'wc_get_products' ) ) return array();

	    return wc_get_products( array(
	        'status'  => array( 'publish' ),
	        'limit'   => 100,
	        'orderby' => 'title',
	        'order'   => 'ASC',
	    ) );
	}
}

Champion_Customer_Test_Page::init();

}
