<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Admin {
	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Logger $logger;
	private RoboLabs_WC_Jobs $jobs;

	public function __construct( RoboLabs_WC_Settings $settings, RoboLabs_WC_Logger $logger, RoboLabs_WC_Jobs $jobs ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->jobs     = $jobs;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_robolabs_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_robolabs_sync_order', array( $this, 'handle_sync_order' ) );
		add_action( 'admin_post_robolabs_resync_order', array( $this, 'handle_resync_order' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_order_metabox' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'RoboLabs Integration', 'robolabs-woocommerce' ),
			__( 'RoboLabs', 'robolabs-woocommerce' ),
			'manage_woocommerce',
			'robolabs-woocommerce',
			array( $this, 'render_admin_page' )
		);
	}

	public function register_settings(): void {
		$this->settings->register();
	}

	public function render_admin_page(): void {
		$this->settings->render_settings_page();
		?>
		<hr>
		<h2><?php esc_html_e( 'Admin Tools', 'robolabs-woocommerce' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'robolabs_test_connection' ); ?>
			<input type="hidden" name="action" value="robolabs_test_connection">
			<?php submit_button( __( 'Test Connection', 'robolabs-woocommerce' ), 'secondary' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'robolabs_sync_order' ); ?>
			<input type="hidden" name="action" value="robolabs_sync_order">
			<p>
				<label for="robolabs_order_id"><?php esc_html_e( 'Sync order by ID', 'robolabs-woocommerce' ); ?></label>
				<input type="number" name="order_id" id="robolabs_order_id" min="1">
				<?php submit_button( __( 'Sync', 'robolabs-woocommerce' ), 'secondary', 'submit', false ); ?>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'robolabs_resync_order' ); ?>
			<input type="hidden" name="action" value="robolabs_resync_order">
			<p>
				<label for="robolabs_order_resync_id"><?php esc_html_e( 'Resync order by ID', 'robolabs-woocommerce' ); ?></label>
				<input type="number" name="order_id" id="robolabs_order_resync_id" min="1">
				<?php submit_button( __( 'Resync', 'robolabs-woocommerce' ), 'delete', 'submit', false ); ?>
			</p>
		</form>
		<?php
	}

	public function render_notices(): void {
		if ( empty( $_GET['robolabs_notice'] ) ) {
			return;
		}

		if ( 'success' === $_GET['robolabs_notice'] ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'RoboLabs connection successful.', 'robolabs-woocommerce' ) . '</p></div>';
			return;
		}

		if ( 'error' === $_GET['robolabs_notice'] ) {
			$message = isset( $_GET['robolabs_error'] ) ? sanitize_text_field( wp_unslash( $_GET['robolabs_error'] ) ) : esc_html__( 'Connection failed.', 'robolabs-woocommerce' );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'robolabs-woocommerce' ) );
		}
		check_admin_referer( 'robolabs_test_connection' );

		$client = new RoboLabs_WC_Api_Client( $this->settings, $this->logger );
		$response = $client->get( 'journal/find' );
		if ( $response['success'] ) {
			wp_safe_redirect( add_query_arg( 'robolabs_notice', 'success', admin_url( 'admin.php?page=robolabs-woocommerce' ) ) );
			exit;
		}

		$notice = urlencode( $response['error'] ?? 'Connection failed' );
		wp_safe_redirect( add_query_arg( array( 'robolabs_notice' => 'error', 'robolabs_error' => $notice ), admin_url( 'admin.php?page=robolabs-woocommerce' ) ) );
		exit;
	}

	public function handle_sync_order(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'robolabs-woocommerce' ) );
		}
		check_admin_referer( 'robolabs_sync_order' );
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( $order_id ) {
			$this->jobs->enqueue_order( $order_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=robolabs-woocommerce' ) );
		exit;
	}

	public function handle_resync_order(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'robolabs-woocommerce' ) );
		}
		check_admin_referer( 'robolabs_resync_order' );
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( $order_id ) {
			delete_post_meta( $order_id, '_robolabs_invoice_id' );
			delete_post_meta( $order_id, '_robolabs_sync_status' );
			$this->jobs->enqueue_order( $order_id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=robolabs-woocommerce' ) );
		exit;
	}

	public function register_order_metabox(): void {
		add_meta_box(
			'robolabs_wc_meta',
			__( 'RoboLabs', 'robolabs-woocommerce' ),
			array( $this, 'render_order_metabox' ),
			'shop_order',
			'side'
		);
	}

	public function render_order_metabox( WP_Post $post ): void {
		$order = wc_get_order( $post->ID );
		if ( ! $order ) {
			return;
		}
		?>
		<p><strong><?php esc_html_e( 'Invoice ID:', 'robolabs-woocommerce' ); ?></strong> <?php echo esc_html( $order->get_meta( '_robolabs_invoice_id' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Status:', 'robolabs-woocommerce' ); ?></strong> <?php echo esc_html( $order->get_meta( '_robolabs_sync_status' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Last Sync:', 'robolabs-woocommerce' ); ?></strong> <?php echo esc_html( $order->get_meta( '_robolabs_last_sync_at' ) ); ?></p>
		<p><strong><?php esc_html_e( 'Last Error:', 'robolabs-woocommerce' ); ?></strong> <?php echo esc_html( $order->get_meta( '_robolabs_last_error' ) ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'robolabs_resync_order' ); ?>
			<input type="hidden" name="action" value="robolabs_resync_order">
			<input type="hidden" name="order_id" value="<?php echo esc_attr( $order->get_id() ); ?>">
			<?php submit_button( __( 'Resync', 'robolabs-woocommerce' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
