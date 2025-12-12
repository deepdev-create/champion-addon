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
             *
             * @param float       $amount_to_award Amount after 10% bonus.
             * @param int         $parent_id       Ambassador (parent) user ID.
             * @param int         $block_index     Milestone block index.
             * @param object|null $row             Milestone DB row (if any).
             */
            $amount_to_award = apply_filters(
                'champion_wployalty_amount',
                $amount_to_award,
                $parent_id,
                $block_index,
                $row
            );

            /**
             * Action where the store dev must actually call WPLoyalty.
             *
             * Example (pseudo):
             *
             *  add_action( 'champion_wployalty_award_credit', function( $user_id, $amount, $block_index, $row ) {
             *      // Call WPLoyalty's API to add wallet/points to $user_id.
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

            // Mark milestone as paid so coupon payout handler will skip it.
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
