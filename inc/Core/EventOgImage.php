<?php
/**
 * Event OG Image Orchestrator
 *
 * Generates per-event Open Graph images via the Data Machine
 * `datamachine/render-image-template` ability and supplies the resulting
 * URL to the `extrachill_seo_singular_og_image_url` filter.
 *
 * Lifecycle:
 *   1. extrachill-seo asks for a singular OG image when the event has no featured image.
 *   2. We check post meta for a cached attachment.
 *   3. If missing, we render a new card via the DM ability (output=attachment).
 *   4. We cache the attachment ID on the post and return its URL.
 *   5. On post save, the cache is busted so the next request regenerates.
 *
 * @package DataMachineEvents\Core
 * @since 0.30.0
 */

namespace DataMachineEvents\Core;

use DataMachineEvents\Templates\EventOgCardTemplate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventOgImage {

	/** Post meta key holding the generated OG attachment ID. */
	public const META_ATTACHMENT_ID = '_dme_og_attachment_id';

	/** Post meta key tracking the data signature used at last render. */
	public const META_SIGNATURE = '_dme_og_signature';

	/** Template ID — must match EventOgCardTemplate::get_id(). */
	public const TEMPLATE_ID = 'event_og_card';

	private static bool $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Register the GD template with Data Machine core.
		add_filter(
			'datamachine/image_generation/templates',
			array( self::class, 'registerTemplate' )
		);

		// Provide OG image URL to extrachill-seo for event posts.
		add_filter(
			'extrachill_seo_singular_og_image_url',
			array( self::class, 'filterSingularOgImageUrl' ),
			10,
			2
		);

		// Provide alt text for the generated card.
		add_filter(
			'extrachill_seo_singular_og_image_alt',
			array( self::class, 'filterSingularOgImageAlt' ),
			10,
			2
		);

		// Bust cache when an event is saved so stale cards get regenerated.
		add_action( 'save_post_' . Event_Post_Type::POST_TYPE, array( self::class, 'maybeInvalidate' ), 20, 3 );
	}

	/**
	 * Register the OG card template class with the DM template registry.
	 *
	 * @param array<string, string> $templates Existing template_id => class map.
	 * @return array<string, string>
	 */
	public static function registerTemplate( array $templates ): array {
		$templates[ self::TEMPLATE_ID ] = EventOgCardTemplate::class;
		return $templates;
	}

	/**
	 * Resolve the OG image URL for an event post.
	 *
	 * @param string   $existing_url Current value (empty string by default).
	 * @param \WP_Post $post         Queried post.
	 * @return string OG image URL, or the existing value if not applicable.
	 */
	public static function filterSingularOgImageUrl( string $existing_url, $post ): string {
		if ( ! self::isEventPost( $post ) ) {
			return $existing_url;
		}

		// If something else already provided one, respect it.
		if ( ! empty( $existing_url ) ) {
			return $existing_url;
		}

		$attachment_id = (int) get_post_meta( $post->ID, self::META_ATTACHMENT_ID, true );

		if ( $attachment_id && get_post( $attachment_id ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				return $url;
			}
		}

		// Generate fresh card.
		$generated = self::generateCard( $post );
		if ( $generated ) {
			return $generated;
		}

		return $existing_url;
	}

	/**
	 * Provide alt text for the generated OG image.
	 *
	 * @param string   $existing_alt Current alt text.
	 * @param \WP_Post $post         Queried post.
	 * @return string
	 */
	public static function filterSingularOgImageAlt( string $existing_alt, $post ): string {
		if ( ! self::isEventPost( $post ) ) {
			return $existing_alt;
		}
		if ( ! empty( $existing_alt ) ) {
			return $existing_alt;
		}

		$data = self::collectEventData( $post );

		$pieces = array_filter(
			array(
				$data['event_name'],
				$data['venue'],
				$data['city'],
				$data['date_label'],
			)
		);

		return implode( ' — ', $pieces );
	}

	/**
	 * Bust the cached card if the event's relevant data has changed.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public static function maybeInvalidate( $post_id, $post, $update ): void {
		// Skip autosaves and revisions.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$current_signature = self::buildSignature( self::collectEventData( $post ) );
		$stored_signature  = (string) get_post_meta( $post_id, self::META_SIGNATURE, true );

		if ( $current_signature === $stored_signature ) {
			return;
		}

		// Drop existing attachment if any.
		$attachment_id = (int) get_post_meta( $post_id, self::META_ATTACHMENT_ID, true );
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
			delete_post_meta( $post_id, self::META_ATTACHMENT_ID );
		}

		delete_post_meta( $post_id, self::META_SIGNATURE );
		// Don't regenerate eagerly here — generation happens lazily on the next OG request.
	}

	/**
	 * Render a new OG card and cache the resulting attachment.
	 *
	 * @param \WP_Post $post Event post.
	 * @return string Attachment URL on success, empty string on failure.
	 */
	private static function generateCard( $post ): string {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return '';
		}

		$ability = wp_get_ability( 'datamachine/render-image-template' );
		if ( ! $ability ) {
			return '';
		}

		$data = self::collectEventData( $post );

		// Title is required by the template; bail if we don't have it.
		if ( empty( $data['event_name'] ) || empty( $data['date_label'] ) ) {
			return '';
		}

		/**
		 * Filter the data passed to the event OG card template.
		 *
		 * Lets themes and other plugins augment the data payload before render —
		 * e.g. the Extra Chill theme hooks this to inject per-location brand
		 * colors (`_brand_override`) based on the core `location` taxonomy.
		 *
		 * The events plugin itself deliberately knows nothing about the
		 * `location` taxonomy — it's defined at the platform/theme level.
		 *
		 * @param array    $data Event data (event_name, date_label, venue, city).
		 * @param \WP_Post $post Event post.
		 */
		$data = (array) apply_filters( 'datamachine_events_og_card_data', $data, $post );

		$result = $ability->execute(
			array(
				'template_id' => self::TEMPLATE_ID,
				'data'        => $data,
				'preset'      => 'open_graph',
				'format'      => 'png',
				'output'      => 'attachment',
				'attachment'  => array(
					'parent_post_id' => $post->ID,
					'title'          => sprintf( 'OG Card — %s', $post->post_title ),
					'alt_text'       => self::filterSingularOgImageAlt( '', $post ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'EventOgImage: ability execution returned WP_Error',
				array(
					'post_id' => $post->ID,
					'error'   => $result->get_error_message(),
				)
			);
			return '';
		}

		if ( empty( $result['success'] ) || empty( $result['attachment_ids'] ) ) {
			do_action(
				'datamachine_log',
				'warning',
				'EventOgImage: ability returned no attachments',
				array(
					'post_id' => $post->ID,
					'message' => $result['message'] ?? '',
				)
			);
			return '';
		}

		$attachment_id  = (int) $result['attachment_ids'][0];
		$attachment_url = (string) ( $result['attachment_urls'][0] ?? wp_get_attachment_url( $attachment_id ) );

		update_post_meta( $post->ID, self::META_ATTACHMENT_ID, $attachment_id );
		update_post_meta( $post->ID, self::META_SIGNATURE, self::buildSignature( $data ) );

		return $attachment_url;
	}

	/**
	 * Pull title / date / venue / city data from the event post.
	 *
	 * @param \WP_Post $post Event post.
	 * @return array{event_name: string, date_label: string, venue: string, city: string}
	 */
	private static function collectEventData( $post ): array {
		$event_name = (string) $post->post_title;
		$date_label = self::resolveDateLabel( $post->ID );
		$venue_name = '';
		$city       = '';

		$venue_terms = get_the_terms( $post->ID, 'venue' );
		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$venue_term = $venue_terms[0];
			if ( class_exists( Venue_Taxonomy::class ) ) {
				$venue_data = Venue_Taxonomy::get_venue_data( $venue_term->term_id );
				$venue_name = (string) ( $venue_data['name'] ?? $venue_term->name );

				$city_parts = array_filter(
					array(
						$venue_data['city']  ?? '',
						$venue_data['state'] ?? '',
					)
				);
				$city       = implode( ', ', $city_parts );
			} else {
				$venue_name = (string) $venue_term->name;
			}
		}

		return array(
			'event_name' => $event_name,
			'date_label' => $date_label,
			'venue'      => $venue_name,
			'city'       => $city,
		);
	}

	/**
	 * Resolve a human-friendly date label for the card.
	 *
	 * @param int $post_id Event post ID.
	 * @return string Formatted date (e.g. "May 16, 2026") or empty string.
	 */
	private static function resolveDateLabel( int $post_id ): string {
		if ( ! class_exists( EventDatesTable::class ) ) {
			return '';
		}

		$dates = EventDatesTable::get( $post_id );
		if ( ! $dates || empty( $dates->start_datetime ) ) {
			return '';
		}

		$start = date_create( $dates->start_datetime );
		if ( ! $start ) {
			return '';
		}

		$end = ! empty( $dates->end_datetime ) ? date_create( $dates->end_datetime ) : null;

		// If end is on a different day, render a range "May 16–18, 2026".
		if ( $end && $start->format( 'Y-m-d' ) !== $end->format( 'Y-m-d' ) ) {
			if ( $start->format( 'Y-m' ) === $end->format( 'Y-m' ) ) {
				return sprintf(
					'%s %s–%s, %s',
					$start->format( 'M' ),
					$start->format( 'j' ),
					$end->format( 'j' ),
					$start->format( 'Y' )
				);
			}
			return sprintf(
				'%s – %s',
				$start->format( 'M j, Y' ),
				$end->format( 'M j, Y' )
			);
		}

		return $start->format( 'M j, Y' );
	}

	/**
	 * Hash the event data so we can detect when a regen is needed.
	 *
	 * @param array $data Event data array.
	 * @return string
	 */
	private static function buildSignature( array $data ): string {
		return md5( wp_json_encode( $data ) );
	}

	/**
	 * Whether a post is an event post.
	 *
	 * @param mixed $post Post or post-like object.
	 * @return bool
	 */
	private static function isEventPost( $post ): bool {
		return $post instanceof \WP_Post && Event_Post_Type::POST_TYPE === $post->post_type;
	}
}
