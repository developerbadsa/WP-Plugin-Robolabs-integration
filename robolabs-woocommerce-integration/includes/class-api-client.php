<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Api_Client {
	private RoboLabs_WC_Settings $settings;
	private RoboLabs_WC_Logger $logger;

	public function __construct( RoboLabs_WC_Settings $settings, RoboLabs_WC_Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function get( string $endpoint, array $args = array() ): array {
		return $this->request( 'GET', $endpoint, $args );
	}

	public function post( string $endpoint, array $body = array() ): array {
		return $this->request( 'POST', $endpoint, array(), $body );
	}

	public function put( string $endpoint, array $body = array() ): array {
		return $this->request( 'PUT', $endpoint, array(), $body );
	}

	public function request( string $method, string $endpoint, array $query = array(), array $body = array() ): array {
		$base_url = $this->settings->get_base_url();
		$url      = trailingslashit( $base_url ) . ltrim( $endpoint, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$headers = array(
			'X-API-KEY'           => $this->settings->get_api_key(),
			'Accept'              => 'application/json',
			'ACCEPT-LANGUAGE'     => $this->settings->get( 'language', 'en_US' ),
			'EXECUTE_IMMEDIATELY' => $this->settings->is_execute_immediately() ? 'true' : 'false',
		);
		unset( $headers['Authorization'] );

		$has_body = ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
		if ( $has_body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'timeout' => 20,
			'headers' => $headers,
			'method'  => $method,
		);
		if ( $has_body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$masked_headers = $headers;
		$masked_headers['X-API-KEY'] = '****';
		$this->logger->info( sprintf( 'RoboLabs API request: %s %s', $method, $url ), array( 'headers' => $masked_headers ) );

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'code'    => 0,
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_raw, true );

		$this->logger->info( 'RoboLabs API response', array( 'code' => $code, 'body' => $data ) );

		if ( 429 === $code ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'Retry-After' );
			return array(
				'success' => false,
				'error'   => 'Rate limit exceeded',
				'code'    => $code,
				'retry_after' => $retry_after,
			);
		}

		if ( $code >= 400 ) {
			$error_message = $this->extract_error_message( $data, $body_raw );
			return array(
				'success' => false,
				'error'   => $error_message,
				'code'    => $code,
				'data'    => $data,
			);
		}

		return array(
			'success' => true,
			'code'    => $code,
			'data'    => $data,
		);
	}

	private function extract_error_message( $data, string $body_raw ): string {
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				return (string) $data['error']['message'];
			}
			if ( isset( $data['message'] ) ) {
				return (string) $data['message'];
			}
			if ( isset( $data['result'] ) && is_string( $data['result'] ) ) {
				return $data['result'];
			}
		}

		return $body_raw;
	}
}
