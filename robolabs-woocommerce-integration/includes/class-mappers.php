<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Mappers {
	private RoboLabs_WC_Settings $settings;

	public function __construct( RoboLabs_WC_Settings $settings ) {
		$this->settings = $settings;
	}

	public function build_partner_payload( WC_Order $order ): array {
		$billing = $order->get_address( 'billing' );
		$email   = $order->get_billing_email();
		$company = $billing['company'] ?? '';
		$vat_code = $this->resolve_vat_code( $order );
		$first_name = trim( (string) $order->get_billing_first_name() );
		$last_name  = trim( (string) $order->get_billing_last_name() );
		$name_parts = trim( $first_name . ' ' . $last_name );
		$fallback_name = $this->resolve_fallback_name( $email );
		$street = trim( implode( ' ', array_filter( array( $billing['address_1'] ?? '', $billing['address_2'] ?? '' ) ) ) );
		$is_company = ! empty( $company ) || ! empty( $vat_code );
		return array(
			'code'        => $this->partner_external_id( $order ),
			'external_id' => $this->partner_external_id( $order ),
			'name'        => $company ?: ( $name_parts ?: $fallback_name ),
			'email'       => $email,
			'phone'       => $order->get_billing_phone(),
			'street'      => $street,
			'city'        => $billing['city'] ?? '',
			'zip'         => $billing['postcode'] ?? '',
			'country'     => $billing['country'] ?? '',
			'is_company'  => $is_company,
			'customer'    => true,
			'supplier'    => false,
			'vat_code'    => $vat_code,
			'vendor_on_sales_invoice' => false,
		);
	}

	public function build_product_payload( WC_Product $product ): array {
		$price       = (float) $product->get_price();
		$external_id = $this->product_external_id( $product->get_id() );
		$payload     = array(
			'default_code' => $external_id,
			'external_id' => $external_id,
			'name'        => $product->get_name(),
			'categ_id'    => $this->settings->get( 'categ_id' ),
			'price'       => wc_format_decimal( $price, 2 ),
			'type'        => $this->settings->get_product_type(),
		);
		$vat_codes = $this->resolve_product_vat_codes( $product );
		if ( ! empty( $vat_codes ) ) {
			$payload['vat_code'] = $vat_codes;
		}

		return $payload;
	}

	public function build_invoice_payload( WC_Order $order, int $partner_id, array $line_items ): array {
		$date_invoice = $order->get_date_created();
		$external_id = $this->invoice_external_id( $order->get_id() );
		$payload = array(
			'number'       => $order->get_order_number(),
			'external_id'  => $external_id,
			'reference'    => $order->get_order_number(),
			'currency'     => $order->get_currency(),
			'invoice_type' => $this->settings->get( 'invoice_type' ),
			'journal_id'   => $this->settings->get( 'journal_id' ),
			'partner_id'   => $partner_id,
			'date_invoice' => $date_invoice ? $date_invoice->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'language'     => $this->resolve_invoice_language_code(),
			'invoice_lines' => $line_items,
		);

		if ( ! $this->line_items_use_gross_prices( $line_items ) ) {
			$amounts = $this->calculate_amounts_from_lines(
				$line_items,
				(float) $order->get_total(),
				(float) $order->get_total_tax()
			);
			$payload['subtotal'] = $amounts['subtotal'];
			$payload['tax'] = $amounts['tax'];
			$payload['total'] = $amounts['total'];
		}

		return $payload;
	}

	public function build_credit_payload( WC_Order $order, int $partner_id, array $line_items, int $refund_id ): array {
		$date_invoice = $order->get_date_created();
		$external_id = $this->credit_external_id( $order->get_id(), $refund_id );
		return array(
			'number'       => $order->get_order_number() . '-CR',
			'external_id'  => $external_id,
			'reference'    => $order->get_order_number() . '-CR',
			'currency'     => $order->get_currency(),
			'invoice_type' => $this->settings->get( 'credit_invoice_type' ),
			'journal_id'   => $this->settings->get( 'journal_id' ),
			'partner_id'   => $partner_id,
			'date_invoice' => $date_invoice ? $date_invoice->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'language'     => $this->resolve_invoice_language_code(),
			'invoice_lines' => $line_items,
		);
	}

	public function build_line_item( WC_Order_Item_Product $item, int $product_id, string $tax_mode ): array {
		$qty = $item->get_quantity();
		$subtotal = (float) $item->get_subtotal();
		$subtotal_tax = (float) $item->get_subtotal_tax();
		$total = (float) $item->get_total();
		$total_tax = (float) $item->get_total_tax();
		$line = array(
			'product_id' => $product_id,
			'qty'        => $qty,
		);

		if ( 'pass_taxes' === $tax_mode ) {
			$unit_price = $qty > 0 ? $subtotal / $qty : 0.0;
			$line['price'] = wc_format_decimal( $unit_price, 2 );
			if ( $subtotal > $total && $subtotal > 0 ) {
				$line['discount'] = wc_format_decimal( ( ( $subtotal - $total ) / $subtotal ) * 100, 4 );
			}
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
		$vat_codes = $this->resolve_invoice_line_vat_codes( $item->get_product() );
		if ( ! empty( $vat_codes ) ) {
			$line['vat_code'] = $vat_codes;
		}
		return $line;
	}

	public function build_fee_line( WC_Order_Item_Fee $item, int $product_id, string $tax_mode ): ?array {
		$total = (float) $item->get_total();
		if ( abs( $total ) < 0.0001 ) {
			return null;
		}

		$line = array(
			'product_id'  => $product_id,
			'qty'         => 1,
			'description' => $item->get_name(),
		);
		if ( 'pass_taxes' === $tax_mode ) {
			$line['price'] = wc_format_decimal( $total, 2 );
			$line['vat'] = wc_format_decimal( (float) $item->get_total_tax(), 2 );
		} else {
			$line['price_with_vat'] = wc_format_decimal( $total + (float) $item->get_total_tax(), 2 );
		}
		$vat_codes = $this->settings->get_default_vat_codes();
		if ( ! empty( $vat_codes ) ) {
			$line['vat_code'] = $vat_codes;
		}

		return $line;
	}

	public function build_shipping_line( WC_Order $order, int $product_id, string $tax_mode ): array {
		$shipping_total = (float) $order->get_shipping_total();
		$shipping_tax = (float) $order->get_shipping_tax();
		$line = array(
			'product_id' => $product_id,
			'qty'        => 1,
		);
		if ( 'pass_taxes' === $tax_mode ) {
			$line['price'] = wc_format_decimal( $shipping_total, 2 );
			$line['vat'] = wc_format_decimal( $shipping_tax, 2 );
		} else {
			$line['price_with_vat'] = wc_format_decimal( $shipping_total + $shipping_tax, 2 );
		}
		$vat_codes = $this->settings->get_default_vat_codes();
		if ( ! empty( $vat_codes ) ) {
			$line['vat_code'] = $vat_codes;
		}
		return $line;
	}

	public function calculate_amounts_from_lines( array $line_items, ?float $expected_total = null, ?float $expected_tax = null ): array {
		$subtotal = 0.0;
		$line_tax = 0.0;

		foreach ( $line_items as $line ) {
			$qty = isset( $line['qty'] ) ? (float) $line['qty'] : 0.0;
			$price = isset( $line['price'] ) ? (float) $line['price'] : 0.0;
			$line_total = $qty * $price;
			if ( isset( $line['discount'] ) ) {
				$discount = max( 0.0, min( 100.0, (float) $line['discount'] ) );
				$line_total *= ( 100.0 - $discount ) / 100.0;
			}
			$subtotal += $line_total;

			if ( isset( $line['vat'] ) ) {
				$line_tax += (float) $line['vat'];
			}
		}

		$subtotal = round( $subtotal, 2 );
		$tax = $line_tax > 0 ? round( $line_tax, 2 ) : round( (float) $expected_tax, 2 );
		$total = round( $subtotal + $tax, 2 );
		$expected_total = null === $expected_total ? null : round( $expected_total, 2 );

		if ( null !== $expected_total ) {
			if ( abs( $expected_total - $total ) <= 0.02 ) {
				$total = $expected_total;
			} elseif ( abs( $expected_total - $subtotal ) <= 0.02 ) {
				$tax = 0.0;
				$total = $expected_total;
			}
		}

		return array(
			'subtotal' => wc_format_decimal( $subtotal, 2 ),
			'tax'      => wc_format_decimal( $tax, 2 ),
			'total'    => wc_format_decimal( $total, 2 ),
		);
	}

	public function line_items_use_gross_prices( array $line_items ): bool {
		foreach ( $line_items as $line ) {
			if ( isset( $line['price_with_vat'] ) && ! isset( $line['price'] ) ) {
				return true;
			}
		}

		return false;
	}

	public function build_discount_line( WC_Order $order, int $product_id ): ?array {
		$discount = (float) $order->get_discount_total();
		if ( $discount <= 0 ) {
			return null;
		}

		$line = array(
			'product_id' => $product_id,
			'qty'        => 1,
			'price'      => wc_format_decimal( -1 * $discount, 2 ),
		);
		$vat_codes = $this->settings->get_default_vat_codes();
		if ( ! empty( $vat_codes ) ) {
			$line['vat_code'] = $vat_codes;
		}

		return $line;
	}

	public function partner_external_id( WC_Order $order ): string {
		$email = (string) $order->get_billing_email();
		if ( $email ) {
			return $this->build_compact_code( 'EWCUSR', strtoupper( md5( $email ) ) );
		}

		return $this->build_compact_code( 'EWCUSR', (string) $order->get_id() );
	}

	public function product_external_id( int $product_id ): string {
		return $this->build_compact_code( 'EWCPRD', (string) $product_id );
	}

	public function invoice_external_id( int $order_id ): string {
		return $this->build_compact_code( 'EWCINV', (string) $order_id );
	}

	public function credit_external_id( int $order_id, int $refund_id ): string {
		return $this->build_compact_code( 'EWCREF', $order_id . $refund_id );
	}

	private function resolve_invoice_language_code(): string {
		return $this->settings->get_invoice_language_code();
	}

	private function resolve_vat_code( WC_Order $order ): string {
		$candidates = array(
			'vat_number',
			'_billing_vat',
			'_billing_vat_number',
			'billing_vat',
		);

		foreach ( $candidates as $key ) {
			$value = $order->get_meta( $key );
			if ( $value ) {
				return (string) $value;
			}
		}

		return '';
	}

	private function resolve_fallback_name( string $email ): string {
		if ( $email ) {
			$local_part = strstr( $email, '@', true );
			if ( $local_part ) {
				return $local_part;
			}
			return $email;
		}

		return __( 'Guest', 'robolabs-woocommerce' );
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

	private function resolve_invoice_line_vat_codes( $product ): array {
		if ( $product instanceof WC_Product ) {
			return $this->resolve_product_vat_codes( $product );
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

	private function build_compact_code( string $prefix, string $raw, int $length = 20 ): string {
		$prefix = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $prefix ) );
		$raw = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $raw ) );
		$max_payload = max( 1, $length - strlen( $prefix ) );
		$payload = substr( $raw, 0, $max_payload );
		$code = $prefix . $payload;

		return substr( $code, 0, $length );
	}
}
