<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Sync_Refund {
	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Api_Client $api_client;
	private RoboLabs_WC_Logger $logger;
	private RoboLabs_WC_Mappers $mappers;
	private string $last_error_message = '';

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
			if ( ! $invoice ) {
				$invoice = $this->find_invoice_by_identifiers(
					array(
						'number'    => $order->get_order_number(),
						'reference' => $order->get_order_number(),
					)
				);
			}
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

		$this->last_error_message = '';
		$line_items = $this->build_refund_lines( $refund );
		if ( empty( $line_items ) ) {
			$this->mark_manual_required( $order, $this->last_error_message ?: 'No refund lines found' );
			return;
		}

		$credit_payload = $this->mappers->build_credit_payload( $order, $partner_id, $line_items, $refund_id );
		$credit_amounts = array();
		if ( ! $this->mappers->line_items_use_gross_prices( $line_items ) ) {
			$credit_amounts = $this->mappers->calculate_amounts_from_lines(
				$line_items,
				abs( (float) $refund->get_total() ),
				abs( (float) $refund->get_total_tax() )
			);
			$credit_payload['subtotal'] = $credit_amounts['subtotal'];
			$credit_payload['tax'] = $credit_amounts['tax'];
			$credit_payload['total'] = $credit_amounts['total'];
		} else {
			$credit_amounts = array(
				'total' => wc_format_decimal( abs( (float) $refund->get_total() ), 2 ),
			);
		}
		$existing = $this->find_invoice_by_external_id( (string) ( $credit_payload['external_id'] ?? '' ) );
		if ( ! $existing ) {
			$existing = $this->find_invoice_by_identifiers(
				array(
					'number'    => $credit_payload['number'],
					'reference' => $credit_payload['reference'],
				)
			);
		}
		if ( ! $existing ) {
			$response = $this->create_credit_invoice( $credit_payload );
			if ( ! $response['success'] ) {
				if ( $this->maybe_schedule_retry( $order, $response['code'] ?? null, $refund_id ) ) {
					return;
				}
				$this->mark_manual_required( $order, $response['error'] ?? 'Credit note create failed' );
				return;
			}
			$existing = $this->api_client->get_result( $response );
		}

		if ( isset( $existing['id'] ) ) {
			$confirm_payload = $credit_amounts;
			$confirm_response = $this->api_client->post( 'invoice/' . (int) $existing['id'] . '/confirm', $confirm_payload );
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
			$qty        = abs( (float) $item->get_quantity() );
			$subtotal = abs( (float) $item->get_subtotal() );
			$subtotal_tax = abs( (float) $item->get_subtotal_tax() );
			$total = abs( (float) $item->get_total() );
			$total_tax = abs( (float) $item->get_total_tax() );
			if ( $subtotal <= 0 ) {
				$subtotal = $total;
				$subtotal_tax = $total_tax;
			}
			$line = array(
				'product_id' => $product_id,
				'qty'        => $qty,
			);
			if ( 'pass_taxes' === $tax_mode ) {
				$unit_price = $qty > 0 ? $total / $qty : 0.0;
				$line['price'] = wc_format_decimal( $unit_price, 2 );
				$line['vat'] = wc_format_decimal( $total_tax, 2 );
			} else {
				$gross_subtotal = $subtotal + $subtotal_tax;
				$gross_total = $total + $total_tax;
				$unit_price = $qty > 0 ? $gross_subtotal / $qty : 0.0;
				$line['price_with_vat'] = wc_format_decimal( $unit_price, 2 );
				if ( $gross_subtotal > $gross_total && $gross_subtotal > 0 ) {
					$line['discount'] = wc_format_decimal( ( ( $gross_subtotal - $gross_total ) / $gross_subtotal ) * 100, 4 );
				}
			}
			$vat_codes = $this->resolve_product_vat_codes( $product );
			if ( ! empty( $vat_codes ) ) {
				$line['vat_code'] = $vat_codes;
			}
			$lines[] = $line;
		}

		foreach ( $refund->get_items( 'shipping' ) as $item ) {
			$total = abs( (float) $item->get_total() );
			if ( $total <= 0 ) {
				continue;
			}
			$product_id = (int) get_option( 'robolabs_wc_shipping_product_id', 0 );
			if ( ! $product_id ) {
				$this->last_error_message = __( 'Missing RoboLabs shipping product for refunded shipping line.', 'robolabs-woocommerce' );
				return array();
			}
			$line = array(
				'product_id'  => $product_id,
				'qty'         => 1,
				'description' => $item->get_name(),
			);
			if ( 'pass_taxes' === $tax_mode ) {
				$line['price'] = wc_format_decimal( $total, 2 );
				$line['vat'] = wc_format_decimal( abs( (float) $item->get_total_tax() ), 2 );
			} else {
				$line['price_with_vat'] = wc_format_decimal( $total + abs( (float) $item->get_total_tax() ), 2 );
			}
			$vat_codes = $this->settings->get_default_vat_codes();
			if ( ! empty( $vat_codes ) ) {
				$line['vat_code'] = $vat_codes;
			}
			$lines[] = $line;
		}

		foreach ( $refund->get_items( 'fee' ) as $item ) {
			$total = abs( (float) $item->get_total() );
			if ( $total <= 0 ) {
				continue;
			}
			$product_id = (int) get_option( 'robolabs_wc_fee_product_id', 0 );
			if ( ! $product_id ) {
				$this->last_error_message = __( 'Missing RoboLabs fee product for refunded fee line.', 'robolabs-woocommerce' );
				return array();
			}
			$line = array(
				'product_id'  => $product_id,
				'qty'         => 1,
				'description' => $item->get_name(),
			);
			if ( 'pass_taxes' === $tax_mode ) {
				$line['price'] = wc_format_decimal( $total, 2 );
				$line['vat'] = wc_format_decimal( abs( (float) $item->get_total_tax() ), 2 );
			} else {
				$line['price_with_vat'] = wc_format_decimal( $total + abs( (float) $item->get_total_tax() ), 2 );
			}
			$vat_codes = $this->settings->get_default_vat_codes();
			if ( ! empty( $vat_codes ) ) {
				$line['vat_code'] = $vat_codes;
			}
			$lines[] = $line;
		}

		return $lines;
	}

	private function resolve_product_vat_codes( WC_Product $product ): array {
		$meta_keys = array(
			'_robolabs_vat_code',
			'robolabs_vat_code',
		);

		foreach ( $meta_keys as $meta_key ) {
			$codes = $this->normalize_codes( $product->get_meta( $meta_key, true ) );
			if ( ! empty( $codes ) ) {
				return $codes;
			}
		}

		return $this->settings->get_default_vat_codes();
	}

	private function normalize_codes( $raw ): array {
		if ( is_array( $raw ) ) {
			$raw = implode( ',', array_map( 'strval', $raw ) );
		}

		$parts = preg_split( '/[\s,]+/', strtoupper( (string) $raw ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$codes = array();
		foreach ( $parts as $part ) {
			$code = preg_replace( '/[^A-Z0-9_-]/', '', sanitize_text_field( $part ) );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return array_values( array_unique( $codes ) );
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

	private function find_invoice_by_identifiers( array $identifiers ): ?array {
		foreach ( $identifiers as $field => $value ) {
			if ( ! $value ) {
				continue;
			}
			$response = $this->api_client->get( 'invoice/find', array( $field => $value ) );
			$items = $this->api_client->get_result_items( $response );
			if ( $response['success'] && ! empty( $items[0] ) ) {
				return $items[0];
			}
		}

		return null;
	}

	private function find_invoice_by_external_id( string $external_id ): ?array {
		if ( '' === $external_id ) {
			return null;
		}

		$response = $this->api_client->get( 'invoice/' . rawurlencode( $external_id ) );
		if ( ! $response['success'] ) {
			return null;
		}

		$invoice = $this->api_client->get_result( $response );
		return ! empty( $invoice['id'] ) ? $invoice : null;
	}

	private function create_credit_invoice( array $payload ): array {
		$response = $this->api_client->post( 'invoice', $payload );
		if ( $response['success'] || empty( $payload['number'] ) || ! $this->api_client->should_retry_without_number( $response ) ) {
			return $response;
		}

		$retry_payload = $payload;
		unset( $retry_payload['number'] );
		$this->logger->info(
			'Retrying credit note create without explicit number',
			array(
				'external_id' => $payload['external_id'] ?? '',
			)
		);

		return $this->api_client->post( 'invoice', $retry_payload );
	}
}
