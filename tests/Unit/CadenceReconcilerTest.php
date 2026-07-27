<?php
/**
 * Event cadence reconciler tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Core\CadencePolicy;
use DataMachineEvents\Core\CadenceReconciler;
use DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFm;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;
use WP_UnitTestCase;

class CadenceReconcilerTest extends WP_UnitTestCase {

	private const HANDLER_CLASSES = array(
		'ticketmaster'          => Ticketmaster::class,
		'dice_fm'               => DiceFm::class,
		'universal_web_scraper' => UniversalWebScraper::class,
	);

	private function intervals(): array {
		return array(
			'hourly'       => array( 'seconds' => HOUR_IN_SECONDS ),
			'twicedaily'   => array( 'seconds' => 12 * HOUR_IN_SECONDS ),
			'daily'        => array( 'seconds' => DAY_IN_SECONDS ),
			'every_3_days' => array( 'seconds' => 3 * DAY_IN_SECONDS ),
			'weekly'       => array( 'seconds' => WEEK_IN_SECONDS ),
		);
	}

	public function test_dry_run_projects_changes_without_mutating(): void {
		$updates = array();
		$flows   = $this->safety_fixture();
		$policy  = new CadencePolicy( self::HANDLER_CLASSES, static fn( int $venue_id ): string => 20 === $venue_id ? 'cold' : 'warm' );
		$service = new CadenceReconciler(
			$policy,
			static fn(): array => $flows,
			static function ( array $flow, string $interval ) use ( &$updates ): bool {
				$updates[ $flow['flow_id'] ] = $interval;
				return true;
			},
			fn(): array => $this->intervals()
		);

		$result = $service->reconcile( false );

		$this->assertSame( array(), $updates, 'Dry-run must never call the flow updater.' );
		$this->assertSame( 'dry-run', $result['mode'] );
		$this->assertSame( 2, $result['changes_proposed'] );
		$this->assertSame( 0, $result['changes_applied'] );
		$this->assertSame( 27.0, $result['run_budget']['before_per_day'] );
		$this->assertSame( 27.333, $result['run_budget']['after_per_day'] );
		$this->assertSame( 0.333, $result['run_budget']['delta_per_day'] );
		$this->assertSame( 'ticketmaster_default', $result['changes'][0]['reason'] );
		$this->assertSame( 'cold_venue', $result['changes'][1]['reason'] );
		$this->assertSame( 2, $result['resolved_distribution']['daily'] );
		$this->assertSame( 1, $result['resolved_distribution']['every_3_days'] );
		$this->assertSame( 1, $result['resolved_distribution']['twicedaily'] );
		$this->assertSame(
			array( 'pinned_interval_preserved', 'manual_flow_preserved', 'inactive_flow_preserved', 'custom_interval_preserved' ),
			array_column( $result['skipped'], 'reason' )
		);
	}

	public function test_apply_updates_only_proposed_changes(): void {
		$updates = array();
		$flows   = array(
			$this->flow( 1, 'Ticketmaster', 'ticketmaster', 'daily' ),
			$this->flow( 2, 'Dice', 'dice_fm', 'daily', array( CadencePolicy::PINNED_INTERVAL_KEY => true ) ),
		);
		$policy  = new CadencePolicy( self::HANDLER_CLASSES, static fn(): string => 'warm' );
		$service = new CadenceReconciler(
			$policy,
			static fn(): array => $flows,
			static function ( array $flow, string $interval ) use ( &$updates ): bool {
				$updates[ $flow['flow_id'] ] = $interval;
				return true;
			},
			fn(): array => $this->intervals()
		);

		$result = $service->reconcile( true );

		$this->assertSame( array( 1 => 'twicedaily' ), $updates );
		$this->assertSame( 'apply', $result['mode'] );
		$this->assertSame( 1, $result['changes_applied'] );
		$this->assertTrue( $result['changes'][0]['applied'] );
		$this->assertSame( 'pinned_interval_preserved', $result['skipped'][0]['reason'] );
	}

	public function test_matching_schedule_is_idempotent_no_op(): void {
		$updates = 0;
		$flows   = array( $this->flow( 1, 'Ticketmaster', 'ticketmaster', 'twicedaily' ) );
		$service = new CadenceReconciler(
			new CadencePolicy( self::HANDLER_CLASSES, static fn(): string => 'warm' ),
			static fn(): array => $flows,
			static function () use ( &$updates ): bool {
				++$updates;
				return true;
			},
			fn(): array => $this->intervals()
		);

		$result = $service->reconcile( true );

		$this->assertSame( 0, $updates );
		$this->assertSame( 0, $result['changes_proposed'] );
		$this->assertSame( 0, $result['changes_applied'] );
	}

	public function test_apply_failure_is_reported_without_hiding_other_projection_data(): void {
		$flows   = array( $this->flow( 1, 'Ticketmaster', 'ticketmaster', 'daily' ) );
		$service = new CadenceReconciler(
			new CadencePolicy( self::HANDLER_CLASSES, static fn(): string => 'warm' ),
			static fn(): array => $flows,
			static fn(): \WP_Error => new \WP_Error( 'write_failed', 'No write.' ),
			fn(): array => $this->intervals()
		);

		$result = $service->reconcile( true );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['failures'] );
		$this->assertSame( 'No write.', $result['changes'][0]['error'] );
		$this->assertSame( 1.0, $result['run_budget']['before_per_day'] );
		$this->assertSame( 2.0, $result['run_budget']['after_per_day'] );
	}

	private function safety_fixture(): array {
		return array(
			$this->flow( 1, 'Ticketmaster', 'ticketmaster', 'daily' ),
			$this->flow( 2, 'Cold scraper', 'universal_web_scraper', 'daily', array(), 20 ),
			$this->flow( 3, 'Pinned Dice', 'dice_fm', 'daily', array( CadencePolicy::PINNED_INTERVAL_KEY => true ) ),
			$this->flow( 4, 'Manual source', 'ticketmaster', 'manual' ),
			$this->flow( 5, 'Inactive source', 'ticketmaster', 'daily', array( 'enabled' => false ) ),
			$this->flow( 6, 'Custom source', 'future_source', 'hourly' ),
		);
	}

	private function flow( int $id, string $name, string $handler, string $interval, array $scheduling = array(), int $venue_id = 0 ): array {
		$config = $venue_id > 0 ? array( 'venue' => (string) $venue_id ) : array();

		return array(
			'flow_id'           => $id,
			'flow_name'         => $name,
			'scheduling_config' => array_merge( array( 'interval' => $interval ), $scheduling ),
			'flow_config'       => array(
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
