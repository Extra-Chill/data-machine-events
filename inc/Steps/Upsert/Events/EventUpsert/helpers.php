//! helpers — extracted from EventUpsert.php.


	/**
	 * Execute event upsert (create or update)
	 *
	 * @param array $parameters Event data from AI tool call
	 * @param array $handler_config Handler configuration
	 * @return array Tool call result with action: created|updated|no_change
	 */
	protected function executeUpdate( array $parameters, array $handler_config ): array {
		// Get engine data FIRST (before validation)
		$job_id = (int) ( $parameters['job_id'] ?? 0 );
		$engine = $parameters['engine'] ?? null;
		if ( ! $engine instanceof EngineData ) {
			$engine_snapshot = $job_id ? $this->getEngineData( $job_id ) : array();
			$engine          = new EngineData( $engine_snapshot, $job_id );
		}

		// Extract event identity fields (AI title takes precedence, engine data fallback for other fields)
		$title     = sanitize_text_field( $parameters['title'] ?? $engine->get( 'title' ) ?? '' );
		$venue     = $engine->get( 'venue' ) ?? $parameters['venue'] ?? '';
		$startDate = $engine->get( 'startDate' ) ?? $parameters['startDate'] ?? '';
		$ticketUrl = $engine->get( 'ticketUrl' ) ?? $parameters['ticketUrl'] ?? '';

		// Validate title after extraction from engine data or parameters
		if ( empty( $title ) ) {
			return $this->errorResponse(
				'title parameter is required for event upsert',
				array(
					'provided_parameters' => array_keys( $parameters ),
					'engine_data_keys'    => array_keys( $engine->all() ),
				)
			);
		}

		do_action(
			'datamachine_log',
			'debug',
			'Event Upsert: Processing event',
			array(
				'title'     => $title,
				'venue'     => $venue,
				'startDate' => $startDate,
				'ticketUrl' => $ticketUrl,
			)
		);

		$datetime_confidence = $this->getDateTimeConfidence( $parameters, $engine );

		if ( 'none' === $datetime_confidence ) {
			return $this->errorResponse(
				'valid startDate is required for event upsert',
				array(
					'title'               => $title,
					'venue'               => $venue,
					'startDate'           => $startDate,
					'datetime_confidence' => $datetime_confidence,
				)
			);
		}

		// Acquire advisory lock to prevent race conditions between concurrent
		// flows importing the same event. Lock key is derived from the date and
		// normalized title so that two flows processing "Eggy" at Charleston Pour
		// House on 2026-03-20 will serialize instead of both creating a new post.
		$lock_key = $this->acquireUpsertLock( $title, $startDate );

		try {
			return $this->executeUpsertWithinLock( $title, $venue, $startDate, $ticketUrl, $parameters, $handler_config, $engine );
		} finally {
			$this->releaseUpsertLock( $lock_key );
		}
	}

	/**
	 * Execute the find-or-create logic within an advisory lock.
	 *
	 * Separated from executeUpdate() so the lock boundary is clear:
	 * the lock is held from before findExistingEvent() through the
	 * completion of createEventPost() or updateEventPost().
	 *
	 * @param string     $title         Event title.
	 * @param string     $venue         Venue name.
	 * @param string     $startDate     Start date.
	 * @param string     $ticketUrl     Ticket URL.
	 * @param array      $parameters    Full tool parameters.
	 * @param array      $handler_config Handler configuration.
	 * @param EngineData $engine        Engine data.
	 * @return array Tool call result.
	 */
	private function executeUpsertWithinLock(
		string $title,
		string $venue,
		string $startDate,
		string $ticketUrl,
		array $parameters,
		array $handler_config,
		EngineData $engine
	): array {
		// Search for existing event via the core duplicate detection system.
		// This uses the PostIdentityIndex (indexed lookups) instead of
		// postmeta LIKE scans. Falls back to the old findExistingEvent()
		// method if the identity index table doesn't exist yet.
		$existing_post_id = $this->findExistingEventViaAbility( $title, $venue, $startDate, $ticketUrl );

		if ( $existing_post_id ) {
			// Event exists - check if data changed
			$existing_data = $this->extractEventData( $existing_post_id );

			if ( $this->hasDataChanged( $existing_data, $parameters ) ) {
				// UPDATE existing event
				$this->updateEventPost( $existing_post_id, $parameters, $handler_config, $engine );

				// Sync identity index after update.
				EventIdentityWriter::syncIdentityRow( $existing_post_id, $title, datamachine_normalize_ticket_url( $ticketUrl ) ?: null );

				do_action(
					'datamachine_log',
					'info',
					'Event Upsert: Updated existing event',
					array(
						'post_id' => $existing_post_id,
						'title'   => $title,
					)
				);

				return $this->successResponse(
					array(
						'post_id'  => $existing_post_id,
						'post_url' => get_permalink( $existing_post_id ),
						'action'   => 'updated',
					)
				);
			} else {
				// SKIP - no changes detected
				do_action(
					'datamachine_log',
					'debug',
					'Event Upsert: Skipped event (no changes)',
					array(
						'post_id' => $existing_post_id,
						'title'   => $title,
					)
				);

				return $this->successResponse(
					array(
						'post_id'  => $existing_post_id,
						'post_url' => get_permalink( $existing_post_id ),
						'action'   => 'no_change',
					)
				);
			}
		} else {
			// CREATE new event
			$post_id = $this->createEventPost( $parameters, $handler_config, $engine );

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return $this->errorResponse(
					'Event post creation failed',
					array(
						'title' => $title,
					)
				);
			}

			// Write identity index row for new event.
			EventIdentityWriter::syncIdentityRow( $post_id, $title, datamachine_normalize_ticket_url( $ticketUrl ) ?: null );

			do_action(
				'datamachine_log',
				'info',
				'Event Upsert: Created new event',
				array(
					'post_id' => $post_id,
					'title'   => $title,
				)
			);

			return $this->successResponse(
				array(
					'post_id'  => $post_id,
					'post_url' => get_permalink( $post_id ),
					'action'   => 'created',
				)
			);
		}
	}

	/**
	 * Find existing event using DM core's duplicate detection system.
	 *
	 * Calls the `datamachine/check-duplicate` ability which delegates to
	 * the registered EventDuplicateStrategy, querying the PostIdentityIndex
	 * with indexed lookups instead of postmeta LIKE scans.
	 *
	 * Falls back to the legacy findExistingEvent() if the ability or
	 * identity index table is not available.
	 *
	 * @param string $title     Event title.
	 * @param string $venue     Venue name.
	 * @param string $startDate Start date.
	 * @param string $ticketUrl Ticket URL.
	 * @return int|null Post ID if found, null otherwise.
	 */
	private function findExistingEventViaAbility( string $title, string $venue, string $startDate, string $ticketUrl ): ?int {
		// Check if the identity index table class exists (requires DM core >= 0.50.0).
		if ( ! class_exists( 'DataMachine\\Core\\Database\\PostIdentityIndex\\PostIdentityIndex' ) ) {
			return $this->findExistingEvent( $title, $venue, $startDate, $ticketUrl );
		}

		$duplicate_check = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/check-duplicate' ) : null;

		if ( ! $duplicate_check ) {
			return $this->findExistingEvent( $title, $venue, $startDate, $ticketUrl );
		}

		$result = $duplicate_check->execute(
			array(
				'title'     => $title,
				'post_type' => Event_Post_Type::POST_TYPE,
				'scope'     => 'published',
				'context'   => array(
					'venue'     => $venue,
					'startDate' => $startDate,
					'ticketUrl' => $ticketUrl,
				),
			)
		);

		if ( is_array( $result ) && 'duplicate' === ( $result['verdict'] ?? '' ) ) {
			$post_id = (int) ( $result['match']['post_id'] ?? 0 );
			if ( $post_id > 0 ) {
				return $post_id;
			}
		}

		return null;
	}

	/**
	 * Release a MySQL advisory lock.
	 *
	 * @since 0.17.3
	 * @param string $lock_key The lock key to release.
	 */
	private function releaseUpsertLock( string $lock_key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
	}

	/**
	 * Find existing event by title, venue, start date, and ticket URL
	 *
	 * Checks in order of reliability:
	 * 1. Ticket URL matching (most reliable - stable identifier from ticketing platform)
	 * 2. Fuzzy title matching at same venue/date
	 * 3. Exact title matching
	 *
	 * @param string $title Event title
	 * @param string $venue Venue name
	 * @param string $startDate Start date (YYYY-MM-DD)
	 * @param string $ticketUrl Ticket purchase URL
	 * @return int|null Post ID if found, null otherwise
	 */
	private function findExistingEvent( string $title, string $venue, string $startDate, string $ticketUrl = '' ): ?int {
		$identity_confidence = EventIdentifierGenerator::getIdentityConfidence( $title, $startDate, $venue );

		// Try ticket URL matching first (most reliable)
		if ( ! empty( $ticketUrl ) && ! empty( $startDate ) ) {
			$ticket_match = $this->findEventByTicketUrl( $ticketUrl, $startDate );
			if ( $ticket_match ) {
				return $ticket_match;
			}
		}

		// Try fuzzy title matching when we have venue and date
		if ( ! empty( $venue ) && ! empty( $startDate ) ) {
			$fuzzy_match = $this->findEventByVenueDateAndFuzzyTitle( $title, $venue, $startDate );
			if ( $fuzzy_match ) {
				return $fuzzy_match;
			}
		}

		// Try exact title matching (with venue confirmation)
		$exact_match = $this->findEventByExactTitle( $title, $venue, $startDate );
		if ( $exact_match ) {
			return $exact_match;
		}

		// Final safety net: fuzzy title + date search without venue constraint.
		// Catches cross-source duplicates where venue names differ between scrapers
		// (e.g. "Come and Take It Live" vs "Come and Take It Productions").
		// Venue is passed for confirmation when both sides have it.
		if ( ! empty( $startDate ) && 'low' !== $identity_confidence ) {
			return $this->findEventByDateAndFuzzyTitle( $title, $startDate, $venue );
		}

		if ( ! empty( $startDate ) ) {
			do_action(
				'datamachine_log',
				'debug',
				'Event Upsert: Skipping venue-agnostic fuzzy fallback for low-confidence event identity',
				array(
					'title'               => $title,
					'venue'               => $venue,
					'startDate'           => $startDate,
					'identity_confidence' => $identity_confidence,
				)
			);
		}

		return null;
	}

	/**
	 * Extract event data from existing post
	 *
	 * @param int $post_id Post ID
	 * @return array Event attributes from event-details block
	 */
	private function extractEventData( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$blocks = parse_blocks( $post->post_content );

		foreach ( $blocks as $block ) {
			if ( 'data-machine-events/event-details' === $block['blockName'] ) {
				return $block['attrs'] ?? array();
			}
		}

		return array();
	}

	/**
	 * Compare existing and incoming event data
	 *
	 * @param array $existing Existing event attributes
	 * @param array $incoming Incoming event parameters
	 * @return bool True if data changed, false if identical
	 */
	private function hasDataChanged( array $existing, array $incoming ): bool {
		// Fields to compare
		$compare_fields = array(
			'startDate',
			'endDate',
			'startTime',
			'endTime',
			'venue',
			'address',
			'price',
			'ticketUrl',
			'performer',
			'performerType',
			'organizer',
			'organizerType',
			'organizerUrl',
			'eventStatus',
			'previousStartDate',
			'priceCurrency',
			'offerAvailability',
		);

		foreach ( $compare_fields as $field ) {
			$existing_value = trim( (string) ( $existing[ $field ] ?? '' ) );
			$incoming_value = trim( (string) ( $incoming[ $field ] ?? '' ) );

			if ( $existing_value !== $incoming_value ) {
				do_action(
					'datamachine_log',
					'debug',
					"Event Upsert: Field changed: {$field}",
					array(
						'existing' => $existing_value,
						'incoming' => $incoming_value,
					)
				);
				return true;
			}
		}

		// Check description (may be in inner blocks)
		$existing_description = trim( (string) ( $existing['description'] ?? '' ) );
		$incoming_description = trim( (string) ( $incoming['description'] ?? '' ) );

		if ( $existing_description !== $incoming_description ) {
			do_action( 'datamachine_log', 'debug', 'Event Upsert: Description changed' );
			return true;
		}

		return false; // No changes detected
	}

	/**
	 * Create new event post
	 *
	 * @param array $parameters Event parameters (AI-provided, already filtered at definition time)
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine snapshot helper
	 * @return int|WP_Error Post ID on success
	 */
	private function createEventPost( array $parameters, array $handler_config, EngineData $engine ): int|\WP_Error {
		$job_id      = (int) ( $parameters['job_id'] ?? 0 );
		$post_status = WordPressSettingsResolver::getPostStatus( $handler_config );
		$post_author = $this->resolvePostAuthor( $handler_config, $engine );

		// Build event data: engine data takes precedence, then AI params
		$event_data = $this->buildEventData( $parameters, $handler_config, $engine );

		$post_data = array(
			'post_type'    => Event_Post_Type::POST_TYPE,
			'post_title'   => $event_data['title'],
			'post_status'  => $post_status,
			'post_author'  => $post_author,
			'post_content' => $this->generate_event_block_content( $event_data, $parameters ),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return $post_id;
		}

		// Store submission metadata when event was user-submitted.
		$submission = $engine->get( 'submission' );
		if ( is_array( $submission ) ) {
			if ( ! empty( $submission['user_id'] ) ) {
				update_post_meta( $post_id, '_datamachine_submitted_by', (int) $submission['user_id'] );
			}
			if ( ! empty( $submission['contact_name'] ) ) {
				update_post_meta( $post_id, '_datamachine_submitter_name', sanitize_text_field( $submission['contact_name'] ) );
			}
			if ( ! empty( $submission['contact_email'] ) ) {
				update_post_meta( $post_id, '_datamachine_submitter_email', sanitize_email( $submission['contact_email'] ) );
			}
		}

		$this->processEventFeaturedImage( $post_id, $handler_config, $engine );
		$this->processVenue( $post_id, $parameters, $engine );
		$this->processPromoter( $post_id, $parameters, $engine, $handler_config );

		// Map performer to artist taxonomy if not explicitly provided
		if ( empty( $parameters['artist'] ) && ! empty( $event_data['performer'] ) ) {
			$parameters['artist'] = $event_data['performer'];
		}

		$handler_config_for_tax = $handler_config;

		$handler_config_for_tax['taxonomy_venue_selection']    = 'skip';
		$handler_config_for_tax['taxonomy_promoter_selection'] = 'skip';
		$engine_data_array                                     = $engine instanceof EngineData ? $engine->all() : array();
		$this->taxonomy_handler->processTaxonomies( $post_id, $parameters, $handler_config_for_tax, $engine_data_array );

		if ( $job_id ) {
			datamachine_merge_engine_data(
				$job_id,
				array(
					'event_id'  => $post_id,
					'event_url' => get_permalink( $post_id ),
				)
			);
		}

		return $post_id;
	}

	/**
	 * Resolve post author for event creation.
	 *
	 * When an event is submitted by a logged-in user (via the event submission form),
	 * their user_id is stored in initial_data['submission']['user_id']. This takes
	 * priority over handler config defaults so submitted events are attributed to
	 * the submitter.
	 *
	 * Resolution order:
	 * 1. Submission user_id from engine data (user-submitted events)
	 * 2. WordPressSettingsResolver (system defaults / handler config / fallbacks)
	 *
	 * @param array      $handler_config Handler configuration.
	 * @param EngineData $engine         Engine snapshot helper.
	 * @return int Post author ID.
	 */
	private function resolvePostAuthor( array $handler_config, EngineData $engine ): int {
		$submission = $engine->get( 'submission' );

		if ( is_array( $submission ) && ! empty( $submission['user_id'] ) ) {
			$submitter_id = (int) $submission['user_id'];
			if ( $submitter_id > 0 && get_userdata( $submitter_id ) ) {
				return $submitter_id;
			}
		}

		return WordPressSettingsResolver::getPostAuthor( $handler_config );
	}

	/**
	 * Determine datetime confidence from engine/AI parameters.
	 *
	 * @param array $parameters Event parameters.
	 * @param EngineData $engine Engine data helper.
	 * @return string One of full|date_only|none.
	 */
	private function getDateTimeConfidence( array $parameters, EngineData $engine ): string {
		$start_date = trim( (string) ( $engine->get( 'startDate' ) ?? $parameters['startDate'] ?? '' ) );
		$start_time = trim( (string) ( $engine->get( 'startTime' ) ?? $parameters['startTime'] ?? '' ) );

		if ( '' === $start_date ) {
			return 'none';
		}

		if ( '' === $start_time ) {
			return 'date_only';
		}

		return 'full';
	}

	/**
	 * Generate paragraph blocks from HTML description
	 *
	 * @param string $description HTML description content
	 * @return string InnerBlocks content with proper paragraph blocks
	 */
	private function generate_description_blocks( string $description ): string {
		if ( empty( $description ) ) {
			return '';
		}

		// Split on closing/opening p tags or double line breaks
		$paragraphs = preg_split( '/<\/p>\s*<p[^>]*>|<\/p>\s*<p>|\n\n+/', $description );

		$blocks = array();
		foreach ( $paragraphs as $para ) {
			// Strip outer p tags but keep inline formatting
			$para = preg_replace( '/^<p[^>]*>|<\/p>$/', '', trim( $para ) );
			$para = trim( $para );

			if ( ! empty( $para ) ) {
				$blocks[] = '<!-- wp:paragraph -->' . "\n" . '<p>' . $para . '</p>' . "\n" . '<!-- /wp:paragraph -->';
			}
		}

		return implode( "\n", $blocks );
	}

	/**
	 * Custom taxonomy handler for promoter
	 * Maps Schema.org "organizer" field to promoter taxonomy
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param array $handler_config Handler configuration
	 * @param mixed $engine_context Engine context (EngineData|array|null)
	 * @return array|null Assignment result
	 */
	public function assignPromoterTaxonomy( int $post_id, array $parameters, array $handler_config, $engine_context = null ): ?array {
		$selection = $this->getPromoterSelection( $handler_config );

		if ( 'skip' === $selection ) {
			return null;
		}

		if ( $this->isPromoterTermSelection( $selection ) ) {
			$result = $this->assignConfiguredPromoter( $post_id, (int) $selection );
			if ( $result ) {
				return $result;
			}
			return array(
				'success' => false,
				'error'   => 'Failed to assign configured promoter',
			);
		}

		if ( ! $this->isPromoterAiSelection( $selection ) ) {
			return null;
		}

		$engine        = $this->resolveEngineContext( $engine_context, $parameters );
		$promoter_name = $parameters['organizer'] ?? $engine->get( 'organizer' ) ?? '';

		if ( empty( $promoter_name ) ) {
			return null;
		}

		$promoter_metadata = array(
			'url'  => $this->getParameterValue( $parameters, 'organizerUrl' ) ?: ( $engine->get( 'organizerUrl' ) ?? '' ),
			'type' => $this->getParameterValue( $parameters, 'organizerType' ) ?: ( $engine->get( 'organizerType' ) ?? 'Organization' ),
		);

		$promoter_result = Promoter_Taxonomy::find_or_create_promoter( $promoter_name, $promoter_metadata );

		if ( ! empty( $promoter_result['term_id'] ) ) {
			$assignment_result = Promoter::assign_promoter_to_event( $post_id, array( 'promoter' => $promoter_result['term_id'] ) );

			if ( ! empty( $assignment_result ) ) {
				return array(
					'success'   => true,
					'taxonomy'  => 'promoter',
					'term_id'   => $promoter_result['term_id'],
					'term_name' => $promoter_name,
					'source'    => 'event_promoter_handler',
				);
			}

			return array(
				'success' => false,
				'error'   => 'Failed to assign promoter term',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to create or find promoter',
		);
	}

	private function getPromoterSelection( array $handler_config ): string {
		$selection = $handler_config['taxonomy_promoter_selection'] ?? 'skip';
		if ( is_numeric( $selection ) ) {
			return (string) absint( $selection );
		}
		return $selection;
	}

	private function isPromoterTermSelection( string $selection ): bool {
		return is_numeric( $selection ) && (int) $selection > 0;
	}

	private function isPromoterAiSelection( string $selection ): bool {
		return 'ai_decides' === $selection;
	}

	private function assignConfiguredPromoter( int $post_id, int $term_id ): ?array {
		if ( $term_id <= 0 ) {
			return null;
		}

		if ( ! term_exists( $term_id, 'promoter' ) ) {
			return null;
		}

		$assignment_result = Promoter::assign_promoter_to_event( $post_id, array( 'promoter' => $term_id ) );

		if ( ! empty( $assignment_result ) ) {
			$term      = get_term( $term_id, 'promoter' );
			$term_name = ( ! is_wp_error( $term ) && $term ) ? $term->name : '';

			return array(
				'success'   => true,
				'taxonomy'  => 'promoter',
				'term_id'   => $term_id,
				'term_name' => $term_name,
				'source'    => 'event_promoter_handler',
			);
		}

		return null;
	}

	/**
	 * Success response wrapper
	 */
	protected function successResponse( array $data ): array {
		return array(
			'success'   => true,
			'data'      => $data,
			'tool_name' => 'data_machine_events',
		);
	}
