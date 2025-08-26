<?php
// includes/inventory-update.php

namespace InventoryTracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class InventoryUpdate {
    public $id;
    public $timestamp;
    public $product_sku;
    public $product_id;
    public $user;
    public $stock_before;
    public $stock_after;
    public $stock_change;
    public $reason;
    public $notes;
    public $order_id;

    public function __construct($params = []) {
        $this->id           = isset($params['id']) ? (int) $params['id'] : null;
        $this->timestamp    = isset($params['timestamp']) ? sanitize_text_field($params['timestamp']) : current_time('mysql');
        $this->product_sku  = isset($params['product_sku']) ? sanitize_text_field($params['product_sku']) : null;
        $this->product_id   = isset($params['product_id']) ? (int) $params['product_id'] : null;
        $this->user         = isset($params['user']) ? (int) $params['user'] : wp_get_current_user()->ID;
        $this->stock_before = isset($params['stock_before']) ? (int) $params['stock_before'] : null;
        $this->stock_after  = isset($params['stock_after']) ? (int) $params['stock_after'] : null;
        $this->stock_change = isset($params['stock_change']) ? (int) $params['stock_change'] : null;
        $this->reason       = isset($params['reason']) ? sanitize_text_field($params['reason']) : null;
        $this->notes        = isset($params['notes']) ? sanitize_textarea_field($params['notes']) : null;
        $this->order_id     = isset($params['order_id']) ? (int) $params['order_id'] : null;
    }
}
