<?php
/**
 * Plugin Name: RoboLabs WooCommerce Integration
 * Plugin URI: https://rankfastllc.com/
 * Description: Integrates WooCommerce with RoboLabs invoicing API. Automatically creates invoices in RoboLabs when orders are placed in WooCommerce, and syncs refunds as well. developed by Rankfast LLC | Rahim Badsa.
 * Version: 1.0.17
 * Author: Rankfastllc
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

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

if ( ! class_exists( 'RoboLabs_WooCommerce_Integration' ) ) {
	final class RoboLabs_WooCommerce_Integration {
		public const VERSION = '1.0.17';
		public const SLUG    = 'robolabs-woocommerce-integration';

		private static ?RoboLabs_WooCommerce_Integration $instance = null;
		private string $boot_error = '';

		public static function instance(): RoboLabs_WooCommerce_Integration {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		private function __construct() {
			if ( ! $this->includes() ) {
				$this->init_error_hooks();
				return;
			}

			$this->init_hooks();
		}

		private function includes(): bool {
			$files = array(
				'includes/class-logger.php',
				'includes/class-settings.php',
				'includes/class-api-client.php',
				'includes/class-mappers.php',
				'includes/class-sync-order.php',
				'includes/class-sync-refund.php',
				'includes/class-jobs.php',
				'includes/class-admin.php',
				'includes/class-plugin.php',
			);

			foreach ( $files as $relative_path ) {
				$file_path = $this->locate_plugin_file( $relative_path );
				if ( ! $file_path ) {
					$this->boot_error = sprintf(
						/* translators: %s: missing plugin file. */
						__( 'RoboLabs plugin could not load because the file "%s" is missing. Please remove the broken plugin folder and upload the latest ZIP again.', 'robolabs-woocommerce' ),
						$relative_path
					);
					return false;
				}

				require_once $file_path;
			}

			return true;
		}

		private function init_hooks(): void {
			add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', 20 );
			add_action( 'plugins_loaded', array( $this, 'boot' ) );
		}

		private function init_error_hooks(): void {
			add_action( 'admin_notices', array( $this, 'render_boot_error_notice' ) );
		}

		public function boot(): void {
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}

			RoboLabs_WC_Plugin::instance();
		}

		public function render_boot_error_notice(): void {
			if ( empty( $this->boot_error ) || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>' . esc_html( $this->boot_error ) . '</p></div>';
		}

		private function locate_plugin_file( string $relative_path ): ?string {
			$relative_path = ltrim( $relative_path, '/\\' );
			$normalized    = str_replace( '\\', '/', $relative_path );
			$candidates    = array(
				__DIR__ . '/' . $normalized,
				__DIR__ . '/' . str_replace( '/', '\\', $normalized ),
			);

			foreach ( $candidates as $candidate ) {
				if ( is_file( $candidate ) ) {
					return $candidate;
				}
			}

			if ( ! class_exists( 'RecursiveIteratorIterator' ) || ! class_exists( 'RecursiveDirectoryIterator' ) ) {
				return null;
			}

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( __DIR__, FilesystemIterator::SKIP_DOTS )
				);
			} catch ( Exception $exception ) {
				return null;
			}

			foreach ( $iterator as $file_info ) {
				if ( ! $file_info->isFile() ) {
					continue;
				}

				$filename = str_replace( '\\', '/', $file_info->getFilename() );
				$pathname = str_replace( '\\', '/', $file_info->getPathname() );
				if ( $filename === $normalized || str_ends_with( $pathname, '/' . $normalized ) ) {
					return $file_info->getPathname();
				}
			}

			return null;
		}
	}
}

RoboLabs_WooCommerce_Integration::instance();
