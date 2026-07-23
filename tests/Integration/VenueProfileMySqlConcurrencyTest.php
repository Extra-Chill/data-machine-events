<?php
/**
 * Real MySQL concurrency coverage for venue fill-empty rechecks.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachineEvents\Core\VenueProfileMutations;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueProfileMySqlConcurrencyTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		if ( ! extension_loaded( 'mysqli' ) || ! function_exists( 'pcntl_fork' ) || ! $wpdb->dbh instanceof \mysqli ) {
			$this->markTestSkipped( 'Real MySQL venue concurrency coverage requires mysqli and pcntl.' );
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	public function test_waiting_fill_empty_writer_rechecks_after_operator_edit(): void {
		global $wpdb;
		$term = wp_insert_term( 'Fill Empty Race ' . uniqid(), 'venue' );
		$this->assertNotWPError( $term );
		$term_id  = (int) $term['term_id'];
		$lock_key = 'dme:venue:' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $term_id );
		$owner    = $this->connection();
		$this->assertSame( 1, $this->lock( $owner, $lock_key, 0 ) );

		$result_file = tempnam( sys_get_temp_dir(), 'dme-venue-race-' );
		$pid         = pcntl_fork();
		$this->assertNotSame( -1, $pid );
		if ( 0 === $pid ) {
			global $wpdb, $table_prefix;
			$wpdb = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			$wpdb->set_prefix( $table_prefix );
			$result = VenueProfileMutations::updateSystem(
				$term_id,
				array( 'phone' => 'stale-ingestion-value' ),
				VenueProfileMutations::STRATEGY_FILL_EMPTY
			);
			file_put_contents( $result_file, wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code() ) : $result ) );
			exit( 0 );
		}

		usleep( 300000 );
		update_term_meta( $term_id, '_venue_phone', 'operator-value' );
		$this->assertSame( 1, $this->release( $owner, $lock_key ) );
		pcntl_waitpid( $pid, $status );
		$this->assertTrue( pcntl_wifexited( $status ) );
		$result = json_decode( (string) file_get_contents( $result_file ), true );
		unlink( $result_file );
		$owner->close();

		wp_cache_delete( $term_id, 'term_meta' );
		$this->assertArrayNotHasKey( 'error', $result );
		$this->assertSame( 'operator-value', get_term_meta( $term_id, '_venue_phone', true ) );
		$this->assertNotContains( 'phone', $result['updated_fields'] );
	}

	private function connection(): \mysqli {
		$connection = mysqli_init();
		$connection->real_connect( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		return $connection;
	}

	private function lock( \mysqli $connection, string $key, int $timeout ): int {
		$statement = $connection->prepare( 'SELECT GET_LOCK(?, ?)' );
		$statement->bind_param( 'si', $key, $timeout );
		$statement->execute();
		return (int) $statement->get_result()->fetch_row()[0];
	}

	private function release( \mysqli $connection, string $key ): int {
		$statement = $connection->prepare( 'SELECT RELEASE_LOCK(?)' );
		$statement->bind_param( 's', $key );
		$statement->execute();
		return (int) $statement->get_result()->fetch_row()[0];
	}
}
