<?php

class RoboLabs_WC_Mappers_Test extends WP_UnitTestCase {
	public function test_external_ids_are_prefixed() {
		$settings = new RoboLabs_WC_Settings();
		$mappers  = new RoboLabs_WC_Mappers( $settings );

		$this->assertSame( 'EWCPRD123', $mappers->product_external_id( 123 ) );
		$this->assertSame( 'EWCINV456', $mappers->invoice_external_id( 456 ) );
		$this->assertSame( 'EWCREF456789', $mappers->credit_external_id( 456, 789 ) );
	}
}
