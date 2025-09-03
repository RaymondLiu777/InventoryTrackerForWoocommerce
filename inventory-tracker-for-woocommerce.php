<?php
/**
 * Plugin Name: Inventory Tracker For Woocommerce
 * Description: Track Changes to Inventory and Stock Levels of your Products
 * Version: 1.0.0
 * Author: Raymond Liu
 * Author URI: https://github.com/RaymondLiu777
 * License: GPL2
 * Requires Plugins: woocommerce
 */

namespace InventoryTracker;
 
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

require_once __DIR__ . '/src/inventory-update-manager.php';
require_once __DIR__ . '/src/inventory-update.php';
require_once __DIR__ . '/src/inventory-tracker-list-table.php';
require_once __DIR__ . '/includes/inventory-update-hooks.php';
require_once __DIR__ . '/includes/inventory-update-hooks-atum.php';
require_once __DIR__ . '/includes/inventory-update-hooks-helper.php';

final class InventoryTrackerForWoocommerce {
    private static $instance = null;

    /** @var \InventoryTracker\InventoryTrackerListTable */
    private $table;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
    }

    public function on_plugins_loaded() {
        if ( class_exists( 'WooCommerce' ) ) {
            InventoryUpdateHooks::init();
            if ( class_exists( '\Atum\Bootstrap' ) ) {
                InventoryUpdateHooksATUM::init();
            }
        }
        else {
            add_action( 'admin_notices', [ $this, 'woocommerce_required_notice' ] );
        }
    }

    public function on_activate() {
        $manager = InventoryUpdateManager::get_instance();
        $manager->create_table();
    }

    public function register_admin_menu() {
        $hook_suffix = add_menu_page(
            __( 'Inventory Tracker', 'inventory-tracker-for-woocommerce' ),
            __( 'Inventory Tracker', 'inventory-tracker-for-woocommerce' ),
            'manage_woocommerce',
            'inventory_tracker_list_table',
            [ $this, 'render_admin_page' ],
            'dashicons-analytics',
            55.7
        );

        add_action( "load-$hook_suffix", [ $this, 'add_screen_options' ] );
        add_action( "load-$hook_suffix", [ $this, 'add_screen_help_tabs' ] );
    }

    public function add_screen_options() {
        $screen = get_current_screen();
        if ( ! is_object( $screen ) || $screen->id !== 'toplevel_page_inventory_tracker_list_table' ) {
            return;
        }

        $args = [
            'label'   => __( 'Elements per page', 'inventory-tracker-for-woocommerce' ),
            'default' => 20,
            'option'  => 'elements_per_page',
        ];

        add_screen_option( 'per_page', $args );

        $this->table = new InventoryTrackerListTable();
    }

    public function add_screen_help_tabs() {
        $screen = get_current_screen();
        if ( ! is_object( $screen ) || $screen->id !== 'toplevel_page_inventory_tracker_list_table' ) {
            return;
        }
        $screen->add_help_tab( array(
            'id'	=> 'itfw_main_tab',
            'title'	=> __('Inventory Tracker For WooCommerce'),
            'content'	=> <<<HTML
                <h2>How to Use Inventory Tracker</h2>
                <p>This screen helps you <strong>monitor and manage product stock</strong> in WooCommerce.</p>
                <p>Screen Options:</p>
                <ul>
                    <li>Filter inventory data by <em>SKU</em> or <em>Product ID</em></li>
                    <li>Filter inventory data by <em>Date</em></li>
                    <li>Track which <em>users made inventory changes</em></li>
                </ul>
                <p><strong>Note: Changes are only tracked while this plugin is active.</strong></p>
            HTML,
        ) );
        $screen->add_help_tab( array(
            'id'	=> 'itfw_woocommerce_tab',
            'title'	=> __('WooCommerce Actions'),
            'content'	=> <<<HTML
                <h2>Tracked WooCommerce Actions</h2>
                <p>Inventory changes are automatically tracked when the following events occur:</p>
                <ul>
                    <li>Changes made through the <em>Product Editor</em></li>
                    <li>Changes made using <em>Bulk Edit</em> or <em>Quick Edit</em></li>
                    <li>Stock increases/decreases from <em>Processing or Cancelled</em> orders</li>
                    <li>Stock increases from <em>Refunded</em> orders</li>
                </ul>
            HTML,
        ) );
        $screen->add_help_tab( array(
            'id'	=> 'itfw_atum_tab',
            'title'	=> __('ATUM Actions'),
            'content'	=> <<<HTML
                <h2>Tracked ATUM Actions</h2>
                <p>Inventory changes are automatically tracked when the following events occur:</p>
                <ul>
                    <li>Changes made through <em>Stock Central</em></li>
                    <li>Stock increases/decreases from receiving <em>POs</em></li>
                </ul>
                <p><strong>Note: These only apply to the free ATUM core, not any of their paid extensions</strong></p>
            HTML,
        ) );

    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'inventory-tracker-for-woocommerce' ) );
        }

        if ( empty( $this->table ) ) {
            $this->table = new \InventoryTracker\InventoryTrackerListTable();
        }

        echo '<div class="wrap"><h2>' . esc_html__( 'Inventory Tracker', 'inventory-tracker-for-woocommerce' ) . '</h2>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="' . esc_attr( sanitize_key( $_REQUEST['page'] ?? '' ) ) . '" />';
        wp_nonce_field( 'inventory_tracker_list', 'inventory_tracker_nonce' );

        $this->table->prepare_items();
        $this->table->search_box( 'search', 'search_id' );
        $this->table->display();

        echo '</form></div>';
    }

    public function enqueue_admin_assets( $hook_suffix ) {
        if ( $hook_suffix !== 'toplevel_page_inventory_tracker_list_table' ) {
            return;
        }

        // Plugin styling
        wp_enqueue_style(
            'itfw-css',
            plugin_dir_url( __FILE__ ) . 'assets/css/itfw.css',
            [],
            '1.0.0'
        );

        // Enqueue WordPress-bundled datepicker (for filter options of table)
        wp_enqueue_script( 'jquery-ui-datepicker' );

        // Enqueue jQuery UI theme styles
        wp_enqueue_style(
            'jquery-ui-datepicker-style',
            '//code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css',
            [],
            '1.13.2'
        );

        // Inline init script
        wp_add_inline_script(
            'jquery-ui-datepicker',
            'jQuery(document).ready(function($){ $("#filter_date").datepicker({ dateFormat: "yy-mm-dd" }); });'
        );
    }

    public function woocommerce_required_notice() {
        echo '<div class="notice notice-error"><p>' .
            esc_html__( 'Inventory Tracker requires WooCommerce to be active.', 'inventory-tracker-for-woocommerce' ) .
            '</p></div>';
    }
}
InventoryTrackerForWoocommerce::get_instance();