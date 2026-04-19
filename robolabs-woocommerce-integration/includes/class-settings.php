<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Settings {
	public const OPTION_KEY = 'robolabs_wc_settings';

	private array $defaults = array(
		'base_url_mode'       => 'sandbox',
		'base_url_custom'     => '',
		'api_key'             => '',
		'language'            => 'en_US',
		'execute_immediately' => 'yes',
		'journal_id'          => '22',
		'categ_id'            => '16',
		'product_type'        => 'product',
		'default_vat_code'    => 'PVM1',
		'invoice_trigger'     => 'order_created',
		'invoice_type'        => 'out_invoice',
		'credit_invoice_type' => 'out_refund',
		'tax_mode'            => 'robo_decide',
		'log_level'           => 'yes',
		'max_attempts'        => 4,
		'lock_ttl'            => 300,
	);

	public function get_settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, $this->defaults );

		foreach ( array( 'journal_id', 'categ_id', 'default_vat_code' ) as $required_key ) {
			if ( ! isset( $settings[ $required_key ] ) || '' === trim( (string) $settings[ $required_key ] ) ) {
				$settings[ $required_key ] = $this->defaults[ $required_key ];
			}
		}

		return $settings;
	}

	public function get( string $key, $default = null ) {
		$settings = $this->get_settings();
		return $settings[ $key ] ?? $default;
	}

	public function get_api_key(): string {
		if ( defined( 'ROBOLABS_API_KEY' ) && ROBOLABS_API_KEY ) {
			return (string) ROBOLABS_API_KEY;
		}

		return (string) $this->get( 'api_key', '' );
	}

	public function get_base_url(): string {
		if ( defined( 'ROBOLABS_API_BASE' ) && ROBOLABS_API_BASE ) {
			return rtrim( (string) ROBOLABS_API_BASE, '/' );
		}

		$mode = $this->get( 'base_url_mode', 'sandbox' );
		if ( 'production' === $mode && defined( 'ROBOLABS_API_BASE_PROD' ) && ROBOLABS_API_BASE_PROD ) {
			return rtrim( (string) ROBOLABS_API_BASE_PROD, '/' );
		}

		switch ( $mode ) {
			case 'production':
				return 'https://api.robolabs.lt/api/v2';
			case 'custom':
				return rtrim( (string) $this->get( 'base_url_custom', '' ), '/' );
			case 'sandbox':
			default:
				return 'https://sandbox.robolabs.lt/api/v2';
		}
	}

	public function is_logging_enabled(): bool {
		return 'yes' === $this->get( 'log_level', 'yes' );
	}

	public function is_execute_immediately(): bool {
		return 'yes' === $this->get( 'execute_immediately', 'yes' );
	}

	public function get_max_attempts(): int {
		return (int) $this->get( 'max_attempts', 4 );
	}

	public function get_lock_ttl(): int {
		return (int) $this->get( 'lock_ttl', 300 );
	}

	public function get_default_vat_codes(): array {
		return $this->normalize_code_list( (string) $this->get( 'default_vat_code', '' ) );
	}

	public function get_product_type(): string {
		return $this->sanitize_product_type( $this->get( 'product_type', 'product' ) );
	}

	public function get_partner_language_code(): string {
		$language = strtolower( (string) $this->get( 'language', 'en_US' ) );
		if ( 0 === strpos( $language, 'lt' ) ) {
			return 'lt_LT';
		}

		return 'en_US';
	}

	public function get_invoice_language_code(): string {
		return 'lt_LT' === $this->get_partner_language_code() ? 'LT' : 'EN';
	}

	public function register(): void {
		register_setting(
			'robolabs_wc_settings',
			self::OPTION_KEY,
			array( $this, 'sanitize' )
		);
	}

	public function sanitize( array $settings ): array {
		$clean = array();
		$existing = $this->get_settings();

		$clean['base_url_mode']       = sanitize_text_field( $settings['base_url_mode'] ?? 'sandbox' );
		$clean['base_url_custom']     = esc_url_raw( $settings['base_url_custom'] ?? '' );
		if ( defined( 'ROBOLABS_API_KEY' ) && ROBOLABS_API_KEY ) {
			$clean['api_key'] = $existing['api_key'] ?? '';
		} else {
			$submitted_key = sanitize_text_field( $settings['api_key'] ?? '' );
			$clean['api_key'] = '' !== $submitted_key ? $submitted_key : ( $existing['api_key'] ?? '' );
		}
		$clean['language']            = $this->sanitize_language( $settings['language'] ?? 'en_US' );
		$clean['execute_immediately'] = isset( $settings['execute_immediately'] ) ? 'yes' : 'no';
		$clean['journal_id']          = $this->sanitize_required_default( $settings['journal_id'] ?? '', 'journal_id' );
		$clean['categ_id']            = $this->sanitize_required_default( $settings['categ_id'] ?? '', 'categ_id' );
		$clean['product_type']        = $this->sanitize_product_type( $settings['product_type'] ?? 'product' );
		$clean['default_vat_code']    = $this->sanitize_default_vat_code( $settings['default_vat_code'] ?? '' );
		$clean['invoice_trigger']     = sanitize_text_field( $settings['invoice_trigger'] ?? 'order_created' );
		$clean['invoice_type']        = sanitize_text_field( $settings['invoice_type'] ?? 'out_invoice' );
		$clean['credit_invoice_type'] = sanitize_text_field( $settings['credit_invoice_type'] ?? 'out_refund' );
		$clean['tax_mode']            = sanitize_text_field( $settings['tax_mode'] ?? 'robo_decide' );
		$clean['log_level']           = isset( $settings['log_level'] ) ? 'yes' : 'no';
		$clean['max_attempts']        = absint( $settings['max_attempts'] ?? 4 );
		$clean['lock_ttl']            = absint( $settings['lock_ttl'] ?? 300 );

		if (
			(string) ( $existing['categ_id'] ?? '' ) !== (string) $clean['categ_id']
			|| (string) ( $existing['product_type'] ?? $this->defaults['product_type'] ) !== (string) $clean['product_type']
			|| (string) ( $existing['default_vat_code'] ?? '' ) !== (string) $clean['default_vat_code']
		) {
			delete_option( 'robolabs_wc_shipping_product_id' );
			delete_option( 'robolabs_wc_fee_product_id' );
			delete_option( 'robolabs_wc_discount_product_id' );
		}

		return $clean;
	}

	public function render_settings_page( bool $include_wrap = true ): void {
		$settings = $this->get_settings();
		$api_key  = $this->get_api_key();
		$api_key_masked = $api_key ? substr( $api_key, 0, 4 ) . str_repeat( '*', max( 0, strlen( $api_key ) - 8 ) ) . substr( $api_key, -4 ) : '';
		$api_key_constant = defined( 'ROBOLABS_API_KEY' ) && ROBOLABS_API_KEY;
		$base_url_constant = defined( 'ROBOLABS_API_BASE' ) && ROBOLABS_API_BASE;
		?>
		<?php if ( $include_wrap ) : ?>
			<div class="wrap">
				<h1><?php esc_html_e( 'RoboLabs WooCommerce Integration', 'robolabs-woocommerce' ); ?></h1>
		<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'robolabs_wc_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Base URL', 'robolabs-woocommerce' ); ?></th>
						<td>
							<fieldset>
								<label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[base_url_mode]" value="sandbox" <?php checked( 'sandbox', $settings['base_url_mode'] ); ?>> <?php esc_html_e( 'Sandbox', 'robolabs-woocommerce' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[base_url_mode]" value="production" <?php checked( 'production', $settings['base_url_mode'] ); ?>> <?php esc_html_e( 'Production', 'robolabs-woocommerce' ); ?></label><br>
								<label><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[base_url_mode]" value="custom" <?php checked( 'custom', $settings['base_url_mode'] ); ?>> <?php esc_html_e( 'Custom', 'robolabs-woocommerce' ); ?></label>
							</fieldset>
							<p><input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[base_url_custom]" value="<?php echo esc_attr( $settings['base_url_custom'] ); ?>" <?php disabled( $base_url_constant ); ?>></p>
							<?php if ( $base_url_constant ) : ?>
								<p class="description"><?php esc_html_e( 'Base URL is set via ROBOLABS_API_BASE constant.', 'robolabs-woocommerce' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'API Key', 'robolabs-woocommerce' ); ?></th>
						<td>
							<input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="" autocomplete="off" <?php disabled( $api_key_constant ); ?>>
							<?php if ( $api_key ) : ?>
								<p class="description"><?php echo esc_html( $api_key_masked ); ?></p>
							<?php endif; ?>
							<?php if ( $api_key_constant ) : ?>
								<p class="description"><?php esc_html_e( 'API key is set via ROBOLABS_API_KEY constant.', 'robolabs-woocommerce' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Language', 'robolabs-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[language]">
								<option value="en_US" <?php selected( 'en_US', $settings['language'] ); ?>>en_US</option>
								<option value="lt_LT" <?php selected( 'lt_LT', $settings['language'] ); ?>>lt_LT</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Execute Immediately', 'robolabs-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[execute_immediately]" <?php checked( 'yes', $settings['execute_immediately'] ); ?>> <?php esc_html_e( 'Send EXECUTE_IMMEDIATELY=true header', 'robolabs-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Journal ID', 'robolabs-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[journal_id]" value="<?php echo esc_attr( $settings['journal_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'Required for invoice and credit note creation.', 'robolabs-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Product Category ID', 'robolabs-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[categ_id]" value="<?php echo esc_attr( $settings['categ_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'Required when the plugin creates missing RoboLabs products, shipping items, or fee items. Use a RoboLabs product category compatible with the selected RoboLabs product type.', 'robolabs-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'RoboLabs Product Type', 'robolabs-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[product_type]">
								<option value="product" <?php selected( 'product', $settings['product_type'] ); ?>><?php esc_html_e( 'Product (product)', 'robolabs-woocommerce' ); ?></option>
								<option value="service" <?php selected( 'service', $settings['product_type'] ); ?>><?php esc_html_e( 'Service (service)', 'robolabs-woocommerce' ); ?></option>
								<option value="consu" <?php selected( 'consu', $settings['product_type'] ); ?>><?php esc_html_e( 'Consumable (consu)', 'robolabs-woocommerce' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Docs allow product, service, or consu. If productCategory/find returns type services for your chosen category, use Service.', 'robolabs-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default VAT Code(s)', 'robolabs-woocommerce' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[default_vat_code]" value="<?php echo esc_attr( $settings['default_vat_code'] ); ?>">
							<p class="description"><?php esc_html_e( 'Required for RoboLabs product creation and invoice lines when the RoboLabs company is registered as a VAT payer. Use comma-separated codes, for example: PVM1', 'robolabs-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Invoice Trigger', 'robolabs-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[invoice_trigger]">
								<option value="order_created" <?php selected( 'order_created', $settings['invoice_trigger'] ); ?>><?php esc_html_e( 'Order created', 'robolabs-woocommerce' ); ?></option>
								<option value="payment_complete" <?php selected( 'payment_complete', $settings['invoice_trigger'] ); ?>><?php esc_html_e( 'Payment complete', 'robolabs-woocommerce' ); ?></option>
								<option value="status_processing" <?php selected( 'status_processing', $settings['invoice_trigger'] ); ?>><?php esc_html_e( 'Status processing', 'robolabs-woocommerce' ); ?></option>
								<option value="status_completed" <?php selected( 'status_completed', $settings['invoice_trigger'] ); ?>><?php esc_html_e( 'Status completed', 'robolabs-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Invoice Type', 'robolabs-woocommerce' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[invoice_type]" value="<?php echo esc_attr( $settings['invoice_type'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Credit Note Invoice Type', 'robolabs-woocommerce' ); ?></th>
						<td><input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[credit_invoice_type]" value="<?php echo esc_attr( $settings['credit_invoice_type'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'VAT/Tax Mode', 'robolabs-woocommerce' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[tax_mode]">
								<option value="robo_decide" <?php selected( 'robo_decide', $settings['tax_mode'] ); ?>><?php esc_html_e( 'Let RoboLabs decide', 'robolabs-woocommerce' ); ?></option>
								<option value="pass_taxes" <?php selected( 'pass_taxes', $settings['tax_mode'] ); ?>><?php esc_html_e( 'Pass taxes from WooCommerce', 'robolabs-woocommerce' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logging', 'robolabs-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[log_level]" <?php checked( 'yes', $settings['log_level'] ); ?>> <?php esc_html_e( 'Enable debug logging', 'robolabs-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Max Attempts', 'robolabs-woocommerce' ); ?></th>
						<td><input type="number" min="1" max="10" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_attempts]" value="<?php echo esc_attr( $settings['max_attempts'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Order Lock TTL (seconds)', 'robolabs-woocommerce' ); ?></th>
						<td><input type="number" min="60" max="3600" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[lock_ttl]" value="<?php echo esc_attr( $settings['lock_ttl'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		<?php if ( $include_wrap ) : ?>
			</div>
		<?php endif; ?>
		<?php
	}

	private function normalize_code_list( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', strtoupper( $raw ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$codes = array();
		foreach ( $parts as $part ) {
			$code = sanitize_text_field( $part );
			$code = preg_replace( '/[^A-Z0-9_-]/', '', $code );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	private function sanitize_required_default( $value, string $key ): string {
		$clean = sanitize_text_field( (string) $value );
		if ( '' === $clean ) {
			return (string) $this->defaults[ $key ];
		}

		return $clean;
	}

	private function sanitize_default_vat_code( $value ): string {
		$codes = $this->normalize_code_list( (string) $value );
		if ( empty( $codes ) ) {
			$codes = $this->normalize_code_list( (string) $this->defaults['default_vat_code'] );
		}

		return implode( ',', $codes );
	}

	private function sanitize_language( $value ): string {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		if ( 0 === strpos( $value, 'lt' ) ) {
			return 'lt_LT';
		}

		return 'en_US';
	}

	private function sanitize_product_type( $value ): string {
		$value = strtolower( sanitize_text_field( (string) $value ) );
		if ( in_array( $value, array( 'product', 'service', 'consu' ), true ) ) {
			return $value;
		}

		return 'product';
	}
}
