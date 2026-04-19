<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Admin {
	private const LOG_SOURCE = 'robolabs-woocommerce';

	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Logger $logger;
	private RoboLabs_WC_Jobs $jobs;
	private RoboLabs_WC_Sync_Order $sync_order;

	public function __construct( RoboLabs_WC_Settings $settings, RoboLabs_WC_Logger $logger, RoboLabs_WC_Jobs $jobs, RoboLabs_WC_Sync_Order $sync_order ) {
		$this->settings   = $settings;
		$this->logger     = $logger;
		$this->jobs       = $jobs;
		$this->sync_order = $sync_order;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_robolabs_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_robolabs_sync_order', array( $this, 'handle_sync_order' ) );
		add_action( 'admin_post_robolabs_resync_order', array( $this, 'handle_resync_order' ) );
		add_action( 'admin_post_robolabs_clear_logs', array( $this, 'handle_clear_logs' ) );
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
		$metrics        = $this->get_dashboard_metrics();
		$recent_orders  = $this->get_recent_sync_orders();
		$log_files      = $this->get_log_file_paths();
		$recent_logs    = $this->get_recent_log_entries( 14 );
		$api_ready      = '' !== $this->settings->get_api_key();
		$mode           = (string) $this->settings->get( 'base_url_mode', 'sandbox' );
		$mode_label     = 'custom' === $mode ? __( 'Custom API', 'robolabs-woocommerce' ) : ucfirst( $mode );
		$latest_sync_at = $metrics['latest_sync_at'] ? $this->format_datetime( $metrics['latest_sync_at'] ) : __( 'No sync yet', 'robolabs-woocommerce' );
		?>
		<div class="wrap robolabs-dashboard">
			<?php $this->render_admin_styles(); ?>

			<h1 class="robolabs-page-title"><?php esc_html_e( 'RoboLabs Dashboard', 'robolabs-woocommerce' ); ?></h1>

			<section class="robolabs-hero">
				<div>
					<p class="robolabs-eyebrow"><?php esc_html_e( 'WooCommerce invoice monitor', 'robolabs-woocommerce' ); ?></p>
					<h2><?php esc_html_e( 'RoboLabs Command Center', 'robolabs-woocommerce' ); ?></h2>
					<p class="robolabs-hero-copy"><?php esc_html_e( 'Track invoice sync health, review failures, inspect logs, and run admin actions from one focused dashboard.', 'robolabs-woocommerce' ); ?></p>
					<div class="robolabs-hero-actions">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'robolabs_test_connection' ); ?>
							<input type="hidden" name="action" value="robolabs_test_connection">
							<button type="submit" class="button robolabs-button robolabs-button-primary"><?php esc_html_e( 'Test Connection', 'robolabs-woocommerce' ); ?></button>
						</form>
						<a class="button robolabs-button robolabs-button-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=' . self::LOG_SOURCE ) ); ?>"><?php esc_html_e( 'Open Woo Logs', 'robolabs-woocommerce' ); ?></a>
					</div>
				</div>
				<div class="robolabs-system-card" aria-label="<?php esc_attr_e( 'RoboLabs system status', 'robolabs-woocommerce' ); ?>">
					<div class="robolabs-status-line">
						<span class="robolabs-status-dot <?php echo esc_attr( $api_ready ? 'is-ready' : 'is-warning' ); ?>"></span>
						<span><?php echo esc_html( $api_ready ? __( 'API key configured', 'robolabs-woocommerce' ) : __( 'API key missing', 'robolabs-woocommerce' ) ); ?></span>
					</div>
					<div class="robolabs-system-row">
						<span><?php esc_html_e( 'Mode', 'robolabs-woocommerce' ); ?></span>
						<strong><?php echo esc_html( $mode_label ); ?></strong>
					</div>
					<div class="robolabs-system-row">
						<span><?php esc_html_e( 'Logging', 'robolabs-woocommerce' ); ?></span>
						<strong><?php echo esc_html( $this->settings->is_logging_enabled() ? __( 'Enabled', 'robolabs-woocommerce' ) : __( 'Disabled', 'robolabs-woocommerce' ) ); ?></strong>
					</div>
					<div class="robolabs-system-row">
						<span><?php esc_html_e( 'Last sync', 'robolabs-woocommerce' ); ?></span>
						<strong><?php echo esc_html( $latest_sync_at ); ?></strong>
					</div>
				</div>
			</section>

			<section class="robolabs-metrics" aria-label="<?php esc_attr_e( 'RoboLabs sync metrics', 'robolabs-woocommerce' ); ?>">
				<?php
				$this->render_metric_card( __( 'Invoices Created', 'robolabs-woocommerce' ), $metrics['invoices_created'], __( 'Orders with RoboLabs invoice IDs', 'robolabs-woocommerce' ), 'success' );
				$this->render_metric_card( __( 'Failed Syncs', 'robolabs-woocommerce' ), $metrics['failed'], __( 'Needs retry or configuration fix', 'robolabs-woocommerce' ), 'danger' );
				$this->render_metric_card( __( 'Pending Jobs', 'robolabs-woocommerce' ), $metrics['pending'], __( 'Waiting for RoboLabs background job', 'robolabs-woocommerce' ), 'warning' );
				$this->render_metric_card( __( 'Manual Review', 'robolabs-woocommerce' ), $metrics['manual_required'], __( 'Refunds or syncs needing attention', 'robolabs-woocommerce' ), 'neutral' );
				$this->render_metric_card( __( 'Log Files', 'robolabs-woocommerce' ), count( $log_files ), __( 'RoboLabs WooCommerce log files found', 'robolabs-woocommerce' ), 'info' );
				?>
			</section>

			<div class="robolabs-layout">
				<section class="robolabs-panel robolabs-panel-large">
					<div class="robolabs-panel-header">
						<div>
							<p class="robolabs-eyebrow"><?php esc_html_e( 'Recent syncs', 'robolabs-woocommerce' ); ?></p>
							<h2><?php esc_html_e( 'Invoice Activity', 'robolabs-woocommerce' ); ?></h2>
						</div>
						<span class="robolabs-count-pill"><?php echo esc_html( sprintf( _n( '%d monitored order', '%d monitored orders', $metrics['monitored'], 'robolabs-woocommerce' ), $metrics['monitored'] ) ); ?></span>
					</div>
					<?php $this->render_recent_activity_table( $recent_orders ); ?>
				</section>

				<section class="robolabs-panel">
					<div class="robolabs-panel-header">
						<div>
							<p class="robolabs-eyebrow"><?php esc_html_e( 'Admin tools', 'robolabs-woocommerce' ); ?></p>
							<h2><?php esc_html_e( 'Control Desk', 'robolabs-woocommerce' ); ?></h2>
						</div>
					</div>
					<?php $this->render_admin_tools(); ?>
				</section>
			</div>

			<section class="robolabs-panel robolabs-logs-panel">
				<div class="robolabs-panel-header">
					<div>
						<p class="robolabs-eyebrow"><?php esc_html_e( 'Monitor logs', 'robolabs-woocommerce' ); ?></p>
						<h2><?php esc_html_e( 'RoboLabs Log Stream', 'robolabs-woocommerce' ); ?></h2>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'robolabs_clear_logs' ); ?>
						<input type="hidden" name="action" value="robolabs_clear_logs">
						<button type="submit" class="button robolabs-button robolabs-button-danger" <?php disabled( empty( $log_files ) ); ?>><?php esc_html_e( 'Clear Logs', 'robolabs-woocommerce' ); ?></button>
					</form>
				</div>
				<?php $this->render_log_stream( $recent_logs ); ?>
			</section>

			<section class="robolabs-panel robolabs-settings-panel">
				<div class="robolabs-panel-header">
					<div>
						<p class="robolabs-eyebrow"><?php esc_html_e( 'Configuration', 'robolabs-woocommerce' ); ?></p>
						<h2><?php esc_html_e( 'Connection Settings', 'robolabs-woocommerce' ); ?></h2>
					</div>
				</div>
				<?php $this->settings->render_settings_page( false ); ?>
			</section>
		</div>
		<?php
	}

	public function render_notices(): void {
		if ( empty( $_GET['robolabs_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['robolabs_notice'] ) );

		if ( 'success' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'RoboLabs connection successful.', 'robolabs-woocommerce' ) . '</p></div>';
			return;
		}

		if ( 'logs_cleared' === $notice ) {
			$deleted = isset( $_GET['robolabs_deleted'] ) ? absint( wp_unslash( $_GET['robolabs_deleted'] ) ) : 0;
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( _n( '%d RoboLabs log file cleared.', '%d RoboLabs log files cleared.', $deleted, 'robolabs-woocommerce' ), $deleted ) ) . '</p></div>';
			return;
		}

		if ( 'error' === $notice ) {
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
		$endpoint = 'aClassCode/find';
		$response = $client->get(
			$endpoint,
			array(
				'limit'  => 1,
				'offset' => 0,
			)
		);
		$this->logger->info(
			'RoboLabs test connection executed',
			array(
				'endpoint' => $endpoint,
				'base_url' => $this->settings->get_base_url(),
				'code'     => $response['code'] ?? null,
				'error'    => $response['error'] ?? null,
				'data'     => $response['data'] ?? null,
			)
		);
		if ( $response['success'] ) {
			wp_safe_redirect( add_query_arg( 'robolabs_notice', 'success', admin_url( 'admin.php?page=robolabs-woocommerce' ) ) );
			exit;
		}

		$details = $response['error'] ?? 'Connection failed';
		if ( isset( $response['code'] ) ) {
			$details = sprintf( 'HTTP %d: %s', (int) $response['code'], $details );
		}
		wp_safe_redirect( add_query_arg( array( 'robolabs_notice' => 'error', 'robolabs_error' => $details ), admin_url( 'admin.php?page=robolabs-woocommerce' ) ) );
		exit;
	}

	public function handle_sync_order(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'robolabs-woocommerce' ) );
		}
		check_admin_referer( 'robolabs_sync_order' );
		$order_id = absint( $_POST['order_id'] ?? 0 );
		if ( $order_id ) {
			$this->run_order_sync_now( $order_id, false );
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
			$this->run_order_sync_now( $order_id, true );
		}
		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=robolabs-woocommerce' );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_clear_logs(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'robolabs-woocommerce' ) );
		}
		check_admin_referer( 'robolabs_clear_logs' );

		$deleted = $this->clear_robolabs_log_files();
		wp_safe_redirect(
			add_query_arg(
				array(
					'robolabs_notice'  => 'logs_cleared',
					'robolabs_deleted' => $deleted,
				),
				admin_url( 'admin.php?page=robolabs-woocommerce' )
			)
		);
		exit;
	}

	public function register_order_metabox(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'robolabs_wc_meta',
			__( 'RoboLabs', 'robolabs-woocommerce' ),
			array( $this, 'render_order_metabox' ),
			$screen,
			'side'
		);
	}

	public function render_order_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
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

	private function render_metric_card( string $label, int $value, string $caption, string $tone ): void {
		?>
		<article class="robolabs-metric is-<?php echo esc_attr( $tone ); ?>">
			<span class="robolabs-metric-label"><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( $value ) ); ?></strong>
			<span class="robolabs-metric-caption"><?php echo esc_html( $caption ); ?></span>
		</article>
		<?php
	}

	private function render_recent_activity_table( array $orders ): void {
		if ( empty( $orders ) ) {
			?>
			<div class="robolabs-empty-state">
				<strong><?php esc_html_e( 'No invoice activity yet.', 'robolabs-woocommerce' ); ?></strong>
				<p><?php esc_html_e( 'Synced, failed, pending, and manual-review orders will appear here after the first RoboLabs run.', 'robolabs-woocommerce' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<div class="robolabs-table-wrap">
			<table class="widefat fixed striped robolabs-activity-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Order', 'robolabs-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Invoice', 'robolabs-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'robolabs-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last Sync', 'robolabs-woocommerce' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Error', 'robolabs-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $order['edit_url'] ); ?>">
									<?php echo esc_html( '#' . $order['number'] ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $order['invoice_id'] ?: '-' ); ?></td>
							<td><span class="robolabs-status-badge is-<?php echo esc_attr( $order['status_class'] ); ?>"><?php echo esc_html( $order['status_label'] ); ?></span></td>
							<td><?php echo esc_html( $order['last_sync'] ); ?></td>
							<td><?php echo esc_html( $order['last_error'] ?: '-' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_admin_tools(): void {
		?>
		<div class="robolabs-tools">
			<form class="robolabs-tool-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'robolabs_sync_order' ); ?>
				<input type="hidden" name="action" value="robolabs_sync_order">
				<label for="robolabs_order_id"><?php esc_html_e( 'Sync order by ID', 'robolabs-woocommerce' ); ?></label>
				<div class="robolabs-inline-form">
					<input type="number" name="order_id" id="robolabs_order_id" min="1" placeholder="1234">
					<button type="submit" class="button robolabs-button robolabs-button-secondary"><?php esc_html_e( 'Sync', 'robolabs-woocommerce' ); ?></button>
				</div>
			</form>
			<form class="robolabs-tool-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'robolabs_resync_order' ); ?>
				<input type="hidden" name="action" value="robolabs_resync_order">
				<label for="robolabs_order_resync_id"><?php esc_html_e( 'Force resync order', 'robolabs-woocommerce' ); ?></label>
				<div class="robolabs-inline-form">
					<input type="number" name="order_id" id="robolabs_order_resync_id" min="1" placeholder="1234">
					<button type="submit" class="button robolabs-button robolabs-button-danger"><?php esc_html_e( 'Resync', 'robolabs-woocommerce' ); ?></button>
				</div>
				<p><?php esc_html_e( 'Resync clears stored RoboLabs invoice metadata for that order before running again.', 'robolabs-woocommerce' ); ?></p>
			</form>
		</div>
		<?php
	}

	private function render_log_stream( array $logs ): void {
		if ( empty( $logs ) ) {
			?>
			<div class="robolabs-empty-state">
				<strong><?php esc_html_e( 'No RoboLabs logs found.', 'robolabs-woocommerce' ); ?></strong>
				<p><?php esc_html_e( 'Enable logging in settings, then run a sync or test connection to populate this monitor.', 'robolabs-woocommerce' ); ?></p>
			</div>
			<?php
			return;
		}
		?>
		<div class="robolabs-log-stream">
			<?php foreach ( $logs as $entry ) : ?>
				<div class="robolabs-log-row">
					<span class="robolabs-log-level is-<?php echo esc_attr( $entry['level_class'] ); ?>"><?php echo esc_html( $entry['level'] ); ?></span>
					<span class="robolabs-log-time"><?php echo esc_html( $entry['time'] ); ?></span>
					<pre><?php echo esc_html( $entry['message'] ); ?></pre>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function get_dashboard_metrics(): array {
		$metrics = array(
			'invoices_created' => $this->count_orders_by_invoice_id(),
			'synced'           => $this->count_orders_by_sync_status( 'synced' ),
			'failed'           => $this->count_orders_by_sync_status( 'failed' ),
			'pending'          => $this->count_orders_by_sync_status( 'pending' ),
			'manual_required'  => $this->count_orders_by_sync_status( 'manual_required' ),
			'refunded'         => $this->count_orders_by_sync_status( 'refunded' ),
			'monitored'        => $this->count_orders_with_sync_status(),
			'latest_sync_at'   => '',
		);

		$recent = $this->get_recent_sync_orders( 1 );
		if ( ! empty( $recent[0]['raw_last_sync'] ) ) {
			$metrics['latest_sync_at'] = $recent[0]['raw_last_sync'];
		}

		return $metrics;
	}

	private function count_orders_by_invoice_id(): int {
		return $this->count_orders(
			array(
				'meta_key'     => '_robolabs_invoice_id',
				'meta_value'   => '',
				'meta_compare' => '!=',
			)
		);
	}

	private function count_orders_by_sync_status( string $status ): int {
		return $this->count_orders(
			array(
				'meta_key'   => '_robolabs_sync_status',
				'meta_value' => $status,
			)
		);
	}

	private function count_orders_with_sync_status(): int {
		return $this->count_orders(
			array(
				'meta_key'     => '_robolabs_sync_status',
				'meta_compare' => 'EXISTS',
			)
		);
	}

	private function count_orders( array $args ): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$query_args = array_merge(
			array(
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
				'status'   => $this->get_order_statuses(),
			),
			$args
		);

		try {
			$orders = wc_get_orders( $query_args );
		} catch ( Throwable $exception ) {
			return 0;
		}

		if ( is_object( $orders ) && isset( $orders->total ) ) {
			return (int) $orders->total;
		}

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	private function get_recent_sync_orders( int $limit = 8 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		try {
			$orders = wc_get_orders(
				array(
					'limit'        => $limit,
					'orderby'      => 'modified',
					'order'        => 'DESC',
					'status'       => $this->get_order_statuses(),
					'meta_key'     => '_robolabs_sync_status',
					'meta_compare' => 'EXISTS',
				)
			);
		} catch ( Throwable $exception ) {
			return array();
		}

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$items = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$status = (string) $order->get_meta( '_robolabs_sync_status' );
			$items[] = array(
				'id'             => $order->get_id(),
				'number'         => $order->get_order_number(),
				'edit_url'       => $order->get_edit_order_url(),
				'invoice_id'     => (string) $order->get_meta( '_robolabs_invoice_id' ),
				'status_label'   => $this->format_sync_status( $status ),
				'status_class'   => $this->get_status_class( $status ),
				'raw_last_sync'  => (string) $order->get_meta( '_robolabs_last_sync_at' ),
				'last_sync'      => $this->format_datetime( (string) $order->get_meta( '_robolabs_last_sync_at' ) ),
				'last_error'     => $this->trim_text( (string) $order->get_meta( '_robolabs_last_error' ), 120 ),
			);
		}

		return $items;
	}

	private function get_order_statuses(): array {
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			return array_keys( wc_get_order_statuses() );
		}

		return array( 'wc-pending', 'wc-processing', 'wc-on-hold', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed' );
	}

	private function format_sync_status( string $status ): string {
		$labels = array(
			'synced'          => __( 'Synced', 'robolabs-woocommerce' ),
			'failed'          => __( 'Failed', 'robolabs-woocommerce' ),
			'pending'         => __( 'Pending', 'robolabs-woocommerce' ),
			'manual_required' => __( 'Manual Review', 'robolabs-woocommerce' ),
			'refunded'        => __( 'Refunded', 'robolabs-woocommerce' ),
		);

		return $labels[ $status ] ?? ( $status ? ucwords( str_replace( '_', ' ', $status ) ) : __( 'Unknown', 'robolabs-woocommerce' ) );
	}

	private function get_status_class( string $status ): string {
		if ( 'synced' === $status || 'refunded' === $status ) {
			return 'success';
		}

		if ( 'failed' === $status || 'manual_required' === $status ) {
			return 'danger';
		}

		if ( 'pending' === $status ) {
			return 'warning';
		}

		return 'neutral';
	}

	private function get_log_file_paths(): array {
		$paths = array();

		if ( defined( 'WC_LOG_DIR' ) ) {
			$matches = glob( trailingslashit( WC_LOG_DIR ) . self::LOG_SOURCE . '*.log' );
			if ( is_array( $matches ) ) {
				$paths = array_merge( $paths, $matches );
			}
		}

		if ( class_exists( 'WC_Log_Handler_File' ) && method_exists( 'WC_Log_Handler_File', 'get_log_file_path' ) ) {
			$path = WC_Log_Handler_File::get_log_file_path( self::LOG_SOURCE );
			if ( is_string( $path ) && is_file( $path ) ) {
				$paths[] = $path;
			}
		}

		$paths = array_filter(
			array_unique( $paths ),
			static function ( $path ) {
				return is_string( $path ) && is_file( $path ) && is_readable( $path );
			}
		);

		usort(
			$paths,
			static function ( string $a, string $b ): int {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		return array_values( $paths );
	}

	private function get_recent_log_entries( int $limit ): array {
		$entries = array();
		foreach ( $this->get_log_file_paths() as $path ) {
			foreach ( $this->read_log_tail( $path, $limit ) as $line ) {
				$entries[] = $this->parse_log_line( $line );
			}
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $b['sort'] <=> $a['sort'];
			}
		);

		return array_slice( $entries, 0, $limit );
	}

	private function read_log_tail( string $path, int $limit ): array {
		if ( ! is_readable( $path ) ) {
			return array();
		}

		try {
			$file = new SplFileObject( $path, 'r' );
			$file->seek( PHP_INT_MAX );
			$last_line = $file->key();
			$lines = array();

			for ( $line_number = $last_line; $line_number >= 0 && count( $lines ) < $limit; $line_number-- ) {
				$file->seek( $line_number );
				$line = trim( (string) $file->current() );
				if ( '' !== $line ) {
					array_unshift( $lines, $line );
				}
			}

			return $lines;
		} catch ( RuntimeException $exception ) {
			return array();
		}
	}

	private function parse_log_line( string $line ): array {
		$level = 'info';
		$time = '';
		$message = $line;

		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}T[^\s]+)\s+([A-Za-z]+)\s+(.*)$/', $line, $matches ) ) {
			$time = $matches[1];
			$level = strtolower( $matches[2] );
			$message = $matches[3];
		} elseif ( preg_match( '/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\s+([A-Za-z]+)\s+(.*)$/', $line, $matches ) ) {
			$time = $matches[1];
			$level = strtolower( $matches[2] );
			$message = $matches[3];
		}

		$timestamp = $time ? strtotime( $time ) : 0;
		return array(
			'level'       => strtoupper( $level ),
			'level_class' => $this->get_log_level_class( $level ),
			'time'        => $timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : __( 'Unknown time', 'robolabs-woocommerce' ),
			'message'     => $this->trim_text( $message, 340 ),
			'sort'        => $timestamp,
		);
	}

	private function get_log_level_class( string $level ): string {
		if ( in_array( $level, array( 'error', 'critical', 'alert', 'emergency' ), true ) ) {
			return 'danger';
		}

		if ( in_array( $level, array( 'warning', 'notice' ), true ) ) {
			return 'warning';
		}

		if ( 'debug' === $level ) {
			return 'neutral';
		}

		return 'success';
	}

	private function clear_robolabs_log_files(): int {
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			return 0;
		}

		$log_dir = realpath( WC_LOG_DIR );
		if ( ! $log_dir ) {
			return 0;
		}

		$deleted = 0;
		$normalized_log_dir = trailingslashit( wp_normalize_path( $log_dir ) );

		foreach ( $this->get_log_file_paths() as $path ) {
			$real_path = realpath( $path );
			if ( ! $real_path ) {
				continue;
			}

			$normalized_path = wp_normalize_path( $real_path );
			if ( 0 !== strpos( $normalized_path, $normalized_log_dir ) ) {
				continue;
			}

			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $real_path );
			} else {
				unlink( $real_path );
			}

			if ( ! file_exists( $real_path ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	private function format_datetime( string $value ): string {
		if ( '' === $value ) {
			return '-';
		}

		$timestamp = strtotime( $value );
		if ( ! $timestamp ) {
			return $value;
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	private function trim_text( string $value, int $limit ): string {
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $limit - 3 ) ) . '...';
	}

	private function run_order_sync_now( int $order_id, bool $reset ): void {
		if ( $reset ) {
			delete_post_meta( $order_id, '_robolabs_invoice_id' );
			delete_post_meta( $order_id, '_robolabs_invoice_external_id' );
			delete_post_meta( $order_id, '_robolabs_sync_status' );
			delete_post_meta( $order_id, '_robolabs_last_error' );
			delete_post_meta( $order_id, '_robolabs_last_sync_at' );
			delete_post_meta( $order_id, '_robolabs_job_id' );
			delete_post_meta( $order_id, '_robolabs_retry_count' );
		}

		$this->sync_order->handle( $order_id );
	}

	private function render_admin_styles(): void {
		?>
		<style>
			.robolabs-dashboard{--rl-primary:#2271b1;--rl-accent:#00a676;--rl-danger:#b42318;--rl-warning:#b7791f;--rl-ink:#1d2327;--rl-muted:#646970;--rl-line:#dcdcde;--rl-soft:#f6f7f7;color:var(--rl-ink);max-width:1480px}
			.robolabs-dashboard *{box-sizing:border-box}
			.robolabs-page-title{font-size:26px;font-weight:700;line-height:1.2;margin:22px 0 10px;padding:0}
			.robolabs-dashboard>.notice,.robolabs-dashboard .updated,.robolabs-dashboard .error{background:#fff;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.04);margin:12px 0 16px}
			.robolabs-hero{align-items:stretch;background:linear-gradient(135deg,#fff 0%,#f7fbff 100%);border:1px solid var(--rl-line);border-radius:8px;box-shadow:0 8px 22px rgba(29,35,39,.06);color:var(--rl-ink);display:grid;gap:22px;grid-template-columns:minmax(0,1fr) minmax(280px,360px);margin:14px 0 18px;overflow:hidden;padding:24px}
			.robolabs-hero .notice{grid-column:1/-1;margin:0 0 12px!important}
			.robolabs-eyebrow{color:var(--rl-primary);font-size:11px;font-weight:800;letter-spacing:0;margin:0 0 8px;text-transform:uppercase}
			.robolabs-hero .robolabs-eyebrow{color:var(--rl-accent)}
			.robolabs-hero h2{color:var(--rl-ink);font-size:30px;font-weight:800;line-height:1.16;margin:0}
			.robolabs-hero-copy{color:var(--rl-muted);font-size:14px;line-height:1.65;margin:12px 0 0;max-width:720px}
			.robolabs-hero-actions,.robolabs-inline-form{align-items:center;display:flex;flex-wrap:wrap;gap:10px}
			.robolabs-hero-actions{margin-top:22px}
			.robolabs-button{align-items:center;border-radius:8px!important;display:inline-flex!important;font-weight:700;gap:8px;min-height:38px}
			.robolabs-button-primary{background:var(--rl-primary)!important;border-color:var(--rl-primary)!important;color:#fff!important}
			.robolabs-button-secondary{background:var(--rl-ink)!important;border-color:var(--rl-ink)!important;color:#fff!important}
			.robolabs-button-ghost{background:#fff!important;border-color:#b7c6d3!important;color:var(--rl-primary)!important}
			.robolabs-button-danger{background:var(--rl-danger)!important;border-color:var(--rl-danger)!important;color:#fff!important}
			.robolabs-button:disabled{background:#d9e1dd!important;border-color:#d9e1dd!important;color:#6d7773!important;cursor:not-allowed}
			.robolabs-system-card{background:#fff;border:1px solid #d9e3ea;border-radius:8px;color:var(--rl-ink);padding:18px}
			.robolabs-status-line{align-items:center;display:flex;font-weight:800;gap:10px;margin-bottom:16px}
			.robolabs-status-dot{background:var(--rl-warning);border-radius:99px;box-shadow:0 0 0 5px rgba(183,121,31,.14);display:inline-block;height:10px;width:10px}
			.robolabs-status-dot.is-ready{background:var(--rl-accent);box-shadow:0 0 0 5px rgba(0,166,118,.14)}
			.robolabs-system-row{align-items:center;border-top:1px solid #edf0f2;display:flex;gap:14px;justify-content:space-between;margin-top:12px;padding:12px 0 0}
			.robolabs-system-row span,.robolabs-metric-label,.robolabs-metric-caption,.robolabs-tool-form p{color:var(--rl-muted)}
			.robolabs-metrics{display:grid;gap:14px;grid-template-columns:repeat(5,minmax(150px,1fr));margin-bottom:18px}
			.robolabs-metric,.robolabs-panel{background:#fff;border:1px solid var(--rl-line);border-radius:8px;box-shadow:0 6px 18px rgba(29,35,39,.05)}
			.robolabs-metric{border-top:3px solid var(--rl-accent);display:flex;flex-direction:column;min-height:124px;padding:16px}
			.robolabs-metric.is-danger{border-top-color:#d63638}
			.robolabs-metric.is-warning{border-top-color:var(--rl-warning)}
			.robolabs-metric.is-neutral{border-top-color:#787c82}
			.robolabs-metric.is-info{border-top-color:var(--rl-primary)}
			.robolabs-metric-label,.robolabs-metric-caption{font-size:12px;font-weight:700}
			.robolabs-metric strong{color:var(--rl-ink);font-size:34px;line-height:1;margin:12px 0 10px}
			.robolabs-layout{display:grid;gap:18px;grid-template-columns:minmax(0,1.8fr) minmax(320px,.8fr);margin-bottom:18px}
			.robolabs-panel{padding:20px}
			.robolabs-panel-header{align-items:center;display:flex;gap:16px;justify-content:space-between;margin-bottom:16px}
			.robolabs-panel h2{color:var(--rl-ink);font-size:20px;line-height:1.2;margin:0}
			.robolabs-count-pill,.robolabs-status-badge,.robolabs-log-level{border-radius:999px;display:inline-flex;font-size:11px;font-weight:800;line-height:1;padding:7px 10px;text-transform:uppercase}
			.robolabs-count-pill{background:#eef6ff;color:var(--rl-primary)}
			.robolabs-table-wrap{overflow-x:auto}
			.robolabs-activity-table{border:0}
			.robolabs-activity-table th{color:var(--rl-muted);font-size:12px;text-transform:uppercase}
			.robolabs-activity-table td,.robolabs-activity-table th{padding:14px 12px;vertical-align:middle}
			.robolabs-status-badge.is-success,.robolabs-log-level.is-success{background:#e8f7f0;color:#007c5b}
			.robolabs-status-badge.is-danger,.robolabs-log-level.is-danger{background:#fcf0f1;color:var(--rl-danger)}
			.robolabs-status-badge.is-warning,.robolabs-log-level.is-warning{background:#fff8e5;color:#8a5a00}
			.robolabs-status-badge.is-neutral,.robolabs-log-level.is-neutral{background:#eef1ef;color:#48524e}
			.robolabs-tools{display:grid;gap:18px}
			.robolabs-tool-form{border:1px solid #e3e7eb;border-radius:8px;padding:16px}
			.robolabs-tool-form label{display:block;font-weight:800;margin-bottom:10px}
			.robolabs-tool-form input[type=number]{flex:1 1 140px;min-height:38px;width:100%}
			.robolabs-tool-form p{margin:10px 0 0}
			.robolabs-logs-panel{margin-bottom:18px}
			.robolabs-log-stream{background:#1d2327;border-radius:8px;color:#dce8e2;font-family:Consolas,Monaco,monospace;max-height:440px;overflow:auto;padding:10px}
			.robolabs-log-row{align-items:flex-start;border-bottom:1px solid rgba(255,255,255,.08);display:grid;gap:12px;grid-template-columns:82px 150px minmax(0,1fr);padding:12px 8px}
			.robolabs-log-row:last-child{border-bottom:0}
			.robolabs-log-time{color:#9fb2aa;font-size:12px;padding-top:4px}
			.robolabs-log-row pre{color:#eef7f2;font:inherit;line-height:1.55;margin:0;white-space:pre-wrap;word-break:break-word}
			.robolabs-empty-state{background:var(--rl-soft);border:1px dashed #c4cbd1;border-radius:8px;padding:24px}
			.robolabs-empty-state strong{color:var(--rl-ink);display:block;font-size:15px;margin-bottom:6px}
			.robolabs-empty-state p{color:var(--rl-muted);margin:0}
			.robolabs-settings-panel form{margin:0}
			.robolabs-settings-panel .form-table{margin-top:0}
			.robolabs-settings-panel .form-table th{color:var(--rl-ink);font-weight:800;padding-left:0}
			.robolabs-settings-panel .form-table td{padding-right:0}
			@media (max-width:1180px){.robolabs-metrics{grid-template-columns:repeat(3,minmax(180px,1fr))}.robolabs-layout,.robolabs-hero{grid-template-columns:1fr}}
			@media (max-width:782px){.robolabs-dashboard{margin-right:12px}.robolabs-hero,.robolabs-panel{padding:16px}.robolabs-hero h2{font-size:26px}.robolabs-metrics{grid-template-columns:1fr}.robolabs-panel-header{align-items:flex-start;flex-direction:column}.robolabs-log-row{grid-template-columns:1fr}}
		</style>
		<?php
	}
}
