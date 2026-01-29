<?php
/**
 * Plugin Name: RoboLabs WooCommerce Integration
 * Plugin URI: https://robolabs.lt
 * Description: Integrates WooCommerce with RoboLabs invoicing API.
 * Version: 1.0.0
 * Author: RoboLabs
 * Text Domain: robolabs-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'RoboLabs_WooCommerce_Integration' ) ) {
	final class RoboLabs_WooCommerce_Integration {
		public const VERSION = '1.0.0';
		public const SLUG    = 'robolabs-woocommerce-integration';

		private static ?RoboLabs_WooCommerce_Integration $instance = null;

		public static function instance(): RoboLabs_WooCommerce_Integration {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		private function __construct() {
			$this->includes();
			$this->init_hooks();
		}

		private function includes(): void {
			require_once __DIR__ . '/includes/class-logger.php';
			require_once __DIR__ . '/includes/class-settings.php';
			require_once __DIR__ . '/includes/class-api-client.php';
			require_once __DIR__ . '/includes/class-mappers.php';
			require_once __DIR__ . '/includes/class-sync-order.php';
			require_once __DIR__ . '/includes/class-sync-refund.php';
			require_once __DIR__ . '/includes/class-jobs.php';
			require_once __DIR__ . '/includes/class-admin.php';
			require_once __DIR__ . '/includes/class-plugin.php';
		}

		private function init_hooks(): void {
			add_action( 'plugins_loaded', array( $this, 'boot' ) );
		}

		public function boot(): void {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			RoboLabs_WC_Plugin::instance();
		}
	}
}

RoboLabs_WooCommerce_Integration::instance();
