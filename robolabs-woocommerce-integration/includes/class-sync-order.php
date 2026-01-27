<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Sync_Order {
	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Api_Client $api_client;
	private RoboLabs_WC_Logger $logger;
	private RoboLabs_WC_Mappers $mappers;
	private ?int $last_error_code = null;

	public function __construct(
		RoboLabs_WC_Settings $settings,
		RoboLabs_WC_Api_Client $api_client,
		RoboLabs_WC_Logger $logger,
		RoboLabs_WC_Mappers $mappers
	) {
		$this->settings   = $settings;
		$this->api_client = $api_client;
		$this->logger     = $logger;
		$this->mappers    = $mappers;
	}

	public function handle( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( ! $this->acquire_lock( $order_id ) ) {
			$this->logger->warning( 'Order sync lock already acquired', array( 'order_id' => $order_id ) );
			return;
		}

		try {
			if ( $order->get_meta( '_robolabs_invoice_id' ) ) {
				$this->logger->info( 'Order already synced', array( 'order_id' => $order_id ) );
				return;
			}

			$this->last_error_code = null;
			$partner_id = $this->ensure_partner( $order );
			if ( ! $partner_id ) {
				if ( $this->maybe_schedule_retry( $order, $this->last_error_code ) ) {
					return;
				}
				$this->mark_failed( $order, 'Partner sync failed' );
				return;
			}

			$this->last_error_code = null;
			$line_items = $this->build_line_items( $order );
			if ( empty( $line_items ) ) {
				if ( $this->maybe_schedule_retry( $order, $this->last_error_code ) ) {
					return;
				}
				$this->mark_failed( $order, 'No invoice lines to sync' );
				return;
			}

			$invoice_payload = $this->mappers->build_invoice_payload( $order, $partner_id, $line_items );

			$existing = $this->find_invoice_by_external_id( $invoice_payload['external_id'] );
			if ( $existing ) {
				$this->update_order_invoice_meta( $order, $existing );
				return;
			}

			$response = $this->api_client->post( 'invoice', $invoice_payload );
			if ( ! $response['success'] ) {
				if ( $this->maybe_schedule_retry( $order, $response['code'] ?? null ) ) {
					return;
				}
				$this->mark_failed( $order, $response['error'] ?? 'Invoice create failed' );
				return;
			}

			$data = $response['data'] ?? array();
			if ( isset( $data['job_id'] ) ) {
				update_post_meta( $order_id, '_robolabs_job_id', sanitize_text_field( $data['job_id'] ) );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( 'robolabs_poll_job', array( 'job_id' => (int) $data['job_id'], 'context' => array( 'order_id' => $order_id ) ), 'robolabs' );
				}
				return;
			}

			$invoice_id = $data['id'] ?? null;
			if ( $invoice_id ) {
				$this->confirm_invoice( (int) $invoice_id );
				$this->update_order_invoice_meta( $order, array( 'id' => $invoice_id, 'external_id' => $invoice_payload['external_id'] ) );
			}
		} finally {
			$this->release_lock( $order_id );
		}
	}

	private function ensure_partner( WC_Order $order ): ?int {
		$partner_id = (int) $order->get_meta( '_robolabs_partner_id', true );
		if ( $partner_id ) {
			return $partner_id;
		}

		$external_id = $this->mappers->partner_external_id( $order );
		$existing = $this->find_partner_by_external_id( $external_id, $order->get_billing_email() );
		if ( $existing ) {
			update_post_meta( $order->get_id(), '_robolabs_partner_id', $existing['id'] );
			update_post_meta( $order->get_id(), '_robolabs_partner_external_id', $external_id );
			if ( $order->get_user_id() ) {
				update_user_meta( $order->get_user_id(), '_robolabs_partner_id', $existing['id'] );
				update_user_meta( $order->get_user_id(), '_robolabs_partner_external_id', $external_id );
			}
			return (int) $existing['id'];
		}

		$response = $this->api_client->post( 'partner', $this->mappers->build_partner_payload( $order ) );
		if ( ! $response['success'] ) {
			$this->last_error_code = $response['code'] ?? null;
			$this->logger->error( 'Partner create failed', array( 'order_id' => $order->get_id(), 'error' => $response['error'] ?? 'unknown' ) );
			return null;
		}

		$data = $response['data'] ?? array();
		if ( isset( $data['id'] ) ) {
			update_post_meta( $order->get_id(), '_robolabs_partner_id', $data['id'] );
			update_post_meta( $order->get_id(), '_robolabs_partner_external_id', $external_id );
			if ( $order->get_user_id() ) {
				update_user_meta( $order->get_user_id(), '_robolabs_partner_id', $data['id'] );
				update_user_meta( $order->get_user_id(), '_robolabs_partner_external_id', $external_id );
			}
			return (int) $data['id'];
		}

		return null;
	}

	private function build_line_items( WC_Order $order ): array {
		$lines = array();
		$tax_mode = $this->settings->get( 'tax_mode', 'robo_decide' );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$product_id = $this->ensure_product( $product );
			if ( ! $product_id ) {
				continue;
			}
			$lines[] = $this->mappers->build_line_item( $item, $product_id, $tax_mode );
		}

		if ( $order->get_shipping_total() > 0 ) {
			$shipping_product_id = $this->ensure_shipping_product();
			if ( $shipping_product_id ) {
				$lines[] = $this->mappers->build_shipping_line( $order, $shipping_product_id, $tax_mode );
			}
		}

		$discount_line = $this->mappers->build_discount_line( $order );
		if ( $discount_line ) {
			$lines[] = $discount_line;
		}

		return $lines;
	}

	private function ensure_product( WC_Product $product ): ?int {
		$product_id = (int) $product->get_meta( '_robolabs_product_id', true );
		if ( $product_id ) {
			return $product_id;
		}

		$external_id = $this->mappers->product_external_id( $product->get_id() );
		$existing = $this->find_product_by_external_id( $external_id, $product->get_sku() );
		if ( $existing ) {
			$product->update_meta_data( '_robolabs_product_id', $existing['id'] );
			$product->update_meta_data( '_robolabs_product_external_id', $external_id );
			$product->save();
			return (int) $existing['id'];
		}

		$response = $this->api_client->post( 'product', $this->mappers->build_product_payload( $product ) );
		if ( ! $response['success'] ) {
			$this->last_error_code = $response['code'] ?? null;
			$this->logger->error( 'Product create failed', array( 'product_id' => $product->get_id(), 'error' => $response['error'] ?? 'unknown' ) );
			return null;
		}

		$data = $response['data'] ?? array();
		if ( isset( $data['id'] ) ) {
			$product->update_meta_data( '_robolabs_product_id', $data['id'] );
			$product->update_meta_data( '_robolabs_product_external_id', $external_id );
			$product->save();
			return (int) $data['id'];
		}

		return null;
	}

	private function ensure_shipping_product(): ?int {
		$stored = (int) get_option( 'robolabs_wc_shipping_product_id', 0 );
		if ( $stored ) {
			return $stored;
		}

		$payload = array(
			'external_id' => 'EWCSHIP',
			'name'        => __( 'Shipping', 'robolabs-woocommerce' ),
			'sku'         => 'WC-SHIPPING',
			'categ_id'    => $this->settings->get( 'categ_id' ),
		);

		$existing = $this->find_product_by_external_id( 'EWCSHIP', 'WC-SHIPPING' );
		if ( $existing ) {
			update_option( 'robolabs_wc_shipping_product_id', (int) $existing['id'] );
			return (int) $existing['id'];
		}

		$response = $this->api_client->post( 'product', $payload );
		if ( ! $response['success'] ) {
			$this->logger->warning( 'Shipping product create failed', array( 'error' => $response['error'] ?? 'unknown' ) );
			return null;
		}

		$data = $response['data'] ?? array();
		if ( isset( $data['id'] ) ) {
			update_option( 'robolabs_wc_shipping_product_id', (int) $data['id'] );
			return (int) $data['id'];
		}

		return null;
	}

	private function confirm_invoice( int $invoice_id ): void {
		$response = $this->api_client->post( 'invoice/' . $invoice_id . '/confirm' );
		if ( ! $response['success'] ) {
			$this->logger->warning( 'Invoice confirm failed', array( 'invoice_id' => $invoice_id, 'error' => $response['error'] ?? 'unknown' ) );
		}
	}

	private function update_order_invoice_meta( WC_Order $order, array $invoice ): void {
		$order->update_meta_data( '_robolabs_invoice_id', $invoice['id'] ?? '' );
		$order->update_meta_data( '_robolabs_invoice_external_id', $invoice['external_id'] ?? '' );
		$order->update_meta_data( '_robolabs_sync_status', 'synced' );
		$order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
		$order->save();
		$order->add_order_note( sprintf( __( 'RoboLabs invoice synced: %s', 'robolabs-woocommerce' ), $invoice['id'] ?? '' ) );
	}

	private function mark_failed( WC_Order $order, string $message ): void {
		$order->update_meta_data( '_robolabs_sync_status', 'failed' );
		$order->update_meta_data( '_robolabs_last_error', sanitize_text_field( $message ) );
		$order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
		$order->save();
		$this->logger->error( 'Order sync failed', array( 'order_id' => $order->get_id(), 'error' => $message ) );
	}

	private function maybe_schedule_retry( WC_Order $order, ?int $code ): bool {
		if ( ! $code || ( 429 !== $code && $code < 500 ) ) {
			return false;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$attempts = (int) $order->get_meta( '_robolabs_retry_count', true );
		$attempts++;
		if ( $attempts > $this->settings->get_max_attempts() ) {
			return false;
		}

		$delay = (int) pow( 2, $attempts ) * 60;
		$order->update_meta_data( '_robolabs_retry_count', $attempts );
		$order->save();
		as_schedule_single_action( time() + $delay, 'robolabs_sync_order', array( 'order_id' => $order->get_id() ), 'robolabs' );
		$this->logger->warning( 'Order sync retry scheduled', array( 'order_id' => $order->get_id(), 'attempt' => $attempts ) );
		return true;
	}

	private function find_partner_by_external_id( string $external_id, string $email ): ?array {
		$response = $this->api_client->get( 'partner/find', array( 'external_id' => $external_id ) );
		if ( $response['success'] && ! empty( $response['data']['data'][0] ) ) {
			return $response['data']['data'][0];
		}

		if ( $email ) {
			$response = $this->api_client->get( 'partner/find', array( 'email' => $email ) );
			if ( $response['success'] && ! empty( $response['data']['data'][0] ) ) {
				return $response['data']['data'][0];
			}
		}

		return null;
	}

	private function find_product_by_external_id( string $external_id, string $sku ): ?array {
		$response = $this->api_client->get( 'product/find', array( 'external_id' => $external_id ) );
		if ( $response['success'] && ! empty( $response['data']['data'][0] ) ) {
			return $response['data']['data'][0];
		}

		if ( $sku ) {
			$response = $this->api_client->get( 'product/find', array( 'sku' => $sku ) );
			if ( $response['success'] && ! empty( $response['data']['data'][0] ) ) {
				return $response['data']['data'][0];
			}
		}

		return null;
	}

	private function find_invoice_by_external_id( string $external_id ): ?array {
		$response = $this->api_client->get( 'invoice/find', array( 'external_id' => $external_id ) );
		if ( $response['success'] && ! empty( $response['data']['data'][0] ) ) {
			return $response['data']['data'][0];
		}

		return null;
	}

	private function acquire_lock( int $order_id ): bool {
		$lock_key = 'robolabs_wc_lock_' . $order_id;
		if ( get_transient( $lock_key ) ) {
			return false;
		}
		set_transient( $lock_key, 1, $this->settings->get_lock_ttl() );
		return true;
	}

	private function release_lock( int $order_id ): void {
		delete_transient( 'robolabs_wc_lock_' . $order_id );
	}
}
