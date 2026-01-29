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
		return array(
			'code'        => $this->partner_external_id( $order ),
			'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email'       => $email,
			'phone'       => $order->get_billing_phone(),
			'address'     => array(
				'address1' => $billing['address_1'] ?? '',
				'address2' => $billing['address_2'] ?? '',
				'city'     => $billing['city'] ?? '',
				'zip'      => $billing['postcode'] ?? '',
				'country'  => $billing['country'] ?? '',
			),
		);
	}

	public function build_product_payload( WC_Product $product ): array {
		return array(
			'default_code' => $this->product_external_id( $product->get_id() ),
			'name'        => $product->get_name(),
			'sku'         => $product->get_sku(),
			'categ_id'    => $this->settings->get( 'categ_id' ),
		);
	}

	public function build_invoice_payload( WC_Order $order, int $partner_id, array $line_items ): array {
		$payload = array(
			'number'       => $order->get_order_number(),
			'order_number' => $this->invoice_external_id( $order->get_id() ),
			'currency'     => $order->get_currency(),
			'invoice_type' => $this->settings->get( 'invoice_type' ),
			'journal_id'   => $this->settings->get( 'journal_id' ),
			'partner_id'   => $partner_id,
			'invoice_lines' => $line_items,
		);

		return $payload;
	}

	public function build_credit_payload( WC_Order $order, int $partner_id, array $line_items, int $refund_id ): array {
		return array(
			'number'       => $order->get_order_number() . '-CR',
			'order_number' => $this->credit_external_id( $order->get_id(), $refund_id ),
			'currency'     => $order->get_currency(),
			'invoice_type' => $this->settings->get( 'credit_invoice_type' ),
			'journal_id'   => $this->settings->get( 'journal_id' ),
			'partner_id'   => $partner_id,
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

	public function build_discount_line( WC_Order $order ): ?array {
		$discount = (float) $order->get_discount_total();
		if ( $discount <= 0 ) {
			return null;
		}

		return array(
			'name'  => __( 'Discount', 'robolabs-woocommerce' ),
			'qty'   => 1,
			'price' => wc_format_decimal( -1 * $discount, 2 ),
		);
	}

	public function partner_external_id( WC_Order $order ): string {
		$user_id = $order->get_user_id();
		if ( $user_id ) {
			return 'EWCUSR-' . $user_id;
		}

		return 'EWCUSR-' . md5( (string) $order->get_billing_email() );
	}

	public function product_external_id( int $product_id ): string {
		return 'EWCPRD-' . $product_id;
	}

	public function invoice_external_id( int $order_id ): string {
		return 'EWCINV-' . $order_id;
	}

	public function credit_external_id( int $order_id, int $refund_id ): string {
		return 'EWCREF-' . $order_id . '-' . $refund_id;
	}
}
