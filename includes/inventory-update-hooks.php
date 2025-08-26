<?php 

namespace InventoryTracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class InventoryUpdateHooks {
    private static $old_products = [];

    public static function init() {
        add_action('woocommerce_admin_process_product_object', [self::class,'check_for_inventory_update'], 20);
        add_action('woocommerce_admin_process_variation_object', [self::class,'check_for_inventory_update'], 20, 1);

        add_action('woocommerce_product_bulk_and_quick_edit', [self::class,'save_product_before_edit'], 1, 1);
        add_action('woocommerce_product_quick_edit_save', [self::class, 'check_for_product_changes_quick_edit']);
        add_action('woocommerce_product_bulk_edit_save', [self::class, 'check_for_product_changes_bulk_edit']);

        add_action( 'woocommerce_reduce_order_item_stock', [self::class, 'stock_reduction_due_to_order'], 10, 3);
        add_action( 'woocommerce_restore_order_item_stock', [self::class, 'stock_restore_due_to_order'], 10, 4);

        add_action( 'woocommerce_ajax_order_items_removed', [self::class, 'line_item_removed'], 10, 4);

        add_action( 'woocommerce_restock_refunded_item', [self::class, 'refund_restock_inventory'], 10, 5 );
    }

    /**
     * When a product is updated through the product editor, check for any changes in inventory
     *
     * @param \WC_Order_Item_Product $product
     */
    public static function check_for_inventory_update($product) {  
        $product_id = $product->get_id();
        $original_product = wc_get_product( $product_id );

        $inventory_update = InventoryUpdateHooksHelper::get_product_changes($product, $original_product);
        $manager = InventoryUpdateManager::get_instance();

        if($inventory_update) {
            $inventory_update->reason = 'Admin Product Editor';
            $manager->add_inventory_update($inventory_update);
        }
    }

    /**
     * Store a copy of the product before a bulk/quick edit 
     *
     * @param int $product_id
     */
    public static function save_product_before_edit($product_id) {
        $product = wc_get_product($product_id);
        if($product) {
            self::$old_products[$product_id] = $product;
        }
    }

    /**
     * Check for changes in stock after a quick edit
     *
     * @param \WC_Order_Item_Product $product
     */
    public static function check_for_product_changes_quick_edit($product) {
        $product_id = $product->get_id();
        if(!isset(self::$old_products[$product_id])) {
            return;
        }
        $inventory_update = InventoryUpdateHooksHelper::get_product_changes($product, self::$old_products[$product_id]);
        $manager = InventoryUpdateManager::get_instance();
        if($inventory_update) {
            $inventory_update->reason = 'Admin Quick Edit';
            $manager->add_inventory_update($inventory_update);
        }
    }

    /**
     * Check for changes in stock after a bulk edit
     *
     * @param \WC_Order_Item_Product $product
     */
    public static function check_for_product_changes_bulk_edit($product) {
        $product_id = $product->get_id();
        if(!isset(self::$old_products[$product_id])) {
            return;
        }
        $inventory_update = InventoryUpdateHooksHelper::get_product_changes($product, self::$old_products[$product_id]);
        $manager = InventoryUpdateManager::get_instance();
        if($inventory_update) {
            $inventory_update->reason = 'Admin Bulk Edit';
            $manager->add_inventory_update($inventory_update);
        }
    }
    

    /**
     * Track stock change when an order status is updated to a status where stock is reduced
     * Typically this is when an order goes from pending to processing
     *
     * @param \WC_Order_Item_Product $item
     * @param array                  $change {
     *     @type int $from Old stock.
     *     @type int $to   New stock.
     *     @type \WC_Product $product Product object.
     * }
     * @param \WC_Order              $order
     */
    public static function stock_reduction_due_to_order($item, $change, $order) {
        $notes = sprintf(
            '%s updated order to "%s" status reducing stock for %s by %d',
            (is_admin() ? "Admin" : "Customer"),
            $order->get_status(),
            $change['product']->get_sku(),
            $item->get_quantity()
        );
        $inventory_update = new InventoryUpdate(array(
            'product_sku' => $change['product']->get_sku(),
            'product_id' => $change['product']->get_id(),
            'stock_before' => $change['from'],
            'stock_change' => $change['to'] - $change['from'],
            'stock_after' => $change['to'],
            'order_id' => $order->get_id(),
            'reason' => 'Order stock reduced',
            'notes' => $notes
        ));
        $manager = InventoryUpdateManager::get_instance();
        $manager->add_inventory_update($inventory_update);
    }

