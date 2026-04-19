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
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'maybe_enqueue_store_api_order' ), 20, 1 );
		add_action( 'woocommerce_blocks_checkout_order_processed', array( $this, 'maybe_enqueue_store_api_order' ), 20, 1 );
		add_action( '__experimental_woocommerce_blocks_checkout_order_processed', array( $this, 'maybe_enqueue_store_api_order' ), 20, 1 );
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

	public function maybe_enqueue_store_api_order( $order ): void {
		if ( 'order_created' !== $this->settings->get( 'invoice_trigger' ) ) {
			return;
		}

		if ( $order instanceof WC_Order ) {
			$this->enqueue_order( $order->get_id() );
			return;
		}

		if ( is_numeric( $order ) ) {
			$this->enqueue_order( (int) $order );
		}
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
		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			$this->logger->warning( 'Action Scheduler not available, skipping order sync', array( 'order_id' => $order_id ) );
			return;
		}

		$args = array( 'order_id' => $order_id );
		if ( $this->has_scheduled_action( 'robolabs_sync_order', $args ) ) {
			$this->logger->info( 'RoboLabs order sync already scheduled', array( 'order_id' => $order_id ) );
			return;
		}

		$this->schedule_action( 'robolabs_sync_order', $args, 5 );
		$this->logger->info( 'Enqueued RoboLabs order sync', array( 'order_id' => $order_id ) );
	}

	public function enqueue_refund( int $order_id, int $refund_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) && ! function_exists( 'as_schedule_single_action' ) ) {
			$this->logger->warning( 'Action Scheduler not available, skipping refund sync', array( 'order_id' => $order_id ) );
			return;
		}

		$args = array(
			'order_id'  => $order_id,
			'refund_id' => $refund_id,
		);
		if ( $this->has_scheduled_action( 'robolabs_sync_refund', $args ) ) {
			$this->logger->info( 'RoboLabs refund sync already scheduled', array( 'order_id' => $order_id, 'refund_id' => $refund_id ) );
			return;
		}

		$this->schedule_action( 'robolabs_sync_refund', $args, 5 );
		$this->logger->info( 'Enqueued RoboLabs refund sync', array( 'order_id' => $order_id, 'refund_id' => $refund_id ) );
	}

	public function poll_job( int $job_id, array $context = array() ): void {
		$response = $this->api_client->get( 'apiJob/' . $job_id );
		if ( ! $response['success'] ) {
			$this->logger->warning( 'Failed to poll RoboLabs job', array( 'job_id' => $job_id, 'error' => $response['error'] ?? 'unknown' ) );
			return;
		}

		$data = $this->api_client->get_result( $response );
		if ( $this->is_job_complete( $data ) ) {
			if ( isset( $context['order_id'] ) ) {
				$invoice_id = $this->extract_invoice_id( $data );
				if ( $invoice_id ) {
					$this->sync_order->finalize_async_invoice( (int) $context['order_id'], (int) $invoice_id );
					update_post_meta( (int) $context['order_id'], '_robolabs_job_id', '' );
				} else {
					$this->logger->warning( 'Job completed but invoice id was not found', array( 'job_id' => $job_id, 'context' => $context ) );
				}
			}
			return;
		}

		if ( $this->is_job_failed( $data ) ) {
			$message = (string) ( $data['response_message'] ?? __( 'RoboLabs background job failed.', 'robolabs-woocommerce' ) );
			$this->logger->error( 'RoboLabs job failed', array( 'job_id' => $job_id, 'context' => $context, 'message' => $message ) );
			if ( isset( $context['order_id'] ) ) {
				$failed_order = wc_get_order( (int) $context['order_id'] );
				if ( $failed_order ) {
					$failed_order->update_meta_data( '_robolabs_job_id', '' );
					$failed_order->update_meta_data( '_robolabs_sync_status', 'failed' );
					$failed_order->update_meta_data( '_robolabs_last_error', sanitize_text_field( $message ) );
					$failed_order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
					$failed_order->save();
				}
			}
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) || function_exists( 'as_schedule_single_action' ) ) {
			$this->schedule_action( 'robolabs_poll_job', array( 'job_id' => $job_id, 'context' => $context ), 10 );
			$this->logger->info( 'Job not completed, re-enqueued', array( 'job_id' => $job_id ) );
		}
	}

	private function is_job_complete( array $data ): bool {
		$state = $this->get_job_state( $data );
		return in_array( $state, array( 'done', 'completed', 'success', 'succeeded' ), true );
	}

	private function is_job_failed( array $data ): bool {
		$state = $this->get_job_state( $data );
		return in_array( $state, array( 'failed', 'error' ), true );
	}

	private function get_job_state( array $data ): string {
		if ( isset( $data['status'] ) && is_string( $data['status'] ) ) {
			return strtolower( $data['status'] );
		}

		if ( isset( $data['state'] ) && is_array( $data['state'] ) ) {
			$state = reset( $data['state'] );
			if ( false !== $state && is_string( $state ) ) {
				return strtolower( $state );
			}
		}

		if ( isset( $data['state'] ) && is_string( $data['state'] ) ) {
			return strtolower( $data['state'] );
		}

		return '';
	}

	private function extract_invoice_id( array $data ): ?string {
		if ( isset( $data['invoice_id'] ) ) {
			return (string) $data['invoice_id'];
		}

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
			if ( isset( $parsed['result']['invoice_id'] ) ) {
				return (string) $parsed['result']['invoice_id'];
			}
			if ( isset( $parsed['result']['id'] ) ) {
				return (string) $parsed['result']['id'];
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

	private function has_scheduled_action( string $hook, array $args ): bool {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return as_has_scheduled_action( $hook, $args, 'robolabs' );
		}

		if ( function_exists( 'as_next_scheduled_action' ) ) {
			return false !== as_next_scheduled_action( $hook, $args, 'robolabs' );
		}

		return false;
	}

	private function schedule_action( string $hook, array $args, int $delay = 0 ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 0, $delay ), $hook, $args, 'robolabs' );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, 'robolabs' );
		}
	}
}
