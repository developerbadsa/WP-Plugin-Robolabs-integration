<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RoboLabs_WC_Plugin {
	private static ?RoboLabs_WC_Plugin $instance = null;

	public RoboLabs_WC_Settings $settings;
	public RoboLabs_WC_Api_Client $api_client;
	public RoboLabs_WC_Logger $logger;
	public RoboLabs_WC_Jobs $jobs;
	public RoboLabs_WC_Admin $admin;
	public RoboLabs_WC_Sync_Order $sync_order;
	public RoboLabs_WC_Sync_Refund $sync_refund;
	public RoboLabs_WC_Mappers $mappers;

	public static function instance(): RoboLabs_WC_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->settings    = new RoboLabs_WC_Settings();
		$this->logger      = new RoboLabs_WC_Logger( $this->settings );
		$this->api_client  = new RoboLabs_WC_Api_Client( $this->settings, $this->logger );
		$this->mappers     = new RoboLabs_WC_Mappers( $this->settings );
		$this->sync_order  = new RoboLabs_WC_Sync_Order( $this->settings, $this->api_client, $this->logger, $this->mappers );
		$this->sync_refund = new RoboLabs_WC_Sync_Refund( $this->settings, $this->api_client, $this->logger, $this->mappers );
		$this->jobs        = new RoboLabs_WC_Jobs( $this->settings, $this->logger, $this->sync_order, $this->sync_refund, $this->api_client );
		$this->admin       = new RoboLabs_WC_Admin( $this->settings, $this->logger, $this->jobs );
		$this->hooks();
	}

	private function hooks(): void {
		$this->jobs->register();
		$this->admin->register();
	}
}
