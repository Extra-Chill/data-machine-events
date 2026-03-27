//! extractDateForQuery — extracted from EventUpsert.php.


	/**
	 * Acquire a MySQL advisory lock for an event identity.
	 *
	 * Uses GET_LOCK() to serialize concurrent upserts for the same event.
	 * The lock key is derived from the date and normalized title, so two
	 * flows importing the same event will queue instead of racing.
	 *
	 * MySQL advisory locks are:
	 * - Per-connection (each PHP-FPM worker has its own connection)
	 * - Automatically released on connection close (no leak risk)
	 * - Non-blocking for unrelated events (different lock keys)
	 *
	 * @since 0.17.3
	 * @param string $title     Event title.
	 * @param string $startDate Event start date.
	 * @return string The lock key (needed for release).
	 */
	private function acquireUpsertLock( string $title, string $startDate ): string {
		global $wpdb;

		$date_only       = self::extractDateForQuery( $startDate );
		$normalized      = SimilarityEngine::normalizeTitle( $title );
		$lock_key        = 'dme_' . md5( $date_only . '|' . $normalized );

		// MySQL lock names are limited to 64 characters; md5 = 36 + prefix = 40, safe.
		// Try up to 3 times with increasing timeouts (5s, 10s, 15s).
		// If all attempts fail, proceed without lock — better to risk a dupe
		// than deadlock the pipeline entirely.
		$timeouts = array( 5, 10, 15 );
		$acquired = false;

		foreach ( $timeouts as $attempt => $timeout ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, $timeout ) );

			if ( '1' === (string) $result ) {
				$acquired = true;
				break;
			}
		}

		if ( ! $acquired ) {
			do_action(
				'datamachine_log',
				'warning',
				'Event Upsert: Advisory lock failed after 3 attempts, proceeding without lock',
				array(
					'lock_key'        => $lock_key,
					'title'           => $title,
					'startDate'       => $startDate,
					'normalized'      => $normalized,
					'total_wait_secs' => array_sum( $timeouts ),
				)
			);
		}

		return $lock_key;
	}

	/**
	 * Find event by venue + date, then fuzzy title comparison
	 *
	 * Queries all events at a venue on a given date, then compares titles
	 * using core title extraction to catch variations like tour names or openers.
	 *
	 * @param string $title Event title to match
	 * @param string $venue Venue name
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @return int|null Post ID if fuzzy match found, null otherwise
	 */
	private function findEventByVenueDateAndFuzzyTitle( string $title, string $venue, string $startDate ): ?int {
		if ( EventIdentifierGenerator::isLowConfidenceTitle( $title ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Event Upsert: Skipping venue-scoped fuzzy match for low-confidence title',
				array(
					'title'     => $title,
					'venue'     => $venue,
					'startDate' => $startDate,
				)
			);
			return null;
		}

		// Find venue term — cascading lookup: exact name → slug → normalized name.
		$venue_term = get_term_by( 'name', $venue, 'venue' );
		if ( ! $venue_term ) {
			$venue_slug = sanitize_title( $venue );
			$venue_term = get_term_by( 'slug', $venue_slug, 'venue' );
		}
		if ( ! $venue_term ) {
			$venue_term = \DataMachineEvents\Core\Venue_Taxonomy::find_venue_by_normalized_name_public( $venue );
		}
		if ( ! $venue_term ) {
			do_action(
				'datamachine_log',
				'debug',
				'Event Upsert: Venue term not found for fuzzy title matching, will try venue-agnostic fallback',
				array(
					'venue_name' => $venue,
					'title'      => $title,
					'startDate'  => $startDate,
				)
			);
			return null;
		}

		// Query events at this venue on this date.
		// Use date-only matching; time comparison is done separately.
		$date_only = self::extractDateForQuery( $startDate );
		$ability   = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$result    = $ability->executeQueryEvents( array(
			'date_match'  => $date_only,
			'tax_filters' => array( 'venue' => array( $venue_term->term_id ) ),
			'per_page'    => 10,
			'status'      => 'any',
		) );
		$candidates = $result['posts'];

		if ( empty( $candidates ) ) {
			return null;
		}

		// Compare titles using core extraction and time window
		foreach ( $candidates as $candidate ) {
			if ( ! EventIdentifierGenerator::titlesMatch( $title, $candidate->post_title ) ) {
				continue;
			}

			// Check time window if both events have time data
			$candidate_dates   = \DataMachineEvents\Core\EventDatesTable::get( $candidate->ID );
			$existing_datetime = $candidate_dates ? $candidate_dates->start_datetime : '';
			if ( ! $this->isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				do_action(
					'datamachine_log',
					'debug',
					'Event Upsert: Title matched but outside time window (possible early/late show)',
					array(
						'incoming_title'    => $title,
						'matched_title'     => $candidate->post_title,
						'incoming_datetime' => $startDate,
						'existing_datetime' => $existing_datetime,
						'post_id'           => $candidate->ID,
					)
				);
				continue;
			}

			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Fuzzy matched incoming title to existing event',
				array(
					'incoming_title' => $title,
					'matched_title'  => $candidate->post_title,
					'post_id'        => $candidate->ID,
					'venue'          => $venue,
					'date'           => $startDate,
				)
			);
			return $candidate->ID;
		}

		return null;
	}

	/**
	 * Check if two datetimes are within a tolerance window
	 *
	 * Used to distinguish early/late shows (3+ hours apart) from the same event
	 * listed with different times across sources (typically within 1-2 hours).
	 *
	 * If either datetime lacks a time component, returns true (allows match).
	 *
	 * @param string $datetime1 First datetime (YYYY-MM-DD or YYYY-MM-DDTHH:MM)
	 * @param string $datetime2 Second datetime (YYYY-MM-DD or YYYY-MM-DDTHH:MM)
	 * @param int $windowHours Maximum hours apart to consider a match (default 2)
	 * @return bool True if within window or time data unavailable
	 */
	private function isWithinTimeWindow( string $datetime1, string $datetime2, int $windowHours = 2 ): bool {
		// If either is empty, allow match
		if ( empty( $datetime1 ) || empty( $datetime2 ) ) {
			return true;
		}

		// Normalize T separator to space for consistent parsing.
		$datetime1 = self::normalizeDatetime( $datetime1 );
		$datetime2 = self::normalizeDatetime( $datetime2 );

		// Check if both have time components (space followed by time)
		$has_time1 = preg_match( '/\s\d{2}:\d{2}/', $datetime1 );
		$has_time2 = preg_match( '/\s\d{2}:\d{2}/', $datetime2 );

		// If either lacks time, allow match (can't compare)
		if ( ! $has_time1 || ! $has_time2 ) {
			return true;
		}

		// Parse both datetimes
		$time1 = strtotime( $datetime1 );
		$time2 = strtotime( $datetime2 );

		if ( false === $time1 || false === $time2 ) {
			return true;
		}

		// Calculate absolute difference in hours
		$diff_hours = abs( $time1 - $time2 ) / 3600;

		return $diff_hours <= $windowHours;
	}

	/**
	 * Normalize a datetime string for consistent comparison.
	 *
	 * Replaces ISO 8601 'T' separator with space so that LIKE queries
	 * and string comparisons work against DB-stored values which use
	 * space separators (e.g. '2026-03-20 21:00:00').
	 *
	 * @param string $datetime Datetime string in any common format.
	 * @return string Normalized datetime with space separator.
	 */
	private static function normalizeDatetime( string $datetime ): string {
		// Replace T separator with space: 2026-03-20T21:00:00 → 2026-03-20 21:00:00
		return preg_replace( '/(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/', '$1 $2', $datetime );
	}

	/**
	 * Extract just the date portion (YYYY-MM-DD) from a datetime string.
	 *
	 * Used for LIKE queries against the event datetime meta field.
	 * The time comparison is done separately in isWithinTimeWindow().
	 *
	 * @param string $datetime Datetime or date string.
	 * @return string Date portion only (YYYY-MM-DD).
	 */
	private static function extractDateForQuery( string $datetime ): string {
		// Handle both "2026-03-20" and "2026-03-20 21:00:00" and "2026-03-20T21:00:00"
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})/', $datetime, $matches ) ) {
			return $matches[1];
		}
		return $datetime;
	}

	/**
	 * Find event by exact title match with venue confirmation
	 *
	 * Matches are returned when:
	 * - No incoming venue (can't verify, trust the title+date match)
	 * - Existing post has no venue assigned (can't verify, trust the match)
	 * - Venues match exactly OR fuzzy-match (normalized comparison)
	 *
	 * @param string $title Event title
	 * @param string $venue Venue name
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @return int|null Post ID if found, null otherwise
	 */
	private function findEventByExactTitle( string $title, string $venue, string $startDate ): ?int {
		$low_confidence_title = EventIdentifierGenerator::isLowConfidenceTitle( $title );

		if ( ! empty( $startDate ) ) {
			$exact_date_only = self::extractDateForQuery( $startDate );
			$ability         = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
			$result          = $ability->executeQueryEvents( array(
				'date_match' => $exact_date_only,
				'per_page'   => -1,
				'fields'     => 'ids',
				'status'     => 'any',
			) );
			// Filter to exact title match in PHP.
			$posts = array();
			foreach ( $result['posts'] as $candidate_id ) {
				if ( get_the_title( $candidate_id ) === $title ) {
					$posts[] = $candidate_id;
					break;
				}
			}
		} else {
			$args = array(
				'post_type'      => Event_Post_Type::POST_TYPE,
				'title'          => $title,
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'fields'         => 'ids',
			);
			$posts = get_posts( $args );
		}

		if ( ! empty( $posts ) ) {
			$post_id = $posts[0];

			// No incoming venue — trust the title+date match only for stronger titles.
			if ( empty( $venue ) ) {
				if ( $low_confidence_title ) {
					return null;
				}
				return $post_id;
			}

			$venue_terms = wp_get_post_terms( $post_id, 'venue', array( 'fields' => 'names' ) );

			// Existing post has no venue — trust the title+date match only for stronger titles.
			if ( empty( $venue_terms ) ) {
				if ( $low_confidence_title ) {
					return null;
				}
				return $post_id;
			}

			// Check venue: exact match first, then normalized comparison
			foreach ( $venue_terms as $existing_venue ) {
				if ( $venue === $existing_venue ) {
					return $post_id;
				}

				if ( EventIdentifierGenerator::venuesMatch( $venue, $existing_venue ) ) {
					do_action(
						'datamachine_log',
						'info',
						'Event Upsert: Fuzzy venue match in exact title search',
						array(
							'incoming_venue' => $venue,
							'existing_venue' => $existing_venue,
							'post_id'        => $post_id,
							'title'          => $title,
						)
					);
					return $post_id;
				}
			}
		}

		return null;
	}

	/**
	 * Find event by matching ticket URL on the same date
	 *
	 * Ticket URLs are stable identifiers from ticketing platforms.
	 * Same ticket URL + same date = definitively the same event.
	 *
	 * @param string $ticketUrl Ticket purchase URL
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @return int|null Post ID if found, null otherwise
	 */
	private function findEventByTicketUrl( string $ticketUrl, string $startDate ): ?int {
		if ( empty( $ticketUrl ) || empty( $startDate ) ) {
			return null;
		}

		$normalized_url = datamachine_normalize_ticket_url( $ticketUrl );
		if ( empty( $normalized_url ) ) {
			return null;
		}

		// Strategy A: exact match on stored normalized URL
		$ticket_date_only = self::extractDateForQuery( $startDate );
		$ability          = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$result_a         = $ability->executeQueryEvents( array(
			'date_match'  => $ticket_date_only,
			'per_page'    => 1,
			'fields'      => 'ids',
			'status'      => 'any',
			'meta_query'  => array(
				array(
					'key'     => EVENT_TICKET_URL_META_KEY,
					'value'   => $normalized_url,
					'compare' => '=',
				),
			),
		) );
		$posts = $result_a['posts'];

		if ( ! empty( $posts ) ) {
			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Found duplicate by ticket URL (exact)',
				array(
					'ticket_url'      => $ticketUrl,
					'normalized_url'  => $normalized_url,
					'matched_post_id' => $posts[0],
					'date'            => $startDate,
				)
			);
			return $posts[0];
		}

		// Strategy B: match on canonical ticket identity (unwraps affiliate URLs).
		// This catches cases where one source provides an affiliate link and another
		// provides the direct URL for the same event.
		$canonical_identity = datamachine_extract_ticket_identity( $ticketUrl );
		if ( empty( $canonical_identity ) || $canonical_identity === $normalized_url ) {
			return null; // No different identity to check
		}

		// Search all events on the same date and compare their canonical identities
		$result_b   = $ability->executeQueryEvents( array(
			'date_match'  => $ticket_date_only,
			'per_page'    => 50,
			'fields'      => 'ids',
			'status'      => 'any',
			'meta_query'  => array(
				array(
					'key'     => EVENT_TICKET_URL_META_KEY,
					'compare' => 'EXISTS',
				),
			),
		) );
		$candidates = $result_b['posts'];

		foreach ( $candidates as $candidate_id ) {
			$stored_url       = get_post_meta( $candidate_id, EVENT_TICKET_URL_META_KEY, true );
			$stored_canonical = datamachine_extract_ticket_identity( $stored_url );

			if ( $stored_canonical === $canonical_identity ) {
				do_action(
					'datamachine_log',
					'info',
					'Event Upsert: Found duplicate by ticket canonical identity',
					array(
						'ticket_url'         => $ticketUrl,
						'canonical_identity' => $canonical_identity,
						'stored_url'         => $stored_url,
						'matched_post_id'    => $candidate_id,
						'date'               => $startDate,
					)
				);
				return $candidate_id;
			}
		}

		return null;
	}

	/**
	 * Find event by date and fuzzy title without venue constraint
	 *
	 * Last-resort deduplication for cross-source imports where the same event
	 * appears with different venue names (e.g. "Come and Take It Live" vs
	 * "Come and Take It Productions") or where one source omits the venue entirely.
	 *
	 * Queries all events on a given date and compares titles using core extraction.
	 * More permissive than venue-scoped fuzzy matching — only fires as a final
	 * fallback after ticket URL, venue+fuzzy, and exact title strategies all fail.
	 *
	 * When both the incoming event and a candidate have venue data, venue matching
	 * is required to prevent false positives (e.g. "Open Mic Night" at two
	 * different bars on the same date).
	 *
	 * @param string $title Event title to match
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @param string $venue Incoming venue name for confirmation (may be empty)
	 * @return int|null Post ID if fuzzy match found, null otherwise
	 */
	private function findEventByDateAndFuzzyTitle( string $title, string $startDate, string $venue = '' ): ?int {
		if ( EventIdentifierGenerator::isLowConfidenceTitle( $title ) ) {
			return null;
		}

		$date_only = self::extractDateForQuery( $startDate );
		$ability   = new \DataMachineEvents\Abilities\EventDateQueryAbilities();
		$result    = $ability->executeQueryEvents( array(
			'date_match' => $date_only,
			'per_page'   => 20,
			'status'     => 'any',
		) );
		$candidates = $result['posts'];

		if ( empty( $candidates ) ) {
			return null;
		}

		foreach ( $candidates as $candidate ) {
			if ( ! EventIdentifierGenerator::titlesMatch( $title, $candidate->post_title ) ) {
				continue;
			}

			$candidate_dates   = \DataMachineEvents\Core\EventDatesTable::get( $candidate->ID );
			$existing_datetime = $candidate_dates ? $candidate_dates->start_datetime : '';
			if ( ! $this->isWithinTimeWindow( $startDate, $existing_datetime ) ) {
				continue;
			}

			// When both sides have venue data, require venue match to avoid
			// false positives on generic titles at different venues.
			if ( ! empty( $venue ) ) {
				$candidate_venues = wp_get_post_terms( $candidate->ID, 'venue', array( 'fields' => 'names' ) );
				$candidate_venue  = ( ! is_wp_error( $candidate_venues ) && ! empty( $candidate_venues ) ) ? $candidate_venues[0] : '';

				if ( ! empty( $candidate_venue ) && ! EventIdentifierGenerator::venuesMatch( $venue, $candidate_venue ) ) {
					do_action(
						'datamachine_log',
						'debug',
						'Event Upsert: Title matched but venues differ in venue-agnostic fallback',
						array(
							'incoming_title' => $title,
							'matched_title'  => $candidate->post_title,
							'incoming_venue' => $venue,
							'existing_venue' => $candidate_venue,
							'post_id'        => $candidate->ID,
						)
					);
					continue;
				}
			}

			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Cross-source fuzzy match (venue-agnostic) found duplicate',
				array(
					'incoming_title' => $title,
					'matched_title'  => $candidate->post_title,
					'post_id'        => $candidate->ID,
					'date'           => $startDate,
				)
			);
			return $candidate->ID;
		}

		return null;
	}
