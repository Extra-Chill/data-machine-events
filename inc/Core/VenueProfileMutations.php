<?php
/**
 * Canonical venue profile mutation contract.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

class VenueProfileMutations {
	private static array $term_edit_locks     = array();
	private static bool $internal_term_update = false;

	public const STRATEGY_OVERWRITE  = 'overwrite';
	public const STRATEGY_FILL_EMPTY = 'fill_empty';

	private const TAXONOMY     = 'venue';
	private const LOCK_TIMEOUT = 10;

	private const EDITABLE_META_FIELDS = array(
		'address'  => '_venue_address',
		'city'     => '_venue_city',
		'state'    => '_venue_state',
		'zip'      => '_venue_zip',
		'country'  => '_venue_country',
		'phone'    => '_venue_phone',
		'website'  => '_venue_website',
		'capacity' => '_venue_capacity',
	);

	private const SYSTEM_META_FIELDS = array(
		'coordinates' => '_venue_coordinates',
		'timezone'    => '_venue_timezone',
	);

	private const ADDRESS_FIELDS = array( 'address', 'city', 'state', 'zip', 'country' );

	/**
	 * Read the bounded member-editable venue profile and canonical revision.
	 *
	 * Authorization belongs to the consumer and must happen before this call.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array|\WP_Error
	 */
	public static function read( int $term_id ): array|\WP_Error {
		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return is_wp_error( $term ) ? $term : new \WP_Error( 'venue_not_found', 'Venue not found.', array( 'status' => 404 ) );
		}

		$profile = array(
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
		);

		foreach ( self::EDITABLE_META_FIELDS as $field => $meta_key ) {
			$profile[ $field ] = (string) get_term_meta( $term_id, $meta_key, true );
		}

		$profile['revision'] = self::revision( $term );
		return $profile;
	}

	/**
	 * Update only member-editable venue profile fields using optimistic concurrency.
	 *
	 * @param int    $term_id           Venue term ID.
	 * @param array  $changes           Bounded profile changes.
	 * @param string $expected_revision Revision returned by read().
	 * @return array|\WP_Error
	 */
	public static function updateProfile( int $term_id, array $changes, string $expected_revision ): array|\WP_Error {
		if ( '' === $expected_revision ) {
			return new \WP_Error( 'venue_revision_required', 'An expected venue revision is required.', array( 'status' => 400 ) );
		}

		return self::mutate( $term_id, $changes, self::EDITABLE_META_FIELDS, self::STRATEGY_OVERWRITE, $expected_revision, true );
	}

	/**
	 * Update canonical fields for an owner-controlled system writer.
	 *
	 * @param int    $term_id Venue term ID.
	 * @param array  $changes Canonical field changes.
	 * @param string $strategy overwrite or fill_empty.
	 * @return array|\WP_Error
	 */
	public static function updateSystem( int $term_id, array $changes, string $strategy = self::STRATEGY_OVERWRITE ): array|\WP_Error {
		return self::mutate(
			$term_id,
			$changes,
			array_merge( self::EDITABLE_META_FIELDS, self::SYSTEM_META_FIELDS ),
			$strategy,
			'',
			false
		);
	}

	/**
	 * Serialize native WordPress venue term edits with canonical writers.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function beginTermEdit( int $term_id, string $taxonomy ): void {
		if ( self::$internal_term_update || self::TAXONOMY !== $taxonomy ) {
			return;
		}
		$lock_key = self::acquireLock( $term_id );
		if ( ! is_wp_error( $lock_key ) ) {
			self::$term_edit_locks[] = $lock_key;
		}
	}

	/**
	 * Release a lock acquired for a native WordPress venue term edit.
	 */
	public static function endTermEdit(): void {
		$lock_key = array_pop( self::$term_edit_locks );
		if ( is_string( $lock_key ) ) {
			self::releaseLock( $lock_key );
		}
	}

	/**
	 * Whether an owner mutation is currently invoking wp_update_term().
	 *
	 * @return bool
	 */
	public static function isInternalTermUpdate(): bool {
		return self::$internal_term_update;
	}

	/**
	 * Run one canonical mutation while holding the venue lock and transaction.
	 *
	 * @param int    $term_id           Venue term ID.
	 * @param array  $changes           Requested changes.
	 * @param array  $meta_fields       Allowed meta field map.
	 * @param string $strategy          Mutation strategy.
	 * @param string $expected_revision Optional optimistic revision.
	 * @param bool   $profile_contract  Whether this is the public profile contract.
	 * @return array|\WP_Error
	 */
	private static function mutate( int $term_id, array $changes, array $meta_fields, string $strategy, string $expected_revision, bool $profile_contract ): array|\WP_Error {
		if ( ! in_array( $strategy, array( self::STRATEGY_OVERWRITE, self::STRATEGY_FILL_EMPTY ), true ) ) {
			return new \WP_Error( 'venue_strategy_invalid', 'Unknown venue mutation strategy.', array( 'status' => 400 ) );
		}

		$allowed = array_merge( array( 'name', 'description' ), array_keys( $meta_fields ) );
		$changes = array_intersect_key( $changes, array_flip( $allowed ) );
		if ( empty( $changes ) ) {
			return new \WP_Error( 'venue_no_fields', 'No supported venue fields were provided.', array( 'status' => 400 ) );
		}

		$lock_key = self::acquireLock( $term_id );
		if ( is_wp_error( $lock_key ) ) {
			return $lock_key;
		}

		$transaction_open  = false;
		$transaction_scope = '';
		try {
			$transaction_scope = self::beginTransaction( $term_id );
			if ( '' === $transaction_scope ) {
				return new \WP_Error( 'venue_transaction_failed', 'Could not start the venue mutation transaction.', array( 'status' => 500 ) );
			}
			$transaction_open = true;
			self::clearCaches( $term_id );

			$term = get_term( $term_id, self::TAXONOMY );
			if ( ! $term || is_wp_error( $term ) ) {
				return self::rollbackError( $term_id, $term instanceof \WP_Error ? $term : new \WP_Error( 'venue_not_found', 'Venue not found.', array( 'status' => 404 ) ), $transaction_scope, $transaction_open );
			}

			$current_revision = self::revision( $term );
			if ( '' !== $expected_revision && ! hash_equals( $current_revision, $expected_revision ) ) {
				return self::rollbackError(
					$term_id,
					new \WP_Error(
						'venue_revision_conflict',
						'The venue changed after it was read.',
						array(
							'status'           => 409,
							'current_revision' => $current_revision,
						)
					),
					$transaction_scope,
					$transaction_open
				);
			}

			$normalized = self::normalizeChanges( $changes );
			$updated    = array();
			$term_args  = array();

			foreach ( array( 'name', 'description' ) as $field ) {
				if ( ! array_key_exists( $field, $normalized ) ) {
					continue;
				}
				$existing = (string) $term->{$field};
				if ( self::STRATEGY_FILL_EMPTY === $strategy && '' !== $existing ) {
					continue;
				}
				if ( $existing !== $normalized[ $field ] ) {
					$term_args[ $field ] = $normalized[ $field ];
					$updated[]           = $field;
				}
			}

			if ( ! empty( $term_args ) || wp_term_is_shared( $term_id ) ) {
				global $wpdb;
				$wpdb->last_error           = '';
				self::$internal_term_update = true;
				$result                     = wp_update_term( $term_id, self::TAXONOMY, $term_args );
				self::$internal_term_update = false;
				if ( is_wp_error( $result ) ) {
					return self::rollbackError( $term_id, $result, $transaction_scope, $transaction_open );
				}
				if ( '' !== $wpdb->last_error ) {
					return self::rollbackError( $term_id, new \WP_Error( 'venue_term_update_failed', 'WordPress could not persist the venue term fields.', array( 'status' => 500 ) ), $transaction_scope, $transaction_open );
				}
				$term_id = (int) $result['term_id'];
			}

			$address_changed = false;
			foreach ( $meta_fields as $field => $meta_key ) {
				if ( ! array_key_exists( $field, $normalized ) ) {
					continue;
				}

				$existing = (string) get_term_meta( $term_id, $meta_key, true );
				if ( self::STRATEGY_FILL_EMPTY === $strategy && '' !== $existing ) {
					continue;
				}
				if ( $existing === $normalized[ $field ] ) {
					continue;
				}

				$result = update_term_meta( $term_id, $meta_key, $normalized[ $field ] );
				if ( is_wp_error( $result ) || false === $result ) {
					$error = is_wp_error( $result ) ? $result : new \WP_Error( 'venue_meta_update_failed', "Could not update venue field '{$field}'.", array( 'status' => 500 ) );
					return self::rollbackError( $term_id, $error, $transaction_scope, $transaction_open );
				}
				$updated[] = $field;
				if ( in_array( $field, self::ADDRESS_FIELDS, true ) ) {
					$address_changed = true;
				}
			}

			if ( $address_changed ) {
				$derived = self::replaceDerivedLocation( $term_id );
				if ( is_wp_error( $derived ) ) {
					return self::rollbackError( $term_id, $derived, $transaction_scope, $transaction_open );
				}
				$updated = array_merge( $updated, $derived );
			} elseif ( in_array( 'coordinates', $updated, true ) ) {
				$derived = self::replaceTimezone( $term_id, (string) get_term_meta( $term_id, '_venue_coordinates', true ) );
				if ( is_wp_error( $derived ) ) {
					return self::rollbackError( $term_id, $derived, $transaction_scope, $transaction_open );
				}
				$updated = array_merge( $updated, $derived );
			}

			if ( ! self::commitTransaction( $transaction_scope ) ) {
				$transaction_open = false;
				self::clearCaches( $term_id );
				return new \WP_Error(
					'venue_commit_uncertain',
					'The database did not confirm the venue commit; callers must read the venue again before retrying.',
					array( 'status' => 503 )
				);
			}
			$transaction_open = false;
			self::clearCaches( $term_id );

			$profile = self::read( $term_id );
			if ( is_wp_error( $profile ) ) {
				return $profile;
			}

			$result = array(
				'success'        => true,
				'term_id'        => $term_id,
				'updated_fields' => array_values( array_unique( $updated ) ),
				'revision'       => $profile['revision'],
				'profile'        => $profile,
			);

			/**
			 * Fires after a canonical venue mutation is durably committed.
			 *
			 * @param array $result Mutation result.
			 * @param bool  $profile_contract Whether the bounded profile contract was used.
			 */
			do_action( 'data_machine_events_venue_mutated', $result, $profile_contract );
			return $result;
		} finally {
			self::$internal_term_update = false;
			if ( $transaction_open ) {
				self::rollbackTransaction( $transaction_scope );
				self::clearCaches( $term_id );
			}
			self::releaseLock( $lock_key );
		}
	}

	/**
	 * Sanitize values owned by this contract.
	 *
	 * WordPress applies taxonomy and metadata filters again in its write APIs.
	 *
	 * @param array $changes Raw changes.
	 * @return array
	 */
	private static function normalizeChanges( array $changes ): array {
		$normalized = array();
		foreach ( $changes as $field => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				continue;
			}
			$value = (string) $value;
			if ( 'description' === $field ) {
				$normalized[ $field ] = wp_kses_post( $value );
			} elseif ( 'website' === $field ) {
				$normalized[ $field ] = esc_url_raw( $value );
			} else {
				$normalized[ $field ] = sanitize_text_field( $value );
			}
		}
		return $normalized;
	}

	/**
	 * Clear stale location data and derive replacements when possible.
	 *
	 * @param int $term_id Venue term ID.
	 * @return array|\WP_Error Updated derived field names or an error.
	 */
	private static function replaceDerivedLocation( int $term_id ): array|\WP_Error {
		foreach ( self::SYSTEM_META_FIELDS as $field => $meta_key ) {
			if ( metadata_exists( 'term', $term_id, $meta_key ) && ! delete_term_meta( $term_id, $meta_key ) ) {
				return new \WP_Error( 'venue_meta_delete_failed', "Could not invalidate venue field '{$field}'.", array( 'status' => 500 ) );
			}
		}

		$updated     = array( 'coordinates', 'timezone' );
		$coordinates = Venue_Taxonomy::geocode_address( Venue_Taxonomy::get_venue_data( $term_id ) );
		if ( ! $coordinates ) {
			return $updated;
		}

		$result = update_term_meta( $term_id, '_venue_coordinates', sanitize_text_field( $coordinates ) );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_coordinates_update_failed', 'Could not save derived venue coordinates.', array( 'status' => 500 ) );
		}

		$timezone_result = self::replaceTimezone( $term_id, $coordinates );
		return is_wp_error( $timezone_result ) ? $timezone_result : $updated;
	}

	/**
	 * Invalidate timezone and derive it for the supplied coordinates when possible.
	 *
	 * @param int    $term_id    Venue term ID.
	 * @param string $coordinates Coordinates as lat,lng.
	 * @return array|\WP_Error
	 */
	private static function replaceTimezone( int $term_id, string $coordinates ): array|\WP_Error {
		if ( metadata_exists( 'term', $term_id, '_venue_timezone' ) && ! delete_term_meta( $term_id, '_venue_timezone' ) ) {
			return new \WP_Error( 'venue_timezone_delete_failed', 'Could not invalidate venue timezone.', array( 'status' => 500 ) );
		}
		if ( '' === $coordinates || ! GeoNamesService::isConfigured() ) {
			return array( 'timezone' );
		}

		$timezone = GeoNamesService::getTimezoneFromCoordinates( $coordinates );
		if ( ! $timezone ) {
			return array( 'timezone' );
		}
		$result = update_term_meta( $term_id, '_venue_timezone', $timezone );
		if ( is_wp_error( $result ) || false === $result ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'venue_timezone_update_failed', 'Could not save the derived venue timezone.', array( 'status' => 500 ) );
		}
		return array( 'timezone' );
	}

	/**
	 * Fingerprint every canonical field supported by owner writers.
	 *
	 * Duplicate meta rows participate so changing any row makes a previously
	 * read revision stale even though single-value reads return the first row.
	 *
	 * @param \WP_Term $term Venue term.
	 * @return string
	 */
	private static function revision( \WP_Term $term ): string {
		$state = array(
			'blog_id'     => get_current_blog_id(),
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'description' => (string) $term->description,
			'meta'        => array(),
		);
		foreach ( array_merge( self::EDITABLE_META_FIELDS, self::SYSTEM_META_FIELDS ) as $field => $meta_key ) {
			$state['meta'][ $field ] = array_values( get_term_meta( $term->term_id, $meta_key, false ) );
		}
		return hash( 'sha256', (string) wp_json_encode( $state ) );
	}

	/**
	 * Roll back a failed mutation and distinguish an uncertain rollback.
	 *
	 * @param int       $term_id Venue term ID.
	 * @param \WP_Error $error   Original error.
	 * @param string    $transaction_scope Transaction or savepoint scope.
	 * @param bool      $transaction_open  Whether the transaction remains open.
	 * @return \WP_Error
	 */
	private static function rollbackError( int $term_id, \WP_Error $error, string $transaction_scope, bool &$transaction_open ): \WP_Error {
		if ( ! self::rollbackTransaction( $transaction_scope ) ) {
			$transaction_open = false;
			self::clearCaches( $term_id );
			return new \WP_Error(
				'venue_rollback_uncertain',
				'The database did not confirm the venue rollback; callers must read the venue again before retrying.',
				array(
					'status'         => 503,
					'original_error' => $error->get_error_code(),
				)
			);
		}
		$transaction_open = false;
		self::clearCaches( $term_id );
		return $error;
	}

	/**
	 * Begin a transaction, or a savepoint when the caller already owns one.
	 *
	 * @param int $term_id Venue term ID.
	 * @return string Empty string on failure, otherwise transaction scope.
	 */
	private static function beginTransaction( int $term_id ): string {
		global $wpdb;
		$in_transaction = '1' === (string) $wpdb->get_var( 'SELECT @@in_transaction' );
		$scope          = $in_transaction ? 'SAVEPOINT dme_venue_' . $term_id : 'TRANSACTION';
		$sql            = $in_transaction ? $scope : 'START TRANSACTION';
		return false === static::query( $sql ) ? '' : $scope;
	}

	/**
	 * Commit or release the active venue mutation scope.
	 *
	 * @param string $scope Transaction scope.
	 * @return bool
	 */
	private static function commitTransaction( string $scope ): bool {
		$sql = 'TRANSACTION' === $scope ? 'COMMIT' : 'RELEASE ' . $scope;
		return false !== static::query( $sql );
	}

	/**
	 * Roll back the active venue mutation scope.
	 *
	 * @param string $scope Transaction scope.
	 * @return bool
	 */
	private static function rollbackTransaction( string $scope ): bool {
		if ( 'TRANSACTION' === $scope ) {
			return false !== static::query( 'ROLLBACK' );
		}
		if ( false === static::query( 'ROLLBACK TO ' . $scope ) ) {
			return false;
		}
		return false !== static::query( 'RELEASE ' . $scope );
	}

	/**
	 * Acquire a multisite-scoped MySQL advisory lock.
	 *
	 * @param int $term_id Venue term ID.
	 * @return string|\WP_Error
	 */
	private static function acquireLock( int $term_id ): string|\WP_Error {
		global $wpdb;
		$scope    = DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() . '|' . $term_id;
		$lock_key = 'dme:venue:' . md5( $scope );
		$result   = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, self::LOCK_TIMEOUT ) );
		if ( '1' !== (string) $result ) {
			return new \WP_Error( 'venue_lock_unavailable', 'The venue is currently being updated; retry the request.', array( 'status' => 409 ) );
		}
		return $lock_key;
	}

	/**
	 * Release an acquired advisory lock.
	 *
	 * @param string $lock_key Lock key.
	 */
	private static function releaseLock( string $lock_key ): void {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		if ( '1' !== (string) $result ) {
			do_action( 'datamachine_log', 'error', 'Venue mutation advisory lock release failed', array( 'lock_key' => $lock_key ) );
		}
	}

	/**
	 * Execute transaction control SQL. Kept overridable for failure-path tests.
	 *
	 * @param string $sql Transaction statement.
	 * @return int|bool
	 */
	protected static function query( string $sql ): int|bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Bounded transaction control statements assembled internally.
		return $wpdb->query( $sql );
	}

	/**
	 * Purge term and metadata caches after commit or rollback boundaries.
	 *
	 * @param int $term_id Venue term ID.
	 */
	private static function clearCaches( int $term_id ): void {
		wp_cache_delete( $term_id, 'term_meta' );
		clean_term_cache( $term_id, self::TAXONOMY );
	}
}
