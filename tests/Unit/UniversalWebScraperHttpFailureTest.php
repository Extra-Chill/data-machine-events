<?php
/**
 * HTTP failure propagation for the universal scraper.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Failed transport must escape FetchHandler's empty-success normalization.
 */
class UniversalWebScraperHttpFailureTest extends WP_UnitTestCase {

	/**
	 * Cover direct ICS and ordinary HTML (including ai1ec export URLs).
	 *
	 * @dataProvider sourceUrls
	 * @param string $url Source URL.
	 */
	public function test_403_after_retry_throws( string $url ): void {
		$requests = array();
		$mock     = static function ( $preempt, $args, $request_url ) use ( &$requests ) {
			$requests[] = array( $request_url, $args['headers'] );
			return array(
				'headers'  => array(),
				'body'     => '<html><title>Just a moment...</title></html>',
				'response' => array(
					'code'    => 403,
					'message' => 'Forbidden',
				),
			);
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );

		try {
			$this->fetch( $url );
		} catch ( RuntimeException $error ) {
			$this->assertStringContainsString( '403', $error->getMessage() );
			$this->assertStringContainsString( esc_html( $url ), $error->getMessage() );
			$this->assertCount( 2, $requests );
			$this->assertSame( $url, $requests[0][0] );
			$this->assertSame( $url, $requests[1][0] );
			$this->assertNotSame( $requests[0][1], $requests[1][1] );
			return;
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->fail( 'HTTP 403 must not become an empty successful fetch.' );
	}

	/**
	 * Source URL cases.
	 *
	 * @return array
	 */
	public static function sourceUrls(): array {
		return array(
			'ics'   => array( 'https://venue.example/events.ics' ),
			'html'  => array( 'https://venue.example/events/' ),
			'ai1ec' => array( 'https://venue.example/?plugin=all-in-one-event-calendar&controller=ai1ec_exporter_controller&action=export_events&no_html=true' ),
		);
	}

	/**
	 * A blocked first attempt can still recover to a genuinely empty feed.
	 */
	public function test_successful_retry_is_not_a_failure(): void {
		$requests = 0;
		$mock     = static function () use ( &$requests ) {
			++$requests;
			return array(
				'headers'  => array(),
				'body'     => 1 === $requests ? 'Forbidden' : "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nEND:VCALENDAR\r\n",
				'response' => array( 'code' => 1 === $requests ? 403 : 200 ),
			);
		};
		add_filter( 'pre_http_request', $mock );

		try {
			$this->assertSame( array(), $this->fetch( 'https://venue.example/events.ics' ) );
			$this->assertSame( 2, $requests );
		} finally {
			remove_filter( 'pre_http_request', $mock );
		}
	}

	/**
	 * Exercise the public, final FetchHandler entry point.
	 *
	 * @param string $url Source URL.
	 * @return array
	 */
	private function fetch( string $url ): array {
		return ( new UniversalWebScraper() )->get_fetch_data(
			'direct',
			array( 'source_url' => $url )
		);
	}
}
