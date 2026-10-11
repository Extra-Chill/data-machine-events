<?php
/**
 * Batch source key selection tests (#909).
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\StructuredDataProcessor;

class StructuredDataProcessorSourceKeyTest extends WP_UnitTestCase {

	public function test_unique_ticket_urls_become_keys(): void {
		$keys = StructuredDataProcessor::batchSourceKeys(
			array(
				array( 'ticketUrl' => 'https://venue.test/shows/1' ),
				array( 'ticketUrl' => 'https://venue.test/shows/2' ),
			)
		);

		$this->assertSame( array( 'https://venue.test/shows/1', 'https://venue.test/shows/2' ), $keys );
	}

	public function test_shared_ticket_url_identifies_no_event(): void {
		$keys = StructuredDataProcessor::batchSourceKeys(
			array(
				array( 'ticketUrl' => 'https://tickets.test/orders/new' ),
				array( 'ticketUrl' => 'https://tickets.test/orders/new' ),
				array( 'ticketUrl' => 'https://venue.test/shows/3' ),
			)
		);

		$this->assertSame( array( '', '', 'https://venue.test/shows/3' ), $keys );
	}

	public function test_declared_key_beats_ticket_url_and_missing_is_empty(): void {
		$keys = StructuredDataProcessor::batchSourceKeys(
			array(
				array(
					'sourceEventKey' => 'wix:abc',
					'ticketUrl'      => 'https://tickets.test/orders/new',
				),
				array(
					'sourceEventKey' => 'wix:def',
					'ticketUrl'      => 'https://tickets.test/orders/new',
				),
				array( 'title' => 'No link' ),
			)
		);

		$this->assertSame( array( 'wix:abc', 'wix:def', '' ), $keys );
	}
}
