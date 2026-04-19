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
			'ACCEPT-LANGUAGE'     => $this->settings->get_partner_language_code(),
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

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body_raw = wp_remote_retrieve_body( $response );
		$data = json_decode( $body_raw, true );

		$log_context = array(
			'code' => $code,
			'body' => $data,
		);
		if ( null === $data && '' !== $body_raw ) {
			$log_context['body_raw'] = $body_raw;
		}
		$this->logger->info( 'RoboLabs API response', $log_context );

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

	public function get_response_data( array $response ): array {
		$data = $response['data'] ?? array();
		return is_array( $data ) ? $data : array();
	}

	public function get_result( array $response ): array {
		$data = $this->get_response_data( $response );
		if ( isset( $data['result'] ) && is_array( $data['result'] ) ) {
			return $data['result'];
		}

		return $data;
	}

	public function get_result_items( array $response ): array {
		$result = $this->get_result( $response );
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			return array_values( $result['data'] );
		}

		return array();
	}

	public function get_job_id( array $response ): ?int {
		$data = $this->get_response_data( $response );
		if ( isset( $data['job_id'] ) && is_numeric( $data['job_id'] ) ) {
			return (int) $data['job_id'];
		}

		return null;
	}

	public function should_retry_without_number( array $response ): bool {
		$error = strtolower( (string) ( $response['error'] ?? '' ) );
		if ( '' === $error ) {
			return false;
		}

		return false !== strpos( $error, 'automatic assignment of an invoice number' )
			|| ( false !== strpos( $error, 'do not add' ) && false !== strpos( $error, 'number' ) );
	}

	private function extract_error_message( $data, string $body_raw ): string {
		if ( is_array( $data ) ) {
			foreach ( array( 'message', 'detail', 'response_message' ) as $key ) {
				if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
					return $data[ $key ];
				}
			}

			if ( isset( $data['error'] ) ) {
				if ( is_string( $data['error'] ) ) {
					return $data['error'];
				}
				if ( is_array( $data['error'] ) ) {
					foreach ( array( 'message', 'detail', 'data' ) as $key ) {
						if ( isset( $data['error'][ $key ] ) && is_string( $data['error'][ $key ] ) ) {
							return $data['error'][ $key ];
						}
					}
				}
			}

			if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
				$messages = array();
				foreach ( $data['errors'] as $error ) {
					if ( is_string( $error ) ) {
						$messages[] = $error;
					} elseif ( is_array( $error ) ) {
						foreach ( array( 'message', 'detail' ) as $key ) {
							if ( isset( $error[ $key ] ) && is_string( $error[ $key ] ) ) {
								$messages[] = $error[ $key ];
							}
						}
					}
				}
				if ( ! empty( $messages ) ) {
					return implode( '; ', array_unique( $messages ) );
				}
			}

			if ( isset( $data['result'] ) && is_string( $data['result'] ) ) {
				return $data['result'];
			}
		}

		return '' !== $body_raw ? $body_raw : 'RoboLabs API request failed';
	}
}
