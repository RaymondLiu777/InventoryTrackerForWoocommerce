<?php 

namespace InventoryTracker;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Loading WP_List_Table class file
// We need to load it as it's not automatically loaded by WordPress
if (!class_exists('WP_List_Table')) {
	require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class InventoryTrackerListTable extends \WP_List_Table
{
    // private $table_view;

    function __construct() {
        parent::__construct();
    }

    // Columns for the table
    function get_columns()
    {
        $columns = array(
            'timestamp'         => __('Date', 'inventory-tracker-for-woocommerce'),
            'product_sku'       => __('Product SKU', 'inventory-tracker-for-woocommerce'),
            'product_id'        => __('Product ID', 'inventory-tracker-for-woocommerce'),
            'order_id'          => __('Order ID', 'inventory-tracker-for-woocommerce'),
            'stock_before'      => __('Previous Stock', 'inventory-tracker-for-woocommerce'),
            'stock_change'      => __('Stock Change', 'inventory-tracker-for-woocommerce'),
            'stock_after'       => __('Current Stock', 'inventory-tracker-for-woocommerce'),
            'user'              => __('User', 'inventory-tracker-for-woocommerce'),
            'reason'            => __('Reason', 'inventory-tracker-for-woocommerce'),
            'notes'             => __('Notes', 'inventory-tracker-for-woocommerce'),
        );
        return $columns;
    }

    private function prepare_search_args()
    {
        $search_args = array();

        // Pagination
        $search_args['limit'] = $this->get_items_per_page('elements_per_page', 20);
        $current_page = $this->get_pagenum();
        $search_args['offset'] = ($current_page - 1) * $search_args['limit'];

        // Search
        if(!empty($_REQUEST['s'])) {
            $search_value = sanitize_text_field( wp_unslash($_REQUEST['s']) );
            if($_REQUEST['search-filter'] == "product_sku") {
                $search_args['product_sku'] = $search_value;
            }
            if($_REQUEST['search-filter'] == "product_id") {
                $search_args['product_id'] = absint($search_value) ;
            }
            if($_REQUEST['search-filter'] == "order_id") {
                $search_args['order_id'] = absint($search_value) ;
            }
        }

        // Filtering
        if(!empty($_REQUEST['filter_date'])) {
            $search_args['date'] = sanitize_text_field( wp_unslash($_REQUEST['filter_date']) );
        }
        return $search_args;
    }

	// Set up table
	function prepare_items()
    {
        // Set up Columns
        $columns = $this->get_columns();
        $hidden_user_config = get_user_meta( get_current_user_id(), 'managetoplevel_page_inventory_tracker_list_tablecolumnshidden', true);
        $hidden = ( is_array($hidden_user_config) ) ? $hidden_user_config : array();
        $sortable = array();
        $this->_column_headers = array($columns, $hidden, $sortable);
        
        // Prepare Search Args
        $search_args = $this->prepare_search_args();
        $per_page = $search_args['limit'];

        // Get current inventory 
        $current_inventory = $this->get_current_inventory($search_args);

        // Get inventory updates
        $update_manager = InventoryUpdateManager::get_instance();
        $inventory_updates = $update_manager->get_inventory_updates($search_args);
        $total_items = $inventory_updates['count'];
        $inventory_updates_data = $inventory_updates['data'];

        // Merge all inventory information
        $this->items = array_merge($current_inventory, $inventory_updates_data);

        // Set Pagination Args
        $this->set_pagination_args(array(
            'total_items' => $total_items, // total number of items
            'per_page'    => $per_page, // items to show on a page
            'total_pages' => ceil( $total_items / $per_page ) // use ceil to round up
        ));
    }

	function column_default($item, $column_name)
    {
        if(!isset($item[$column_name])) {
            return "-";
        }

        switch($column_name){
            case 'timestamp':
                $time = new \WC_DateTime($item[$column_name]);
                return nl2br($time->format(wc_date_format() . "\n" . wc_time_format()));
            case 'order_id':
                $order_id = $item[$column_name];
                if(empty($order_id)) {
                    return "-";
                }
                $order = wc_get_order( $order_id );
                $order_column = '<span>' . esc_html( $order_id ). " (Deleted)</span>";
                if( $order ) {
                    $order_column = '<a href=' . esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id ) ) . '>' . esc_html( $order_id ) . "</a>";
                }
                return apply_filters('itfw_order_column', $order_column, $order_id); 

            case 'user':
                $user_id = $item[$column_name];
                if($user_id == 0) {
                    return "Guest";
                }
                $user = get_user_by('id', $user_id);
                if($user) {
                    if (in_array( 'customer', (array) $user->roles, true )) {
                        return 'Customer';
                    } else {
                        return '<a href=' . esc_url( admin_url( 'user-edit.php?user_id=' . $user_id ) ). '>' . esc_html( $user->user_login ) . '</a>';
                    }
                }
                else {
                    return '<span>' . esc_html( $user_id ) . " (Deleted User)</span>"; 
                }

        }
        
		return esc_html( $item[$column_name] );
    }

    function search_box( $text, $input_id ) {
		$input_id = $input_id . '-search-input';
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $text ); ?>:</label>
			<input type="search" id="<?php echo esc_attr( $input_id ); ?>" name="s" value="<?php _admin_search_query(); ?>" />
			<?php $this->search_filter(); ?>
			<?php submit_button( $text, '', '', false, array( 'id' => 'search-submit' ) ); ?>
		</p>
		<?php
	}

    function search_filter()
    {
        $selected = sanitize_text_field( wp_unslash( $_REQUEST['search-filter'] ?? "" ) );
        ?>
        <select name="search-filter" id="order-search-filter">
            <option value="product_sku" <?php selected( 'product_sku', $selected) ?>>Product SKU</option>
            <option value="product_id" <?php selected( 'product_id', $selected) ?>>Product ID</option>
            <option value="order_id" <?php selected( 'order_id', $selected) ?>>Order ID</option>
        </select>
        <?php
    }

    protected function display_filters() {
        $selected_date = isset( $_GET['filter_date'] ) ? esc_attr( $_GET['filter_date'] ) : '';
        ?>
        <div class="alignleft actions">
            <input type="text" name="filter_date" id="filter_date" placeholder="Select a date" value="<?php echo esc_attr( $selected_date ) ?>" />
            <?php submit_button('Filter', 'button', 'filter_action', false); ?>
        </div>
        <?php
    }

    protected function display_tablenav( $which ) {
		?>
        <div class="tablenav <?php echo esc_attr( $which ); ?>">
            <?php
            $this->extra_tablenav( $which );
            $this->pagination( $which );
            ?>
            <br class="clear" />
        </div>
		<?php
	}

    public function extra_tablenav($which) {
        if ($which === 'top') {
            $this->display_filters();
        }
    }

    // Get current inventory of products to display at the top
    function get_current_inventory($search_args) {
        // Only display current inventory when on first page
        if( $this->get_pagenum() !== 1) {
            return array();
        }
        // Only if there is a product sku/id
        if (!isset($search_args['product_sku']) && !isset($search_args['product_id']) ) {
            return array();
        }
        // If there is an order_id or filter dates, do not get current inventory
        if(isset($search_args['order_id'])) {
            return array();
        }
        if(isset($search_args['date'])) {
            return array();
        }

        $products = array();
        if(isset($search_args['product_id'])) {
            $product = wc_get_product($search_args['product_id']);
            if($product) {
                $products[] = $product;
            }
        }
        if(isset($search_args['product_sku'])) {
            $product = wc_get_product( wc_get_product_id_by_sku($search_args['product_sku']) );
            if($product) {
                $products[] = $product;
            }
        }

        // Limit number of products to display
        if( !isset($products) || count($products)  >= 10 ) {
            return array();
        }
        
        $time = new \WC_DateTime();
        $time_str = nl2br($time->format(wc_date_format() . "\n" . wc_time_format()));
        $products_info = array();

        foreach( $products as $product ) {
            $product_info = array(
                'timestamp'         => current_time('mysql'),
                'product_sku'       => $product->get_sku(),
                'product_id'        => $product->get_id(),
            );
            // Set values depending on if stock tracking is enabled
            if($product->managing_stock()) {
                $product_info['stock_after'] = $product->get_stock_quantity();
                $product_info['reason'] = __('Current Stock', 'inventory-tracker-for-woocommerce');
            } else {
                continue;
            }
            
            $products_info[] = $product_info;
        }
        
        return $products_info;
    }
}