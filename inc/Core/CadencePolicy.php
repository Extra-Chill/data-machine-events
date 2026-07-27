<?php
/**
 * Event source cadence policy.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

use DataMachineEvents\Steps\EventImport\Handlers\DiceFm\DiceFm;
use DataMachineEvents\Steps\EventImport\Handlers\SingleRecurring\SingleRecurring;
use DataMachineEvents\Steps\EventImport\Handlers\Ticketmaster\Ticketmaster;
use DataMachineEvents\Steps\EventImport\Handlers\WebScraper\UniversalWebScraper;

defined( 'ABSPATH' ) || exit;

final class CadencePolicy {

	public const VENUE_HEAT_META_KEY = '_dme_venue_heat';
	public const PINNED_INTERVAL_KEY = '_dme_pinned_interval';

	private const VALID_HEAT = array( 'hot', 'warm', 'cold' );

	/** @var array<string, class-string> */
	private array $handler_classes;

	/** @var callable */
	private $heat_reader;

	/**
	 * @param array<string, class-string>|null $handler_classes Registered event-import handler classes.
	 * @param callable|null                    $heat_reader     Receives a venue term ID and returns its heat.
	 */
	public function __construct( ?array $handler_classes = null, ?callable $heat_reader = null ) {
		$this->handler_classes = $handler_classes ?? $this->registered_handler_classes();
		$this->heat_reader     = $heat_reader ?? static function ( int $venue_term_id ): string {
			return (string) get_term_meta( $venue_term_id, self::VENUE_HEAT_META_KEY, true );
		};
	}

	/**
	 * Resolve the cadence owned by an event-import source class.
	 *
	 * @return array{interval:string,reason:string,handler:string,source_class:string,venue_term_id:int,heat:string}
	 */
	public function resolve( array $flow ): array {
		$source        = $this->resolve_source( $flow['flow_config'] ?? array() );
		$handler       = $source['handler'];
		$source_class  = $source['source_class'];
		$venue_term_id = 0;
		$heat          = '';

		if ( Ticketmaster::class === $source_class ) {
			return $this->result( 'twicedaily', 'ticketmaster_default', $handler, $source_class );
		}

		if ( DiceFm::class === $source_class ) {
			return $this->result( 'twicedaily', 'dice_default', $handler, $source_class );
		}

		if ( SingleRecurring::class === $source_class ) {
			return $this->result( 'weekly', 'single_recurring_default', $handler, $source_class );
		}

		if ( UniversalWebScraper::class === $source_class ) {
			$venue_term_id = $this->extract_venue_term_id( $source['step'], $handler );
			$heat          = $this->venue_heat( $venue_term_id );
			$tiers         = array(
				'hot'  => array( 'twicedaily', 'hot_venue' ),
				'warm' => array( 'daily', 'warm_venue' ),
				'cold' => array( 'every_3_days', 'cold_venue' ),
			);

			return $this->result( $tiers[ $heat ][0], $tiers[ $heat ][1], $handler, $source_class, $venue_term_id, $heat );
		}

		return $this->result( 'daily', 'unknown_source_fallback', $handler, $source_class );
	}

	/**
	 * @return array{handler:string,source_class:string,step:array}
	 */
	private function resolve_source( array $flow_config ): array {
		foreach ( $flow_config as $step ) {
			if ( ! is_array( $step ) || 'event_import' !== ( $step['step_type'] ?? '' ) || false === ( $step['enabled'] ?? true ) ) {
				continue;
			}

			$handler_slugs = (array) ( $step['handler_slugs'] ?? array() );
			foreach ( $handler_slugs as $handler ) {
				$handler = (string) $handler;
				if ( isset( $this->handler_classes[ $handler ] ) ) {
					return array(
						'handler'      => $handler,
						'source_class' => $this->handler_classes[ $handler ],
						'step'         => $step,
					);
				}
			}

			$first_handler = reset( $handler_slugs );
			$handler       = false === $first_handler ? '' : (string) $first_handler;
			return array(
				'handler'      => $handler,
				'source_class' => '',
				'step'         => $step,
			);
		}

		return array(
			'handler'      => '',
			'source_class' => '',
			'step'         => array(),
		);
	}

	/** @return array<string, class-string> */
	private function registered_handler_classes(): array {
		$registered = apply_filters( 'datamachine_handlers', array(), 'event_import' );
		$classes    = array();

		foreach ( $registered as $handler => $definition ) {
			if ( is_array( $definition ) && is_string( $definition['class'] ?? null ) ) {
				$classes[ (string) $handler ] = $definition['class'];
			}
		}

		return $classes;
	}

	private function extract_venue_term_id( array $step, string $handler ): int {
		$configs = $step['handler_configs'] ?? $step['handler_config'] ?? array();
		if ( isset( $configs[ $handler ] ) && is_array( $configs[ $handler ] ) ) {
			$configs = $configs[ $handler ];
		}

		return absint( $configs['venue'] ?? 0 );
	}

	private function venue_heat( int $venue_term_id ): string {
		$heat = $venue_term_id > 0 ? strtolower( trim( (string) call_user_func( $this->heat_reader, $venue_term_id ) ) ) : '';
		return in_array( $heat, self::VALID_HEAT, true ) ? $heat : 'warm';
	}

	/**
	 * @return array{interval:string,reason:string,handler:string,source_class:string,venue_term_id:int,heat:string}
	 */
	private function result( string $interval, string $reason, string $handler, string $source_class, int $venue_term_id = 0, string $heat = '' ): array {
		return array(
			'interval'      => $interval,
			'reason'        => $reason,
			'handler'       => $handler,
			'source_class'  => $source_class,
			'venue_term_id' => $venue_term_id,
			'heat'          => $heat,
		);
	}
}
