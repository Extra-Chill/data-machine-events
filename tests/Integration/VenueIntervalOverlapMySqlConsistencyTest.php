<?php
/**
 * Real MySQL overlap visibility coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachineEvents\Abilities\VenueIntervalOverlapAbilities;
use DataMachineEvents\Core\EventDatesTable;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueIntervalOverlapMySqlConsistencyTest extends WP_UnitTestCase {

	public function test_overlap_query_observes_only_committed_index_updates(): void {
		global $wpdb, $table_prefix;
		if ( ! $wpdb->dbh instanceof \mysqli ) {
			$this->markTestSkipped( 'MySQL consistency test requires mysqli.' );
		}
		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
		if ( ! EventDatesTable::table_exists() ) {
			EventDatesTable::create_table();
		}

		$venue = wp_insert_term( 'Consistency Venue ' . uniqid(), 'venue' );
		$this->assertNotWPError( $venue );
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => Event_Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $event_id, array( (int) $venue['term_id'] ), 'venue' );
		EventDatesTable::upsert( $event_id, '2028-01-01 10:00:00', '2028-01-01 11:00:00', 'publish' );

		$reader = new VenueIntervalOverlapAbilities();
		$input  = array(
			'venue_id' => (int) $venue['term_id'],
			'start'    => '2028-01-01T10:00:00+00:00',
			'end'      => '2028-01-01T12:00:00+00:00',
		);
		$this->assertSame( array( $event_id ), array_column( $reader->execute( $input )['events'], 'event_id' ) );

		$writer = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$writer->set_prefix( $table_prefix );
		$table = EventDatesTable::table_name();
		$writer->query( 'START TRANSACTION' );
		$writer->query( $writer->prepare( "UPDATE {$table} SET start_datetime = %s, end_datetime = %s WHERE post_id = %d", '2028-01-02 10:00:00', '2028-01-02 11:00:00', $event_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Independent transaction proves commit visibility.

		$this->assertSame( array( $event_id ), array_column( $reader->execute( $input )['events'], 'event_id' ) );
		$writer->query( 'COMMIT' );
		$this->assertSame( array(), $reader->execute( $input )['events'] );
		$writer->close();
	}
}
