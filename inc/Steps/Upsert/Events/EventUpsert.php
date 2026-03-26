<?php
/**
 * Event Upsert Handler
 *
 * Intelligently creates or updates event posts based on event identity.
 * Searches for existing events by (title, venue, startDate) and updates if found,
 * creates if new, or skips if data unchanged.
 *
 * Replaces Publisher with smarter create/update logic and change detection.
 *
 * @package DataMachineEvents\Steps\Upsert\Events
 * @since   0.2.0
 */

namespace DataMachineEvents\Steps\Upsert\Events;

use DataMachine\Core\EngineData;
use DataMachineEvents\Steps\Upsert\Events\Venue;
use DataMachineEvents\Steps\Upsert\Events\Promoter;
use DataMachineEvents\Core\Event_Post_Type;
use DataMachineEvents\Core\VenueParameterProvider;
use DataMachineEvents\Core\Promoter_Taxonomy;
use DataMachineEvents\Core\EventSchemaProvider;
use DataMachineEvents\Utilities\EventIdentifierGenerator;
use const DataMachineEvents\Core\EVENT_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_END_DATETIME_META_KEY;
use const DataMachineEvents\Core\EVENT_TICKET_URL_META_KEY;
use function DataMachineEvents\Core\datamachine_normalize_ticket_url;
use function DataMachineEvents\Core\datamachine_extract_ticket_identity;
use DataMachine\Core\Similarity\SimilarityEngine;
use DataMachine\Core\Steps\Update\Handlers\UpdateHandler;
use DataMachine\Core\WordPress\TaxonomyHandler;
use DataMachine\Core\WordPress\WordPressSettingsResolver;
use DataMachine\Core\WordPress\WordPressPublishHelper;
use DataMachineEvents\Core\DuplicateDetection\EventIdentityWriter;

defined( 'ABSPATH' ) || exit;

class EventUpsert extends UpdateHandler {

	protected $taxonomy_handler;

	/**
	 * Process promoter taxonomy assignment.
	 * Engine data takes precedence over AI-provided values.
	 * Maps to Schema.org "organizer" property.
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param EngineData $engine Engine data helper
	 * @param array $handler_config Handler configuration
	 */
	private function processPromoter( int $post_id, array $parameters, EngineData $engine, array $handler_config = array() ): void {
		$selection = $this->getPromoterSelection( $handler_config );

		if ( 'skip' === $selection ) {
			return;
		}

		if ( $this->isPromoterTermSelection( $selection ) ) {
			$this->assignConfiguredPromoter( $post_id, (int) $selection );
			return;
		}

		if ( ! $this->isPromoterAiSelection( $selection ) ) {
			return;
		}

		// Organizer field name maps to promoter taxonomy
		$promoter_name = $engine->get( 'organizer' ) ?? $parameters['organizer'] ?? '';

		if ( empty( $promoter_name ) ) {
			return;
		}

		$promoter_metadata = array(
			'url'  => $engine->get( 'organizerUrl' ) ?? $parameters['organizerUrl'] ?? '',
			'type' => $engine->get( 'organizerType' ) ?? $parameters['organizerType'] ?? 'Organization',
		);

		$promoter_result = Promoter_Taxonomy::find_or_create_promoter( $promoter_name, $promoter_metadata );

		if ( $promoter_result['term_id'] ) {
			Promoter::assign_promoter_to_event(
				$post_id,
				array(
					'promoter' => $promoter_result['term_id'],
				)
			);
		}
	}

	/**
	 * Generate Event Details block content
	 *
	 * @param array $event_data Event data
	 * @param array $parameters Full parameters (includes engine data)
	 * @return string Block content
	 */
	private function generate_event_block_content( array $event_data, array $parameters = array() ): string {
		$block_attributes = array(
			'startDate'         => $event_data['startDate'] ?? '',
			'startTime'         => $event_data['startTime'] ?? '',
			'endDate'           => $event_data['endDate'] ?? '',
			'endTime'           => $event_data['endTime'] ?? '',
			'occurrenceDates'   => $event_data['occurrenceDates'] ?? array(),
			'venue'             => $event_data['venue'] ?? $parameters['venue'] ?? '',
			'address'           => $event_data['venueAddress'] ?? $parameters['venueAddress'] ?? '',
			'price'             => $event_data['price'] ?? '',
			'ticketUrl'         => $event_data['ticketUrl'] ?? '',

			'performer'         => $event_data['performer'] ?? '',
			'performerType'     => $event_data['performerType'] ?? 'PerformingGroup',
			'organizer'         => $event_data['organizer'] ?? '',
			'organizerType'     => $event_data['organizerType'] ?? 'Organization',
			'organizerUrl'      => $event_data['organizerUrl'] ?? '',
			'eventStatus'       => $event_data['eventStatus'] ?? 'EventScheduled',
			'previousStartDate' => $event_data['previousStartDate'] ?? '',
			'priceCurrency'     => $event_data['priceCurrency'] ?? 'USD',
			'offerAvailability' => $event_data['offerAvailability'] ?? 'InStock',

			'showVenue'         => true,
			'showPrice'         => true,
			'showTicketLink'    => true,
		);

		$block_attributes = array_filter(
			$block_attributes,
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);

		$block_attributes['showVenue']      = true;
		$block_attributes['showPrice']      = true;
		$block_attributes['showTicketLink'] = true;

		$block_json  = wp_json_encode( $block_attributes, JSON_UNESCAPED_UNICODE );
		$description = ! empty( $event_data['description'] ) ? wp_kses_post( $event_data['description'] ) : '';

		$inner_blocks = $this->generate_description_blocks( $description );

		return '<!-- wp:data-machine-events/event-details ' . $block_json . ' -->' . "\n" .
				'<div class="wp-block-data-machine-events-event-details">' .
				( $inner_blocks ? "\n" . $inner_blocks . "\n" : '' ) .
				'</div>' . "\n" .
				'<!-- /wp:data-machine-events/event-details -->';
	}

	/**
	 * Normalize arbitrary engine context input into an EngineData instance.
	 *
	 * @param mixed $engine_context Engine context (EngineData|array|null)
	 * @param array $parameters Parameters array
	 * @return EngineData EngineData instance
	 */
	private function resolveEngineContext( $engine_context = null, array $parameters = array() ): EngineData {
		if ( $engine_context instanceof EngineData ) {
			return $engine_context;
		}

		$job_id = (int) ( $parameters['job_id'] ?? null );

		if ( null === $engine_context ) {
			$engine_context = $parameters['engine'] ?? ( $parameters['engine_data'] ?? array() );
		}

		if ( $engine_context instanceof EngineData ) {
			return $engine_context;
		}

		if ( ! is_array( $engine_context ) ) {
			$engine_context = is_string( $engine_context ) ? array( 'image_url' => $engine_context ) : array();
		}

		return new EngineData( $engine_context, $job_id );
	}
}
