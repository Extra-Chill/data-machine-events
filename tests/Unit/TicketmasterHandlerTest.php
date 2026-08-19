<?php
/**
 * Ticketmaster Handler Tests
 *
 * Tests Ticketmaster API integration handler.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since 0.9.16
 */

namespace DataMachineEvents\Tests\Unit;

use WP_UnitTestCase;
use DataMachine\Core\Database\Jobs\Jobs;
use DataMachine\Core\Database\ProcessedItems\FanoutClaimOwnership;
use DataMachine\Core\Database\TrackedItems\TrackedItems;
use DataMachine\Core\EngineData;
use DataMachine\Core\ExecutionContext;
use DataMachine\Core\Database\ProcessedItems\ProcessedItems;
use DataMachine\Core\JobStatus;
use DataMachine\Core\RunMetrics;
use DataMachine\Core\Steps\Fetch\Tools\FetchItemDispositionTool;
use DataMachine\Engine\Actions\Handlers\StepLifecycleHandler;
use DataMachineEvents\Core\DuplicateDetection\PreAIEventDedupGate;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSettings;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\TicketmasterSourceIdentity;
use ReflectionClass;

class TicketmasterHandlerTest extends WP_UnitTestCase {

	private Ticketmaster $handler;
	private \Closure $tracked_completion_filter;

	public function setUp(): void {
		parent::setUp();
		$this->ensureEventIdentityTables();
		Jobs::create_table();
		( new ProcessedItems() )->create_table();
		( new TrackedItems() )->create_table();
		$this->tracked_completion_filter = static fn( array $handlers ): array => TrackedItems::registerClaimCompletionHandler( $handlers );
		add_filter( 'datamachine_item_claim_completion_handlers', $this->tracked_completion_filter );
		$this->handler = new Ticketmaster();
		set_transient( 'data_machine_events_ticketmaster_classifications', array( 'music' => 'Music' ) );
	}

	public function tearDown(): void {
		remove_filter( 'data_machine_events_junk_payload_patterns', array( $this->handler, 'register_junk_patterns' ), 10 );
		remove_filter( 'datamachine_item_claim_completion_handlers', $this->tracked_completion_filter );
		delete_transient( 'data_machine_events_ticketmaster_classifications' );
		parent::tearDown();
	}

	public function test_handler_extends_event_import_handler() {
		$this->assertInstanceOf(
			\DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler::class,
			$this->handler
		);
	}

	public function test_settings_class_exists() {
		$this->assertTrue( class_exists( TicketmasterSettings::class ) );
	}

	public function test_map_event_returns_array() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'      => 'Test Concert',
			'id'        => 'TM123456',
			'url'       => 'https://www.ticketmaster.com/event/123',
			'dates'     => array(
				'start'    => array(
					'localDate' => '2099-03-15',
					'localTime' => '19:30:00',
				),
				'timezone' => 'America/Denver',
			),
			'_embedded' => array(
				'venues' => array(
					array(
						'name'       => 'Test Arena',
						'address'    => array(
							'line1' => '123 Main St',
						),
						'city'       => array(
							'name' => 'Denver',
						),
						'state'      => array(
							'stateCode' => 'CO',
						),
						'postalCode' => '80202',
						'country'    => array(
							'countryCode' => 'US',
						),
						'timezone'   => 'America/Denver',
					),
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'Test Concert', $result['title'] );
		$this->assertEquals( 'Test Arena', $result['venue'] );
		$this->assertEquals( '2099-03-15', $result['startDate'] );
		$this->assertEquals( '19:30', $result['startTime'] );
	}

