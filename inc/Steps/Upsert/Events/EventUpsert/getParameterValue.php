//! getParameterValue — extracted from EventUpsert.php.


	public function __construct() {
		$this->taxonomy_handler = new TaxonomyHandler();
		// Register custom handler for venue taxonomy
		TaxonomyHandler::addCustomHandler( 'venue', array( $this, 'assignVenueTaxonomy' ) );
		// Register custom handler for promoter taxonomy
		TaxonomyHandler::addCustomHandler( 'promoter', array( $this, 'assignPromoterTaxonomy' ) );
	}

	/**
	 * Custom taxonomy handler for venue
	 *
	 * @param int $post_id Post ID
	 * @param array $parameters Event parameters
	 * @param array $handler_config Handler configuration
	 * @param mixed $engine_context Engine context (EngineData|array|null)
	 * @return array|null Assignment result
	 */
	public function assignVenueTaxonomy( int $post_id, array $parameters, array $handler_config, $engine_context = null ): ?array {
		$engine     = $this->resolveEngineContext( $engine_context, $parameters );
		$venue_name = $parameters['venue'] ?? $engine->get( 'venue' ) ?? '';

		if ( empty( $venue_name ) ) {
			return null;
		}

		$venue_metadata = array(
			'address'     => $this->getParameterValue( $parameters, 'venueAddress' ) ?: ( $engine->get( 'venueAddress' ) ?? '' ),
			'city'        => $this->getParameterValue( $parameters, 'venueCity' ) ?: ( $engine->get( 'venueCity' ) ?? '' ),
			'state'       => $this->getParameterValue( $parameters, 'venueState' ) ?: ( $engine->get( 'venueState' ) ?? '' ),
			'zip'         => $this->getParameterValue( $parameters, 'venueZip' ) ?: ( $engine->get( 'venueZip' ) ?? '' ),
			'country'     => $this->getParameterValue( $parameters, 'venueCountry' ) ?: ( $engine->get( 'venueCountry' ) ?? '' ),
			'phone'       => $this->getParameterValue( $parameters, 'venuePhone' ) ?: ( $engine->get( 'venuePhone' ) ?? '' ),
			'website'     => $this->getParameterValue( $parameters, 'venueWebsite' ) ?: ( $engine->get( 'venueWebsite' ) ?? '' ),
			'coordinates' => $this->getParameterValue( $parameters, 'venueCoordinates' ) ?: ( $engine->get( 'venueCoordinates' ) ?? '' ),
			'capacity'    => $this->getParameterValue( $parameters, 'venueCapacity' ) ?: ( $engine->get( 'venueCapacity' ) ?? '' ),
		);

		$venue_result = \DataMachineEvents\Core\Venue_Taxonomy::find_or_create_venue( $venue_name, $venue_metadata );

		if ( ! empty( $venue_result['term_id'] ) ) {
			$assignment_result = Venue::assign_venue_to_event( $post_id, array( 'venue' => $venue_result['term_id'] ) );

			if ( ! empty( $assignment_result ) ) {
				return array(
					'success'   => true,
					'taxonomy'  => 'venue',
					'term_id'   => $venue_result['term_id'],
					'term_name' => $venue_name,
					'source'    => 'event_venue_handler',
				);
			}

			return array(
				'success' => false,
				'error'   => 'Failed to assign venue term',
			);
		}

		return array(
			'success' => false,
			'error'   => 'Failed to create or find venue',
		);
	}

	/**
	 * Get parameter value (camelCase only)
	 *
	 * @param array $parameters Parameters array
	 * @param string $camelKey CamelCase parameter key
	 * @return string Parameter value or empty string
	 */
	private function getParameterValue( array $parameters, string $camelKey ): string {
		if ( ! empty( $parameters[ $camelKey ] ) ) {
			return (string) $parameters[ $camelKey ];
		}
		return '';
	}
