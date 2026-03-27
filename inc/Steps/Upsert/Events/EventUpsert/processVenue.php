//! processVenue — extracted from EventUpsert.php.


	/**
	 * Update existing event post
	 *
	 * @param int $post_id Existing post ID
	 * @param array $parameters Event parameters (AI-provided, already filtered at definition time)
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine snapshot helper
	 */
	private function updateEventPost( int $post_id, array $parameters, array $handler_config, EngineData $engine ): void {
		// Build event data: engine data takes precedence, then AI params
		$event_data = $this->buildEventData( $parameters, $handler_config, $engine );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => $event_data['title'],
				'post_content' => $this->generate_event_block_content( $event_data, $parameters ),
			)
		);

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
	}

	/**
	 * Build event data by merging engine data with AI parameters.
	 *
	 * Engine data takes precedence since AI only received parameters
	 * for fields not already in engine data (filtered at definition time).
	 *
	 * @param array $parameters AI-provided parameters
	 * @param array $handler_config Handler configuration
	 * @param EngineData $engine Engine data helper
	 * @return array Merged event data
	 */
	private function buildEventData( array $parameters, array $handler_config, EngineData $engine ): array {
		$event_data = array(
			'title'       => sanitize_text_field( $parameters['title'] ?? $engine->get( 'title' ) ?? '' ),
			'description' => $parameters['description'] ?? '',
		);

		// Engine data takes precedence - use schema providers as single source of truth
		$schema_fields     = EventSchemaProvider::getFieldKeys();
		$venue_fields      = VenueParameterProvider::getParameterKeys();
		$all_engine_fields = array_unique( array_merge( $schema_fields, $venue_fields ) );

		foreach ( $all_engine_fields as $field ) {
			$value = $engine->get( $field );
			if ( null !== $value && '' !== $value ) {
				$event_data[ $field ] = $value;
			}
		}

		// AI parameters fill in remaining fields
		foreach ( $schema_fields as $field ) {
			if ( ! isset( $event_data[ $field ] ) && ! empty( $parameters[ $field ] ) ) {
				if ( 'ticketUrl' === $field ) {
					$event_data[ $field ] = trim( $parameters[ $field ] );
				} else {
					$event_data[ $field ] = sanitize_text_field( $parameters[ $field ] );
				}
			}
		}

		// Handler config venue override (highest priority)
		if ( ! empty( $handler_config['venue'] ) ) {
			$event_data['venue'] = $handler_config['venue'];
		}

		// Persist datetime values from meta as system-level fallbacks
		$resolved_post_id = $engine->get( 'post_id' ) ?? $parameters['post_id'] ?? 0;
		if ( ! empty( $resolved_post_id ) ) {
			$this->hydrateStartDateFromMeta( (int) $resolved_post_id, $event_data );
			$this->hydrateEndDateFromMeta( (int) $resolved_post_id, $event_data );
		}

		return $event_data;
	}

	private function hydrateStartDateFromMeta( int $post_id, array &$event_data ): void {
		if ( ! empty( $event_data['startDate'] ) && array_key_exists( 'startTime', $event_data ) ) {
			return;
		}

		$dates          = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
		$start_datetime = $dates ? $dates->start_datetime : '';
		if ( empty( $start_datetime ) ) {
			return;
		}

		$date_obj = date_create( $start_datetime );
		if ( ! $date_obj ) {
			return;
		}

		if ( empty( $event_data['startDate'] ) ) {
			$event_data['startDate'] = $date_obj->format( 'Y-m-d' );
		}

		if ( ! array_key_exists( 'startTime', $event_data ) ) {
			$time = $date_obj->format( 'H:i:s' );
			if ( '00:00:00' !== $time ) {
				$event_data['startTime'] = $time;
			}
		}
	}

	private function hydrateEndDateFromMeta( int $post_id, array &$event_data ): void {
		if ( ! empty( $event_data['endDate'] ) && array_key_exists( 'endTime', $event_data ) ) {
			return;
		}

		$dates        = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
		$end_datetime = $dates ? $dates->end_datetime : '';
		if ( empty( $end_datetime ) ) {
			return;
		}

		$date_obj = date_create( $end_datetime );
		if ( ! $date_obj ) {
			return;
		}

		if ( empty( $event_data['endDate'] ) ) {
			$event_data['endDate'] = $date_obj->format( 'Y-m-d' );
		}

		if ( ! array_key_exists( 'endTime', $event_data ) ) {
			$time = $date_obj->format( 'H:i:s' );
			if ( '00:00:00' !== $time && ( $event_data['startTime'] ?? '' ) !== $time ) {
				$event_data['endTime'] = $time;
			}
		}
	}

	/**
	 * Process venue taxonomy assignment.
	 * Engine data takes precedence over AI-provided values.
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param EngineData $engine Engine data helper
	 */
	private function processVenue( int $post_id, array $parameters, EngineData $engine ): void {
		$venue_name = $engine->get( 'venue' ) ?? $parameters['venue'] ?? '';

		if ( empty( $venue_name ) ) {
			$venue_context = $engine->get( 'venue_context' );
			if ( is_array( $venue_context ) && ! empty( $venue_context['name'] ) ) {
				$venue_name = $venue_context['name'];
			}
		}

		if ( ! empty( $venue_name ) ) {
			// Merge engine data with AI parameters (engine takes precedence)
			$merged_params  = array_merge( $parameters, $engine->all() );
			$venue_metadata = VenueParameterProvider::extractFromParameters( $merged_params );

			$venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_metadata );

			if ( $venue_result['term_id'] ) {
				Venue::assign_venue_to_event(
					$post_id,
					array(
						'venue' => $venue_result['term_id'],
					)
				);
			}
		}
	}

	/**
	 * Process featured image with EngineData context and handler fallbacks.
	 */
	private function processEventFeaturedImage( int $post_id, array $handler_config, EngineData $engine ): void {
		if ( empty( $handler_config['include_images'] ) ) {
			return;
		}

		$image_path = $engine->getImagePath();

		if ( ! empty( $image_path ) ) {
			WordPressPublishHelper::attachImageToPost( $post_id, $image_path, $handler_config );
		} elseif ( ! empty( $handler_config['eventImage'] ) ) {
			WordPressPublishHelper::attachImageToPost( $post_id, $handler_config['eventImage'], $handler_config );
		}
	}
