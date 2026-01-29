<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Logger {
	private RoboLabs_WC_Settings $settings;
	private ?WC_Logger $logger = null;

	public function __construct( RoboLabs_WC_Settings $settings ) {
		$this->settings = $settings;
		if ( class_exists( 'WC_Logger' ) ) {
			$this->logger = wc_get_logger();
		}
	}

	public function log( string $level, string $message, array $context = array() ): void {
		if ( ! $this->settings->is_logging_enabled() || ! $this->logger ) {
			return;
		}

		$context['source'] = 'robolabs-woocommerce';
		$this->logger->log( $level, $message, $context );
	}

	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}
}
