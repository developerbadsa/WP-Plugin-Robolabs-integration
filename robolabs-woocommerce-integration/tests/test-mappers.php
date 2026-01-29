<?php

class RoboLabs_WC_Mappers_Test extends WP_UnitTestCase {
	public function test_external_ids_are_prefixed() {
		$settings = new RoboLabs_WC_Settings();
		$mappers  = new RoboLabs_WC_Mappers( $settings );

		$this->assertSame( 'EWCPRD-123', $mappers->product_external_id( 123 ) );
		$this->assertSame( 'EWCINV-456', $mappers->invoice_external_id( 456 ) );
		$this->assertSame( 'EWCREF-456-789', $mappers->credit_external_id( 456, 789 ) );
	}
}
