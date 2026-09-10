<?php
/**
 * ResolveTicketDestinationAbilities tests.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Abilities\ResolveTicketDestinationAbilities;
use DataMachineEvents\Core\Event_Post_Type;
use WP_UnitTestCase;

class ResolveTicketDestinationAbilitiesTest extends WP_UnitTestCase {

	private ResolveTicketDestinationAbilities $ability;

	public function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Event_Post_Type::POST_TYPE ) ) {
			Event_Post_Type::register();
		}

		$this->ability = new ResolveTicketDestinationAbilities();
	}

	public function test_registered_ability_has_public_permission_and_is_hidden_from_rest(): void {
		$registered = wp_get_ability( 'data-machine-events/resolve-ticket-destination' );

		$this->assertNotNull( $registered );
		// get_meta() takes no arguments and always returns the whole meta
		// array (WP_Ability::get_meta(): array); get_meta_item() is the
		// single-key accessor.
		$this->assertFalse( $registered->get_meta_item( 'show_in_rest' ) );
	}

	public function test_rejects_non_positive_event_id(): void {
		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_event_id', $result->get_error_code() );
	}

	public function test_rejects_missing_event(): void {
		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => 999999 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
	}

	public function test_rejects_non_event_post_type(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'post', 'post_status' => 'publish' ) );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
	}

	public function test_rejects_unpublished_event(): void {
		$event_id = $this->makeEvent( 'https://www.ticketmaster.com/event/123', 'draft' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'event_not_found', $result->get_error_code() );
	}

	public function test_rejects_event_with_no_ticket_url(): void {
		$event_id = self::factory()->post->create(
			array(
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'no_ticket_url', $result->get_error_code() );
	}

	public function test_resolves_direct_ticket_url_as_non_affiliate(): void {
		$url      = 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-';
		$event_id = $this->makeEvent( $url );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'] );
		$this->assertFalse( $result['is_affiliate'] );
	}

	public function test_resolves_affiliate_ticket_url_as_affiliate(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' ) . '&utm_medium=affiliate';
		$event_id = $this->makeEvent( $url );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'], 'Ability returns the stored affiliate URL verbatim; unwrapping is a separate concern.' );
		$this->assertTrue( $result['is_affiliate'] );
	}

	public function test_resolves_full_url_from_block_content_even_when_meta_is_absent(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' );
		$event_id = $this->makeEvent( $url );

		delete_post_meta( $event_id, '_datamachine_ticket_url' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'] );
		$this->assertTrue( $result['is_affiliate'] );
	}

	/**
	 * Regression guard for a revenue-affecting bug: `_datamachine_ticket_url`
	 * meta is deliberately lossy (`datamachine_normalize_ticket_url()`
	 * strips non-identity query params like `utm_medium` for dedup
	 * comparison — see event-dates-sync.php). This ability must resolve
	 * the FULL, as-authored URL from block content — never the stripped
	 * meta — whenever block content is available, or every affiliate
	 * click-through would silently lose tracking parameters.
	 */
	public function test_prefers_full_block_content_url_over_lossy_dedup_meta(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' ) . '&utm_medium=affiliate';
		$event_id = $this->makeEvent( $url );

		// The real save_post sync path would have already normalized this
		// meta down to just the identity ?u= param; assert that directly so
		// this test still catches a regression even if the sync behavior
		// changes shape later.
		$stored_meta = get_post_meta( $event_id, '_datamachine_ticket_url', true );
		$this->assertStringNotContainsString( 'utm_medium', $stored_meta, 'Precondition: the dedup meta is expected to have stripped utm_medium.' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertSame( $url, $result['url'], 'Must return the full block-content URL, not the tracking-param-stripped dedup meta.' );
	}

	public function test_falls_back_to_meta_when_block_content_has_no_ticket_url(): void {
		$event_id = self::factory()->post->create(
			array(
				'post_title'   => 'Meta-Only Ticket Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		$this->assertIsInt( $event_id );

		// Simulate a legacy/edge-case post where the dedup meta carries a
		// ticket URL independently of (missing) block content — e.g.
		// written by an older import path. The ability should still
		// resolve it rather than returning nothing.
		update_post_meta( $event_id, '_datamachine_ticket_url', 'https://www.ticketmaster.com/event/fallback-only' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'https://www.ticketmaster.com/event/fallback-only', $result['url'] );
	}

	private function makeEvent( string $ticket_url, string $status = 'publish' ): int {
		$event_id = self::factory()->post->create(
			array(
				'post_title'   => 'Ticket Destination Event ' . uniqid(),
				'post_type'    => Event_Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_content' => '<!-- wp:data-machine-events/event-details {"startDate":"2027-01-01","startTime":"20:00","ticketUrl":"' . $ticket_url . '"} --><div></div><!-- /wp:data-machine-events/event-details -->',
			)
		);
		$this->assertIsInt( $event_id );
		$this->assertGreaterThan( 0, $event_id );

		return $event_id;
	}
}
