<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Payouts {
    private static $instance = null;
    private $milestones_table;
    private $customer_milestones_table;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        global $wpdb;
        $this->milestones_table = $wpdb->prefix . 'champion_milestones';
        $this->customer_milestones_table = $wpdb->prefix . 'champion_customer_milestones';

        // default coupon creation handler (lowest priority so direct WPLoyalty can run first)
        add_action('champion_award_milestone', array($this, 'default_award_milestone_handler'), 30, 3);

        add_action('admin_post_champion_manual_payout', array($this, 'handle_manual_payout'));

        add_action('champion_customer_award_milestone', array($this, 'default_award_customer_milestone_handler'), 30, 3);
    }


    /**
     * Process monthly customer commission payouts
     * Uses order meta to prevent double payout
     */
    public function process_monthly_customer_commission_payouts() {

        $opts = Champion_Helpers::instance()->get_opts();

        $method = ! empty( $opts['customer_commission_payout_method'] )
            ? $opts['customer_commission_payout_method']
            : 'wployalty';


            

        // Get completed orders with unpaid customer commission
        $orders = wc_get_orders( array(
            'limit'   => -1,
            'status'  => array( 'completed','wc-processing' ),
            'meta_key'     => 'champion_commission_amount',
            'meta_compare' => '>',
            'meta_value'   => '0',
        ) );


        if ( empty( $orders ) ) {
            return;
        }

        foreach ( $orders as $order ) {

            $paid = $order->get_meta( 'champion_commission_paid' );
            if ( ! empty( $paid ) ) {
                continue;
            }

            $amount        = (float) $order->get_meta( 'champion_commission_amount' );
            $ambassador_id = (int) $order->get_meta( 'champion_ambassador_id' );

            if ( $amount <= 0 || $ambassador_id <= 0 ) {
                continue;
            }

            $payout_ref = '';

            /**
             * WPLoyalty payout (same hook as bonus)
             */
            if ( $method === 'wployalty' ) {

                $awarded = $this->award_points_via_wployalty_rest( $ambassador_id, $amount );

                $payout_ref = 'wployalty';

            } else {

                /**
                 * Coupon payout (same pattern as bonus payout)
                 */
                $user = get_user_by( 'id', $ambassador_id );
                if ( ! $user ) {
                    continue;
                }

                $code = 'CHMP-CUST-' . $ambassador_id . '-' . time() . '-' . wp_generate_password( 6, false, false );

                $coupon_id = wp_insert_post( array(
                    'post_title'   => $code,
                    'post_content' => 'Champion customer commission payout',
                    'post_status'  => 'publish',
                    'post_author'  => get_current_user_id(),
                    'post_type'    => 'shop_coupon',
                ) );

                if ( ! $coupon_id ) {
                    continue;
                }

                update_post_meta( $coupon_id, 'discount_type', 'fixed_cart' );
                update_post_meta( $coupon_id, 'coupon_amount', number_format( $amount, 2, '.', '' ) );
                update_post_meta( $coupon_id, 'individual_use', 'yes' );
                update_post_meta( $coupon_id, 'usage_limit', 1 );
                update_post_meta( $coupon_id, 'customer_email', $user->user_email );
                update_post_meta( $coupon_id, 'description', 'Champion customer commission payout for order #' . $order->get_id() );

                $payout_ref = $coupon_id;
            }

            /**
             * Mark commission as paid (CRITICAL)
             */
            $order->update_meta_data( 'champion_commission_paid', 1 );
            $order->update_meta_data( 'champion_commission_paid_on', current_time( 'mysql' ) );
            $order->update_meta_data( 'champion_commission_payout_ref', $payout_ref );
            $order->save();
        }
    }


    private function award_points_via_wployalty_rest( $user_id, $amount ) {

            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) {
                Champion_Helpers::log( 'WPLoyalty award: invalid user_id', array( 'user_id' => $user_id ) );
                return false;
            }

            $user = get_user_by( 'id', $user_id );
            if ( ! $user || empty( $user->user_email ) ) {
                Champion_Helpers::log( 'WPLoyalty award: user not found or missing email', array( 'user_id' => $user_id ) );
                return false;
            }

            // WPLoyalty expects integer points
            $points = (int) round( (float) $amount );
            if ( $points <= 0 ) {
                Champion_Helpers::log( 'WPLoyalty award: non-positive points computed', array(
                    'user_id' => $user_id,
                    'amount'  => $amount,
                    'points'  => $points,
                ) );
                return false;
            }

            // Cron runs without current user; REST permissions may require an admin.
            $old_user = get_current_user_id();

            $admins = get_users( array(
                'role'    => 'administrator',
                'orderby' => 'ID',
                'order'   => 'ASC',
                'number'  => 1,
                'fields'  => array( 'ID' ),
            ) );

            if ( ! empty( $admins[0]->ID ) ) {
                wp_set_current_user( (int) $admins[0]->ID );
            }

            $route = '/wc/v3/wployalty/customers/points/add';

            // Verify REST route exists before calling (helps explain "2xx but nothing happened" / or missing route).
            $route_exists = false;
            if ( function_exists( 'rest_get_server' ) ) {
                $server = rest_get_server();
                if ( $server && method_exists( $server, 'get_routes' ) ) {
                    $routes = $server->get_routes();
                    if ( is_array( $routes ) && isset( $routes[ $route ] ) ) {
                        $route_exists = true;
                    }
                }
            }

            Champion_Helpers::log( 'WPLoyalty award: attempt', array(
                'user_id'       => $user_id,
                'user_email'    => $user->user_email,
                'points'        => $points,
                'route'         => $route,
                'route_exists'  => $route_exists ? 1 : 0,
                'acting_user'   => get_current_user_id(),
            ) );

            if ( ! $route_exists ) {
                // Restore context before returning.
                if ( $old_user ) {
                    wp_set_current_user( (int) $old_user );
                } else {
                    wp_set_current_user( 0 );
                }

                Champion_Helpers::log( 'WPLoyalty award: route missing; aborting to allow coupon fallback', array(
                    'route' => $route,
                ) );

                return false;
            }

            // Internal REST request to WPLoyalty
            $request = new WP_REST_Request( 'POST', $route );
            $request->set_param( 'user_email', $user->user_email );
            $request->set_param( 'points', $points );

            $response = rest_do_request( $request );

            // Restore context
            if ( $old_user ) {
                wp_set_current_user( (int) $old_user );
            } else {
                wp_set_current_user( 0 );
            }

            if ( is_wp_error( $response ) ) {
                Champion_Helpers::log( 'WPLoyalty award: WP_Error response', array(
                    'user_id'  => $user_id,
                    'error'    => $response->get_error_message(),
                    'code'     => $response->get_error_code(),
                    'data'     => $response->get_error_data(),
                ) );
                return false;
            }

            $status = (int) $response->get_status();
            $data   = $response->get_data();

            Champion_Helpers::log( 'WPLoyalty award: REST response', array(
                'user_id' => $user_id,
                'status'  => $status,
                'data'    => $data,
            ) );

            // Must be a 2xx response.
            if ( $status < 200 || $status >= 300 ) {
                return false;
            }

            /**
             * Tighten "success":
             * - Some endpoints can respond 2xx with an error payload.
             * - We only treat it as success if payload is not empty AND does not clearly indicate an error.
             */
            if ( empty( $data ) ) {
                return false;
            }

            if ( is_array( $data ) ) {
                // Common patterns: { success: true }, { status: "success" }, { error: "..."} etc.
                if ( isset( $data['error'] ) && ! empty( $data['error'] ) ) {
                    return false;
                }
                if ( isset( $data['success'] ) && $data['success'] === false ) {
                    return false;
                }
            }

            return true;
        }


    /**
     * Default handler: create private coupon for the user and update milestone row (if present)
     */
    public function default_award_milestone_handler($parent_id, $amount, $block_index) {
        // If WPLoyalty option is enabled, we let WPLoyalty handler run first (it was hooked earlier); check if milestone row already has coupon or paid flag
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$this->milestones_table} WHERE parent_affiliate_id = %d AND block_index = %d ORDER BY id DESC LIMIT 1", $parent_id, intval($block_index)) );
        
        // If row already has a coupon OR is marked as paid (eg. via WPLoyalty), skip.
        if ( $row && ( intval( $row->coupon_id ) > 0 || intval( $row->paid ) === 1 ) ) {
            return;
        }

        $user = get_user_by('id', intval($parent_id));
        if ( ! $user ) return;

        $code = 'CHMP-' . $parent_id . '-' . time() . '-' . wp_generate_password(6,false,false);

        $coupon = array(
            'post_title'   => $code,
            'post_content' => 'Champion milestone payout block #' . intval($block_index),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
            'post_type'    => 'shop_coupon'
        );
        $coupon_id = wp_insert_post($coupon);

        if ( is_wp_error($coupon_id) || $coupon_id === 0 ) return;

        update_post_meta($coupon_id, 'discount_type', 'fixed_cart');
        update_post_meta($coupon_id, 'coupon_amount', number_format((float)$amount, 2, '.', ''));
        update_post_meta($coupon_id, 'individual_use', 'yes');
        update_post_meta($coupon_id, 'usage_limit', 1);
        update_post_meta($coupon_id, 'customer_email', $user->user_email);
        update_post_meta($coupon_id, 'description', 'Champion milestone payout to user ID ' . $parent_id);

        if ( $row ) {
            $wpdb->update( $this->milestones_table, array('coupon_id' => intval($coupon_id), 'note' => 'coupon_auto_created', 'paid' => 1), array('id' => $row->id) );
        }
    }

    /**
     * Monthly processor - create coupons for unpaid milestone rows (or call external payout hook).
     */
    public function process_monthly_payouts() {
        global $wpdb;
        $opts = Champion_Helpers::instance()->get_opts();
        $min = floatval($opts['min_payout_amount']);

        $now  = current_time( 'mysql' );
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->milestones_table} WHERE paid = 0 AND awarded_at IS NOT NULL AND awarded_at <= %s",
                $now
            )
        );

        

        if ( empty($rows) ) return;

        foreach ( $rows as $r ) {
            // if amount less than min and not allowed, skip
            if ( floatval($r->amount) < $min ) continue;
            // create coupon via default handler
            do_action('champion_award_milestone', intval($r->parent_affiliate_id), floatval($r->amount), intval($r->block_index));
        }


        // Process customer commission payouts (additive)
        $this->process_monthly_customer_commission_payouts();


        // optionally send admin email with summary
        $admin_email = get_option('admin_email');
        wp_mail($admin_email, 'Champion monthly payouts run', 'Champion addon processed monthly payout run. See admin.');
    }

    /*public function default_award_customer_milestone_handler($parent_id, $amount, $block_index) {

        // If WPLoyalty option is enabled, we let WPLoyalty handler run first (it was hooked earlier); check if milestone row already has coupon or paid flag
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$this->customer_milestones_table} WHERE parent_affiliate_id = %d AND block_index = %d ORDER BY id DESC LIMIT 1", $parent_id, intval($block_index)) );
        
        // If row already has a coupon OR is marked as paid (eg. via WPLoyalty), skip.
        if ( $row && ( intval( $row->coupon_id ) > 0 || intval( $row->paid ) === 1 ) ) {
            return;
        }

        $user = get_user_by('id', intval($parent_id));
        if ( ! $user ) return;

        $code = 'CHMP-' . $parent_id . '-' . time() . '-' . wp_generate_password(6,false,false);

        $coupon = array(
            'post_title'   => $code,
            'post_content' => 'Champion customer to customers milestone payout block #' . intval($block_index),
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
            'post_type'    => 'shop_coupon'
        );
        $coupon_id = wp_insert_post($coupon);

        if ( is_wp_error($coupon_id) || $coupon_id === 0 ) return;

        update_post_meta($coupon_id, 'discount_type', 'fixed_cart');
        update_post_meta($coupon_id, 'coupon_amount', number_format((float)$amount, 2, '.', ''));
        update_post_meta($coupon_id, 'individual_use', 'yes');
        update_post_meta($coupon_id, 'usage_limit', 1);
        update_post_meta($coupon_id, 'customer_email', $user->user_email);
        update_post_meta($coupon_id, 'description', 'Champion milestone payout to user ID ' . $parent_id);

        if ( $row ) {
            $wpdb->update( $this->customer_milestones_table, array('coupon_id' => intval($coupon_id), 'note' => 'coupon_auto_created', 'paid' => 1), array('id' => $row->id) );
        }
    }*/

    private function customer_award_points_via_wployalty_rest( $user_id, $amount ) {

        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            Champion_Helpers::log( 'WPLoyalty award: invalid user_id', array( 'user_id' => $user_id ) );
            return false;
        }

        $user = get_user_by( 'id', $user_id );
        if ( ! $user || empty( $user->user_email ) ) {
            Champion_Helpers::log( 'WPLoyalty award: user not found or missing email', array( 'user_id' => $user_id ) );
            return false;
        }

        // WPLoyalty expects integer points
        $points = (int) round( (float) $amount );
        if ( $points <= 0 ) {
            Champion_Helpers::log( 'WPLoyalty award: non-positive points computed', array(
                'user_id' => $user_id,
                'amount'  => $amount,
                'points'  => $points,
            ) );
            return false;
        }

        // Cron runs without current user; REST permissions may require an admin.
        $old_user = get_current_user_id();

        $admins = get_users( array(
            'role'    => 'administrator',
            'orderby' => 'ID',
            'order'   => 'ASC',
            'number'  => 1,
            'fields'  => array( 'ID' ),
        ) );

        if ( ! empty( $admins[0]->ID ) ) {
            wp_set_current_user( (int) $admins[0]->ID );
        }

        $route = '/wc/v3/wployalty/customers/points/add';

        // Verify REST route exists before calling (helps explain "2xx but nothing happened" / or missing route).
        $route_exists = false;
        if ( function_exists( 'rest_get_server' ) ) {
            $server = rest_get_server();
            if ( $server && method_exists( $server, 'get_routes' ) ) {
                $routes = $server->get_routes();
                if ( is_array( $routes ) && isset( $routes[ $route ] ) ) {
                    $route_exists = true;
                }
            }
        }

        Champion_Helpers::log( 'WPLoyalty award: attempt', array(
            'user_id'       => $user_id,
            'user_email'    => $user->user_email,
            'points'        => $points,
            'route'         => $route,
            'route_exists'  => $route_exists ? 1 : 0,
            'acting_user'   => get_current_user_id(),
        ) );

        if ( ! $route_exists ) {
            // Restore context before returning.
            if ( $old_user ) {
                wp_set_current_user( (int) $old_user );
            } else {
                wp_set_current_user( 0 );
            }

            Champion_Helpers::log( 'WPLoyalty award: route missing; aborting to allow coupon fallback', array(
                'route' => $route,
            ) );

            return false;
        }

        // Internal REST request to WPLoyalty
        $request = new WP_REST_Request( 'POST', $route );
        $request->set_param( 'user_email', $user->user_email );
        $request->set_param( 'points', $points );

        $response = rest_do_request( $request );

        // Restore context
        if ( $old_user ) {
            wp_set_current_user( (int) $old_user );
        } else {
            wp_set_current_user( 0 );
        }

        if ( is_wp_error( $response ) ) {
            Champion_Helpers::log( 'WPLoyalty award: WP_Error response', array(
                'user_id'  => $user_id,
                'error'    => $response->get_error_message(),
                'code'     => $response->get_error_code(),
                'data'     => $response->get_error_data(),
            ) );
            return false;
        }

        $status = (int) $response->get_status();
        $data   = $response->get_data();

        Champion_Helpers::log( 'WPLoyalty award: REST response', array(
            'user_id' => $user_id,
            'status'  => $status,
            'data'    => $data,
        ) );

        // Must be a 2xx response.
        if ( $status < 200 || $status >= 300 ) {
            return false;
        }

        /**
         * Tighten "success":
         * - Some endpoints can respond 2xx with an error payload.
         * - We only treat it as success if payload is not empty AND does not clearly indicate an error.
         */
        if ( empty( $data ) ) {
            return false;
        }

        if ( is_array( $data ) ) {
            // Common patterns: { success: true }, { status: "success" }, { error: "..."} etc.
            if ( isset( $data['error'] ) && ! empty( $data['error'] ) ) {
                return false;
            }
            if ( isset( $data['success'] ) && $data['success'] === false ) {
                return false;
            }
        }

        return true;
    }

    private function process_monthly_customer_to_customer_commission_payouts( $parent_id, $amount, $block_index ) {

        $parent_id   = intval( $parent_id );
        $block_index = intval( $block_index );

        if ( $parent_id <= 0 ) {
            return;
        }

        // Read addon settings.
        $opts = Champion_Helpers::instance()->get_opts();

        // If admin did not enable this, exit.
        if ( empty( $opts['award_via_wployalty'] ) ) {
            return;
        }

        global $wpdb;

        $milestones_table = $wpdb->prefix . 'champion_customer_milestones';

        // Load relevant milestone row.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$milestones_table}
                 WHERE parent_affiliate_id = %d AND block_index = %d
                 ORDER BY id DESC
                 LIMIT 1",
                $parent_id,
                $block_index
            )
        );

        // Already paid (via coupon or previous WPLoyalty payout)? Do nothing.
        if ( $row && ( intval( $row->paid ) === 1 || intval( $row->coupon_id ) > 0 ) ) {
            return;
        }

        // +10% bonus for store credit payouts.
        $amount_to_award = floatval( $amount ) * 1.10;

        /**
         * Filter to tweak final amount passed to WPLoyalty.
         */
        $amount_to_award = apply_filters(
            'champion_wployalty_amount',
            $amount_to_award,
            $parent_id,
            $block_index,
            $row
        );

        $awarded = $this->customer_award_points_via_wployalty_rest( $parent_id, $amount_to_award );

        Champion_Helpers::log( 'WPLoyalty award: result (pre-filters)', array(
            'parent_id'       => $parent_id,
            'block_index'     => $block_index,
            'amount'          => $amount,
            'amount_to_award' => $amount_to_award,
            'awarded'         => $awarded ? 1 : 0,
            'milestone_id'    => $row ? (int) $row->id : 0,
        ) );

        /**
         * 2) Allow store/dev override to confirm award happened
         * (e.g. if they use wallet credit instead of points)
         */
        $awarded = (bool) apply_filters(
            'champion_wployalty_awarded',
            $awarded,
            $parent_id,
            $amount_to_award,
            $block_index,
            $row
        );

        /**
         * 3) Keep the old action for backward compatibility
         */
        do_action(
            'champion_wployalty_award_credit',
            $parent_id,
            $amount_to_award,
            $block_index,
            $row
        );

        /**
         * Mark milestone as paid ONLY if award succeeded.
         * If award fails, keep unpaid so coupon payout handler can process it.
         */
        if ( $row ) {
            if ( $awarded ) {

                Champion_Helpers::log( 'WPLoyalty award: marking milestone paid', array(
                    'milestone_id' => (int) $row->id,
                    'parent_id'    => $parent_id,
                    'block_index'  => $block_index,
                ) );

                $wpdb->update(
                    $milestones_table,
                    array(
                        'paid' => 1,
                        'note' => 'wployalty_awarded_ok',
                    ),
                    array( 'id' => $row->id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );

            } else {

                Champion_Helpers::log( 'WPLoyalty award: failed; leaving unpaid for coupon fallback', array(
                    'milestone_id' => (int) $row->id,
                    'parent_id'    => $parent_id,
                    'block_index'  => $block_index,
                ) );

                // Do NOT mark paid. Default coupon handler (priority 30) will run and create coupon.
                $wpdb->update(
                    $milestones_table,
                    array(
                        'note' => 'wployalty_failed_fallback_to_coupon',
                    ),
                    array( 'id' => $row->id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    public function process_customer_monthly_payouts() {
        global $wpdb;
        $opts = Champion_Helpers::instance()->get_opts();
        
        $customer_min_bonus_amount = floatval($opts['child_customer_bonus_amount']);

        $now  = current_time( 'mysql' ); // Note: remove this duplicate entry et last 
        $customer_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->customer_milestones_table} WHERE paid = 0 AND awarded_at IS NOT NULL AND awarded_at <= %s",
                $now
            )
        );
        
        if ( empty($customer_rows) ) return;

        foreach ( $customer_rows as $r ) {
            // if amount less than min and not allowed, skip
            if ( floatval($r->amount) < $customer_min_bonus_amount ) continue;

            // create coupon via default handler
            //do_action('champion_customer_award_milestone', intval($r->parent_affiliate_id), floatval($r->amount), intval($r->block_index));

            // Process customer commission payouts (additive)
            $this->process_monthly_customer_to_customer_commission_payouts( intval($r->parent_affiliate_id), floatval($r->amount), intval($r->block_index) );

        }


        // optionally send admin email with summary
        $admin_email = get_option('admin_email');
        wp_mail($admin_email, 'Champion monthly payouts run', 'Champion addon processed monthly payout run. See admin.');
    }

    /**
     * Manual payout via admin-post. Simple flow: milestone_id is posted.
     */
    public function handle_manual_payout() {
        if ( ! current_user_can('manage_options') ) wp_die('forbidden');
        if ( empty($_POST['milestone_id']) ) wp_die('missing id');

        $mid = intval($_POST['milestone_id']);
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$this->milestones_table} WHERE id = %d", $mid) );
        
        if ( ! $row ) {
            wp_die('not found');
        }

        // Already paid either via coupon or external (eg. WPLoyalty)
        if ( intval( $row->coupon_id ) > 0 || intval( $row->paid ) === 1 ) {
            wp_safe_redirect( wp_get_referer() );
            exit;
        }

        do_action(
            'champion_award_milestone',
            intval( $row->parent_affiliate_id ),
            floatval( $row->amount ),
            intval( $row->block_index )
        );


        wp_safe_redirect( wp_get_referer() );
        exit;
    }

    public function get_milestones($limit = 200) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$this->milestones_table} ORDER BY awarded_at DESC LIMIT %d", intval($limit)) );
    }

    
}
