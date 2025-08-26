<?php 

namespace InventoryTracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class InventoryUpdateHooksATUM {
    private static $old_products = [];

    public static function init() {
        add_filter( 'itfw_order_column', [self::class, 'display_atum_po_in_list_table'], 10, 2);

        add_filter( 'atum/ajax/before_update_product_meta', [self::class, 'before_atum_stock_central_update']);
        add_action( 'atum/ajax/after_update_list_data', [self::class, 'after_atum_stock_central_update']);

        add_filter( 'atum/purchase_orders/can_reduce_order_stock', [self::class, 'before_po_stock_update'], 20, 2);
        add_filter( 'atum/purchase_orders/can_restore_order_stock', [self::class, 'before_po_stock_update'], 20, 2);
        add_action( 'atum/purchase_orders/po/after_increase_stock_levels', [self::class, 'after_po_stock_increase']);
        add_action( 'atum/purchase_orders/po/after_decrease_stock_levels', [self::class, 'after_po_stock_decrease']);  
    }

    // Add link to POs for ATUM in Inventory Tracker Table
    public static function display_atum_po_in_list_table($order_column, $order_id) {
        // Check if its an atum order
        $post_type = get_post_type( $order_id );
        if( $post_type === 'atum_purchase_order' ) {
            return '<a href=' . esc_url( admin_url( 'post.php?action=edit&post=' . $order_id ) ) . '>' . esc_html( $order_id ) . "</a>";
        }
        return $order_column;
    }

    // Store stock information before atum stock central update
    public static function before_atum_stock_central_update($data) {
        foreach ( $data as $product_id => &$product_meta ) {
            $product = wc_get_product($product_id);
            if($product) {
                self::$old_products[$product_id] = $product;
            }
        }
        return $data;
    }

    // Check for any changes to stock after stock central update
    public static function after_atum_stock_central_update($data) {
        $manager = InventoryUpdateManager::get_instance();
        foreach ( $data as $product_id => &$product_meta ) {
            if(!isset(self::$old_products[$product_id])) {
                continue;
            }
            $product = wc_get_product($product_id);
            $inventory_update = InventoryUpdateHooksHelper::get_product_changes($product, self::$old_products[$product_id]);
            if($inventory_update) {
                $inventory_update->reason = 'ATUM Stock Central Edit';
                $inventory_update->notes = sprintf(
                    'Stock for %s was set to %s in ATUM Stock Central',
                    $product->get_sku(),
                    $product_meta['stock']
                );
                $manager->add_inventory_update($inventory_update);
            }
        }
    }

    // Store stock information before a PO updates stock
    public static function before_po_stock_update($restore, $order) {
        $atum_order_items = $order->get_items();
        foreach ( $atum_order_items as $item_id => $atum_order_item ) {
            $product_id = $atum_order_item->get_product()->get_id();
            $product = wc_get_product($product_id);
            if($product) {
                self::$old_products[$product_id] = $product;
            }
        }
        return $restore;
    }

    // Check for stock increases after a PO increase stock
    public static function after_po_stock_increase($order) {
        self::handle_po_update($order, 'PO set to status "%s" which increased %s stock by %d');
    }

    // Check for stock increases after a PO decreases stock
    public static function after_po_stock_decrease($order) {
        self::handle_po_update($order, 'PO set to status "%s" which decreased %s stock by %d');
    }

    public static function handle_po_update($order, $order_note) {
        $manager = InventoryUpdateManager::get_instance();
        $atum_order_items = $order->get_items();
        foreach ( $atum_order_items as $item_id => $atum_order_item ) {
            $product_id = $atum_order_item->get_product()->get_id();
            $product = wc_get_product($product_id);
            if(!$product || !isset(self::$old_products[$product_id])) {
                continue;
            }
            $inventory_update = InventoryUpdateHooksHelper::get_product_changes($product, self::$old_products[$product_id]);
            if($inventory_update) {
                $inventory_update->reason = 'ATUM PO Update';
                $inventory_update->order_id = $order->get_id();
                $inventory_update->notes = sprintf(
                    $order_note,
                    $order->get_status(),
                    $inventory_update->product_sku,
                    $atum_order_item->get_quantity(),
                );
                $manager->add_inventory_update($inventory_update);
            }
        }
    }
}
