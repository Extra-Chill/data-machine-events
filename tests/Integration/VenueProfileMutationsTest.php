<?php
/**
 * Canonical venue profile mutation integration coverage.
 *
 * @package DataMachineEvents\Tests\Integration
 */

namespace DataMachineEvents\Tests\Integration;

use DataMachineEvents\Admin\Settings_Page;
use DataMachineEvents\Core\VenueProfileMutations;
use DataMachineEvents\Core\Venue_Taxonomy;
use WP_UnitTestCase;

class VenueProfileMutationsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'venue' ) ) {
			Venue_Taxonomy::register();
		}
	}

	public function test_stale_profile_write_detects_another_canonical_writer(): void {
		$term_id = $this->venue( 'Competing Writer' );
		$profile = VenueProfileMutations::read( $term_id );
		$this->assertNotWPError( $profile );

		$system = VenueProfileMutations::updateSystem( $term_id, array( 'phone' => '843-555-0100' ) );
		$this->assertNotWPError( $system );

		$stale = VenueProfileMutations::updateProfile( $term_id, array( 'website' => 'https://venue.example' ), $profile['revision'] );
		$this->assertWPError( $stale );
		$this->assertSame( 'venue_revision_conflict', $stale->get_error_code() );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_website', true ) );
	}

	public function test_profile_boundary_excludes_and_ignores_system_fields(): void {
		$term_id = $this->venue( 'Bounded Profile' );
		update_term_meta( $term_id, '_venue_coordinates', '32.7765,-79.9311' );
		update_term_meta( $term_id, '_venue_timezone', 'America/New_York' );
		$profile = VenueProfileMutations::read( $term_id );

		$this->assertArrayNotHasKey( 'coordinates', $profile );
		$this->assertArrayNotHasKey( 'timezone', $profile );
		$result = VenueProfileMutations::updateProfile(
			$term_id,
			array(
				'phone'       => '843-555-0101',
				'coordinates' => '1,2',
				'timezone'    => 'UTC',
			),
			$profile['revision']
		);
		$this->assertNotWPError( $result );
		$this->assertSame( '32.7765,-79.9311', get_term_meta( $term_id, '_venue_coordinates', true ) );
		$this->assertSame( 'America/New_York', get_term_meta( $term_id, '_venue_timezone', true ) );
	}

	public function test_duplicate_meta_hooks_cache_and_canonical_hook_follow_core_semantics(): void {
		$term_id = $this->venue( 'Duplicate Meta' );
		add_term_meta( $term_id, '_venue_phone', 'first' );
		add_term_meta( $term_id, '_venue_phone', 'second' );
		get_term_meta( $term_id );
		$profile = VenueProfileMutations::read( $term_id );
		$updates = 0;
		$mutated = 0;

		$meta_hook = static function ( $meta_id, $object_id, $meta_key ) use ( $term_id, &$updates ): void {
			if ( $term_id === (int) $object_id && '_venue_phone' === $meta_key ) {
				++$updates;
			}
		};
		$owner_hook = static function () use ( &$mutated ): void {
			++$mutated;
		};
		add_action( 'updated_term_meta', $meta_hook, 10, 3 );
		add_action( 'data_machine_events_venue_mutated', $owner_hook );

		$result = VenueProfileMutations::updateProfile( $term_id, array( 'phone' => 'canonical' ), $profile['revision'] );

		remove_action( 'updated_term_meta', $meta_hook, 10 );
		remove_action( 'data_machine_events_venue_mutated', $owner_hook );
		$this->assertNotWPError( $result );
		$this->assertSame( 2, $updates, 'Core must update and fire hooks for both duplicate rows.' );
		$this->assertSame( 1, $mutated, 'The durable owner hook must fire exactly once.' );
		$this->assertSame( array( 'canonical', 'canonical' ), get_term_meta( $term_id, '_venue_phone', false ) );
		$this->assertSame( 'canonical', get_term_meta( $term_id, '_venue_phone', true ) );
	}

	public function test_address_relocation_replaces_coordinates_and_timezone_atomically(): void {
		$term_id = $this->venue( 'Relocation' );
		update_term_meta( $term_id, '_venue_address', '100 King Street' );
		update_term_meta( $term_id, '_venue_city', 'Charleston' );
		update_term_meta( $term_id, '_venue_state', 'SC' );
		update_term_meta( $term_id, '_venue_coordinates', '32.7765,-79.9311' );
		update_term_meta( $term_id, '_venue_timezone', 'America/New_York' );
		update_option( Settings_Page::OPTION_KEY, array( 'geonames_username' => 'integration-test' ) );

		$http = static function ( $preempt, $args, $url ) {
			if ( str_contains( $url, 'nominatim.openstreetmap.org' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( array( 'lat' => '34.0522', 'lon' => '-118.2437' ) ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			if ( str_contains( $url, 'geonames.org' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'timezoneId' => 'America/Los_Angeles' ) ),
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		$profile = VenueProfileMutations::read( $term_id );
		$result  = VenueProfileMutations::updateProfile(
			$term_id,
			array(
				'address' => '800 West Olympic Boulevard',
				'city'    => 'Los Angeles',
				'state'   => 'CA',
			),
			$profile['revision']
		);
		remove_filter( 'pre_http_request', $http, 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( '34.0522,-118.2437', get_term_meta( $term_id, '_venue_coordinates', true ) );
		$this->assertSame( 'America/Los_Angeles', get_term_meta( $term_id, '_venue_timezone', true ) );
	}

	public function test_failed_meta_filter_rolls_back_all_fields_and_suppresses_owner_hook(): void {
		$term_id = $this->venue( 'Rollback' );
		update_term_meta( $term_id, '_venue_phone', 'before' );
		$profile = VenueProfileMutations::read( $term_id );
		$mutated = 0;
		$block   = static function ( $check, $object_id, $meta_key ) use ( $term_id ) {
			return $term_id === (int) $object_id && '_venue_website' === $meta_key ? false : $check;
		};
		$owner_hook = static function () use ( &$mutated ): void {
			++$mutated;
		};
		add_filter( 'update_term_metadata', $block, 10, 3 );
		add_action( 'data_machine_events_venue_mutated', $owner_hook );

		$result = VenueProfileMutations::updateProfile(
			$term_id,
			array(
				'phone'   => 'after',
				'website' => 'https://blocked.example',
			),
			$profile['revision']
		);

		remove_filter( 'update_term_metadata', $block, 10 );
		remove_action( 'data_machine_events_venue_mutated', $owner_hook );
		$this->assertWPError( $result );
		$this->assertSame( 'before', get_term_meta( $term_id, '_venue_phone', true ) );
		$this->assertSame( '', get_term_meta( $term_id, '_venue_website', true ) );
		$this->assertSame( 0, $mutated );
	}

	public function test_multisite_lock_and_revision_scope_are_blog_specific(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite scope requires the multisite WordPress test suite.' );
		}
		$first_id = $this->venue( 'Main Site Venue' );
		$first    = VenueProfileMutations::read( $first_id );
		$blog_id  = self::factory()->blog->create();
		switch_to_blog( $blog_id );
		Venue_Taxonomy::register();
		$second_id = $this->venue( 'Second Site Venue' );
		$second    = VenueProfileMutations::read( $second_id );
		restore_current_blog();

		$this->assertNotSame( $first['revision'], $second['revision'] );
	}

	public function test_uncertain_commit_requires_a_fresh_read(): void {
		$term_id = $this->venue( 'Commit Uncertain' );
		$profile = VenueProfileMutations::read( $term_id );
		$result  = VenueProfileCommitUncertain::updateProfile( $term_id, array( 'phone' => 'committed-or-not' ), $profile['revision'] );

		$this->assertWPError( $result );
		$this->assertSame( 'venue_commit_uncertain', $result->get_error_code() );
		$this->assertNotWPError( VenueProfileMutations::read( $term_id ) );
	}

	public function test_uncertain_rollback_reports_the_original_failure_code(): void {
		$term_id = $this->venue( 'Rollback Uncertain' );
		$profile = VenueProfileMutations::read( $term_id );
		$block   = static function ( $check, $object_id, $meta_key ) use ( $term_id ) {
			return $term_id === (int) $object_id && '_venue_phone' === $meta_key ? false : $check;
		};
		add_filter( 'update_term_metadata', $block, 10, 3 );
		$result = VenueProfileRollbackUncertain::updateProfile( $term_id, array( 'phone' => 'blocked' ), $profile['revision'] );
		remove_filter( 'update_term_metadata', $block, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'venue_rollback_uncertain', $result->get_error_code() );
		$this->assertSame( 'venue_meta_update_failed', $result->get_error_data()['original_error'] );
	}

	private function venue( string $prefix ): int {
		$result = wp_insert_term( $prefix . ' ' . uniqid(), 'venue' );
		$this->assertNotWPError( $result );
		return (int) $result['term_id'];
	}
}

class VenueProfileCommitUncertain extends VenueProfileMutations {
	protected static function query( string $sql ): int|bool {
		$result = parent::query( $sql );
		return 'COMMIT' === $sql || str_starts_with( $sql, 'RELEASE SAVEPOINT' ) ? false : $result;
	}
}

class VenueProfileRollbackUncertain extends VenueProfileMutations {
	protected static function query( string $sql ): int|bool {
		$result = parent::query( $sql );
		return 'ROLLBACK' === $sql || str_starts_with( $sql, 'ROLLBACK TO SAVEPOINT' ) ? false : $result;
	}
}
