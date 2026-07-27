<?php
/**
 * Event cadence policy tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\CadencePolicy;
use DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFm;
use DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring\SingleRecurring;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use WP_UnitTestCase;

class CadencePolicyTest extends WP_UnitTestCase {

	private const HANDLER_CLASSES = array(
		'ticketmaster'          => Ticketmaster::class,
		'dice_fm'               => DiceFm::class,
		'universal_web_scraper' => UniversalWebScraper::class,
		'single_recurring'      => SingleRecurring::class,
	);

	/**
	 * @dataProvider source_default_provider
	 */
	public function test_source_class_defaults( string $handler, string $expected_interval, string $expected_reason ): void {
		$policy = new CadencePolicy( self::HANDLER_CLASSES, static fn(): string => '' );
		$result = $policy->resolve( $this->flow( $handler ) );

		$this->assertSame( self::HANDLER_CLASSES[ $handler ], $result['source_class'] );
		$this->assertSame( $expected_interval, $result['interval'] );
		$this->assertSame( $expected_reason, $result['reason'] );
	}

	public function source_default_provider(): array {
		return array(
			'ticketmaster'     => array( 'ticketmaster', 'twicedaily', 'ticketmaster_default' ),
			'dice'             => array( 'dice_fm', 'twicedaily', 'dice_default' ),
			'single recurring' => array( 'single_recurring', 'weekly', 'single_recurring_default' ),
		);
	}

	/**
	 * @dataProvider venue_heat_provider
	 */
	public function test_scraper_venue_heat_tiers( string $stored_heat, string $expected_heat, string $interval, string $reason ): void {
		$policy = new CadencePolicy( self::HANDLER_CLASSES, static fn( int $venue_id ): string => $stored_heat );
		$result = $policy->resolve( $this->flow( 'universal_web_scraper', 42 ) );

		$this->assertSame( 42, $result['venue_term_id'] );
		$this->assertSame( $expected_heat, $result['heat'] );
		$this->assertSame( $interval, $result['interval'] );
		$this->assertSame( $reason, $result['reason'] );
	}

	public function venue_heat_provider(): array {
		return array(
			'hot'             => array( 'hot', 'hot', 'twicedaily', 'hot_venue' ),
			'warm'            => array( 'warm', 'warm', 'daily', 'warm_venue' ),
			'cold'            => array( 'cold', 'cold', 'every_3_days', 'cold_venue' ),
			'missing default' => array( '', 'warm', 'daily', 'warm_venue' ),
			'invalid default' => array( 'boiling', 'warm', 'daily', 'warm_venue' ),
		);
	}

	public function test_unknown_source_uses_daily_fallback(): void {
		$policy = new CadencePolicy( array(), static fn(): string => '' );
		$result = $policy->resolve( $this->flow( 'future_event_source' ) );

		$this->assertSame( '', $result['source_class'] );
		$this->assertSame( 'daily', $result['interval'] );
		$this->assertSame( 'unknown_source_fallback', $result['reason'] );
	}

	private function flow( string $handler, int $venue_id = 0 ): array {
		$config = array();
		if ( $venue_id > 0 ) {
			$config['venue'] = (string) $venue_id;
		}

		return array(
			'flow_config' => array(
				'event_import_step' => array(
					'step_type'       => 'event_import',
					'enabled'         => true,
					'handler_slugs'   => array( $handler ),
					'handler_configs' => array( $handler => $config ),
				),
			),
		);
	}
}
