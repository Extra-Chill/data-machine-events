<?php
/**
 * Canonical calendar occurrence artifact tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use PHPUnit\Framework\TestCase;
use DataMachineEvents\Api\Serializers\CalendarOccurrence;
use DataMachineEvents\Contracts\CalendarOccurrenceArtifact;

/**
 * Verifies deterministic producer serialization and portable pinning.
 */
final class CalendarOccurrenceContractTest extends TestCase {

	/**
	 * Producer regeneration must equal the fixture byte for byte.
	 */
	public function test_checked_in_artifact_matches_byte_stable_producer_output(): void {
		$first  = $this->generate_artifact_bytes();
		$second = $this->generate_artifact_bytes();
		$path   = dirname( __DIR__, 2 ) . '/contracts/calendar-occurrence-v1.json';
		if ( '1' === getenv( 'DME_UPDATE_CONTRACT_FIXTURES' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Explicit fixture regeneration mode.
			file_put_contents( $path, $first );
		}

		$this->assertSame( $first, $second, 'Repeated producer serialization must be byte-stable.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local checked-in artifact.
		$this->assertSame( (string) file_get_contents( $path ), $first );
	}

	/**
	 * Required canonical fields must remain on the producer boundary.
	 */
	public function test_artifact_contains_required_canonical_fields(): void {
		$artifact   = json_decode( $this->generate_artifact_bytes(), true, 512, JSON_THROW_ON_ERROR );
		$event      = $artifact['event'];
		$occurrence = $artifact['occurrence'];

		$this->assertSame( 'The Seeded Performers', $event['performer']['name'] );
		$this->assertSame( 'PerformingGroup', $event['performer']['type'] );
		$this->assertSame( 'Seeded Productions', $event['organizer']['name'] );
		$this->assertSame( 'Organization', $event['organizer']['type'] );
		$this->assertSame( 'EventRescheduled', $event['status'] );
		foreach ( array( 'artist', 'location', 'promoter' ) as $taxonomy ) {
			$this->assertArrayHasKey( $taxonomy, $event['taxonomies'] );
		}
		foreach ( array( 'term_id', 'name', 'slug', 'address', 'formatted_address', 'city', 'state', 'zip', 'country', 'coordinates', 'timezone' ) as $field ) {
			$this->assertArrayHasKey( $field, $event['venue'] );
		}
		foreach ( array( 'is_multi_day', 'is_start_day', 'is_end_day', 'is_continuation', 'display_date', 'original_start_date', 'original_end_date', 'day_number', 'total_days' ) as $field ) {
			$this->assertArrayHasKey( $field, $occurrence['display_context'] );
		}
	}

	/**
	 * Portable pin checks must reject producer version and hash drift.
	 */
	public function test_portable_pin_verification_rejects_version_and_hash_drift(): void {
		$manifest = CalendarOccurrenceArtifact::manifest();

		$this->assertTrue( CalendarOccurrenceArtifact::verify_pin( $manifest['name'], $manifest['version'], $manifest['sha256'] ) );
		$this->assertFalse( CalendarOccurrenceArtifact::verify_pin( $manifest['name'], $manifest['version'] + 1, $manifest['sha256'] ) );
		$this->assertFalse( CalendarOccurrenceArtifact::verify_pin( $manifest['name'], $manifest['version'], str_repeat( '0', 64 ) ) );
	}

	/**
	 * Generate canonical bytes from deterministic generic producer data.
	 */
	private function generate_artifact_bytes(): string {
		$artifact = CalendarOccurrence::serialize( $this->seeded_serializer_output() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Portable test intentionally runs without WordPress.
		return json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	}

	/**
	 * Seed the complete generic output of the runtime calendar serializer.
	 */
	private function seeded_serializer_output(): array {
		return array(
			'event'      => array(
				'id'             => 507001,
				'title'          => 'Seeded Calendar Contract Event',
				'date'           => array(
					'start_date'     => '2099-07-21',
					'start_time'     => '19:30:00',
					'end_date'       => '2099-07-22',
					'end_time'       => '00:30:00',
					'venue_timezone' => 'America/New_York',
				),
				'venue'          => array(
					'term_id'           => 507101,
					'name'              => 'Seeded Hall',
					'slug'              => 'seeded-hall',
					'address'           => '100 Fixture Way',
					'formatted_address' => '100 Fixture Way, Seed City, SC, 29000',
					'city'              => 'Seed City',
					'state'             => 'SC',
					'zip'               => '29000',
					'country'           => 'US',
					'coordinates'       => '32.000000,-80.000000',
					'timezone'          => 'America/New_York',
					'website'           => 'https://venue.invalid',
				),
				'organizer'      => array(
					'name' => 'Seeded Productions',
					'url'  => 'https://producer.invalid',
					'type' => 'Organization',
				),
				'ticket'         => array( 'url' => 'https://tickets.invalid/seeded-show' ),
				'performer'      => array(
					'name' => 'The Seeded Performers',
					'type' => 'PerformingGroup',
				),
				'status'         => 'EventRescheduled',
				'taxonomies'     => array(
					'artist'   => array(
						array(
							'term_id' => 507102,
							'name'    => 'The Seeded Performers',
							'slug'    => 'the-seeded-performers',
							'link'    => 'https://producer.invalid/artist',
						),
					),
					'location' => array(
						array(
							'term_id' => 507103,
							'name'    => 'Seed City',
							'slug'    => 'seed-city',
							'link'    => 'https://producer.invalid/location',
						),
					),
					'promoter' => array(
						array(
							'term_id' => 507104,
							'name'    => 'Seeded Productions',
							'slug'    => 'seeded-productions',
							'link'    => 'https://producer.invalid/promoter',
						),
					),
				),
				'badges_html'    => '<div>presentation-only</div>',
				'button_classes' => 'presentation-only',
			),
			'occurrence' => array(
				'post_id'         => 507001,
				'display_context' => array(
					'is_multi_day'        => true,
					'is_start_day'        => true,
					'is_end_day'          => false,
					'is_continuation'     => false,
					'display_date'        => '2099-07-21',
					'original_start_date' => '2099-07-21',
					'original_end_date'   => '2099-07-22',
					'day_number'          => 1,
					'total_days'          => 1,
				),
				'display'         => array(
					'formatted_time_display' => '7:30 PM',
					'multi_day_label'        => 'through Jul 22',
					'iso_start_date'         => '2099-07-21T19:30:00-04:00',
					'venue_name'             => 'Seeded Hall',
					'performer_name'         => 'The Seeded Performers',
					'show_performer'         => false,
					'show_ticket_link'       => true,
					'is_continuation'        => false,
					'is_multi_day'           => true,
				),
			),
		);
	}
}
