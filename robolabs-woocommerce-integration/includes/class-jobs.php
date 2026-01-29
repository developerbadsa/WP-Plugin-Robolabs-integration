<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Jobs {
	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Logger $logger;
	private RoboLabs_WC_Sync_Order $sync_order;
	private RoboLabs_WC_Sync_Refund $sync_refund;
	private RoboLabs_WC_Api_Client $api_client;

	public function __construct(
		RoboLabs_WC_Settings $settings,
		RoboLabs_WC_Logger $logger,
		RoboLabs_WC_Sync_Order $sync_order,
		RoboLabs_WC_Sync_Refund $sync_refund,
		RoboLabs_WC_Api_Client $api_client
	) {
		$this->settings    = $settings;
		$this->logger      = $logger;
		$this->sync_order  = $sync_order;
		$this->sync_refund = $sync_refund;
		$this->api_client  = $api_client;
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'maybe_enqueue_order' ), 20, 1 );
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_enqueue_order_payment' ), 20, 1 );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_enqueue_order_status' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_enqueue_order_status' ), 20, 1 );

		add_action( 'woocommerce_order_refunded', array( $this, 'enqueue_refund' ), 20, 2 );

		add_action( 'robolabs_sync_order', array( $this->sync_order, 'handle' ), 10, 1 );
		add_action( 'robolabs_sync_refund', array( $this->sync_refund, 'handle' ), 10, 2 );
		add_action( 'robolabs_poll_job', array( $this, 'poll_job' ), 10, 2 );
	}

	public function maybe_enqueue_order( int $order_id ): void {
		if ( 'order_created' !== $this->settings->get( 'invoice_trigger' ) ) {
			return;
		}
		$this->enqueue_order( $order_id );
	}

	public function maybe_enqueue_order_payment( int $order_id ): void {
		if ( 'payment_complete' !== $this->settings->get( 'invoice_trigger' ) ) {
			return;
		}
		$this->enqueue_order( $order_id );
	}

	public function maybe_enqueue_order_status( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'status_processing' === $this->settings->get( 'invoice_trigger' ) && $order->has_status( 'processing' ) ) {
			$this->enqueue_order( $order_id );
		}
		if ( 'status_completed' === $this->settings->get( 'invoice_trigger' ) && $order->has_status( 'completed' ) ) {
			$this->enqueue_order( $order_id );
		}
	}

	public function enqueue_order( int $order_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->logger->warning( 'Action Scheduler not available, skipping order sync', array( 'order_id' => $order_id ) );
			return;
		}

		as_enqueue_async_action( 'robolabs_sync_order', array( 'order_id' => $order_id ), 'robolabs' );
		$this->logger->info( 'Enqueued RoboLabs order sync', array( 'order_id' => $order_id ) );
	}

	public function enqueue_refund( int $order_id, int $refund_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->logger->warning( 'Action Scheduler not available, skipping refund sync', array( 'order_id' => $order_id ) );
			return;
		}

		as_enqueue_async_action(
			'robolabs_sync_refund',
			array(
				'order_id'  => $order_id,
				'refund_id' => $refund_id,
			),
			'robolabs'
		);
		$this->logger->info( 'Enqueued RoboLabs refund sync', array( 'order_id' => $order_id, 'refund_id' => $refund_id ) );
	}

	public function poll_job( int $job_id, array $context = array() ): void {
		$response = $this->api_client->get( 'apiJob/' . $job_id );
		if ( ! $response['success'] ) {
			$this->logger->warning( 'Failed to poll RoboLabs job', array( 'job_id' => $job_id, 'error' => $response['error'] ?? 'unknown' ) );
			return;
		}

		$data = $response['data'] ?? array();
		if ( ! $this->is_job_complete( $data ) ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'robolabs_poll_job', array( 'job_id' => $job_id, 'context' => $context ), 'robolabs' );
				$this->logger->info( 'Job not completed, re-enqueued', array( 'job_id' => $job_id ) );
			}
			return;
		}

		if ( isset( $context['order_id'] ) ) {
			$invoice_id = $this->extract_invoice_id( $data );
			if ( $invoice_id ) {
				update_post_meta( (int) $context['order_id'], '_robolabs_invoice_id', sanitize_text_field( $invoice_id ) );
				update_post_meta( (int) $context['order_id'], '_robolabs_job_id', '' );
			}
		}
	}

	private function is_job_complete( array $data ): bool {
		if ( isset( $data['status'] ) ) {
			return 'completed' === $data['status'];
		}

		if ( isset( $data['state'] ) && is_array( $data['state'] ) ) {
			$state = array_map( 'strtolower', $data['state'] );
			return (bool) array_intersect( $state, array( 'done', 'completed', 'success' ) );
		}

		return false;
	}

	private function extract_invoice_id( array $data ): ?string {
		if ( isset( $data['result']['invoice_id'] ) ) {
			return (string) $data['result']['invoice_id'];
		}

		if ( isset( $data['response_data'] ) ) {
			$parsed = $this->parse_response_data( $data['response_data'] );
			if ( isset( $parsed['invoice_id'] ) ) {
				return (string) $parsed['invoice_id'];
			}
			if ( isset( $parsed['id'] ) ) {
				return (string) $parsed['id'];
			}
		}

		return null;
	}

	private function parse_response_data( $response_data ): array {
		if ( is_array( $response_data ) ) {
			return $response_data;
		}

		if ( is_string( $response_data ) && '' !== $response_data ) {
			$normalized = trim( $response_data );
			$normalized = str_replace( "'", '"', $normalized );
			$decoded = json_decode( $normalized, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}
}