	public function test_new_event_uses_stable_ticketmaster_source_identity(): void {
		$handler = new TicketmasterHandlerTestDouble(
			array(
				0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( 'TM-new-event', 'New Event ' . uniqid() ) ) ),
			)
		);

		$packets = $handler->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', 'charleston-fetch' ), '1001' );

		$this->assertCount( 1, $packets );
		$packet = $packets[0]->addTo( array() )[0];
		$this->assertSame( 'TM-new-event', $packet['metadata']['source_item_id'] );
		$this->assertArrayNotHasKey( 'item_identifier', $packet['metadata'] );
		$this->assertSame( 'TM-new-event', $packet['metadata']['_engine_data']['item_identifier'] );
		$claim = $packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ];
		$this->assertSame( TicketmasterSourceIdentity::CLAIM_SCOPE, $claim['identity_scope'] );
		$this->assertSame( 'ticketmaster', $claim['source_type'] );
		$this->assertSame( 'TM-new-event', $claim['item_identifier'] );
		$this->assertSame( 'tracked_item', $claim['completion']['handler'] );
		$this->assertFalse( $claim['completion']['retain_processed'] );
		$this->assertSame( $claim['disposition_id'], $packet['metadata'][ ProcessedItems::DISPOSITION_ID_METADATA_KEY ] );
		$this->failPacket( $packet, 1001 );
	}

	public function test_single_packet_inline_ai_resume_and_upsert_commit_canonical_claim(): void {
		$item_id = 'TM-inline-' . uniqid();
		$job_id  = $this->createJob( 'Ticketmaster inline lifecycle' );
		$packets = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( $item_id, 'Inline Event' ) ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', 'inline-fetch' ), (string) $job_id );
		$packet = $packets[0]->addTo( array() )[0];

		$this->assertTrue( StepLifecycleHandler::handleInlineContinuation( $job_id, array( 'step_type' => 'event_import' ), array( $packet ) ) );
		$claim = datamachine_get_engine_data( $job_id )[ ProcessedItems::CLAIMS_METADATA_KEY ][0];
		datamachine_merge_engine_data( $job_id, array( 'ai_resume_generation' => 2 ) );
		$this->assertSame( $claim, datamachine_get_engine_data( $job_id )[ ProcessedItems::CLAIMS_METADATA_KEY ][0] );
		$this->assertTrue( StepLifecycleHandler::reconcileStepOutput( $job_id, array( 'step_type' => 'ai' ), array( $packet ), true )['success'] );
		$this->assertTrue( StepLifecycleHandler::reconcileStepOutput( $job_id, array( 'step_type' => 'upsert' ), array( $packet ), true )['success'] );
		$this->assertTrue( ( new Jobs() )->complete_job( $job_id, JobStatus::COMPLETED ) );

		$tracked = ( new TrackedItems() )->get( TicketmasterSourceIdentity::TRACK_NAMESPACE, $item_id );
		$this->assertSame( $packet['metadata']['source_revision'], $tracked['source_revision'] );
		$this->assertFalse( ( new ProcessedItems() )->has_active_claim( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $item_id ) );
		$this->assertSame( 1, RunMetrics::fromJob( ( new Jobs() )->get_job( $job_id ) )['counts']['processed'] );
	}

	public function test_reject_and_defer_resolve_exact_ticketmaster_disposition(): void {
		foreach ( array( 'reject_source', 'defer_item' ) as $disposition ) {
			$item_id = 'TM-' . $disposition . '-' . uniqid();
			$job_id  = $this->createJob( 'Ticketmaster ' . $disposition );
			$packets = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( $item_id, 'Disposition Event' ) ) ) ) ) )
				->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', $disposition . '-fetch' ), (string) $job_id );
			$packet = $packets[0]->addTo( array() )[0];
			$this->assertTrue( StepLifecycleHandler::handleInlineContinuation( $job_id, array( 'step_type' => 'event_import' ), array( $packet ) ) );
			$disposition_id = $packet['metadata'][ ProcessedItems::DISPOSITION_ID_METADATA_KEY ];
			$tool           = new FetchItemDispositionTool();
			$wrong          = $tool->handle_tool_call(
				array(
					'reason'         => 'Wrong packet identity',
					'job_id'         => $job_id,
					'disposition_id' => str_repeat( '0', 64 ),
					'engine'         => EngineData::forJob( $job_id ),
				),
				array( 'disposition' => $disposition )
			);
			$this->assertFalse( $wrong['success'] );
			$parameters = array(
				'reason'         => 'Canonical disposition test',
				'job_id'         => $job_id,
				'disposition_id' => $disposition_id,
				'engine'         => EngineData::forJob( $job_id ),
			);
			$result     = $tool->handle_tool_call( $parameters, array( 'disposition' => $disposition ) );
			$this->assertTrue( $result['success'] );
			$this->assertSame( $disposition_id, $result['disposition_id'] );
			$parameters['reason'] = 'Replay';
			$replay               = $tool->handle_tool_call( $parameters, array( 'disposition' => $disposition ) );
			$this->assertTrue( $replay['already_dispositioned'] );

			$packet['metadata']['packet_disposition'] = $disposition;
			$this->assertTrue( StepLifecycleHandler::reconcileStepOutput( $job_id, array( 'step_type' => 'ai' ), array( $packet ), true )['success'] );
			$tracked = ( new TrackedItems() )->get( TicketmasterSourceIdentity::TRACK_NAMESPACE, $item_id );
			if ( 'reject_source' === $disposition ) {
				$this->assertNotNull( $tracked );
			} else {
				$this->assertNull( $tracked );
			}
			$this->assertFalse( ( new ProcessedItems() )->has_active_claim( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $item_id ) );
		}
	}

	public function test_failed_ai_and_upsert_release_claim_without_revision(): void {
		foreach ( array( 'ai', 'upsert' ) as $step_type ) {
			$item_id = 'TM-failed-' . $step_type . '-' . uniqid();
			$job_id  = $this->createJob( 'Ticketmaster failed ' . $step_type );
			$packets = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( $item_id, 'Failed Event' ) ) ) ) ) )
				->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', $step_type . '-failure-fetch' ), (string) $job_id );
			$packet = $packets[0]->addTo( array() )[0];
			$this->assertTrue( StepLifecycleHandler::handleInlineContinuation( $job_id, array( 'step_type' => 'event_import' ), array( $packet ) ) );
			$this->assertTrue( StepLifecycleHandler::reconcileStepOutput( $job_id, array( 'step_type' => $step_type ), array(), false )['success'] );
			$this->assertFalse( ( new ProcessedItems() )->has_active_claim( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $item_id ) );
			$this->assertNull( ( new TrackedItems() )->get( TicketmasterSourceIdentity::TRACK_NAMESPACE, $item_id ) );
		}
	}

	public function test_two_packet_scheduled_fanout_settles_without_parent_claims(): void {
		$parent_id = $this->createJob( 'Ticketmaster fanout parent' );
		$events    = array(
			$this->ticketmasterEvent( 'TM-fanout-a-' . uniqid(), 'Fanout A' ),
			$this->ticketmasterEvent( 'TM-fanout-b-' . uniqid(), 'Fanout B' ),
		);
		$packets = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( $events ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', 'fanout-fetch' ), (string) $parent_id );
		$this->assertCount( 2, $packets );
		$ownership = new FanoutClaimOwnership();
		$child_ids = array();

		foreach ( $packets as $index => $data_packet ) {
			$packet      = $data_packet->addTo( array() )[0];
			$claim       = $packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ];
			$child_id    = $this->createJob( 'Ticketmaster fanout child ' . $index, $parent_id );
			$child_ids[] = $child_id;
			$this->assertTrue( $ownership->adopt_owned_claims( array( $claim ), $parent_id, $child_id ) );
			$this->assertTrue( StepLifecycleHandler::handleCompleted( $child_id, array( ProcessedItems::CLAIM_METADATA_KEY => $claim ) ) );
		}

		$this->assertCount( 0, $ownership->active_claims_for_job( $parent_id ) );
		foreach ( $child_ids as $child_id ) {
			$this->assertCount( 0, $ownership->active_claims_for_job( $child_id ) );
		}
	}

	public function test_case_variant_sql_row_uses_acquisition_descriptor_authoritatively(): void {
		$lowercase = 'tm-case-' . uniqid();
		$uppercase = strtoupper( $lowercase );
		$processed = new ProcessedItems();
		$this->assertTrue( $processed->claim_item( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $lowercase, 1701 ) );
		$this->assertSame( 1, $processed->complete_claim_for_job( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $lowercase, 1701 ) );

		$packets = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( $uppercase, 'Case Variant Event' ) ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', 'case-variant-fetch' ), '1702' );
		$this->assertCount( 1, $packets );
		$packet = $packets[0]->addTo( array() )[0];
		$claim  = $packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ];
		$this->assertSame( $uppercase, $claim['item_identifier'] );
		$this->assertSame( ProcessedItems::disposition_identity( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $uppercase ), $claim['disposition_id'] );
		$this->completePacket( $packet, 1702 );
		$this->assertNotNull( ( new TrackedItems() )->get( TicketmasterSourceIdentity::TRACK_NAMESPACE, $uppercase ) );
	}

	public function test_terminal_replay_is_idempotent_for_ticketmaster_descriptor(): void {
		$item_id = 'TM-terminal-replay-' . uniqid();
		$packets = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $this->ticketmasterEvent( $item_id, 'Replay Event' ) ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '32.7765,-79.9311', 'terminal-replay-fetch' ), '1801' );
		$packet = $packets[0]->addTo( array() )[0];
		$this->completePacket( $packet, 1801 );
		StepLifecycleHandler::handleCompleted( 1801, array( ProcessedItems::CLAIM_METADATA_KEY => $packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ] ) );

		$tracked = ( new TrackedItems() )->get( TicketmasterSourceIdentity::TRACK_NAMESPACE, $item_id );
		$this->assertSame( $packet['metadata']['source_revision'], $tracked['source_revision'] );
		$this->assertFalse( ( new ProcessedItems() )->has_active_claim( TicketmasterSourceIdentity::CLAIM_SCOPE, 'ticketmaster', $item_id ) );
	}

	public function test_repeated_ticketmaster_ids_across_pages_only_schedule_once(): void {
		$repeated = $this->ticketmasterEvent( 'TM-overlap', 'Overlap Event ' . uniqid() );
		$handler  = new TicketmasterHandlerTestDouble(
			array(
				0 => $this->ticketmasterPage( array( $repeated ), 0, 2 ),
				1 => $this->ticketmasterPage(
					array(
						$repeated,
						$this->ticketmasterEvent( 'TM-distinct', 'Distinct Event ' . uniqid() ),
					),
					1,
					2
				),
			)
		);

		$packets = $handler->get_fetch_data( 1, $this->handlerConfig( '37.7749,-122.4194', 'sf-pages' ), '1002' );

		$this->assertCount( 2, $packets );
		foreach ( $packets as $packet ) {
			$this->failPacket( $packet->addTo( array() )[0], 1002 );
		}
	}

	public function test_distinct_city_flows_racing_same_id_create_one_packet(): void {
		$raw_event = $this->ticketmasterEvent( 'TM-race-' . uniqid(), 'Cross-flow Race' );
		$logs      = array();
		$logger    = static function ( string $level, string $message, array $context ) use ( &$logs ): void {
			$level;
			if ( 'Ticketmaster: Import fan-out summary' === $message ) {
				$logs[] = $context;
			}
		};
		add_action( 'datamachine_log', $logger, 10, 3 );

		$san_francisco = new TicketmasterHandlerTestDouble(
			array( 0 => $this->ticketmasterPage( array( $raw_event ) ) )
		);
		$oakland       = new TicketmasterHandlerTestDouble(
			array( 0 => $this->ticketmasterPage( array( $raw_event ) ) )
		);

		$first  = $san_francisco->get_fetch_data( 1, $this->handlerConfig( '37.7749,-122.4194', 'san-francisco-fetch' ), '1101' );
		$second = $oakland->get_fetch_data( 1, $this->handlerConfig( '37.8044,-122.2712', 'oakland-fetch' ), '1102' );

		remove_action( 'datamachine_log', $logger, 10 );
		$this->assertCount( 1, $first );
		$this->assertCount( 0, $second );
		$this->assertCount( 2, $logs );
		$this->assertSame( count( $first ), $logs[0]['source_claimed'] );
		$this->assertSame( count( $first ), $logs[0]['packets_ready'] );
		$this->assertSame( count( $second ), $logs[1]['source_claimed'] );
		$this->assertSame( count( $second ), $logs[1]['packets_ready'] );
		$this->assertSame( 1, $logs[1]['contended_claims'] );
		$this->failPacket( $first[0]->addTo( array() )[0], 1101 );
	}

	public function test_mutable_future_event_revision_reaches_upsert(): void {
		$item_id = 'TM-mutable-' . uniqid();
		$initial = $this->ticketmasterEvent( $item_id, 'Initial Title' );
		$first   = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $initial ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '37.7749,-122.4194', 'mutable-initial' ), '1201' );
		$this->assertCount( 1, $first );
		$this->completePacket( $first[0]->addTo( array() )[0], 1201 );

		$changed                                      = $initial;
		$changed['name']                              = 'Updated Title';
		$changed['url']                               = 'https://www.ticketmaster.com/event/' . $item_id . '-updated';
		$changed['dates']['start']['localDate']       = '2099-08-16';
		$changed['dates']['start']['localTime']       = '21:30:00';
		$changed['_embedded']['venues'][0]['name']    = 'Updated Arena';
		$changed['priceRanges'][0]                    = array( 'min' => 45, 'max' => 60, 'currency' => 'USD' );
		$second = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $changed ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '37.8044,-122.2712', 'mutable-update' ), '1202' );

		$this->assertCount( 1, $second, 'A changed source revision must reach EventUpsert.' );
		$body = json_decode( $second[0]->addTo( array() )[0]['data']['body'], true );
		$this->assertSame( 'Updated Title', $body['event']['title'] );
		$this->assertSame( '2099-08-16', $body['event']['startDate'] );
		$this->assertSame( 'Updated Arena', $body['event']['venue'] );
		$this->assertSame( '$45.00 - $60.00', $body['event']['price'] );
		$this->failPacket( $second[0]->addTo( array() )[0], 1202 );
	}

	public function test_failure_after_identity_insertion_releases_claim_for_retry(): void {
		$item_id    = 'TM-retry-' . uniqid();
		$title      = 'Interrupted Import ' . uniqid();
		$ticket_url = 'https://www.ticketmaster.com/event/' . $item_id;
		$raw_event  = $this->ticketmasterEvent( $item_id, $title, $ticket_url );
		[ $post_id, $term_id ] = $this->seedImportedEvent( $title, $ticket_url );

		$first = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $raw_event ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '37.7749,-122.4194', 'retry-first' ), '1301' );
		$this->assertCount( 1, $first, 'An identity-index row must not suppress source processing.' );
		$this->failPacket( $first[0]->addTo( array() )[0], 1301 );

		$retry = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $raw_event ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '37.8044,-122.2712', 'retry-second' ), '1302' );
		$this->assertCount( 1, $retry, 'A failed child must not persist its source revision.' );
		$this->failPacket( $retry[0]->addTo( array() )[0], 1302 );

		wp_delete_post( $post_id, true );
		wp_delete_term( $term_id, 'venue' );
	}

	public function test_overflow_items_are_selected_on_later_runs(): void {
		$prefix = uniqid();
		$events = array(
			$this->ticketmasterEvent( 'TM-overflow-a-' . $prefix, 'Overflow A' ),
			$this->ticketmasterEvent( 'TM-overflow-b-' . $prefix, 'Overflow B' ),
		);

		$first = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( $events ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '34.0522,-118.2437', 'overflow-first', 1 ), '1401' );
		$this->assertCount( 1, $first );
		$this->assertSame( 'TM-overflow-a-' . $prefix, $first[0]->addTo( array() )[0]['metadata']['source_item_id'] );
		$this->completePacket( $first[0]->addTo( array() )[0], 1401 );

		$second = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( $events ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '34.0522,-118.2437', 'overflow-second', 1 ), '1402' );
		$this->assertCount( 1, $second );
		$this->assertSame( 'TM-overflow-b-' . $prefix, $second[0]->addTo( array() )[0]['metadata']['source_item_id'] );
		$this->failPacket( $second[0]->addTo( array() )[0], 1402 );
	}

	public function test_reprocess_policy_can_select_unchanged_revision(): void {
		$item_id   = 'TM-reprocess-' . uniqid();
		$raw_event = $this->ticketmasterEvent( $item_id, 'Reprocess Event' );
		$first     = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $raw_event ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '40.7128,-74.0060', 'reprocess-first' ), '1501' );
		$this->completePacket( $first[0]->addTo( array() )[0], 1501 );

		$unchanged = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $raw_event ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '40.7128,-74.0060', 'reprocess-default' ), '1502' );
		$this->assertCount( 0, $unchanged );

		$policy = static function ( bool $skip, array $context ): bool {
			return TicketmasterSourceIdentity::CLAIM_SCOPE === $context['flow_step_id'] ? false : $skip;
		};
		add_filter( 'datamachine_should_reprocess_item', $policy, 10, 2 );
		$reprocessed = ( new TicketmasterHandlerTestDouble( array( 0 => $this->ticketmasterPage( array( $raw_event ) ) ) ) )
			->get_fetch_data( 1, $this->handlerConfig( '40.7128,-74.0060', 'reprocess-policy' ), '1503' );
		remove_filter( 'datamachine_should_reprocess_item', $policy, 10 );

		$this->assertCount( 1, $reprocessed );
		$this->failPacket( $reprocessed[0]->addTo( array() )[0], 1503 );
	}

	public function test_ticketmaster_settings_expose_and_sanitize_fanout_bound(): void {
		$fields = TicketmasterSettings::get_fields();
		$this->assertSame( Ticketmaster::DEFAULT_MAX_ITEMS, $fields['max_items']['default'] );
		$this->assertSame( 25, TicketmasterSettings::sanitize( array( 'max_items' => 25 ) )['max_items'] );
		$this->assertSame( Ticketmaster::DEFAULT_MAX_ITEMS, TicketmasterSettings::sanitize( array( 'max_items' => 1000 ) )['max_items'] );
		$this->assertSame( Ticketmaster::DEFAULT_MAX_ITEMS, TicketmasterSettings::get_defaults()['max_items'] );
	}

	public function test_pre_ai_gate_allows_ticketmaster_revision_updates(): void {
		$engine = new EngineData(
			array(
				'source_type' => 'ticketmaster',
				'flow_config' => array(
					'upsert' => array( 'handler_slugs' => array( 'upsert_event' ) ),
				),
			),
			null
		);

		$this->assertNull( PreAIEventDedupGate::check( null, $engine, array(), 1601 ) );
	}

	public function test_map_event_handles_missing_venue() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'  => 'No Venue Event',
			'id'    => 'TM789',
			'dates' => array(
				'start' => array(
					'localDate' => '2099-04-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertEquals( 'No Venue Event', $result['title'] );
		$this->assertEquals( '', $result['venue'] ?? '' );
	}

	public function test_map_event_handles_price_ranges() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Priced Event',
			'id'          => 'TM456',
			'priceRanges' => array(
				array(
					'min'      => 25.00,
					'max'      => 75.00,
					'currency' => 'USD',
				),
			),
			'dates'       => array(
				'start' => array(
					'localDate' => '2099-05-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['price'] ?? '' );
	}

	public function test_map_event_formats_price_correctly() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'        => 'Price Format Test',
			'id'          => 'TM789',
			'priceRanges' => array(
				array(
					'min'      => 50.00,
					'max'      => 50.00,
					'currency' => 'USD',
				),
			),
			'dates'       => array(
				'start' => array(
					'localDate' => '2099-06-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertEquals( '$50.00', $result['price'] );
	}

	public function test_map_event_handles_missing_price() {
		$method = $this->getProtectedMethod( 'map_ticketmaster_event' );

		$api_event = array(
			'name'  => 'No Price Event',
			'id'    => 'TM999',
			'dates' => array(
				'start' => array(
					'localDate' => '2099-07-01',
				),
			),
		);

		$result = $method->invoke( $this->handler, $api_event );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'price', $result );
		$this->assertEquals( '', $result['price'] );
	}

	public function test_is_rate_limited_detects_429_from_error_message() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 429: {"fault":{"faultstring":"Spike arrest violation"}}',
		);

		$this->assertTrue( $method->invoke( $this->handler, $result ) );
	}

	public function test_is_rate_limited_detects_429_from_status_code() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success'     => false,
			'status_code' => 429,
			'error'       => 'throttled',
		);

		$this->assertTrue( $method->invoke( $this->handler, $result ) );
	}

	public function test_is_rate_limited_false_for_success() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success'     => true,
			'status_code' => 200,
			'data'        => '{}',
		);

		$this->assertFalse( $method->invoke( $this->handler, $result ) );
	}

	public function test_is_rate_limited_false_for_other_errors() {
		$method = $this->getProtectedMethod( 'is_rate_limited' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 500: server error',
		);

		$this->assertFalse( $method->invoke( $this->handler, $result ) );
	}

	public function test_retry_after_seconds_parses_delta_seconds_header() {
		$method = $this->getProtectedMethod( 'retry_after_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'Retry-After' => '5' ),
		);

		$this->assertSame( 5, $method->invoke( $this->handler, $result ) );
	}

	public function test_retry_after_seconds_is_case_insensitive() {
		$method = $this->getProtectedMethod( 'retry_after_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'retry-after' => '3' ),
		);

		$this->assertSame( 3, $method->invoke( $this->handler, $result ) );
	}

	public function test_retry_after_seconds_null_when_absent() {
		$method = $this->getProtectedMethod( 'retry_after_seconds' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 429: throttled',
		);

		$this->assertNull( $method->invoke( $this->handler, $result ) );
	}

	public function test_backoff_grows_exponentially_and_is_clamped() {
		$method = $this->getProtectedMethod( 'rate_limit_backoff_seconds' );

		$result = array(
			'success' => false,
			'error'   => 'Ticketmaster GET returned HTTP 429: throttled',
		);

		// Without a Retry-After header, delay grows with the attempt index and
		// is clamped to RATE_LIMIT_BACKOFF_MAX_SECONDS. Jitter adds 0 or 1s.
		$delay0 = $method->invoke( $this->handler, $result, 0 );
		$delay3 = $method->invoke( $this->handler, $result, 3 );

		$this->assertGreaterThanOrEqual( 1, $delay0 );
		$this->assertLessThanOrEqual( 8, $delay0 );
		$this->assertLessThanOrEqual( 8, $delay3 );
	}

	public function test_backoff_respects_retry_after_header() {
		$method = $this->getProtectedMethod( 'rate_limit_backoff_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'Retry-After' => '4' ),
		);

		$this->assertSame( 4, $method->invoke( $this->handler, $result, 0 ) );
	}

	public function test_backoff_clamps_oversized_retry_after_header() {
		$method = $this->getProtectedMethod( 'rate_limit_backoff_seconds' );

		$result = array(
			'success' => false,
			'headers' => array( 'Retry-After' => '600' ),
		);

		$this->assertSame( 8, $method->invoke( $this->handler, $result, 0 ) );
	}

	private function getProtectedMethod( string $name ) {
		$reflection = new ReflectionClass( $this->handler );
		$method     = $reflection->getMethod( $name );
		$method->setAccessible( true );
		return $method;
	}

	public function test_register_junk_patterns_seeds_ticketmaster_defaults() {
		$result = $this->handler->register_junk_patterns( array(), 'ticketmaster' );

		$this->assertContains( 'CCPER-', $result['id'] );
		$this->assertContains( 'CCPER-', $result['title'] );
		$this->assertContains( 'Standalone Upsell', $result['title'] );
		$this->assertContains( 'Test Event', $result['title'] );
		$this->assertContains( 'Upcoming Event', $result['title_prefix_no_artist'] );
		$this->assertTrue( $result['honor_test_flag'] );
	}

	public function test_register_junk_patterns_ignores_other_sources() {
		$empty = array( 'id' => array(), 'title' => array() );
		$result = $this->handler->register_junk_patterns( $empty, 'dice' );

		$this->assertSame( $empty, $result );
	}

	public function test_junk_patterns_exposed_via_filter() {
		$patterns = apply_filters( 'data_machine_events_junk_payload_patterns', array(), 'ticketmaster' );

		$this->assertNotEmpty( $patterns['id'] );
		$this->assertNotEmpty( $patterns['title'] );
		$this->assertNotEmpty( $patterns['title_prefix_no_artist'] );
	}

	public function test_is_junk_payload_drops_ccper_event() {
		$filter = new \DataMachineEvents\Steps\EventImport\JunkPayloadFilter();
		$this->assertTrue(
			$filter->is_junk(
				array(
					'source_id'        => 'CCPER-2756',
					'title'            => 'Upcoming Event CCPER-2756',
					'artist'           => '',
					'is_explicit_test' => false,
				),
				'ticketmaster'
			)
		);
	}

	public function test_is_junk_payload_drops_explicit_test_flag() {
		$filter = new \DataMachineEvents\Steps\EventImport\JunkPayloadFilter();
		$this->assertTrue(
			$filter->is_junk(
				array(
					'source_id'        => 'Z5xNormal',
					'title'            => 'Some Real Event',
					'artist'           => 'Real Artist',
					'is_explicit_test' => true,
				),
				'ticketmaster'
			)
		);
	}

	public function test_is_junk_payload_keeps_normal_event() {
		$filter = new \DataMachineEvents\Steps\EventImport\JunkPayloadFilter();
		$this->assertFalse(
			$filter->is_junk(
				array(
					'source_id'        => 'vvG1hZwAd_k-p',
					'title'            => 'Phish - Summer Tour 2026',
					'artist'           => 'Phish',
					'is_explicit_test' => false,
				),
				'ticketmaster'
			)
		);
	}

	private function handlerConfig( string $location, string $flow_step_id, int $max_items = Ticketmaster::DEFAULT_MAX_ITEMS ): array {
		return array(
			'pipeline_id'        => 1,
			'flow_id'            => abs( crc32( $flow_step_id ) ),
			'flow_step_id'       => $flow_step_id,
			'classification_type' => 'music',
			'location'           => $location,
			'max_items'          => $max_items,
		);
	}

	private function completePacket( array $packet, int $job_id ): void {
		$this->assertTrue( StepLifecycleHandler::handleCompleted( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ] ) ) );
	}

	private function failPacket( array $packet, int $job_id ): void {
		$this->assertTrue( StepLifecycleHandler::handleFailed( $job_id, array( ProcessedItems::CLAIM_METADATA_KEY => $packet['metadata'][ ProcessedItems::CLAIM_METADATA_KEY ] ) ) );
	}

	private function createJob( string $label, int $parent_job_id = 0 ): int {
		$job_id = ( new Jobs() )->create_job(
			array(
				'pipeline_id'   => 1,
				'flow_id'       => 1,
				'label'         => $label,
				'parent_job_id' => $parent_job_id,
			)
		);
		$this->assertIsInt( $job_id );
		return $job_id;
	}

	private function ticketmasterEvent( string $id, string $title, string $ticket_url = '' ): array {
		return array(
			'id'    => $id,
			'name'  => $title,
			'url'   => '' !== $ticket_url ? $ticket_url : 'https://www.ticketmaster.com/event/' . rawurlencode( $id ),
			'dates' => array(
				'status' => array( 'code' => 'onsale' ),
				'start'  => array(
					'localDate' => '2099-08-15',
					'localTime' => '20:00:00',
				),
			),
			'_embedded' => array(
				'venues' => array(
					array(
						'name'     => 'Overlap Arena',
						'timezone' => 'America/Los_Angeles',
						'address'  => array( 'line1' => '1 Music Way' ),
						'city'     => array( 'name' => 'Oakland' ),
						'state'    => array( 'stateCode' => 'CA' ),
					),
				),
			),
		);
	}

	private function ticketmasterPage( array $events, int $number = 0, int $total_pages = 1 ): array {
		return array(
			'events' => $events,
			'page'   => array(
				'number'     => $number,
				'totalPages' => $total_pages,
			),
		);
	}

	private function ensureEventIdentityTables(): void {
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}
	}

	private function seedImportedEvent( string $title, string $ticket_url ): array {
		$term = wp_insert_term( 'Overlap Arena ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$term_id = (int) $term['term_id'];
		update_term_meta( $term_id, '_venue_address', '1 Music Way' );
		update_term_meta( $term_id, '_venue_city', 'Oakland' );

		$post_id = wp_insert_post(
			array(
				'post_title'  => $title,
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		$this->assertGreaterThan( 0, $post_id );
		wp_set_object_terms( $post_id, array( $term_id ), 'venue' );
		update_post_meta( $post_id, \DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY, $ticket_url );
		EventDatesTable::upsert( $post_id, '2099-08-15 20:00:00' );

		return array( $post_id, $term_id );
	}
}

class TicketmasterHandlerTestDouble extends Ticketmaster {

	private array $pages;

	public function __construct( array $pages ) {
		$this->pages = $pages;
		parent::__construct();
	}

	protected function getAuthProvider( string $provider_key ): ?object {
		$provider_key;
		return new class() {
			public function get_account(): array {
				return array( 'api_key' => 'test-key' );
			}
		};
	}

	protected function fetch_events( array $params, ExecutionContext $context ): array {
		$context;
		return $this->pages[ (int) ( $params['page'] ?? 0 ) ] ?? array(
			'events' => array(),
			'page'   => array(
				'number'     => (int) ( $params['page'] ?? 0 ),
				'totalPages' => 0,
			),
		);
	}
}
