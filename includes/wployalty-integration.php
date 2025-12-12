<?php
/**
 * WPLoyalty integration for Champion Addon.
 *
 * Goal:
 * - Listen on `champion_award_milestone` BEFORE default coupon handler.
 * - If "Award via WPLoyalty" enabled and WPLoyalty active:
 *      → calculate +10% amount
 *      → trigger custom action to actually credit via WPLoyalty
 *      → mark milestone as paid so coupon handler will not run.
 */

if ( ! class_exists( 'Champion_WPLoyalty' ) ) {

    class Champion_WPLoyalty {

        /**
         * @var Champion_WPLoyalty|null
         */
        protected static $instance = null;

        /**
         * Singleton bootstrap.
         *
         * @return Champion_WPLoyalty
         */
        public static function instance() {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        /**
         * Constructor.
         * Hook early on champion_award_milestone so we run BEFORE default coupon payout (priority 30).
         */
        public function __construct() {
            add_action(
                'champion_award_milestone',
                array( $this, 'maybe_award_via_wployalty' ),
                5,
                3
            );
        }

        /**
         * Lightweight check to see if WPLoyalty (free or PRO) is active.
         *
         * We support both plugin slug check and class existence check.
         *
         * @return bool
         */
        protected function is_wployalty_active() {
            // Check by plugin slug if function exists.
            if ( function_exists( 'is_plugin_active' ) ) {
                if (
                    is_plugin_active( 'wployalty/wployalty.php' ) ||
                    is_plugin_active( 'wployalty-pro/wployalty-pro.php' )
                ) {
                    return true;
                }
            }

            // Fallback: class-based detection.
            if ( class_exists( 'WPLoyalty' ) || class_exists( 'WPLoyalty_Pro' ) ) {
                return true;
            }

            return false;
        }

        /**
         * Main handler - runs on champion_award_milestone with high priority (5).
         *
         * If:
         *  - WPLoyalty enabled in settings
         *  - WPLoyalty plugin active
         *  - milestone row is NOT already paid/coupon-issued
         *
         * then we:
         *  - calculate +10% bonus amount for store credit
         *  - trigger champion_wployalty_award_credit for actual integration
         *  - mark milestone as paid so default coupon handler is skipped.
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

            // Read options.
            $opts = Champion_Helpers::instance()->get_opts();

            // If admin has NOT enabled "Award via WPLoyalty", do nothing.
            if ( empty( $opts['award_via_wployalty'] ) ) {
                return;
            }

            // If WPLoyalty plugin is not active, do nothing.
            if ( ! $this->is_wployalty_active() ) {
                return;
            }

            global $wpdb;

            $milestones_table = $wpdb->prefix . 'champion_milestones';

            // Load the relevant milestone row.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$milestones_table}
                     WHERE parent_affiliate_id = %d AND block_index = %d
                     ORDER BY id DESC LIMIT 1",
                    $parent_id,
                    $block_index
                )
            );

            // If already paid or coupon already attached - do nothing.
            if ( $row && ( intval( $row->paid ) === 1 || intval( $row->coupon_id ) > 0 ) ) {
                return;
            }

            // +10% bonus for store credit payouts (as per program).
            $amount_to_award = floatval( $amount ) * 1.10;

            /**
             * Filter: allow integrators to tweak the final amount.
             *
             * @param float $amount_to_award   Amount after 10% bonus.
             * @param int   $parent_id         Ambassador (parent) user ID.
             * @param int   $block_index       Milestone block index.
             * @param object|null $row         Milestone DB row (if any).
             */
            $amount_to_award = apply_filters(
                'champion_wployalty_amount',
                $amount_to_award,
                $parent_id,
                $block_index,
                $row
            );

            /**
             * Action: champion_wployalty_award_credit
             *
             * IMPORTANT:
             *  - THIS is where you (or the store dev) must hook and actually
             *    talk to WPLoyalty (via PHP API or REST API) to add points/credit.
             *
             * Example pseudo usage in a custom plugin:
             *
             *  add_action( 'champion_wployalty_award_credit', function( $user_id, $amount, $block_index, $row ) {
             *      // Call WPLoyalty to add points or wallet credits to $user_id.
             *  }, 10, 4 );
             *
             * @param int         $parent_id       Ambassador user ID.
             * @param float       $amount_to_award Final amount (with bonus).
             * @param int         $block_index     Milestone block index.
             * @param object|null $row             Milestone DB row.
             */
            do_action(
                'champion_wployalty_award_credit',
                $parent_id,
                $amount_to_award,
                $block_index,
                $row
            );

            // Mark milestone as "paid" so default coupon handler will skip it.
            if ( $row ) {
                $wpdb->update(
                    $milestones_table,
                    array(
                        'paid' => 1,
                        'note' => 'wployalty_credit',
                    ),
                    array( 'id' => $row->id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    // Bootstrap the integration after plugins load.
    add_action(
        'plugins_loaded',
        function () {
            Champion_WPLoyalty::instance();
        }
    );

}
