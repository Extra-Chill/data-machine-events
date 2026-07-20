<?php
/**
 * Events By Term Abilities Tests
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\EventsByTermAbilities;
use WP_UnitTestCase;

class EventsByTermAbilitiesTest extends WP_UnitTestCase {

	private const MAPPING_META = '_data_machine_events_main_artist_term_id';

	protected function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post' );
		}
	}

	private function createArtistTerm( string $name, string $slug ): int {
		$created = wp_insert_term( $name, 'artist', array( 'slug' => $slug ) );
		$this->assertNotWPError( $created );

		return (int) $created['term_id'];
	}

	public function test_events_blog_defaults_to_current_site(): void {
		$abilities = new EventsByTermAbilities();
		$method    = new \ReflectionMethod( $abilities, 'resolveEventsBlogId' );
		$method->setAccessible( true );

		$this->assertSame( get_current_blog_id(), $method->invoke( $abilities ) );
	}

	public function test_events_blog_can_be_configured_by_consumer(): void {
		$callback = static function ( int $blog_id ): int {
			return $blog_id + 1;
		};
		add_filter( 'data_machine_events_events_blog_id', $callback );

		$abilities = new EventsByTermAbilities();
		$method    = new \ReflectionMethod( $abilities, 'resolveEventsBlogId' );
		$method->setAccessible( true );

		$this->assertSame( get_current_blog_id() + 1, $method->invoke( $abilities ) );
		remove_filter( 'data_machine_events_events_blog_id', $callback );
	}

	public function test_canonical_main_id_resolves_mapped_events_term_after_slug_rename(): void {
		$canonical_id = $this->createArtistTerm( 'Canonical Artist', 'canonical-original' );
		$events_id    = $this->createArtistTerm( 'Events Artist', 'events-original' );
		update_term_meta( $events_id, self::MAPPING_META, $canonical_id );
		$this->assertNotWPError( wp_update_term( $canonical_id, 'artist', array( 'slug' => 'renamed-on-main' ) ) );
		$this->assertNotWPError( wp_update_term( $events_id, 'artist', array( 'slug' => 'renamed-on-events' ) ) );

		$result = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy'     => 'artist',
				'main_term_id' => $canonical_id,
				'scope'        => 'upcoming',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['found'] );
		$this->assertSame( $events_id, $result['term_id'] );
		$this->assertSame( 'renamed-on-events', $result['term_slug'] );
		$this->assertSame( $canonical_id, $result['main_term_id'] );
	}

	public function test_legacy_slug_lookup_remains_supported(): void {
		$events_id = $this->createArtistTerm( 'Legacy Artist', 'legacy-artist' );

		$result = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy'  => 'artist',
				'term_slug' => 'legacy-artist',
				'scope'     => 'upcoming',
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['found'] );
		$this->assertSame( $events_id, $result['term_id'] );
		$this->assertSame( 'legacy-artist', $result['term_slug'] );
	}

	public function test_events_term_id_wins_over_disagreeing_legacy_slug(): void {
		$stable_id = $this->createArtistTerm( 'Stable Artist', 'stable-artist' );
		$this->createArtistTerm( 'Wrong Artist', 'wrong-artist' );

		$result = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy'  => 'artist',
				'term_id'   => $stable_id,
				'term_slug' => 'wrong-artist',
				'scope'     => 'upcoming',
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( $stable_id, $result['term_id'] );
		$this->assertSame( 'stable-artist', $result['term_slug'] );
	}

	public function test_missing_and_stale_canonical_mappings_fail_closed(): void {
		$canonical_id = $this->createArtistTerm( 'Unmapped Artist', 'unmapped-artist' );
		$stale_id     = $this->createArtistTerm( 'Stale Events Artist', 'stale-events-artist' );
		update_term_meta( $stale_id, self::MAPPING_META, 999999 );

		$missing = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy'     => 'artist',
				'main_term_id' => $canonical_id,
				'scope'        => 'upcoming',
			)
		);
		$stale   = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy'     => 'artist',
				'main_term_id' => 999999,
				'scope'        => 'upcoming',
			)
		);

		$this->assertIsArray( $missing );
		$this->assertFalse( $missing['found'] );
		$this->assertWPError( $stale );
		$this->assertSame( 'invalid_main_term_id', $stale->get_error_code() );
	}

	public function test_duplicate_canonical_claims_are_rejected(): void {
		$canonical_id = $this->createArtistTerm( 'Canonical Collision', 'canonical-collision' );
		$first_id     = $this->createArtistTerm( 'First Claim', 'first-claim' );
		$second_id    = $this->createArtistTerm( 'Second Claim', 'second-claim' );
		update_term_meta( $first_id, self::MAPPING_META, $canonical_id );
		update_term_meta( $second_id, self::MAPPING_META, $canonical_id );

		$result = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy'     => 'artist',
				'main_term_id' => $canonical_id,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ambiguous_artist_term_mapping', $result->get_error_code() );
	}

	/**
	 * Invalid stable identifiers are rejected before lookup.
	 *
	 * @param array  $input         Ability input under test.
	 * @param string $expected_code Expected WP_Error code.
	 *
	 * @dataProvider invalidIdProvider
	 */
	public function test_invalid_ids_are_rejected( array $input, string $expected_code ): void {
		$result = ( new EventsByTermAbilities() )->executeEventsByTerm( array_merge( array( 'taxonomy' => 'artist' ), $input ) );

		$this->assertWPError( $result );
		$this->assertSame( $expected_code, $result->get_error_code() );
	}

	public static function invalidIdProvider(): array {
		return array(
			'zero events ID'        => array( array( 'term_id' => 0 ), 'invalid_term_id' ),
			'negative events ID'    => array( array( 'term_id' => -1 ), 'invalid_term_id' ),
			'missing events ID'     => array( array( 'term_id' => 999999 ), 'invalid_term_id' ),
			'zero canonical ID'     => array( array( 'main_term_id' => 0 ), 'invalid_main_term_id' ),
			'negative canonical ID' => array( array( 'main_term_id' => -1 ), 'invalid_main_term_id' ),
		);
	}

	public function test_wrong_taxonomy_events_id_is_rejected(): void {
		if ( ! taxonomy_exists( 'venue' ) ) {
			register_taxonomy( 'venue', 'post' );
		}
		$created = wp_insert_term( 'Wrong Taxonomy', 'venue' );
		$this->assertNotWPError( $created );

		$result = ( new EventsByTermAbilities() )->executeEventsByTerm(
			array(
				'taxonomy' => 'artist',
				'term_id'  => (int) $created['term_id'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_term_id', $result->get_error_code() );
	}

	public function test_canonical_lookup_restores_multisite_blog_context(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required to verify blog restoration.' );
		}

		$events_blog_id = get_current_blog_id();
		$main_blog_id   = self::factory()->blog->create();
		switch_to_blog( $main_blog_id );
		try {
			$canonical_id = $this->createArtistTerm( 'Remote Canonical', 'remote-canonical' );
		} finally {
			restore_current_blog();
		}

		$main_filter = static fn(): int => $main_blog_id;
		add_filter( 'data_machine_events_main_blog_id', $main_filter );
		try {
			$result = ( new EventsByTermAbilities() )->executeEventsByTerm(
				array(
					'taxonomy'     => 'artist',
					'main_term_id' => $canonical_id,
				)
			);
			$this->assertIsArray( $result );
			$this->assertSame( $events_blog_id, get_current_blog_id() );
		} finally {
			remove_filter( 'data_machine_events_main_blog_id', $main_filter );
		}
	}

	public function test_backfill_maps_only_unambiguous_unclaimed_pairs(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite is required to verify cross-site backfill.' );
		}

		$main_blog_id = self::factory()->blog->create();
		switch_to_blog( $main_blog_id );
		try {
			$safe_main_id      = $this->createArtistTerm( 'Safe Pair', 'safe-pair' );
			$collision_main_id = $this->createArtistTerm( 'Collision Pair', 'collision-pair' );
			$unmatched_main_id = $this->createArtistTerm( 'Main Only', 'main-only' );
		} finally {
			restore_current_blog();
		}

		$safe_events_id      = $this->createArtistTerm( 'Safe Pair', 'safe-pair' );
		$collision_events_id = $this->createArtistTerm( 'Collision Pair', 'collision-pair' );
		$existing_claim_id   = $this->createArtistTerm( 'Existing Claim', 'existing-claim' );
		$missing_events_id   = $this->createArtistTerm( 'Missing Pair', 'missing-pair' );
		update_term_meta( $existing_claim_id, self::MAPPING_META, $collision_main_id );

		$main_filter = static fn(): int => $main_blog_id;
		add_filter( 'data_machine_events_main_blog_id', $main_filter );
		try {
			$report = ( new EventsByTermAbilities() )->backfillArtistTermMappings();
		} finally {
			remove_filter( 'data_machine_events_main_blog_id', $main_filter );
		}

		$this->assertSame( $safe_main_id, (int) get_term_meta( $safe_events_id, self::MAPPING_META, true ) );
		$this->assertSame( '', get_term_meta( $collision_events_id, self::MAPPING_META, true ) );
		$this->assertSame( 1, $report['mapped'] );
		$this->assertContains( $collision_events_id, $report['collisions'] );
		$this->assertContains( $missing_events_id, $report['missing'] );
		$this->assertContains( $unmatched_main_id, $report['unmatched_main'] );
	}
}
