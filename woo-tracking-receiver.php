<?php
/**
 * Plugin Name: WooCommerce Tracking Receiver
 * Plugin URI: https://yourwebsite.com/woo-tracking-receiver
 * Description: Receives order tracking data from webhooks and stores in a custom table
 * Version: 1.0.0
 * Author: Chetan Vaghela
 * Author URI: https://yourwebsite.com
 * Text Domain: woo-tracking-receiver
 * Requires at least: 5.0
 * Requires PHP: 7.2
 *
 * @package WooTrackingReceiver
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WOO_TRACKING_RECEIVER_VERSION', '1.0.0' );
define( 'WOO_TRACKING_RECEIVER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WOO_TRACKING_RECEIVER_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_TRACKING_RECEIVER_DB_VERSION', '1.0' );

/**
 * Main plugin class
 */
class WOO_Tracking_Receiver {
	/**
	 * The single instance of the class
	 *
	 * @var WOO_Order_Tracking_Receiver
	 */
	protected static $instance = null;

	/**
	 * Main instance
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		// Create database table on activation.
		register_activation_hook( __FILE__, array( $this, 'wtr_create_database_table' ) );

		// Register REST API endpoint.
		add_action( 'rest_api_init', array( $this, 'wtr_register_api_endpoint' ) );

		// Add admin menu.
		add_action( 'admin_menu', array( $this, 'wtr_add_admin_menu' ) );

		// Add API key management.
		add_action( 'admin_init', array( $this, 'wtr_register_settings' ) );

		// Register shortcode for displaying order tracking on this site.
		add_shortcode( 'woo_tracking_receiver_display', array( $this, 'wtr_tracking_display_shortcode' ) );
	}

	/**
	 * Create custom database table for storing tracking information
	 */
	public function wtr_create_database_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'woo_order_tracking';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            tracking_number varchar(255) NOT NULL,
            status varchar(100) NOT NULL,
            customer_email varchar(255) NOT NULL,
            order_total decimal(10,2) NOT NULL,
            currency varchar(10) NOT NULL,
            date_created datetime NOT NULL,
            order_items longtext NOT NULL,
            date_updated datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY tracking_number (tracking_number),
            KEY customer_email (customer_email)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		add_option( 'woo_tracking_receiver_db_version', WOO_TRACKING_RECEIVER_DB_VERSION );
	}

	/**
	 * Register REST API endpoint
	 */
	public function wtr_register_api_endpoint() {
		register_rest_route(
			'woo-tracking-receiver/v1',
			'/orders',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'wtr_process_webhook_data' ),
				'permission_callback' => array( $this, 'wtr_check_api_permission' ),
			)
		);

		register_rest_route(
			'woo-tracking-receiver/v1',
			'/orders/(?P<order_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'wtr_get_order_data' ),
				'permission_callback' => array( $this, 'wtr_check_api_permission' ),
			)
		);

		register_rest_route(
			'woo-tracking-receiver/v1',
			'/tracking/(?P<tracking_number>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'wtr_get_tracking_data' ),
				'permission_callback' => '__return_true', // Public access for tracking lookups.
			)
		);
	}

	/**
	 * Check API permissions using API key
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 */
	public function wtr_check_api_permission( $request ) {
		$headers = $request->get_headers();

		// Get API key from database.
		$saved_api_key = get_option( 'woo_tracking_receiver_api_key' );

		// If no API key is set, deny access.
		if ( empty( $saved_api_key ) ) {
			return false;
		}

		// Check for API key in header.
		if ( isset( $headers['x_api_key'] ) && $headers['x_api_key'][0] === $saved_api_key ) {
			return true;
		}

		// Check for API key in parameters.
		$params = $request->get_params();
		if ( isset( $params['api_key'] ) && $params['api_key'] === $saved_api_key ) {
			return true;
		}

		return false;
	}

	/**
	 * Process webhook data.
	 */
	public function wtr_process_webhook_data( $request ) {
		// Get request body.
		$data = $request->get_json_params();

		// Validate required fields.
		if ( empty( $data['order_id'] ) || empty( $data['tracking_number'] ) ) {
			return new WP_Error( 'missing_data', 'Order ID and tracking number are required', array( 'status' => 400 ) );
		}

		// Prepare data for database.
		$order_data = array(
			'order_id'        => absint( $data['order_id'] ),
			'tracking_number' => sanitize_text_field( $data['tracking_number'] ),
			'status'          => isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'pending',
			'customer_email'  => isset( $data['customer_email'] ) ? sanitize_email( $data['customer_email'] ) : '',
			'order_total'     => isset( $data['order_total'] ) ? floatval( $data['order_total'] ) : 0,
			'currency'        => isset( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : 'USD',
			'date_created'    => isset( $data['date_created'] ) ? sanitize_text_field( $data['date_created'] ) : current_time( 'mysql' ),
			'order_items'     => isset( $data['items'] ) ? wp_json_encode( $data['items'] ) : '[]',
			'date_updated'    => current_time( 'mysql' ),
		);

		// Insert or update data in database.
		$result = $this->wtr_save_tracking_data( $order_data );

		if ( $result ) {
			return array(
				'success'  => true,
				'message'  => 'Order tracking data saved successfully',
				'order_id' => $order_data['order_id'],
			);
		}

		return new WP_Error( 'database_error', 'Failed to save order tracking data', array( 'status' => 500 ) );
	}

	/**
	 * Save tracking data to database
	 *
	 * @param array $data Order data.
	 */
	private function wtr_save_tracking_data( $data ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woo_order_tracking';

		// Check if order already exists.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table_name WHERE order_id = %d",
				$data['order_id']
			)
		);

		if ( $existing ) {
			// Update existing record.
			$result = $wpdb->update(
				$table_name,
				array(
					'tracking_number' => $data['tracking_number'],
					'status'          => $data['status'],
					'customer_email'  => $data['customer_email'],
					'order_total'     => $data['order_total'],
					'currency'        => $data['currency'],
					'order_items'     => $data['order_items'],
					'date_updated'    => current_time( 'mysql' ),
				),
				array( 'order_id' => $data['order_id'] )
			);
		} else {
			// Insert new record.
			$result = $wpdb->insert( $table_name, $data );
		}

		return false !== $result;
	}

	/**
	 * Get order data by order ID
	 */
	public function wtr_get_order_data( $request ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woo_order_tracking';
		$order_id   = $request['order_id'];

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE order_id = %d",
				$order_id
			),
			ARRAY_A
		);

		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found', array( 'status' => 404 ) );
		}

		// Decode JSON order items.
		$order['order_items'] = json_decode( $order['order_items'], true );

		return $order;
	}

	/**
	 * Get tracking data by tracking number.
	 *
	 * @param WP_REST_Request $request Full data about the request.
	 */
	public function wtr_get_tracking_data( $request ) {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'woo_order_tracking';
		$tracking_number = $request['tracking_number'];

		$order = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE tracking_number = %s",
				$tracking_number
			),
			ARRAY_A
		);

		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Tracking number not found', array( 'status' => 404 ) );
		}

		// Decode JSON order items.
		$order['order_items'] = json_decode( $order['order_items'], true );

		// Remove sensitive information for public tracking endpoint.
		unset( $order['customer_email'] );

		return $order;
	}

	/**
	 * Add admin menu
	 */
	public function wtr_add_admin_menu() {
		add_menu_page(
			__( 'Order Tracking Receiver', 'woo-tracking-receiver' ),
			__( 'Order Tracking', 'woo-tracking-receiver' ),
			'manage_options',
			'woo-tracking-receiver',
			array( $this, 'atr_admin_page' ),
			'dashicons-clipboard',
			30
		);

		add_submenu_page(
			'woo-tracking-receiver',
			__( 'Settings', 'woo-tracking-receiver' ),
			__( 'Settings', 'woo-tracking-receiver' ),
			'manage_options',
			'woo-tracking-receiver-settings',
			array( $this, 'wot_settings_page' )
		);
	}

	/**
	 * Register plugin settings
	 */
	public function wtr_register_settings() {
		register_setting( 'woo_tracking_receiver_settings', 'woo_tracking_receiver_api_key' );
	}

	/**
	 * Admin page
	 */
	public function atr_admin_page() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woo_order_tracking';

		// Handle search.
		$search = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';

		// Get orders with pagination.
		$paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;

		$where = '';
		if ( ! empty( $search ) ) {
			$where = $wpdb->prepare(
				'WHERE order_id = %d OR tracking_number LIKE %s OR customer_email LIKE %s',
				absint( $search ),
				'%' . $wpdb->esc_like( $search ) . '%',
				'%' . $wpdb->esc_like( $search ) . '%'
			);
		}

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name $where" );

		$orders = $wpdb->get_results(
			"SELECT * FROM $table_name $where ORDER BY date_updated DESC LIMIT $offset, $per_page"
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Order Tracking Data', 'woo-tracking-receiver' ); ?></h1>
			
			<form method="get">
				<input type="hidden" name="page" value="woo-tracking-receiver">
				<p class="search-box">
					<label class="screen-reader-text" for="order-search"><?php echo esc_html__( 'Search Orders', 'woo-tracking-receiver' ); ?></label>
					<input type="search" id="order-search" name="search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Search by order ID, tracking number or email', 'woo-tracking-receiver' ); ?>">
					<input type="submit" class="button" value="<?php echo esc_attr__( 'Search Orders', 'woo-tracking-receiver' ); ?>">
				</p>
			</form>
			
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Order ID', 'woo-tracking-receiver' ); ?></th>
						<th><?php echo esc_html__( 'Tracking Number', 'woo-tracking-receiver' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'woo-tracking-receiver' ); ?></th>
						<th><?php echo esc_html__( 'Customer', 'woo-tracking-receiver' ); ?></th>
						<th><?php echo esc_html__( 'Total', 'woo-tracking-receiver' ); ?></th>
						<th><?php echo esc_html__( 'Date Created', 'woo-tracking-receiver' ); ?></th>
						<th><?php echo esc_html__( 'Last Updated', 'woo-tracking-receiver' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $orders ) ) : ?>
						<tr>
							<td colspan="7"><?php echo esc_html__( 'No orders found.', 'woo-tracking-receiver' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $orders as $order ) : ?>
							<tr>
								<td><?php echo esc_html( $order->order_id ); ?></td>
								<td>
									<a href="<?php echo esc_url( home_url( '/wp-json/woo-tracking-receiver/v1/tracking/' . $order->tracking_number ) ); ?>" target="_blank">
										<?php echo esc_html( $order->tracking_number ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $order->status ); ?></td>
								<td><?php echo esc_html( $order->customer_email ); ?></td>
								<td><?php echo esc_html( $order->order_total . ' ' . $order->currency ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $order->date_created ) ) ); ?></td>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $order->date_updated ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			
			<?php
			// Pagination.
			$total_pages = ceil( $total / $per_page );

			if ( $total_pages > 1 ) {
				$current_page = max( 1, $paged );

				echo '<div class="tablenav-pages" style="margin: 1em 0;">';
				echo '<span class="displaying-num">' . sprintf( _n( '%s item', '%s items', $total, 'woo-tracking-receiver' ), number_format_i18n( $total ) ) . '</span>';

				$paginate_links = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $total_pages,
						'current'   => $current_page,
					)
				);

				echo $paginate_links;
				echo '</div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * Settings page
	 */
	public function wot_settings_page() {
		// Generate API key if not exists or requested.
		$api_key = get_option( 'woo_tracking_receiver_api_key' );

		if ( empty( $api_key ) || ( isset( $_POST['regenerate_api_key'] ) && check_admin_referer( 'woo_tracking_receiver_regenerate_key' ) ) ) {
			$api_key = wp_generate_password( 24, false );
			update_option( 'woo_tracking_receiver_api_key', $api_key );
		}

		// Webhook URL to show to users.
		$webhook_url = rest_url( 'woo-tracking-receiver/v1/orders' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Order Tracking Receiver Settings', 'woo-tracking-receiver' ); ?></h1>
			
			<div class="card">
				<h2><?php echo esc_html__( 'Webhook Information', 'woo-tracking-receiver' ); ?></h2>
				
				<p><?php echo esc_html__( 'Use the following URL in your WooCommerce Order Tracking plugin:', 'woo-tracking-receiver' ); ?></p>
				<code style="display: block; padding: 10px; background: #f5f5f5; margin-bottom: 15px;">
					<?php echo esc_html( $webhook_url ); ?>
				</code>
				
				<p><?php echo esc_html__( 'Include your API key as an X-API-Key header or as an api_key parameter in the request.', 'woo-tracking-receiver' ); ?></p>
			</div>
			
			<div class="card" style="margin-top: 15px;">
				<h2><?php echo esc_html__( 'API Key', 'woo-tracking-receiver' ); ?></h2>
				
				<p>
					<strong><?php echo esc_html__( 'Your API Key:', 'woo-tracking-receiver' ); ?></strong>
					<code style="background: #f5f5f5; padding: 5px;"><?php echo esc_html( $api_key ); ?></code>
				</p>
				
				<form method="post">
					<?php wp_nonce_field( 'woo_tracking_receiver_regenerate_key' ); ?>
					<p class="submit">
						<input type="submit" name="regenerate_api_key" class="button button-secondary" value="<?php echo esc_attr__( 'Regenerate API Key', 'woo-tracking-receiver' ); ?>">
					</p>
					<p class="description">
						<?php echo esc_html__( 'Warning: Regenerating the API key will require updating it in your WooCommerce Order Tracking plugin.', 'woo-tracking-receiver' ); ?>
					</p>
				</form>
			</div>
			
			<div class="card" style="margin-top: 15px;">
				<h2><?php echo esc_html__( 'Tracking Display', 'woo-tracking-receiver' ); ?></h2>
				
				<p><?php echo esc_html__( 'Use this shortcode to add a tracking form to any page:', 'woo-tracking-receiver' ); ?></p>
				<code>[woo_tracking_receiver_display]</code>
			</div>
		</div>
		<?php
	}

	/**
	 * Shortcode for displaying tracking information
	 *
	 * @param array $atts Shortcode attributes
	 */
	public function wtr_tracking_display_shortcode( $atts ) {
		// Enqueue styles.
		wp_enqueue_style( 'woo-tracking-receiver', WOO_TRACKING_RECEIVER_URL . 'assets/css/tracking.css', array(), WOO_TRACKING_RECEIVER_VERSION );

		// Get tracking number from URL.
		$tracking_number = isset( $_GET['tracking_number'] ) ? sanitize_text_field( $_GET['tracking_number'] ) : '';

		ob_start();
		?>
		<div class="woo-tracking-receiver-container">
			<form class="woo-tracking-form" method="get">
				<h2><?php esc_html_e( 'Track Your Order', 'woo-tracking-receiver' ); ?></h2>
				<p><?php esc_html_e( 'Enter your tracking number below to track your order.', 'woo-tracking-receiver' ); ?></p>
				
				<div class="form-row">
					<label for="tracking_number"><?php esc_html_e( 'Tracking Number', 'woo-tracking-receiver' ); ?></label>
					<input type="text" name="tracking_number" id="tracking_number" 
							value="<?php echo esc_attr( $tracking_number ); ?>" required>
				</div>
				
				<div class="form-row">
					<button type="submit" class="button"><?php esc_html_e( 'Track', 'woo-tracking-receiver' ); ?></button>
				</div>
			</form>
			
			<?php if ( ! empty( $tracking_number ) ) : ?>
				<?php
				global $wpdb;
				$table_name = $wpdb->prefix . 'woo_order_tracking';

				$order = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM $table_name WHERE tracking_number = %s",
						$tracking_number
					)
				);

				if ( $order ) :
					// Decode order items
					$order_items = json_decode( $order->order_items, true );
					?>
				<div class="woo-tracking-results">
					<h3><?php esc_html_e( 'Order Information', 'woo-tracking-receiver' ); ?></h3>
					
					<table class="woo-tracking-table">
						<tr>
							<th><?php esc_html_e( 'Order Number', 'woo-tracking-receiver' ); ?></th>
							<td><?php echo esc_html( $order->order_id ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Tracking Number', 'woo-tracking-receiver' ); ?></th>
							<td><?php echo esc_html( $order->tracking_number ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Order Date', 'woo-tracking-receiver' ); ?></th>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $order->date_created ) ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'woo-tracking-receiver' ); ?></th>
							<td><?php echo esc_html( ucfirst( $order->status ) ); ?></td>
						</tr>
					</table>
					
					<h3><?php esc_html_e( 'Order Progress', 'woo-tracking-receiver' ); ?></h3>
					
					<div class="tracking-progress">
						<?php
						// Create a tracking progress bar based on order status.
						$status   = $order->status;
						$statuses = array(
							'pending'    => 1,
							'processing' => 2,
							'shipped'    => 3,
							'completed'  => 4,
						);

						$current_status = isset( $statuses[ $status ] ) ? $statuses[ $status ] : 0;
						?>
						
						<div class="progress-bar">
							<div class="progress-step <?php echo $current_status >= 1 ? 'active' : ''; ?>">
								<div class="step-icon">1</div>
								<div class="step-label"><?php esc_html_e( 'Order Received', 'woo-tracking-receiver' ); ?></div>
							</div>
							<div class="progress-step <?php echo $current_status >= 2 ? 'active' : ''; ?>">
								<div class="step-icon">2</div>
								<div class="step-label"><?php esc_html_e( 'Processing', 'woo-tracking-receiver' ); ?></div>
							</div>
							<div class="progress-step <?php echo $current_status >= 3 ? 'active' : ''; ?>">
								<div class="step-icon">3</div>
								<div class="step-label"><?php esc_html_e( 'Shipped', 'woo-tracking-receiver' ); ?></div>
							</div>
							<div class="progress-step <?php echo $current_status >= 4 ? 'active' : ''; ?>">
								<div class="step-icon">4</div>
								<div class="step-label"><?php esc_html_e( 'Delivered', 'woo-tracking-receiver' ); ?></div>
							</div>
						</div>
					</div>
					
					<?php if ( ! empty( $order_items ) ) : ?>
						<h3><?php esc_html_e( 'Order Items', 'woo-tracking-receiver' ); ?></h3>
						<table class="woo-tracking-items">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'woo-tracking-receiver' ); ?></th>
									<th><?php esc_html_e( 'Quantity', 'woo-tracking-receiver' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $order_items as $item ) : ?>
									<tr>
										<td><?php echo esc_html( $item['name'] ); ?></td>
										<td><?php echo esc_html( $item['quantity'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
				<?php else : ?>
					<div class="woo-tracking-not-found">
						<p><?php esc_html_e( 'No order found with the provided tracking number. Please check and try again.', 'woo-tracking-receiver' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php

		return ob_get_clean();
	}
}

/**
 * Initialize the plugin.
 *
 * @return WOO_Tracking_Receiver
 */
function woo_tracking_receiver() {
	return WOO_Tracking_Receiver::instance();
}

// Start the plugin.
woo_tracking_receiver();