<?php
/**
 * Calendar Generation Publisher Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Abilities\Engine\PipelineBatchScheduler;
use DataMachine\Core\ActionScheduler\BatchScheduler;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\EngineData;
use DataMachineEvents\Blocks\Calendar\Cache\CacheInvalidator;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarCache;
use DataMachineEvents\Blocks\Calendar\Cache\CalendarGenerationFence;
use DataMachineEvents\Tasks\CalendarGenerationPublisher;
use WP_UnitTestCase;

class CalendarGenerationPublisherTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$this->resetInvalidationDepth();
		delete_option( CalendarGenerationFence::OPTION );
		CalendarCache::get_generation();
	}

	public function tearDown(): void {
		$this->resetInvalidationDepth();
		parent::tearDown();
	}

	public function test_many_import_requests_keep_one_reusable_generation(): void {
		$parent_id  = $this->createBatchParent();
		$generation = CalendarCache::get_generation();
		$cache_key  = CalendarCache::PREFIX . 'batch-reuse-' . uniqid();
		CalendarCache::set( $cache_key, 'reused', HOUR_IN_SECONDS, $generation );

		$this->deferImportRequests( $parent_id, 100 );

		$this->assertSame( $generation, CalendarCache::get_generation() );
		$this->assertSame( 'reused', CalendarCache::get( $cache_key, $generation ) );
		$marker = EngineData::retrieve( $parent_id )[ CalendarGenerationPublisher::ENGINE_KEY ];
		$this->assertGreaterThan( 0, $marker['obligation_revision'] );
		$this->assertArrayNotHasKey( 'publication_revision', $marker );
	}

	public function test_terminal_replays_reuse_revision_specific_operation(): void {
		$parent_id = $this->createBatchParent();
		$this->deferImportRequests( $parent_id, 2 );

		$calls     = array();
		$scheduler = static function ( $task_type, $params, $context, $parent_job_id, $operation_key ) use ( &$calls ): int {
			$calls[] = compact( 'task_type', 'params', 'context', 'parent_job_id', 'operation_key' );
			return count( $calls );
		};
		$this->assertTrue( CalendarGenerationPublisher::scheduleForTerminalBatch( $parent_id, $scheduler, 1000 ) );
		$this->assertTrue( CalendarGenerationPublisher::scheduleForTerminalBatch( $parent_id, $scheduler, 1000 ) );

		$this->assertCount( 2, $calls, 'Owner-scoped schedulers may receive replay calls; the exact revision must remain stable.' );
		$this->assertSame( $calls[0]['params']['revision'], $calls[1]['params']['revision'] );
		$this->assertSame( $calls[0]['operation_key'], $calls[1]['operation_key'] );
		$this->assertSame( 1200, $calls[0]['params']['scheduled_at'] );
		$this->assertStringEndsWith( ':' . $calls[0]['params']['revision'], $calls[0]['operation_key'] );

		$this->assertTrue( CalendarGenerationPublisher::scheduleForTerminalBatch( $parent_id, $scheduler, 2000 ) );
		$this->assertSame( $calls[0]['operation_key'], $calls[2]['operation_key'], 'A later terminal replay must retain the original window and revision.' );
		$this->assertSame( 1200, $calls[2]['params']['scheduled_at'] );
	}

	public function test_old_then_new_publisher_only_new_revision_transitions(): void {
		$old_revision = CalendarCache::reserve_revision();
		$old_target   = $this->generationFor( $old_revision );
		$old          = CalendarGenerationFence::publish( $old_revision, $old_target, 101 );

		$new_revision = CalendarCache::reserve_revision();
		$new_target   = $this->generationFor( $new_revision );
		$new          = CalendarGenerationFence::publish( $new_revision, $new_target, 102 );

		$this->assertSame( 'published', $old['status'] );
		$this->assertSame( 'published', $new['status'] );
		$this->assertSame( $new_target, CalendarCache::get_generation() );
	}

	public function test_new_then_old_publisher_cannot_roll_generation_backward(): void {
		$old_revision = CalendarCache::reserve_revision();
		$new_revision = CalendarCache::reserve_revision();
		$old_target   = $this->generationFor( $old_revision );
		$new_target   = $this->generationFor( $new_revision );

		$new = CalendarGenerationFence::publish( $new_revision, $new_target, 202 );
		$old = CalendarGenerationFence::publish( $old_revision, $old_target, 201 );

		$this->assertSame( 'published', $new['status'] );
		$this->assertSame( 'superseded', $old['status'] );
		$this->assertSame( $new_target, CalendarCache::get_generation() );
	}

	public function test_immediate_invalidation_supersedes_scheduled_publisher(): void {
		$revision = CalendarCache::reserve_revision();
		$target   = $this->generationFor( $revision );

		CalendarCache::invalidate();
		$immediate = CalendarCache::get_generation();
		$result    = CalendarGenerationFence::publish( $revision, $target, 301 );

		$this->assertSame( 'superseded', $result['status'] );
		$this->assertSame( $immediate, CalendarCache::get_generation() );
	}

	public function test_warmer_failure_and_winner_replay_transition_once(): void {
		$revision  = CalendarCache::reserve_revision();
		$generation = $this->generationFor( $revision );
		$params     = $this->publicationParams( $revision, $generation );
		$publisher  = new CalendarGenerationPublisher();
		$transitions = 0;
		$observer    = static function () use ( &$transitions ): void {
			++$transitions;
		};
		add_action( 'update_option_' . CalendarCache::GENERATION_OPTION, $observer );

		try {
			$failed = $publisher->runPublication( 401, $params, static fn() => false );
			$this->assertWPError( $failed );

			$calls = 0;
			$replay = $publisher->runPublication(
				401,
				$params,
				static function () use ( &$calls ): int {
					++$calls;
					return 9001;
				}
			);
			$this->assertIsArray( $replay );
			$this->assertSame( 9001, $replay['warmer_job_id'] );

			$completed_replay = $publisher->runPublication( 401, $params, static fn() => 9002 );
			$this->assertSame( 'warmer_already_scheduled', $completed_replay['skipped'] );
			$this->assertSame( 1, $calls );
		} finally {
			remove_action( 'update_option_' . CalendarCache::GENERATION_OPTION, $observer );
		}

		$this->assertSame( 1, $transitions );
		$this->assertSame( $generation, CalendarCache::get_generation() );
	}

	public function test_immediate_edit_between_failed_handoff_and_replay_cannot_roll_backward(): void {
		$revision   = CalendarCache::reserve_revision();
		$generation = $this->generationFor( $revision );
		$params     = $this->publicationParams( $revision, $generation );
		$publisher  = new CalendarGenerationPublisher();

		$this->assertWPError( $publisher->runPublication( 501, $params, static fn() => false ) );
		CalendarCache::invalidate();
		$immediate = CalendarCache::get_generation();
		$calls     = 0;
		$replay    = $publisher->runPublication(
			501,
			$params,
			static function () use ( &$calls ): int {
				++$calls;
				return 1;
			}
		);

		$this->assertSame( 'superseded', $replay['skipped'] );
		$this->assertSame( 0, $calls );
		$this->assertSame( $immediate, CalendarCache::get_generation() );
	}

	public function test_owner_scoped_duplicate_publishers_elect_one_warmer_scheduler(): void {
		$revision   = CalendarCache::reserve_revision();
		$generation = $this->generationFor( $revision );
		$params     = $this->publicationParams( $revision, $generation );
		$publisher  = new CalendarGenerationPublisher();
		$calls      = array();
		$scheduler  = static function ( $task_type, $task_params, $context, $parent_id, $operation_key ) use ( &$calls ): int {
			$calls[] = compact( 'task_type', 'task_params', 'context', 'parent_id', 'operation_key' );
			return 6001;
		};

		$winner    = $publisher->runPublication( 601, $params, $scheduler );
		$duplicate = $publisher->runPublication( 602, $params, $scheduler );

		$this->assertSame( 6001, $winner['warmer_job_id'] );
		$this->assertSame( 'duplicate_publisher', $duplicate['skipped'] );
		$this->assertCount( 1, $calls );
	}

	public function test_cross_site_parent_refuses_second_site_without_replacing_first_obligation(): void {
		$parent_id    = $this->createBatchParent();
		$current_site = get_current_blog_id();
		$other_site   = self::factory()->blog->create();
		$this->assertTrue( CalendarGenerationPublisher::deferBatch( $parent_id ) );
		$before = EngineData::retrieve( $parent_id )[ CalendarGenerationPublisher::ENGINE_KEY ];

		switch_to_blog( $other_site );
		try {
			$this->assertFalse( CalendarGenerationPublisher::deferBatch( $parent_id ) );
		} finally {
			restore_current_blog();
		}
		$after = EngineData::retrieve( $parent_id )[ CalendarGenerationPublisher::ENGINE_KEY ];

		$this->assertSame( $current_site, $after['site_id'] );
		$this->assertSame( $before['obligation_revision'], $after['obligation_revision'] );
		$this->assertNotSame( $other_site, $after['site_id'] );
	}

	public function test_claimed_revision_rejects_conflicting_generation_payload(): void {
		$revision   = CalendarCache::reserve_revision();
		$generation = $this->generationFor( $revision );
		$this->assertSame( 'published', CalendarGenerationFence::publish( $revision, $generation, 701 )['status'] );

		$this->assertFalse( CalendarGenerationFence::publish( $revision, hash( 'sha256', 'conflicting-generation' ), 702 ) );
		$this->assertSame( $generation, CalendarCache::get_generation() );
	}

	public function test_failed_revision_reservation_falls_back_to_immediate_freshness(): void {
		$parent_id = $this->createBatchParent();
		$before    = CalendarCache::get_generation();

		$this->assertFalse( CalendarGenerationPublisher::deferBatch( $parent_id, static fn() => false ) );
		CacheInvalidator::invalidate_all();

		$this->assertNotSame( $before, CalendarCache::get_generation() );
		$this->assertArrayNotHasKey( CalendarGenerationPublisher::ENGINE_KEY, EngineData::retrieve( $parent_id ) );
	}

	public function test_exhausted_cas_uses_row_locked_immediate_transition(): void {
		$before = CalendarGenerationFence::currentState();
		$target = wp_generate_uuid4();
		$result = CalendarGenerationFence::invalidate( $target, static fn() => false );

		$this->assertIsArray( $result );
		$this->assertSame( $before['revision'] + 1, $result['state']['revision'] );
		$this->assertSame( $target, CalendarCache::get_generation() );
	}

	public function test_unsupported_database_refuses_deferral_and_uses_immediate_generation(): void {
		$support = static fn(): bool => false;
		add_filter( 'data_machine_events_calendar_generation_fence_supported', $support );
		try {
			$parent_id = $this->createBatchParent();
			$before    = CalendarCache::get_generation();
			$this->assertFalse( CalendarGenerationPublisher::deferBatch( $parent_id ) );
			CalendarCache::invalidate();
			$this->assertNotSame( $before, CalendarCache::get_generation() );
		} finally {
			remove_filter( 'data_machine_events_calendar_generation_fence_supported', $support );
		}
	}

	public function test_nested_defer_resume_and_exception_balance(): void {
		$before = CalendarCache::get_generation();
		CacheInvalidator::defer();
		CacheInvalidator::defer();
		try {
			CacheInvalidator::invalidate_all();
			throw new \RuntimeException( 'exercise finally balance' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'exercise finally balance', $exception->getMessage() );
		} finally {
			CacheInvalidator::resume();
		}

		CacheInvalidator::invalidate_all();
		$this->assertSame( $before, CalendarCache::get_generation(), 'One nested defer frame must remain active.' );
		CacheInvalidator::resume();
		CacheInvalidator::invalidate_all();
		$this->assertNotSame( $before, CalendarCache::get_generation() );
	}

	public function test_sites_have_isolated_revisions_and_generations(): void {
		$site_id          = get_current_blog_id();
		$other_id         = self::factory()->blog->create();
		$first_generation = CalendarCache::get_generation();
		$first_revision   = CalendarCache::reserve_revision();

		switch_to_blog( $other_id );
		try {
			$other_generation = CalendarCache::get_generation();
			$other_revision   = CalendarCache::reserve_revision();
			CalendarCache::invalidate();
			$this->assertNotSame( $other_generation, CalendarCache::get_generation() );
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $first_generation, CalendarCache::get_generation() );
		$this->assertSame( $first_revision, CalendarGenerationFence::currentState()['revision'] );
		$this->assertSame( 1, $other_revision );
		$this->assertNotSame( CalendarGenerationPublisher::operationKey( $site_id, 10, 1 ), CalendarGenerationPublisher::operationKey( $other_id, 10, 1 ) );
	}

	public function test_terminal_callback_registry_preserves_existing_callbacks(): void {
		$existing  = static fn(): bool => true;
		$callbacks = CalendarGenerationPublisher::registerTerminalCallback( array( 'existing' => $existing ), 1, 'completed' );

		$this->assertSame( $existing, $callbacks['existing'] );
		$this->assertSame( array( CalendarGenerationPublisher::class, 'publishForTerminalBatch' ), $callbacks['data_machine_events_calendar_generation'] );
	}

	/** @param array<string,mixed> $engine Engine data for the job. */
	private function createJob( array $engine ): int {
		$job_id = ( new Jobs() )->create_job(
			array(
				'pipeline_id' => 1,
				'flow_id'     => 1,
				'label'       => 'Calendar generation publisher test',
				'user_id'     => 0,
			)
		);
		$this->assertIsInt( $job_id );
		$this->assertTrue( EngineData::persist( $job_id, $engine ) );
		return $job_id;
	}

	private function createBatchParent(): int {
		return $this->createJob(
			array(
				'batch'                     => true,
				'batch_context'             => PipelineBatchScheduler::BATCH_CONTEXT,
				'batch_completion_strategy' => BatchScheduler::COMPLETION_STRATEGY_CHILDREN_COMPLETE,
			)
		);
	}

	private function deferImportRequests( int $parent_id, int $requests ): void {
		for ( $request = 0; $request < $requests; ++$request ) {
			$this->assertTrue( CalendarGenerationPublisher::deferBatch( $parent_id ) );
			CacheInvalidator::defer();
			try {
				CacheInvalidator::invalidate_all();
				CacheInvalidator::invalidate_all();
			} finally {
				CacheInvalidator::resume();
			}
		}
	}

	private function generationFor( int $revision ): string {
		return hash( 'sha256', 'calendar-generation-test:' . get_current_blog_id() . ':' . $revision );
	}

	/** @return array<string,mixed> */
	private function publicationParams( int $revision, string $generation ): array {
		return array(
			'site_id'          => get_current_blog_id(),
			'revision'         => $revision,
			'generation'       => $generation,
			'coalesced_window' => 10,
		);
	}

	private function resetInvalidationDepth(): void {
		$depth = new \ReflectionProperty( CacheInvalidator::class, 'deferred_invalidations' );
		$depth->setValue( null, 0 );
	}
}
