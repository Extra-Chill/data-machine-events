<?php
/**
 * Events By Term Abilities
 *
 * Cross-site-callable primitive that returns the LIST of events tagged to a
 * term in any taxonomy on the events site. The whole point is the CONSUMER
 * (e.g. the artist profile hub on a different blog) can call this ability even
 * though this plugin's PHP is NOT loaded there — switch_to_blog() changes the
 * DB context, not the loaded code, so a consumer on another blog cannot call
 * data_machine_events_query_events() directly. The ability is the bridge.
 *
 * It internally switches to the configured events blog (the current site by
 * default), reads the datamachine_event_dates
 * table + venue/date data directly, and returns a PLAIN STRUCTURED ARRAY with
 * every presentational string (title, permalink, venue name, formatted date /
 * time) pre-resolved while still in events-blog context — because the caller
 * renders on a DIFFERENT blog and cannot resolve those afterward.
 *
 * The general path remains "events for term X in taxonomy Y on this events
 * site" and returns data rather than consumer markup. Artist terms additionally
 * support the bounded main-site mapping owned by this plugin; no generic
 * cross-site identity framework is introduced.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\EventDatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventsByTermAbilities {

	private static bool $registered = false;

	/**
	 * Default per-scope result limit when the caller omits `limit`.
	 */
	private const DEFAULT_LIMIT = 12;

	/**
	 * Events-site artist term meta containing the canonical main-site term ID.
	 */
	private const MAIN_ARTIST_TERM_ID_META = '_data_machine_events_main_artist_term_id';

	/**
	 * One-time backfill gate and report option.
	 */
	private const ARTIST_MAPPING_BACKFILL_OPTION = 'data_machine_events_artist_term_mapping_backfill_1';

	public function __construct() {
		if ( ! self::$registered ) {
			$this->registerAbilities();
			add_action( 'admin_init', array( $this, 'maybeBackfillArtistTermMappings' ) );
			self::$registered = true;
		}
	}

	private function registerAbilities(): void {
		$register_callback = function () {
			wp_register_ability(
				'data-machine-events/events-by-term',
				array(
					'label'               => __( 'Events By Term', 'data-machine-events' ),
					'description'         => __( 'Return the list of events for a term in a taxonomy on the events site, split into upcoming and past. Cross-site callable: resolves everything (permalinks, venue names, formatted dates) on the events blog so consumers on any other site can render the result directly.', 'data-machine-events' ),
					'category'            => 'datamachine-events-events',
					'input_schema'        => array(
						'type'       => 'object',
						'required'   => array( 'taxonomy' ),
						'properties' => array(
							'taxonomy'  => array(
								'type'        => 'string',
								'description' => __( 'Taxonomy name on the events site (e.g. "artist"). Must be registered there.', 'data-machine-events' ),
							),
							'term_slug' => array(
								'type'        => 'string',
								'description' => __( 'Legacy term slug to look up on the events site. Retained for compatibility while stable mappings are populated.', 'data-machine-events' ),
							),
							'term_id'      => array(
								'type'        => 'integer',
								'description' => __( 'Stable term ID on the events site. Takes precedence over term_slug.', 'data-machine-events' ),
							),
							'main_term_id' => array(
								'type'        => 'integer',
								'description' => __( 'Canonical main-site artist term ID. Resolved through the validated Events-site artist mapping and takes precedence over term_slug.', 'data-machine-events' ),
							),
							'scope'     => array(
								'type'        => 'string',
								'enum'        => array( 'upcoming', 'past', 'all' ),
								'description' => __( 'Which events to return. Default all.', 'data-machine-events' ),
							),
							'limit'     => array(
								'type'        => 'integer',
								'description' => __( 'Maximum events to return per scope (upcoming and past are limited independently). Default 12.', 'data-machine-events' ),
							),
						),
					),
					'output_schema'       => array(
						'type'       => 'object',
						'properties' => array(
							'taxonomy'     => array( 'type' => 'string' ),
							'term_id'      => array( 'type' => 'integer' ),
							'term_slug'    => array( 'type' => 'string' ),
							'main_term_id' => array( 'type' => 'integer' ),
							'found'     => array( 'type' => 'boolean' ),
							'upcoming'  => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
							'past'      => array(
								'type'  => 'array',
								'items' => array( 'type' => 'object' ),
							),
						),
					),
					'execute_callback'    => array( $this, 'executeEventsByTerm' ),
					'permission_callback' => '__return_true',
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'   => true,
							'idempotent' => true,
						),
					),
				)
			);
		};

		add_action( 'wp_abilities_api_init', $register_callback );
	}

	/**
	 * Execute the events-by-term ability.
	 *
	 * Switches to the events blog, resolves the term by stable local ID, bounded
	 * canonical artist mapping, or legacy slug, queries the event_dates table for
	 * that term split by now, and returns pre-resolved presentational strings.
	 *
	 * @param array $input Input parameters.
	 * @return array|\WP_Error { taxonomy, term_slug, found, upcoming: [...], past: [...] }
	 */
	public function executeEventsByTerm( array $input ): array|\WP_Error {
		$taxonomy     = isset( $input['taxonomy'] ) ? sanitize_key( (string) $input['taxonomy'] ) : '';
		$term_slug    = isset( $input['term_slug'] ) ? sanitize_title( (string) $input['term_slug'] ) : '';
		$term_id      = isset( $input['term_id'] ) ? (int) $input['term_id'] : 0;
		$main_term_id = isset( $input['main_term_id'] ) ? (int) $input['main_term_id'] : 0;
		$scope        = $input['scope'] ?? 'all';
		if ( ! in_array( $scope, array( 'upcoming', 'past', 'all' ), true ) ) {
			$scope = 'all';
		}
		$limit = isset( $input['limit'] ) ? absint( $input['limit'] ) : self::DEFAULT_LIMIT;
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		if ( '' === $taxonomy ) {
			return new \WP_Error(
				'invalid_taxonomy',
				__( 'A non-empty taxonomy is required.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $input['term_id'] ) && $term_id <= 0 ) {
			return new \WP_Error(
				'invalid_term_id',
				__( 'term_id must be a positive Events-site term ID.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $input['main_term_id'] ) && $main_term_id <= 0 ) {
			return new \WP_Error(
				'invalid_main_term_id',
				__( 'main_term_id must be a positive canonical main-site artist term ID.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		if ( $term_id <= 0 && $main_term_id <= 0 && '' === $term_slug ) {
			return new \WP_Error(
				'missing_term_identifier',
				__( 'A positive term_id, positive main_term_id, or non-empty term_slug is required.', 'data-machine-events' ),
				array( 'status' => 400 )
			);
		}

		$events_blog_id = $this->resolveEventsBlogId();
		if ( ! $events_blog_id ) {
			return new \WP_Error(
				'events_site_unresolved',
				__( 'Could not resolve the events site blog ID.', 'data-machine-events' ),
				array( 'status' => 500 )
			);
		}

		switch_to_blog( $events_blog_id );
		try {
			$term = $this->resolveTerm( $taxonomy, $term_id, $main_term_id, $term_slug );
			if ( is_wp_error( $term ) ) {
				return $term;
			}

			$result = $this->collectEventsForTerm( $taxonomy, $term, $main_term_id, $scope, $limit );

			/**
			 * Filter events-by-term results in the canonical events-site context.
			 *
			 * Consumers can compose domain-owned relationship data while term and
			 * permalink APIs still resolve against the events site. This primitive
			 * remains taxonomy-agnostic: it neither assumes nor declares consumers'
			 * response fields.
			 *
			 * @param array $result Hydrated events-by-term response.
			 * @param array $input  Normalized ability input.
			 */
			return apply_filters( 'data_machine_events_events_by_term_result', $result, $input );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Resolve an Events-site term without treating cross-site numeric IDs as portable.
	 *
	 * @param string $taxonomy     Events-site taxonomy.
	 * @param int    $term_id      Events-site term ID.
	 * @param int    $main_term_id Canonical main-site artist term ID.
	 * @param string $term_slug    Legacy Events-site term slug.
	 * @return \WP_Term|null|\WP_Error Resolved term, null for a missing safe mapping, or validation error.
	 */
	private function resolveTerm( string $taxonomy, int $term_id, int $main_term_id, string $term_slug ): \WP_Term|null|\WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'invalid_taxonomy', __( 'The requested taxonomy is not registered on the events site.', 'data-machine-events' ), array( 'status' => 400 ) );
		}

		if ( $term_id > 0 ) {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term || is_wp_error( $term ) || $taxonomy !== $term->taxonomy ) {
				return new \WP_Error( 'invalid_term_id', __( 'The requested term_id does not exist in that Events-site taxonomy.', 'data-machine-events' ), array( 'status' => 404 ) );
			}

			return $term;
		}

		if ( $main_term_id > 0 ) {
			if ( 'artist' !== $taxonomy ) {
				return new \WP_Error( 'invalid_main_term_taxonomy', __( 'main_term_id is supported only for the artist taxonomy.', 'data-machine-events' ), array( 'status' => 400 ) );
			}

			if ( ! $this->canonicalArtistTermExists( $main_term_id ) ) {
				return new \WP_Error( 'invalid_main_term_id', __( 'The canonical main-site artist term does not exist.', 'data-machine-events' ), array( 'status' => 404 ) );
			}

			$matches = get_terms(
				array(
					'taxonomy'   => 'artist',
					'hide_empty' => false,
					'meta_key'   => self::MAIN_ARTIST_TERM_ID_META,
					'meta_value' => $main_term_id,
					'number'     => 2,
				)
			);
			if ( is_wp_error( $matches ) ) {
				return $matches;
			}
			if ( count( $matches ) > 1 ) {
				return new \WP_Error( 'ambiguous_artist_term_mapping', __( 'More than one Events-site artist term claims this canonical main-site term.', 'data-machine-events' ), array( 'status' => 409 ) );
			}

			return $matches[0] ?? null;
		}

		$term = get_term_by( 'slug', $term_slug, $taxonomy );

		return $term && ! is_wp_error( $term ) ? $term : null;
	}

	/**
	 * Validate a canonical artist term while always restoring Events-site context.
	 *
	 * @param int $term_id Canonical main-site term ID.
	 * @return bool Whether the term exists in the main-site artist taxonomy.
	 */
	private function canonicalArtistTermExists( int $term_id ): bool {
		$main_blog_id = $this->resolveMainBlogId();
		if ( $main_blog_id <= 0 ) {
			return false;
		}

		switch_to_blog( $main_blog_id );
		try {
			$term = get_term( $term_id, 'artist' );

			return $term && ! is_wp_error( $term ) && 'artist' === $term->taxonomy;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Resolve the canonical main-site blog ID.
	 *
	 * @return int Main-site blog ID, or 0 when unavailable.
	 */
	private function resolveMainBlogId(): int {
		$blog_id = is_multisite() ? (int) get_main_site_id() : get_current_blog_id();

		return max( 0, (int) apply_filters( 'data_machine_events_main_blog_id', $blog_id ) );
	}

	/**
	 * Run the bounded artist mapping backfill once and retain its audit report.
	 *
	 * Existing mappings are never replaced. Slugs are used only to seed a pair
	 * when exactly one main-site term matches and neither side is already claimed.
	 *
	 * @return void
	 */
	public function maybeBackfillArtistTermMappings(): void {
		if ( false !== get_option( self::ARTIST_MAPPING_BACKFILL_OPTION, false ) ) {
			return;
		}

		update_option( self::ARTIST_MAPPING_BACKFILL_OPTION, $this->backfillArtistTermMappings(), false );
	}

	/**
	 * Backfill unambiguous main-site artist term mappings onto Events terms.
	 *
	 * @return array{mapped:int,existing:int,missing:int[],unmatched_main:int[],ambiguous:int[],stale:int[],collisions:int[]}
	 */
	public function backfillArtistTermMappings(): array {
		$report = array(
			'mapped'         => 0,
			'existing'       => 0,
			'missing'        => array(),
			'unmatched_main' => array(),
			'ambiguous'      => array(),
			'stale'          => array(),
			'collisions'     => array(),
		);
		if ( ! taxonomy_exists( 'artist' ) ) {
			return $report;
		}

		$main_blog_id = $this->resolveMainBlogId();
		if ( $main_blog_id <= 0 || get_current_blog_id() === $main_blog_id ) {
			return $report;
		}

		$main_terms = array();
		switch_to_blog( $main_blog_id );
		try {
			$terms = get_terms(
				array(
					'taxonomy'   => 'artist',
					'hide_empty' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$main_terms[ (string) $term->slug ][] = (int) $term->term_id;
				}
			}
		} finally {
			restore_current_blog();
		}

		$events_terms = get_terms(
			array(
				'taxonomy'   => 'artist',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $events_terms ) ) {
			return $report;
		}

		$claims = array();
		foreach ( $events_terms as $term ) {
			$mapped_id = (int) get_term_meta( $term->term_id, self::MAIN_ARTIST_TERM_ID_META, true );
			if ( $mapped_id > 0 ) {
				$claims[ $mapped_id ][] = (int) $term->term_id;
			}
		}

		$valid_main_ids = array();
		foreach ( $main_terms as $ids ) {
			foreach ( $ids as $id ) {
				$valid_main_ids[ $id ] = true;
			}
		}

		foreach ( $events_terms as $term ) {
			$events_term_id = (int) $term->term_id;
			$mapped_id      = (int) get_term_meta( $events_term_id, self::MAIN_ARTIST_TERM_ID_META, true );
			if ( $mapped_id > 0 ) {
				if ( ! isset( $valid_main_ids[ $mapped_id ] ) ) {
					$report['stale'][] = $events_term_id;
				} elseif ( count( $claims[ $mapped_id ] ) > 1 ) {
					$report['collisions'][] = $events_term_id;
				} else {
					++$report['existing'];
				}
				continue;
			}

			$candidates = $main_terms[ (string) $term->slug ] ?? array();
			if ( empty( $candidates ) ) {
				$report['missing'][] = $events_term_id;
				continue;
			}
			if ( 1 !== count( $candidates ) ) {
				$report['ambiguous'][] = $events_term_id;
				continue;
			}

			$canonical_id = $candidates[0];
			if ( ! empty( $claims[ $canonical_id ] ) ) {
				$report['collisions'][] = $events_term_id;
				continue;
			}

			update_term_meta( $events_term_id, self::MAIN_ARTIST_TERM_ID_META, $canonical_id );
			$claims[ $canonical_id ] = array( $events_term_id );
			++$report['mapped'];
		}

		foreach ( array_keys( $valid_main_ids ) as $main_term_id ) {
			if ( empty( $claims[ $main_term_id ] ) ) {
				$report['unmatched_main'][] = $main_term_id;
			}
		}

		return $report;
	}

	/**
	 * Resolve the events site blog ID.
	 *
	 * Defaults to the current site so standalone installs work without any
	 * configuration. Consumers that keep events on another site can override
	 * the target through the filter below.
	 *
	 * @return int Blog ID, or 0 when unresolved.
	 */
	private function resolveEventsBlogId(): int {
		$blog_id = get_current_blog_id();

		/**
		 * Filter the resolved events-site blog ID for the events-by-term ability.
		 *
		 * Lets consumers point the ability at the blog that holds event posts.
		 * Return a positive integer blog ID.
		 *
		 * @param int $blog_id Current-site blog ID by default.
		 */
		$blog_id = (int) apply_filters( 'data_machine_events_events_blog_id', $blog_id );

		return $blog_id > 0 ? $blog_id : 0;
	}

	/**
	 * Collect upcoming and past events for a term in a taxonomy.
	 *
	 * Must be called in events-blog context (after switch_to_blog). Reads the
	 * event_dates table joined to term_relationships so past/upcoming split
	 * comes straight from start_datetime vs now, then pre-resolves every
	 * presentational string per event.
	 *
	 * @param string        $taxonomy     Taxonomy name on the events site.
	 * @param \WP_Term|null $term         Resolved Events-site term.
	 * @param int           $main_term_id Requested canonical main-site term ID.
	 * @param string        $scope        upcoming|past|all.
	 * @param int           $limit        Per-scope result limit.
	 * @return array Structured result.
	 */
	private function collectEventsForTerm( string $taxonomy, ?\WP_Term $term, int $main_term_id, string $scope, int $limit ): array {
		$empty = array(
			'taxonomy'     => $taxonomy,
			'term_id'      => 0,
			'term_slug'    => '',
			'main_term_id' => $main_term_id,
			'found'        => false,
			'upcoming'     => array(),
			'past'         => array(),
		);

		if ( ! $term ) {
			return $empty;
		}

		$result = array(
			'taxonomy'     => $taxonomy,
			'term_id'      => (int) $term->term_id,
			'term_slug'    => (string) $term->slug,
			'main_term_id' => $main_term_id,
			'found'        => true,
			'upcoming'     => array(),
			'past'         => array(),
		);

		if ( 'past' !== $scope ) {
			$result['upcoming'] = $this->queryScope( (int) $term->term_id, $taxonomy, 'upcoming', $limit );
		}
		if ( 'upcoming' !== $scope ) {
			$result['past'] = $this->queryScope( (int) $term->term_id, $taxonomy, 'past', $limit );
		}

		return $result;
	}

	/**
	 * Query one scope (upcoming or past) of events for a term.
	 *
	 * Reads post IDs directly from the event_dates table joined to
	 * term_relationships / term_taxonomy, filtered by start_datetime vs now
	 * and post_status = 'publish'. Upcoming is ordered soonest-first; past is
	 * ordered most-recent-first. Each event is then hydrated into a plain
	 * presentational array.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $scope    upcoming|past.
	 * @param int    $limit    Max events to return.
	 * @return array List of event arrays.
	 */
	private function queryScope( int $term_id, string $taxonomy, string $scope, int $limit ): array {
		global $wpdb;

		$ed_table = EventDatesTable::table_name();
		$now      = current_time( 'mysql' );

		// Two fully-literal query strings keep the date comparator and sort
		// direction out of variable interpolation so $wpdb->prepare() sees a
		// stable placeholder count. The only interpolated token is the table
		// name (a trusted internal constant); the taxonomy is passed as a %s
		// placeholder, matching the sibling UpcomingCountAbilities pattern.
		$timing = ( 'upcoming' === $scope ) ? 'upcoming' : 'past';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ed_table is a trusted internal constant; the two literal query strings keep placeholder counts stable.
		if ( 'upcoming' === $scope ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ed.post_id AS post_id, ed.start_datetime AS start_datetime
					FROM {$ed_table} ed
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = %s
					AND tt.term_id = %d
					AND ed.post_status = 'publish'
					AND ed.start_datetime >= %s
					ORDER BY ed.start_datetime ASC
					LIMIT %d",
					$taxonomy,
					$term_id,
					$now,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ed.post_id AS post_id, ed.start_datetime AS start_datetime
					FROM {$ed_table} ed
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = ed.post_id
					INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
					WHERE tt.taxonomy = %s
					AND tt.term_id = %d
					AND ed.post_status = 'publish'
					AND ed.start_datetime < %s
					ORDER BY ed.start_datetime DESC
					LIMIT %d",
					$taxonomy,
					$term_id,
					$now,
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return array();
		}

		$events = array();
		foreach ( $rows as $row ) {
			$event = $this->hydrateEvent( (int) $row->post_id, (string) $row->start_datetime, $timing );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * Hydrate a single event post into a plain presentational array.
	 *
	 * Resolves title, permalink, venue name, and formatted date / time strings
	 * while in events-blog context so the consumer (on another blog) can render
	 * them directly without re-resolving anything.
	 *
	 * @param int    $post_id        Event post ID.
	 * @param string $start_datetime MySQL start datetime from the event_dates table.
	 * @param string $timing         'upcoming' | 'past'.
	 * @return array|null Event array, or null when the post is unavailable.
	 */
	private function hydrateEvent( int $post_id, string $start_datetime, string $timing ): ?array {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink || is_wp_error( $permalink ) ) {
			return null;
		}

		$venue_name  = '';
		$venue_terms = get_the_terms( $post_id, 'venue' );
		if ( $venue_terms && ! is_wp_error( $venue_terms ) ) {
			$venue_name = html_entity_decode( (string) $venue_terms[0]->name, ENT_QUOTES, 'UTF-8' );
		}

		$timestamp    = strtotime( $start_datetime );
		$date_iso     = $timestamp ? gmdate( 'c', $timestamp ) : '';
		$date_display = $timestamp ? date_i18n( get_option( 'date_format' ), $timestamp ) : '';

		// A midnight start time is the sentinel for "no known time"; only emit
		// a time string when the event actually carries one.
		$time_display = '';
		if ( $timestamp && '00:00:00' !== gmdate( 'H:i:s', $timestamp ) ) {
			$time_display = date_i18n( get_option( 'time_format' ), $timestamp );
		}

		return array(
			'event_id'     => $post_id,
			'title'        => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
			'permalink'    => $permalink,
			'venue_name'   => $venue_name,
			'date_iso'     => $date_iso,
			'date_display' => $date_display,
			'time_display' => $time_display,
			'timing'       => $timing,
		);
	}
}
