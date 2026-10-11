<?php
/**
 * Native source key tests (#909).
 *
 * A corrected start time changes the time-aware source identity. The native
 * per-event source key must route the re-import to the original event.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\Upsert\Events\EventUpsert;

class EventUpsertSourceKeyTest extends EventUpsertTestCase {

	public function test_time_correction_updates_original_event(): void {
		$title = 'Spotlight Dance ' . uniqid();
		$venue = 'Source Key Venue ' . uniqid();
		$key   = 'https://link.dice.fm/' . uniqid();

		$first  = $this->invoke_upsert(
			array(
				'title'            => $title,
				'venue'            => $venue,
				'startDate'        => '2026-10-12',
				'startTime'        => '03:00',
				'source_type'      => 'dice_fm',
				'item_identifier'  => 'hash-utc-' . uniqid(),
				'source_event_key' => $key,
			)
		);
		$second = $this->invoke_upsert(
			array(
				'title'            => $title,
				'venue'            => $venue,
				'startDate'        => '2026-10-11',
				'startTime'        => '20:00',
				'source_type'      => 'dice_fm',
				'item_identifier'  => 'hash-local-' . uniqid(),
				'source_event_key' => $key,
			)
		);

		$this->assertTrue( $first['success'] ?? false, wp_json_encode( $first ) );
		$this->assertTrue( $second['success'] ?? false, wp_json_encode( $second ) );
		$this->assertSame( $first['data']['post_id'], $second['data']['post_id'] );
		$this->assertSame( 'updated', $second['data']['action'] );
		$this->assertSame( 1, $this->countEventsWithTitle( $title ) );
		$this->assertSame(
			EventUpsert::sourceKeyIdentity( 'dice_fm', $key ),
			get_post_meta( $first['data']['post_id'], EventUpsert::SOURCE_KEY_META_KEY, true )
		);
	}

	public function test_reused_key_for_a_different_show_creates_a_new_event(): void {
		$venue = 'Shared Link Venue ' . uniqid();
		$key   = 'https://tickets.example.test/orders/new';

		$first  = $this->invoke_upsert(
			array(
				'title'            => 'Early Show ' . uniqid(),
				'venue'            => $venue,
				'startDate'        => '2026-10-17',
				'startTime'        => '19:00',
				'source_type'      => 'universal_web_scraper',
				'item_identifier'  => 'early-' . uniqid(),
				'source_event_key' => '42|' . $key,
			)
		);
		$second = $this->invoke_upsert(
			array(
				'title'            => 'Late Show ' . uniqid(),
				'venue'            => $venue,
				'startDate'        => '2026-10-17',
				'startTime'        => '22:00',
				'source_type'      => 'universal_web_scraper',
				'item_identifier'  => 'late-' . uniqid(),
				'source_event_key' => '42|' . $key,
			)
		);

		$this->assertSame( 'created', $second['data']['action'] ?? '', wp_json_encode( $second ) );
		$this->assertNotSame( $first['data']['post_id'], $second['data']['post_id'] );
	}

	public function test_same_title_weeks_apart_creates_a_new_event(): void {
		$title = 'Weekly Residency ' . uniqid();
		$venue = 'Residency Venue ' . uniqid();
		$key   = 'https://venue.example.test/residency';

		$first  = $this->invoke_upsert(
			array(
				'title'            => $title,
				'venue'            => $venue,
				'startDate'        => '2026-10-07',
				'startTime'        => '20:00',
				'source_type'      => 'universal_web_scraper',
				'item_identifier'  => 'week1-' . uniqid(),
				'source_event_key' => '7|' . $key,
			)
		);
		$second = $this->invoke_upsert(
			array(
				'title'            => $title,
				'venue'            => $venue,
				'startDate'        => '2026-10-14',
				'startTime'        => '20:00',
				'source_type'      => 'universal_web_scraper',
				'item_identifier'  => 'week2-' . uniqid(),
				'source_event_key' => '7|' . $key,
			)
		);

		$this->assertSame( 'created', $second['data']['action'] ?? '', wp_json_encode( $second ) );
		$this->assertNotSame( $first['data']['post_id'], $second['data']['post_id'] );
	}

	public function test_lock_domains_include_source_key(): void {
		$method = new \ReflectionMethod( $this->handler, 'buildUpsertLockKeys' );
		$method->setAccessible( true );

		$without = $method->invoke( $this->handler, 'Title', 'Venue', '2026-10-11 20:00', 'identity', array(), 0 );
		$with    = $method->invoke( $this->handler, 'Title', 'Venue', '2026-10-11 20:00', 'identity', array(), 0, 'source-key' );

		$this->assertCount( count( $without ) + 1, $with );
		$this->assertContains( 'dme:' . md5( 'sourcekey|' . get_current_blog_id() . '|source-key' ), $with );
	}
}