    /**
     * Track stock change when an order status is updated to a status where stock is increased
     * This typically occurs when a processing order is put back to pending or cancelled
     *
     * @param \WC_Order_Item_Product $item
     * @param array                  $change {
     *     @type int $from Old stock.
     *     @type int $to   New stock.
     *     @type \WC_Product $product Product object.
     * }
     * @param \WC_Order              $order
     */
    public static function stock_restore_due_to_order($item, $new_stock, $old_stock, $order) {
        $product = $item->get_product();
        $notes = sprintf(
            '%s updated order to "%s" status increasing stock for %s by %d',
            (is_admin() ? "Admin" : "Customer"),
            $order->get_status(),
            $product->get_sku(),
            $item->get_quantity()
        );
        $inventory_update = new InventoryUpdate(array(
            'product_sku' => $product->get_sku(),
            'product_id' => $product->get_id(),
            'stock_before' => $old_stock,
            'stock_change' => $new_stock - $old_stock,
            'stock_after' => $new_stock,
            'order_id' => $order->get_id(),
            'reason' => 'Order stock restored',
            'notes' => $notes
        ));
        $manager = InventoryUpdateManager::get_instance();
        $manager->add_inventory_update($inventory_update);
    }
    
    /**
     * Check for stock changes when a line item is deleted
     * Occurs if a line item whose stock was already reduce is deleted off an order
     *
     * @param int                       $item_id
     * @param \WC_Order_Item_Product    $item 
     * @param array                     $change {
     *     @type int $from Old stock.
     *     @type int $to   New stock.
     *     @type \WC_Product $product Product object.
     * }
     * @param \WC_Order                 $order
     */
    public static function line_item_removed($item_id, $item, $changed_stock, $order) {
        if($item && $changed_stock) {
            $product = $changed_stock['product'];
            $notes = sprintf(
                'Admin deleted line item for %s with quantity %d',
                $product->get_sku(),
                $item->get_quantity()
            );
            $inventory_update = new InventoryUpdate(array(
                'product_sku' => $product->get_sku(),
                'product_id' => $product->get_id(),
                'stock_before' => $changed_stock['from'],
                'stock_change' => $changed_stock['to'] - $changed_stock['from'],
                'stock_after' => $changed_stock['to'],
                'order_id' => $order->get_id(),
                'reason' => 'Order Line Item Deleted',
                'notes' => $notes
            ));
            $manager = InventoryUpdateManager::get_instance();
            $manager->add_inventory_update($inventory_update);
        }
    }

    /**
     * Check for stock changes when a line item is updated
     * This function is not use by this plugin but can be called by other plugins when updating a line item's stock
     *
     * @param int                       $item_id
     * @param \WC_Order_Item_Product    $item 
     * @param array                     $change {
     *     @type int $from Old stock.
     *     @type int $to   New stock.
     *     @type \WC_Product $product Product object.
     * }
     * @param \WC_Order                 $order
     */
    public static function line_item_updated($item_id, $item, $changed_stock, $order) {
        if($item && $changed_stock) {
            $product = $item->get_product();
            $stock_change = $changed_stock['to'] - $changed_stock['from'];
            $line_item_qty = $item->get_quantity();
            $notes = sprintf(
                'Admin updated line item for %s from %d to %d',
                $product->get_sku(),
                ($line_item_qty + $stock_change),
                $line_item_qty
            );
            $inventory_update = new InventoryUpdate(array(
                'product_sku' => $product->get_sku(),
                'product_id' => $product->get_id(),
                'stock_before' => $changed_stock['from'],
                'stock_change' => $stock_change,
                'stock_after' => $changed_stock['to'],
                'order_id' => $order->get_id(),
                'reason' => 'Order Line Item Updated',
                'notes' => $notes
            ));
            $manager = InventoryUpdateManager::get_instance();
            $manager->add_inventory_update($inventory_update);
        }
    }

    public static function refund_restock_inventory($product_id, $old_stock, $new_stock, $order, $product) {
        if($old_stock === $new_stock) {
            return;
        }
        $notes = sprintf(
            'Order was refunded restoring %d stock to product %s',
            $new_stock - $old_stock,
            $product->get_sku()
        );
        $inventory_update = new InventoryUpdate(array(
            'product_sku' => $product->get_sku(),
            'product_id' => $product->get_id(),
            'stock_before' => $old_stock,
            'stock_change' => $new_stock - $old_stock,
            'stock_after' => $new_stock,
            'order_id' => $order->get_id(),
            'reason' => 'Order Refund',
            'notes' => $notes
        ));
        $manager = InventoryUpdateManager::get_instance();
        $manager->add_inventory_update($inventory_update);
    }
}


