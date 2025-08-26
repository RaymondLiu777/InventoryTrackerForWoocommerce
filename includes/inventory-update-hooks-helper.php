<?php 

namespace InventoryTracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class InventoryUpdateHooksHelper {
    /**
     * Compare 2 product objects and return their differences
     * 
     * @param   WC_Product          $product                Updated Product
     * @param   WC_Product          $original_product       Original Product
     * 
     * @return  InventoryUpdate|false     $inventory_update       Inventory Update containing any changes to inventory, returns False if there is no change
     * 
     */
    public static function get_product_changes($product, $original_product) {
        // If both products do not manage stock, ignore
        if(!$product->get_manage_stock() && !$original_product->get_manage_stock()) {
            return false;
        }
        // Stock tracking was disabled
        if(!$product->get_manage_stock() && $original_product->get_manage_stock()) {
            $inventory_update = new InventoryUpdate(array(
                'product_sku' => $product->get_sku(),
                'product_id' => $product->get_id(),
                'stock_before' => $original_product->get_stock_quantity(),
                'notes' => sprintf(
                    'Stock for %s was disabled',
                    $product->get_sku(),
                )
            ));
            return $inventory_update;
        }
        // Stock tracking is enabled
        else if ($product->get_manage_stock() && !$original_product->get_manage_stock()) {
            $inventory_update = new InventoryUpdate(array(
                'product_sku' => $product->get_sku(),
                'product_id' => $product->get_id(),
                'stock_after' => $product->get_stock_quantity(),
                'notes' => sprintf(
                    'Stock for %s was enabled',
                    $product->get_sku(),
                )
            ));
            return $inventory_update;
        }
        // Stock tracking is active for both original and current product
        else {
            $original_qty = (int) $original_product->get_stock_quantity();
            $new_qty = (int) $product->get_stock_quantity();
            if ($original_qty === $new_qty) {
                // No change to stock quantity
                return false;
            }
            $inventory_update = new InventoryUpdate(array(
                'product_sku' => $product->get_sku(),
                'product_id' => $product->get_id(),
                'stock_before' => $original_qty,
                'stock_change' => $new_qty - $original_qty,
                'stock_after' => $new_qty,
                'notes' => sprintf(
                    'Updated stock for %s to %d',
                    $product->get_sku(),
                    $new_qty,
                )
            ));
            return $inventory_update;
        }
    }
}