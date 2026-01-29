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
			'name'        => $company ?: ( $name_parts ?: $fallback_name ),
			'email'       => $email,
			'phone'       => $order->get_billing_phone(),
			'street'      => $street,
			'city'        => $billing['city'] ?? '',
			'zip'         => $billing['postcode'] ?? '',
			'country'     => $billing['country'] ?? '',
			'language'    => $this->resolve_language_code(),
			'is_company'  => $is_company,
			'customer'    => true,
			'supplier'    => false,
			'vat_code'    => $vat_code,
		);
	}

	public function build_product_payload( WC_Product $product ): array {
		$price = (float) $product->get_price();
		return array(
			'default_code' => $this->product_external_id( $product->get_id() ),
			'name'        => $product->get_name(),
			'categ_id'    => $this->settings->get( 'categ_id' ),
			'price'       => wc_format_decimal( $price, 2 ),
			'type'        => 'product',
		);
	}

	public function build_invoice_payload( WC_Order $order, int $partner_id, array $line_items ): array {
		$date_invoice = $order->get_date_created();
		$payload = array(
			'number'       => $order->get_order_number(),
			'order_number' => $this->invoice_external_id( $order->get_id() ),
			'currency'     => $order->get_currency(),
			'invoice_type' => $this->settings->get( 'invoice_type' ),
			'journal_id'   => $this->settings->get( 'journal_id' ),
			'partner_id'   => $partner_id,
			'date_invoice' => $date_invoice ? $date_invoice->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'subtotal'     => wc_format_decimal( (float) $order->get_subtotal(), 2 ),
			'tax'          => wc_format_decimal( (float) $order->get_total_tax(), 2 ),
			'total'        => wc_format_decimal( (float) $order->get_total(), 2 ),
			'invoice_lines' => $line_items,
		);

		return $payload;
	}

	public function build_credit_payload( WC_Order $order, int $partner_id, array $line_items, int $refund_id ): array {
		$date_invoice = $order->get_date_created();
		return array(
			'number'       => $order->get_order_number() . '-CR',
			'order_number' => $this->credit_external_id( $order->get_id(), $refund_id ),
			'currency'     => $order->get_currency(),
			'invoice_type' => $this->settings->get( 'credit_invoice_type' ),
			'journal_id'   => $this->settings->get( 'journal_id' ),
			'partner_id'   => $partner_id,
			'date_invoice' => $date_invoice ? $date_invoice->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'invoice_lines' => $line_items,
		);
	}

	public function build_line_item( WC_Order_Item_Product $item, int $product_id, string $tax_mode ): array {
		$qty   = $item->get_quantity();
		$total = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();
		$unit_price = $qty > 0 ? $total / $qty : 0.0;
		$line = array(
			'product_id' => $product_id,
			'qty'        => $qty,
			'price'      => wc_format_decimal( $unit_price, 2 ),
		);
		if ( 'pass_taxes' === $tax_mode ) {
			$line['tax'] = wc_format_decimal( $tax, 2 );
		}
		return $line;
	}

	public function build_shipping_line( WC_Order $order, int $product_id, string $tax_mode ): array {
		$shipping_total = (float) $order->get_shipping_total();
		$shipping_tax = (float) $order->get_shipping_tax();
		$line = array(
			'product_id' => $product_id,
			'qty'        => 1,
			'price'      => wc_format_decimal( $shipping_total, 2 ),
		);
		if ( 'pass_taxes' === $tax_mode ) {
			$line['tax'] = wc_format_decimal( $shipping_tax, 2 );
		}
		return $line;
	}

	public function build_discount_line( WC_Order $order, int $product_id ): ?array {
		$discount = (float) $order->get_discount_total();
		if ( $discount <= 0 ) {
			return null;
		}

		return array(
			'product_id' => $product_id,
			'qty'        => 1,
			'price'      => wc_format_decimal( -1 * $discount, 2 ),
		);
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

	private function resolve_language_code(): string {
		$language = strtolower( (string) $this->settings->get( 'language', 'en_US' ) );
		if ( 0 === strpos( $language, 'lt' ) ) {
			return 'LT';
		}

		return 'EN';
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

	private function build_compact_code( string $prefix, string $raw, int $length = 20 ): string {
		$prefix = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $prefix ) );
		$raw = strtoupper( preg_replace( '/[^A-Z0-9]/', '', $raw ) );
		$max_payload = max( 1, $length - strlen( $prefix ) );
		$payload = substr( $raw, 0, $max_payload );
		$code = $prefix . $payload;

		return substr( $code, 0, $length );
	}
}
