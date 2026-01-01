<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Champion_Customer_Milestones {
    private static $instance = null;
    private $wpdb;
    private $child_customer_table;
    private $customer_milestone_table;

    public static function instance(){
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct(){
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->child_customer_table = $wpdb->prefix . 'champion_child_customer_counters';
        $this->customer_milestone_table = $wpdb->prefix . 'champion_customer_milestones';
    }

    public function create_customer_tables(){
        $charset_collate = $this->wpdb->get_charset_collate();

        $sql3 = "CREATE TABLE IF NOT EXISTS {$this->child_customer_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            child_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            parent_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            qualifying_orders INT(11) NOT NULL DEFAULT 0,
            last_order_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY child_affiliate_id (child_affiliate_id),
            KEY parent_affiliate_id (parent_affiliate_id)
        ) $charset_collate;";

        $sql4 = "CREATE TABLE IF NOT EXISTS {$this->customer_milestone_table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_affiliate_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            block_index INT(11) NOT NULL DEFAULT 0,
            milestone_children INT(11) NOT NULL DEFAULT 0,
            awarded_at DATETIME NULL,
            note VARCHAR(255) DEFAULT '' NOT NULL,
            coupon_id BIGINT(20) DEFAULT 0,
            paid tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY parent_affiliate_id (parent_affiliate_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql3);
        dbDelta($sql4);
    }

}
