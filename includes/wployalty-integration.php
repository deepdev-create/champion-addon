<?php
/**
 * WPLoyalty integration for Champion Addon.
 *
 * - Listens on `champion_award_milestone`.
 * - If "Award via WPLoyalty" enabled and WPLoyalty active:
 *      → calculate +10% bonus
 *      → fire `champion_wployalty_award_credit` for actual integration
 *      → mark milestone as paid so default coupon payout will skip.
 */

if ( ! class_exists( 'Champion_WPLoyalty' ) ) {

    class Champion_WPLoyalty {

        /**
         * Singleton instance.
         *
         * @var Champion_WPLoyalty|null
         */
        protected static $instance = null;

        /**
         * Bootstrap.
         *
         * @return Champion_WPLoyalty
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }


        private function award_points_via_wployalty_rest( $user_id, $amount ) {

            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) return false;

            $user = get_user_by( 'id', $user_id );
            if ( ! $user || empty( $user->user_email ) ) return false;

            // WPLoyalty expects integer points
            $points = (int) round( (float) $amount );
            if ( $points <= 0 ) return false;

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

            // Internal REST request to WPLoyalty
            $request = new WP_REST_Request( 'POST', '/wc/v3/wployalty/customers/points/add' );
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
                return false;
            }

            $status = (int) $response->get_status();
            if ( $status < 200 || $status >= 300 ) {
                return false;
            }

            return true;
        }



        /**
         * Constructor.
         *
         * Hook early on champion_award_milestone so we can take over payout
         * before default coupon handler (which typically runs at later priority).
         */
        private function __construct() {
            add_action(
                'champion_award_milestone',
                array( $this, 'maybe_award_via_wployalty' ),
                5,
                3
            );
        }

        /**
         * Check if WPLoyalty (free or PRO) is active.
         *
         * @return bool
         */
        protected function is_wployalty_active() {
            // If is_plugin_active is available, check by plugin slug.
            if ( function_exists( 'is_plugin_active' ) ) {
                if (
                    is_plugin_active( 'wployalty/wployalty.php' ) ||
                    is_plugin_active( 'wployalty-pro/wployalty-pro.php' )
                ) {
                    return true;
                }
            }

            // Fallback: check by class existence.
            if ( class_exists( 'WPLoyalty' ) || class_exists( 'WPLoyalty_Pro' ) ) {
                return true;
            }

            return false;
        }

        /**
         * Handle milestone award via WPLoyalty instead of coupon.
         *
         * @param int   $parent_id
         * @param float $amount
         * @param int   $block_index
         *
         * @return void
         */
        public function maybe_award_via_wployalty( $parent_id, $amount, $block_index ) {

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

            // If WPLoyalty is not active, exit.
            if ( ! $this->is_wployalty_active() ) {
                return;
            }

            global $wpdb;

            $milestones_table = $wpdb->prefix . 'champion_milestones';

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

            /**
             * 1) Default implementation: award via WPLoyalty REST (points add)
             * Returns true on success.
             */
            $awarded = $this->award_points_via_wployalty_rest( $parent_id, $amount_to_award );

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
                    $wpdb->update(
                        $milestones_table,
                        array(
                            'paid' => 1,
                            'note' => 'wployalty_points_added',
                        ),
                        array( 'id' => $row->id ),
                        array( '%d', '%s' ),
                        array( '%d' )
                    );
                } else {
                    // store note for debugging; do NOT mark paid
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

    }
}

// Bootstrap the integration after plugins load.
add_action(
    'plugins_loaded',
    function () {
        if ( class_exists( 'Champion_WPLoyalty' ) ) {
            Champion_WPLoyalty::instance();
        }
    }
);
