<?php
/**
 * Structured data processor.
 *
 * Handles common processing for structured event data from any extractor:
 * venue config override, engine data storage, and DataPacket creation.
 *
 * @package DataMachineEvents\Steps\EventImport\Handlers\WebScraper
 */

namespace DataMachineEvents\Steps\EventImport\Handlers\WebScraper;

use DataMachine\Core\ExecutionContext;
use DataMachineEvents\Core\EventTimeLocalizer;
use DataMachineEvents\Steps\EventImport\EventEngineData;
use DataMachineEvents\Steps\EventImport\Handlers\EventImportHandler;
use DataMachineEvents\Utilities\EventSourceIdentity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StructuredDataProcessor {

	private EventImportHandler $handler;

	public function __construct( EventImportHandler $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Process structured events and return all eligible items as raw arrays.
	 *
	 * @param array            $events            Array of normalized event data from extractor
	 * @param string           $extraction_method Extraction method identifier
	 * @param string           $source_url        Source URL
	 * @param array            $config            Handler configuration
	 * @param ExecutionContext $context           Execution context
	 * @return array|null Array with 'items' key, or null if no eligible events
	 */
	public function process(
		array $events,
		string $extraction_method,
		string $source_url,
		array $config,
		ExecutionContext $context
	): ?array {
		$eligible_items = array();
		$event_keys     = self::batchSourceKeys( $events );

		foreach ( $events as $index => $raw_event ) {
			$event = $raw_event;

			if ( empty( $event['title'] ) ) {
				continue;
			}

			if ( $this->handler->shouldSkipEventTitle( $event['title'] ) ) {
				continue;
			}

			if ( ! empty( $event['startDate'] ) && $this->handler->isPastEvent( $event['startDate'] ) ) {
				continue;
			}

			$search_text = ( $event['title'] ?? '' ) . ' ' . ( $event['description'] ?? '' );
			if ( ! $this->handler->applyKeywordSearch( $search_text, $config['search'] ?? '' ) ) {
				continue;
			}
			if ( $this->handler->applyExcludeKeywords( $search_text, $config['exclude_keywords'] ?? '' ) ) {
				continue;
			}

			$this->applyVenueConfigOverride( $event, $config );
			$event = $this->handler->applyVenueSourceAliases( $event );
			$event = EventTimeLocalizer::localize( $event, EventTimeLocalizer::venueTimezone( $event, $config ) );

			$source_identity  = EventSourceIdentity::resolve( $event, $context );
			$event_identifier = $source_identity['event_identifier'];

			$venue_from_config = ! empty( $config['venue'] ) || ! empty( $config['venue_name'] );
			if ( ! $venue_from_config && empty( trim( (string) ( $event['venue'] ?? '' ) ) ) ) {
				$context->log(
					'warning',
					'Universal Web Scraper: Missing venue; configure venue override',
					array(
						'source_url'        => $source_url,
						'extraction_method' => $extraction_method,
						'title'             => $event['title'] ?? '',
						'startDate'         => $event['startDate'] ?? '',
					)
				);
			}

			$venue_metadata = $this->handler->extractVenueMetadata( $event );
			$engine_data    = EventEngineData::buildEngineData( $event, $venue_metadata );

			// A per-event source key lets a corrected start time update the
			// original event (#909).
			if ( '' !== ( $event_keys[ $index ] ?? '' ) ) {
				$engine_data['source_event_key'] = $context->getFlowId() . '|' . $event_keys[ $index ];
			}

			// Add scraper-specific fields to engine data.
			$scraper_fields = array_filter(
				array(
					'title'     => $event['title'] ?? '',
					'image_url' => $event['imageUrl'] ?? '',
				),
				static function ( $value ) {
					return '' !== $value;
				}
			);
			if ( ! empty( $scraper_fields ) ) {
				$engine_data = array_merge( $engine_data, $scraper_fields );
			}

			$this->handler->stripVenueMetadataFromEvent( $event );

			$eligible_items[] = array(
				'title'    => $event['title'],
				'content'  => wp_json_encode(
					array(
						'event'             => $event,
						'raw_source'        => $raw_event,
						'venue_metadata'    => $venue_metadata,
						'import_source'     => 'universal_web_scraper',
						'extraction_method' => $extraction_method,
					),
					JSON_PRETTY_PRINT
				),
				'metadata' => array(
					'source_type'       => 'universal_web_scraper',
					'extraction_method' => $extraction_method,
					'pipeline_id'       => $context->getPipelineId(),
					'flow_id'           => $context->getFlowId(),
					'original_title'    => $event['title'],
					'event_identifier'  => $event_identifier,
					'item_identifier'   => $source_identity['item_identifier'],
					'import_timestamp'  => time(),
					'_engine_data'      => $engine_data,
				),
			);
		}

		if ( empty( $eligible_items ) ) {
			return null;
		}

		return array( 'items' => $eligible_items );
	}

	/**
	 * Apply static venue config override from handler settings.
	 *
	 * @param array &$event Event data (modified in place)
	 * @param array $config Handler configuration
	 */
	private function applyVenueConfigOverride( array &$event, array $config ): void {
		if ( ! empty( $config['venue'] ) && is_numeric( $config['venue'] ) ) {
			$term = get_term( (int) $config['venue'], 'venue' );
			if ( $term && ! is_wp_error( $term ) ) {
				$event['venue'] = $term->name;
				$venue_meta     = \DataMachineEvents\Core\Venue_Taxonomy::get_venue_data( (int) $config['venue'] );
				$this->applyVenueMeta( $event, $venue_meta );
			}
		} elseif ( ! empty( $config['venue_name'] ) ) {
			$event['venue'] = sanitize_text_field( $config['venue_name'] );
			$this->applyVenueConfigFields( $event, $config );
		}
	}

	/**
	 * Usable per-event source key for each event in a fetched batch.
	 *
	 * The key is an extractor-declared `sourceEventKey`, else the ticket URL.
	 * A key shared by more than one event in the batch (a generic ticket
	 * link) identifies none of them and is returned as ''.
	 *
	 * @param array $events Extracted events, keyed as passed to process().
	 * @return array<int|string, string> Key per event index.
	 */
	public static function batchSourceKeys( array $events ): array {
		$keys = array();
		foreach ( $events as $index => $event ) {
			$keys[ $index ] = trim( (string) ( $event['sourceEventKey'] ?? $event['ticketUrl'] ?? '' ) );
		}

		$counts = array_count_values( array_filter( $keys ) );
		foreach ( $keys as $index => $key ) {
			if ( '' !== $key && $counts[ $key ] > 1 ) {
				$keys[ $index ] = '';
			}
		}

		return $keys;
	}

	/**
	 * Apply venue metadata from taxonomy term.
	 */
	private function applyVenueMeta( array &$event, array $venue_meta ): void {
		$field_map = array(
			'address'       => 'venueAddress',
			'city'          => 'venueCity',
			'state'         => 'venueState',
			'zip'           => 'venueZip',
			'country'       => 'venueCountry',
			'phone'         => 'venuePhone',
			'website'       => 'venueWebsite',
			'ticketing_url' => 'venueTicketingUrl',
			'coordinates'   => 'venueCoordinates',
		);

		foreach ( $field_map as $meta_key => $event_key ) {
			if ( ! empty( $venue_meta[ $meta_key ] ) ) {
				$event[ $event_key ] = $venue_meta[ $meta_key ];
			}
		}
	}

	/**
	 * Apply venue fields from handler config.
	 */
	private function applyVenueConfigFields( array &$event, array $config ): void {
		$field_map = array(
			'venue_address'       => 'venueAddress',
			'venue_city'          => 'venueCity',
			'venue_state'         => 'venueState',
			'venue_zip'           => 'venueZip',
			'venue_country'       => 'venueCountry',
			'venue_phone'         => 'venuePhone',
			'venue_website'       => 'venueWebsite',
			'venue_ticketing_url' => 'venueTicketingUrl',
		);

		foreach ( $field_map as $config_key => $event_key ) {
			if ( ! empty( $config[ $config_key ] ) ) {
				$value               = $config[ $config_key ];
				$event[ $event_key ] = in_array( $config_key, array( 'venue_website', 'venue_ticketing_url' ), true )
					? esc_url_raw( $value )
					: sanitize_text_field( $value );
			}
		}
	}
}
