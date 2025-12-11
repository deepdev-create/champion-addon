<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_WPLoyalty {
    private static $instance = null;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        // Attempt direct handling if option enabled; fallback to coupon
        add_action('champion_award_milestone', array($this, 'award_milestone_handler'), 20, 3);
    }

    public function is_wployalty_active() {
        return class_exists('Wlr\\App\\App') || defined('WLR_PLUGIN_FILE') || file_exists(WP_PLUGIN_DIR . '/wployalty/wployalty.php');
    }

    /**
     * Primary handler: if admin set award_via_wployalty true, dispatch action champion_wployalty_award_credit
     * Integrators should hook champion_wployalty_award_credit to call the exact WPLoyalty API on their site.
     */
    public function award_milestone_handler($parent_id, $amount, $block_index) {
        $opts = Champion_Helpers::instance()->get_opts();
        if ( empty($opts['award_via_wployalty']) ) return; // disabled

        if ( ! $this->is_wployalty_active() ) {
            // not installed - nothing to do
            return;
        }

        // We add +10% bonus for store credit payouts (as per program) before calling WPLoyalty,
        // but we allow integrator to override via filter.
        $amount_to_award = floatval($amount) * 1.10;
        $amount_to_award = apply_filters('champion_wployalty_amount', $amount_to_award, $parent_id, $block_index);

        // Trigger a specific action integrator must implement to call the actual WPLoyalty API.
        // Example integrator code (example) will be provided separately.
        do_action('champion_wployalty_award_credit', $parent_id, $amount_to_award, $block_index);
    }
}
