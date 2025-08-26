<?php
// includes/inventory-update-manager.php

namespace InventoryTracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class InventoryUpdateManager {
    private $table_name;
    

    // Singleton Class
    private static $obj;
    private function __construct() {
        global $wpdb;

        $this->table_name = $wpdb->prefix . 'itfw_inventory_updates';
    }

	private function __clone() { }
	final public function __wakeup() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'inventory-tracker-for-woocommerce' ), '1.0' );
		die();
	}

    public static function get_instance() {
        if(!isset(self::$obj)) {
            self::$obj = new InventoryUpdateManager();
        }
        return self::$obj;
    }

    // DB table setup 
    public function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            product_sku varchar(100),
            product_id bigint(20) UNSIGNED NOT NULL,
            order_id bigint(20) UNSIGNED,
            user bigint(20) UNSIGNED,
            stock_before int,
            stock_after int,
            stock_change int,
            reason varchar(255),
            notes text,
            PRIMARY KEY  (id),
            KEY product_id (product_id),
            KEY product_sku (product_sku),
            KEY order_id (order_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Store inventory update object in the database
     * 
     * @param InventoryUpdate   $inventory_update    Inventory Update Object to Save
     * 
     */
    public function add_inventory_update($inventory_update) {
        global $wpdb;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'timestamp' => $inventory_update->timestamp,
                'product_sku' => $inventory_update->product_sku,
                'product_id' => $inventory_update->product_id,
                'order_id'  => $inventory_update->order_id,
                'user'  => $inventory_update->user,
                'stock_before'  => $inventory_update->stock_before,
                'stock_after'  => $inventory_update->stock_after,
                'stock_change'  => $inventory_update->stock_change,
                'reason'  => $inventory_update->reason,
                'notes'  => $inventory_update->notes,
            ),
            array(
                '%s','%s','%d','%d','%d','%d','%d','%d','%s','%s' // format specifiers
            )
        );

        if ($result === false) {
            // Log or throw error
            error_log("Failed to insert inventory update: " . $wpdb->last_error);
        }

        return $result ? $wpdb->insert_id : false;
    }

    // 
    private function construct_where_clause($params = array()) {
        global $wpdb;
        
        $where_clauses = [];
        $query_params = [];

        // Find specific product id or sku
        if (isset($params['product_id'])) {
            $where_clauses[] = 'product_id = %d';
            $query_params[] = $params['product_id'];
        }
        if (isset($params['product_sku'])) {
            $where_clauses[] = 'product_sku LIKE %s';
            $query_params[] = '%' . $wpdb->esc_like($params['product_sku']) . '%';
        }
        if (isset($params['date'])) {
            $where_clauses[] = 'DATE(`timestamp`) <= %s';
            $query_params[] = $params['date'];
        }
        $where_sql = '';
        if (!empty($where_clauses)) {
            $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
        }

        return array(
            "sql"         => $where_sql,
            "query_params"  => $query_params,
        );
    }

    public function get_inventory_updates($params = array()) {
        global $wpdb;

        $where_query = $this->construct_where_clause($params);
        $where_sql = $where_query['sql'];
        $query_params = $where_query['query_params'];

        // Pagemation args
        $limit  = isset($params['limit']) ? (int) $params['limit'] : 20;
        $offset = isset($params['offset']) ? (int) $params['offset'] : 0;

        // First get count
        $query = "SELECT COUNT(*) FROM {$this->table_name} {$where_sql}";
        $prepared_query = $wpdb->prepare($query, ...$query_params);
        $count = $wpdb->get_var( $prepared_query );


        // Regular query with pagemation
        $query = "SELECT * FROM {$this->table_name} {$where_sql} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
        $query_params[] = $limit;
        $query_params[] = $offset;

        // Submit query
        $prepared_query = $wpdb->prepare($query, ...$query_params);
        $results = $wpdb->get_results($prepared_query, ARRAY_A) ?: [];
        return array(
            'count' => $count,
            'data' => $results
        );
    }

}
