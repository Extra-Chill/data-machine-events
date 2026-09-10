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
		$this->assertFalse( $registered->get_meta( 'show_in_rest' ) );
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

	public function test_falls_back_to_block_content_when_meta_is_absent(): void {
		$url      = 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=' . rawurlencode( 'https://www.ticketmaster.com/event/Z7r9jZ1A7JFo-' );
		$event_id = $this->makeEvent( $url );

		delete_post_meta( $event_id, '_datamachine_ticket_url' );

		$result = $this->ability->executeResolveTicketDestination( array( 'event_id' => $event_id ) );

		$this->assertIsArray( $result );
		$this->assertSame( $url, $result['url'] );
		$this->assertTrue( $result['is_affiliate'] );
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
