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
	private ?string $last_error_message = null;

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
			if ( $order->get_meta( '_robolabs_invoice_id' ) && 'synced' === $order->get_meta( '_robolabs_sync_status' ) ) {
				$this->logger->info( 'Order already synced', array( 'order_id' => $order_id ) );
				return;
			}

			$this->last_error_code = null;
			$this->last_error_message = null;
			$configuration_error = $this->validate_order_sync_settings( $order );
			if ( $configuration_error ) {
				$this->mark_failed( $order, $configuration_error );
				return;
			}

			$partner_id = $this->ensure_partner( $order );
			if ( ! $partner_id ) {
				if ( in_array( $this->last_error_code, array( 401, 403 ), true ) ) {
					$this->mark_failed( $order, 'API credentials are invalid or missing permissions' );
					return;
				}
				if ( $this->maybe_schedule_retry( $order, $this->last_error_code ) ) {
					return;
				}
				$this->mark_failed( $order, $this->last_error_message ?: 'Partner sync failed' );
				return;
			}

			$this->last_error_code = null;
			$this->last_error_message = null;
			$line_items = $this->build_line_items( $order );
			if ( empty( $line_items ) ) {
				if ( $this->maybe_schedule_retry( $order, $this->last_error_code ) ) {
					return;
				}
				$this->mark_failed( $order, $this->last_error_message ?: 'No invoice lines to sync' );
				return;
			}

			$invoice_payload = $this->mappers->build_invoice_payload( $order, $partner_id, $line_items );

			$existing = $this->find_invoice_by_external_id( (string) ( $invoice_payload['external_id'] ?? '' ) );
			if ( ! $existing ) {
				$existing = $this->find_invoice_by_identifiers(
					array(
						'number'    => $invoice_payload['number'],
						'reference' => $invoice_payload['reference'],
					)
				);
			}
			if ( $existing ) {
				$existing_invoice_id = (int) ( $existing['id'] ?? 0 );
				if ( $existing_invoice_id && $this->invoice_is_confirmed( $existing ) ) {
					$this->update_order_invoice_meta( $order, $existing );
					return;
				}

				if ( $existing_invoice_id ) {
					$update_response = $this->update_invoice( $existing_invoice_id, $invoice_payload );
					if ( ! $update_response['success'] ) {
						if ( $this->maybe_schedule_retry( $order, $update_response['code'] ?? null ) ) {
							return;
						}
						$this->mark_failed( $order, $update_response['error'] ?? 'Invoice update failed', $existing );
						return;
					}

					if ( $this->confirm_invoice( $existing_invoice_id, $order ) ) {
						$this->update_order_invoice_meta( $order, $existing );
					} else {
						$this->mark_failed( $order, $this->last_error_message ?: 'Invoice confirm failed', $existing );
					}
					return;
				}

				$this->mark_failed( $order, 'Existing RoboLabs invoice was found but its ID is missing.', $existing );
				return;
			}

			$this->logger->info(
				'Prepared invoice payload',
				array(
					'order_id'     => $order->get_id(),
					'external_id'  => $invoice_payload['external_id'] ?? '',
					'number'       => $invoice_payload['number'] ?? '',
					'partner_id'   => $invoice_payload['partner_id'] ?? null,
					'subtotal'     => $invoice_payload['subtotal'] ?? null,
					'tax'          => $invoice_payload['tax'] ?? null,
					'total'        => $invoice_payload['total'] ?? null,
					'lines_count'  => isset( $invoice_payload['invoice_lines'] ) ? count( $invoice_payload['invoice_lines'] ) : 0,
				)
			);
			$response = $this->create_invoice( $invoice_payload );
			if ( ! $response['success'] ) {
				if ( in_array( $response['code'] ?? null, array( 401, 403 ), true ) ) {
					$this->mark_failed( $order, 'API credentials are invalid or missing permissions' );
					return;
				}
				if ( $this->maybe_schedule_retry( $order, $response['code'] ?? null ) ) {
					return;
				}
				$this->mark_failed( $order, $response['error'] ?? 'Invoice create failed' );
				return;
			}

			$job_id = $this->api_client->get_job_id( $response );
			if ( $job_id ) {
				$order->update_meta_data( '_robolabs_job_id', (string) $job_id );
				$order->update_meta_data( '_robolabs_sync_status', 'pending' );
				$order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
				$order->save();
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + 10, 'robolabs_poll_job', array( 'job_id' => $job_id, 'context' => array( 'order_id' => $order_id ) ), 'robolabs' );
				} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( 'robolabs_poll_job', array( 'job_id' => $job_id, 'context' => array( 'order_id' => $order_id ) ), 'robolabs' );
				}
				return;
			}

			$data = $this->api_client->get_result( $response );
			$invoice_id = $data['id'] ?? null;
			if ( $invoice_id ) {
				if ( $this->confirm_invoice( (int) $invoice_id, $order ) ) {
					$this->update_order_invoice_meta( $order, array( 'id' => $invoice_id, 'external_id' => $invoice_payload['external_id'] ) );
				} else {
					$this->mark_failed( $order, $this->last_error_message ?: 'Invoice confirm failed', array( 'id' => $invoice_id, 'external_id' => $invoice_payload['external_id'] ) );
				}
			}
		} finally {
			$this->release_lock( $order_id );
		}
	}

	public function finalize_async_invoice( int $order_id, int $invoice_id ): bool {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		if ( ! $this->confirm_invoice( $invoice_id, $order ) ) {
			$this->mark_failed( $order, $this->last_error_message ?: 'Invoice confirm failed', array( 'id' => $invoice_id, 'external_id' => $this->mappers->invoice_external_id( $order_id ) ) );
			return false;
		}

		$this->update_order_invoice_meta(
			$order,
			array(
				'id' => $invoice_id,
				'external_id' => $this->mappers->invoice_external_id( $order_id ),
			)
		);

		return true;
	}

	private function validate_order_sync_settings( WC_Order $order ): ?string {
		if ( '' === trim( (string) $this->settings->get( 'journal_id' ) ) ) {
			return __( 'Default Journal ID is required before syncing invoices.', 'robolabs-woocommerce' );
		}

		if ( ! $this->order_needs_product_creation( $order ) ) {
			return null;
		}

		if ( '' === trim( (string) $this->settings->get( 'categ_id' ) ) ) {
			return __( 'Default Product Category ID is required before RoboLabs products can be created.', 'robolabs-woocommerce' );
		}

		if ( empty( $this->settings->get_default_vat_codes() ) ) {
			return __( 'Default VAT Code(s) are required before RoboLabs products can be created.', 'robolabs-woocommerce' );
		}

		return null;
	}

	private function order_needs_product_creation( WC_Order $order ): bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( $product && ! $product->get_meta( '_robolabs_product_id', true ) ) {
				return true;
			}
		}

		if ( $order->get_shipping_total() > 0 && ! get_option( 'robolabs_wc_shipping_product_id', 0 ) ) {
			return true;
		}

		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			if ( abs( (float) $fee_item->get_total() ) > 0 && ! get_option( 'robolabs_wc_fee_product_id', 0 ) ) {
				return true;
			}
		}

		return false;
	}

	private function ensure_partner( WC_Order $order ): ?int {
		$partner_id = (int) $order->get_meta( '_robolabs_partner_id', true );
		if ( $partner_id ) {
			return $partner_id;
		}

		$external_id = $this->mappers->partner_external_id( $order );
		$existing = $this->find_partner_by_external_id( $external_id, $order->get_billing_email() );
		if ( $existing ) {
			$order->update_meta_data( '_robolabs_partner_id', $existing['id'] );
			$order->update_meta_data( '_robolabs_partner_external_id', $external_id );
			$order->save();
			if ( $order->get_user_id() ) {
				update_user_meta( $order->get_user_id(), '_robolabs_partner_id', $existing['id'] );
				update_user_meta( $order->get_user_id(), '_robolabs_partner_external_id', $external_id );
			}
			return (int) $existing['id'];
		}

		$partner_payload = $this->mappers->build_partner_payload( $order );
		if ( empty( $partner_payload['code'] ) || ! preg_match( '/^[A-Z0-9]{1,20}$/', $partner_payload['code'] ) ) {
			$partner_payload['code'] = $this->mappers->partner_external_id( $order );
		}
		$this->logger->info(
			'Prepared partner payload',
			array(
				'order_id'   => $order->get_id(),
				'name'       => $partner_payload['name'] ?? '',
				'email_set'  => ! empty( $partner_payload['email'] ),
				'is_company' => $partner_payload['is_company'] ?? false,
			)
		);
		$response = $this->api_client->post( 'partner', $partner_payload );
		if ( ! $response['success'] ) {
			$this->last_error_code = $response['code'] ?? null;
			$this->last_error_message = $response['error'] ?? 'Partner create failed';
			$this->logger->error( 'Partner create failed', array( 'order_id' => $order->get_id(), 'error' => $this->last_error_message ) );
			return null;
		}

		$data = $this->api_client->get_result( $response );
		if ( isset( $data['id'] ) ) {
			$order->update_meta_data( '_robolabs_partner_id', $data['id'] );
			$order->update_meta_data( '_robolabs_partner_external_id', $external_id );
			$order->save();
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
		$missing_product_line = false;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$product_id = $this->ensure_product( $product );
			if ( ! $product_id ) {
				$missing_product_line = true;
				continue;
			}
			$lines[] = $this->mappers->build_line_item( $item, $product_id, $tax_mode );
		}

		if ( $missing_product_line ) {
			if ( ! $this->last_error_message ) {
				$this->last_error_message = __( 'RoboLabs product sync failed for at least one order item.', 'robolabs-woocommerce' );
			}
			return array();
		}

		if ( $order->get_shipping_total() > 0 ) {
			$shipping_product_id = $this->ensure_shipping_product();
			if ( $shipping_product_id ) {
				$lines[] = $this->mappers->build_shipping_line( $order, $shipping_product_id, $tax_mode );
			}
		}

		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			if ( abs( (float) $fee_item->get_total() ) > 0 ) {
				$fee_product_id = $this->ensure_fee_product();
				if ( $fee_product_id ) {
					$fee_line = $this->mappers->build_fee_line( $fee_item, $fee_product_id, $tax_mode );
					if ( $fee_line ) {
						$lines[] = $fee_line;
					}
				}
			}
		}

		return $lines;
	}

	private function ensure_product( WC_Product $product ): ?int {
		$product_id = (int) $product->get_meta( '_robolabs_product_id', true );
		if ( $product_id ) {
			return $product_id;
		}

		$external_id = $this->mappers->product_external_id( $product->get_id() );
		$existing = $this->find_product_by_external_id( $external_id );
		if ( $existing ) {
			$product->update_meta_data( '_robolabs_product_id', $existing['id'] );
			$product->update_meta_data( '_robolabs_product_external_id', $external_id );
			$product->save();
			return (int) $existing['id'];
		}

		$payload = $this->mappers->build_product_payload( $product );
		if ( empty( $payload['vat_code'] ) ) {
			$this->last_error_message = __( 'Default VAT Code(s) are required before RoboLabs products can be created.', 'robolabs-woocommerce' );
			$this->logger->error( 'Product create skipped due to missing VAT code', array( 'product_id' => $product->get_id() ) );
			return null;
		}

		$response = $this->api_client->post( 'product', $payload );
		if ( ! $response['success'] ) {
			$this->last_error_code = $response['code'] ?? null;
			$this->last_error_message = $this->format_product_category_error( $response['error'] ?? 'Product create failed', $payload );
			$this->logger->error( 'Product create failed', array( 'product_id' => $product->get_id(), 'error' => $this->last_error_message ) );
			return null;
		}

		$data = $this->api_client->get_result( $response );
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

		$vat_codes = $this->settings->get_default_vat_codes();
		if ( empty( $vat_codes ) ) {
			$this->last_error_message = __( 'Default VAT Code(s) are required before RoboLabs products can be created.', 'robolabs-woocommerce' );
			return null;
		}

		$payload = array(
			'default_code' => 'EWCSHIP',
			'external_id'  => 'EWCSHIP',
			'name'         => __( 'Shipping', 'robolabs-woocommerce' ),
			'categ_id'     => $this->settings->get( 'categ_id' ),
			'vat_code'     => $vat_codes,
			'type'         => $this->settings->get_product_type(),
		);

		$existing = $this->find_product_by_external_id( 'EWCSHIP' );
		if ( $existing ) {
			update_option( 'robolabs_wc_shipping_product_id', (int) $existing['id'] );
			return (int) $existing['id'];
		}

		$response = $this->api_client->post( 'product', $payload );
		if ( ! $response['success'] ) {
			$this->last_error_message = $this->format_product_category_error( $response['error'] ?? 'Shipping product create failed', $payload );
			$this->logger->warning( 'Shipping product create failed', array( 'error' => $this->last_error_message ) );
			return null;
		}

		$data = $this->api_client->get_result( $response );
		if ( isset( $data['id'] ) ) {
			update_option( 'robolabs_wc_shipping_product_id', (int) $data['id'] );
			return (int) $data['id'];
		}

		return null;
	}

	private function confirm_invoice( int $invoice_id, WC_Order $order ): bool {
		$this->last_error_code = null;
		$this->last_error_message = null;
		$payload = $this->build_confirm_payload( $order );
		$response = $this->api_client->post( 'invoice/' . $invoice_id . '/confirm', $payload );
		if ( ! $response['success'] ) {
			$this->last_error_code = $response['code'] ?? null;
			$this->last_error_message = $response['error'] ?? 'Invoice confirm failed';
			$this->logger->warning( 'Invoice confirm failed', array( 'invoice_id' => $invoice_id, 'error' => $this->last_error_message ) );
			return false;
		}
		return true;
	}

	private function build_confirm_payload( WC_Order $order ): array {
		$total = (float) $order->get_total();
		if ( 'pass_taxes' !== $this->settings->get( 'tax_mode', 'robo_decide' ) ) {
			return array(
				'total' => wc_format_decimal( $total, 2 ),
			);
		}

		$tax = (float) $order->get_total_tax();
		$subtotal = max( 0, $total - $tax );
		return array(
			'subtotal' => wc_format_decimal( $subtotal, 2 ),
			'tax'      => wc_format_decimal( $tax, 2 ),
			'total'    => wc_format_decimal( $total, 2 ),
		);
	}

	private function update_order_invoice_meta( WC_Order $order, array $invoice ): void {
		$external_id = $invoice['external_id'] ?? $this->mappers->invoice_external_id( $order->get_id() );
		$order->update_meta_data( '_robolabs_invoice_id', $invoice['id'] ?? '' );
		$order->update_meta_data( '_robolabs_invoice_external_id', $external_id );
		$order->update_meta_data( '_robolabs_sync_status', 'synced' );
		$order->update_meta_data( '_robolabs_job_id', '' );
		$order->update_meta_data( '_robolabs_last_error', '' );
		$order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
		$order->update_meta_data( '_robolabs_retry_count', 0 );
		$order->save();
		$order->add_order_note( sprintf( __( 'RoboLabs invoice synced: %s', 'robolabs-woocommerce' ), $invoice['id'] ?? '' ) );
	}

	private function ensure_fee_product(): ?int {
		$stored = (int) get_option( 'robolabs_wc_fee_product_id', 0 );
		if ( $stored ) {
			return $stored;
		}

		$vat_codes = $this->settings->get_default_vat_codes();
		if ( empty( $vat_codes ) ) {
			$this->last_error_message = __( 'Default VAT Code(s) are required before RoboLabs products can be created.', 'robolabs-woocommerce' );
			return null;
		}

		$payload = array(
			'default_code' => 'EWCFEE',
			'external_id'  => 'EWCFEE',
			'name'         => __( 'Fee', 'robolabs-woocommerce' ),
			'categ_id'     => $this->settings->get( 'categ_id' ),
			'vat_code'     => $vat_codes,
			'type'         => $this->settings->get_product_type(),
		);

		$existing = $this->find_product_by_external_id( 'EWCFEE' );
		if ( $existing ) {
			update_option( 'robolabs_wc_fee_product_id', (int) $existing['id'] );
			return (int) $existing['id'];
		}

		$response = $this->api_client->post( 'product', $payload );
		if ( ! $response['success'] ) {
			$this->last_error_message = $this->format_product_category_error( $response['error'] ?? 'Fee product create failed', $payload );
			$this->logger->warning( 'Fee product create failed', array( 'error' => $this->last_error_message ) );
			return null;
		}

		$data = $this->api_client->get_result( $response );
		if ( isset( $data['id'] ) ) {
			update_option( 'robolabs_wc_fee_product_id', (int) $data['id'] );
			return (int) $data['id'];
		}

		return null;
	}

	private function ensure_discount_product(): ?int {
		$stored = (int) get_option( 'robolabs_wc_discount_product_id', 0 );
		if ( $stored ) {
			return $stored;
		}

		$vat_codes = $this->settings->get_default_vat_codes();
		if ( empty( $vat_codes ) ) {
			$this->last_error_message = __( 'Default VAT Code(s) are required before RoboLabs products can be created.', 'robolabs-woocommerce' );
			return null;
		}

		$payload = array(
			'default_code' => 'EWCDISC',
			'external_id'  => 'EWCDISC',
			'name'         => __( 'Discount', 'robolabs-woocommerce' ),
			'categ_id'     => $this->settings->get( 'categ_id' ),
			'vat_code'     => $vat_codes,
			'type'         => $this->settings->get_product_type(),
		);

		$existing = $this->find_product_by_external_id( 'EWCDISC' );
		if ( $existing ) {
			update_option( 'robolabs_wc_discount_product_id', (int) $existing['id'] );
			return (int) $existing['id'];
		}

		$response = $this->api_client->post( 'product', $payload );
		if ( ! $response['success'] ) {
			$this->last_error_message = $this->format_product_category_error( $response['error'] ?? 'Discount product create failed', $payload );
			$this->logger->warning( 'Discount product create failed', array( 'error' => $this->last_error_message ) );
			return null;
		}

		$data = $this->api_client->get_result( $response );
		if ( isset( $data['id'] ) ) {
			update_option( 'robolabs_wc_discount_product_id', (int) $data['id'] );
			return (int) $data['id'];
		}

		return null;
	}

	private function mark_failed( WC_Order $order, string $message, array $invoice = array() ): void {
		if ( ! empty( $invoice['id'] ) ) {
			$order->update_meta_data( '_robolabs_invoice_id', $invoice['id'] );
		}
		if ( ! empty( $invoice['external_id'] ) ) {
			$order->update_meta_data( '_robolabs_invoice_external_id', $invoice['external_id'] );
		}
		$order->update_meta_data( '_robolabs_sync_status', 'failed' );
		$order->update_meta_data( '_robolabs_last_error', sanitize_text_field( $message ) );
		$order->update_meta_data( '_robolabs_job_id', '' );
		$order->update_meta_data( '_robolabs_last_sync_at', gmdate( 'c' ) );
		$order->save();
		$order->add_order_note( sprintf( __( 'RoboLabs sync failed: %s', 'robolabs-woocommerce' ), $message ) );
		$this->logger->error( 'Order sync failed', array( 'order_id' => $order->get_id(), 'error' => $message ) );
	}

	private function format_product_category_error( string $message, array $payload ): string {
		if ( false === stripos( $message, 'internal category' ) ) {
			return $message;
		}

		return sprintf(
			'%s Check that Default Product Category ID %s is a RoboLabs product category compatible with product type %s.',
			$message,
			(string) ( $payload['categ_id'] ?? '' ),
			(string) ( $payload['type'] ?? 'product' )
		);
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
		$response = $this->api_client->get( 'partner/find', array( 'code' => $external_id ) );
		$items = $this->api_client->get_result_items( $response );
		if ( $response['success'] && ! empty( $items[0] ) ) {
			return $items[0];
		}

		if ( $email ) {
			$response = $this->api_client->get( 'partner/find', array( 'email' => $email ) );
			$items = $this->api_client->get_result_items( $response );
			if ( $response['success'] && ! empty( $items[0] ) ) {
				return $items[0];
			}
		}

		return null;
	}

	private function find_product_by_external_id( string $external_id ): ?array {
		$response = $this->api_client->get( 'product/find', array( 'default_code' => $external_id ) );
		$items = $this->api_client->get_result_items( $response );
		if ( $response['success'] && ! empty( $items[0] ) ) {
			return $items[0];
		}

		return null;
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

	private function invoice_is_confirmed( array $invoice ): bool {
		$state = $invoice['state'] ?? '';
		if ( is_array( $state ) ) {
			$state = reset( $state );
		}
		$state = strtolower( (string) $state );

		return in_array( $state, array( 'open', 'paid' ), true );
	}

	private function create_invoice( array $payload ): array {
		$response = $this->api_client->post( 'invoice', $payload );
		if ( $response['success'] || empty( $payload['number'] ) || ! $this->api_client->should_retry_without_number( $response ) ) {
			return $response;
		}

		$retry_payload = $payload;
		unset( $retry_payload['number'] );
		$this->logger->info(
			'Retrying invoice create without explicit number',
			array(
				'external_id' => $payload['external_id'] ?? '',
			)
		);

		return $this->api_client->post( 'invoice', $retry_payload );
	}

	private function update_invoice( int $invoice_id, array $payload ): array {
		$response = $this->api_client->put( 'invoice/' . $invoice_id, $payload );
		if ( $response['success'] || empty( $payload['number'] ) || ! $this->api_client->should_retry_without_number( $response ) ) {
			return $response;
		}

		$retry_payload = $payload;
		unset( $retry_payload['number'] );
		$this->logger->info(
			'Retrying invoice update without explicit number',
			array(
				'invoice_id'  => $invoice_id,
				'external_id' => $payload['external_id'] ?? '',
			)
		);

		return $this->api_client->put( 'invoice/' . $invoice_id, $retry_payload );
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
