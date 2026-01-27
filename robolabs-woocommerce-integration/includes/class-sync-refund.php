<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Sync_Refund {
	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Api_Client $api_client;
	private RoboLabs_WC_Logger $logger;
	private RoboLabs_WC_Mappers $mappers;

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

	public function handle( int $order_id, int $refund_id ): void {
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}

		$invoice_id = (int) $order->get_meta( '_robolabs_invoice_id', true );
		if ( ! $invoice_id ) {
			$invoice = $this->find_invoice_by_external_id( $this->mappers->invoice_external_id( $order_id ) );
			if ( $invoice ) {
				$invoice_id = (int) $invoice['id'];
			}
		}

		if ( ! $invoice_id ) {
			$this->mark_manual_required( $order, 'Missing invoice for refund' );
			return;
		}

		$total_refunded = (float) $order->get_total_refunded();
		$order_total    = (float) $order->get_total();

		if ( abs( $total_refunded - $order_total ) < 0.01 ) {
			$response = $this->api_client->post( 'invoice/' . $invoice_id . '/cancel', array( 'delete_payments' => true ) );
			if ( ! $response['success'] ) {
				if ( $this->maybe_schedule_retry( $order, $response['code'] ?? null, $refund_id ) ) {
					return;
				}
				$this->mark_manual_required( $order, $response['error'] ?? 'Invoice cancel failed' );
				return;
			}
			$order->update_meta_data( '_robolabs_sync_status', 'refunded' );
			$order->save();
			return;
		}

		$partner_id = (int) $order->get_meta( '_robolabs_partner_id', true );
		if ( ! $partner_id ) {
			$this->mark_manual_required( $order, 'Missing partner for credit note' );
			return;
		}

		$line_items = $this->build_refund_lines( $refund );
		if ( empty( $line_items ) ) {
			$this->mark_manual_required( $order, 'No refund lines found' );
			return;
		}

		$credit_payload = $this->mappers->build_credit_payload( $order, $partner_id, $line_items, $refund_id );
		$existing = $this->find_invoice_by_external_id( $credit_payload['order_number'] );
		if ( ! $existing ) {
			$response = $this->api_client->post( 'invoice', $credit_payload );
			if ( ! $response['success'] ) {
				if ( $this->maybe_schedule_retry( $order, $response['code'] ?? null, $refund_id ) ) {
					return;
				}
				$this->mark_manual_required( $order, $response['error'] ?? 'Credit note create failed' );
				return;
			}
			$existing = $response['data'] ?? array();
		}

		if ( isset( $existing['id'] ) ) {
			$confirm_response = $this->api_client->post( 'invoice/' . (int) $existing['id'] . '/confirm' );
			if ( ! $confirm_response['success'] ) {
				if ( $this->maybe_schedule_retry( $order, $confirm_response['code'] ?? null, $refund_id ) ) {
					return;
				}
				$this->mark_manual_required( $order, $confirm_response['error'] ?? 'Credit note confirm failed' );
				return;
			}
			$reconcile_response = $this->api_client->post( 'invoice/' . $invoice_id . '/reconcile_with_credit', array( 'credit_invoice_id' => (int) $existing['id'] ) );
			if ( ! $reconcile_response['success'] ) {
				if ( $this->maybe_schedule_retry( $order, $reconcile_response['code'] ?? null, $refund_id ) ) {
					return;
				}
				$this->mark_manual_required( $order, $reconcile_response['error'] ?? 'Reconcile failed' );
				return;
			}
			$order->update_meta_data( '_robolabs_sync_status', 'refunded' );
			$order->save();
		}
	}

	private function build_refund_lines( WC_Order_Refund $refund ): array {
		$lines = array();
		$tax_mode = $this->settings->get( 'tax_mode', 'robo_decide' );
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$product_id = (int) $product->get_meta( '_robolabs_product_id', true );
			if ( ! $product_id ) {
				continue;
			}
			$qty = $item->get_quantity();
			$total = (float) $item->get_total();
			$unit_price = $qty > 0 ? $total / $qty : 0;
			$line = array(
				'product_id' => $product_id,
				'qty'        => $qty,
				'price'      => wc_format_decimal( $unit_price, 2 ),
			);
			if ( 'pass_taxes' === $tax_mode ) {
				$line['tax'] = wc_format_decimal( (float) $item->get_total_tax(), 2 );
			}
			$lines[] = $line;
		}

		return $lines;
	}

	private function mark_manual_required( WC_Order $order, string $message ): void {
		$order->update_meta_data( '_robolabs_sync_status', 'manual_required' );
		$order->update_meta_data( '_robolabs_last_error', sanitize_text_field( $message ) );
		$order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
		$order->save();
		$this->logger->error( 'Refund sync failed', array( 'order_id' => $order->get_id(), 'error' => $message ) );
	}

	private function maybe_schedule_retry( WC_Order $order, ?int $code, int $refund_id ): bool {
		if ( ! $code || ( 429 !== $code && $code < 500 ) ) {
			return false;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}

		$attempts = (int) $order->get_meta( '_robolabs_refund_retry_count', true );
		$attempts++;
		if ( $attempts > $this->settings->get_max_attempts() ) {
			return false;
		}

		$delay = (int) pow( 2, $attempts ) * 60;
		$order->update_meta_data( '_robolabs_refund_retry_count', $attempts );
		$order->save();
		as_schedule_single_action( time() + $delay, 'robolabs_sync_refund', array( 'order_id' => $order->get_id(), 'refund_id' => $refund_id ), 'robolabs' );
		$this->logger->warning( 'Refund sync retry scheduled', array( 'order_id' => $order->get_id(), 'refund_id' => $refund_id, 'attempt' => $attempts ) );
		return true;
	}

	private function find_invoice_by_external_id( string $external_id ): ?array {
		$response = $this->api_client->get( 'invoice/find', array( 'order_number' => $external_id ) );
		if ( $response['success'] && ! empty( $response['data']['data'][0] ) ) {
			return $response['data']['data'][0];
		}

		return null;
	}
}
