<?php
/**
 * Event source cadence reconciliation.
 *
 * @package DataMachineEvents\Core
 */

namespace DataMachineEvents\Core;

defined( 'ABSPATH' ) || exit;

final class CadenceReconciler {

	private const MANAGED_INTERVALS = array( 'qtrdaily', 'twicedaily', 'daily', 'every_3_days', 'weekly' );

	private CadencePolicy $policy;

	/** @var callable */
	private $flow_loader;

	/** @var callable */
	private $flow_updater;

	/** @var callable */
	private $interval_loader;

	public function __construct( ?CadencePolicy $policy = null, ?callable $flow_loader = null, ?callable $flow_updater = null, ?callable $interval_loader = null ) {
		$this->policy          = $policy ?? new CadencePolicy();
		$this->flow_loader     = $flow_loader ?? array( $this, 'load_flows' );
		$this->flow_updater    = $flow_updater ?? array( $this, 'update_flow' );
		$this->interval_loader = $interval_loader ?? static fn(): array => apply_filters( 'datamachine_scheduler_intervals', array() );
	}

	/**
	 * Build a status projection and optionally apply proposed changes.
	 */
	public function reconcile( bool $apply = false, string $operation = 'reconcile' ): array|\WP_Error {
		$flows = call_user_func( $this->flow_loader );
		if ( is_wp_error( $flows ) ) {
			return $flows;
		}

		$intervals             = call_user_func( $this->interval_loader );
		$current_distribution  = array();
		$resolved_distribution = array();
		$tier_distribution     = array();
		$changes               = array();
		$skipped               = array();
		$before_budget         = 0.0;
		$after_budget          = 0.0;
		$applied               = 0;
		$failures              = 0;

		foreach ( $flows as $flow ) {
			$scheduling        = is_array( $flow['scheduling_config'] ?? null ) ? $flow['scheduling_config'] : array();
			$current_interval  = (string) ( $scheduling['interval'] ?? 'manual' );
			$policy            = $this->policy->resolve( $flow );
			$resolved_interval = $policy['interval'];
			$skip_reason       = $this->skip_reason( $scheduling, $current_interval );

			if ( '' !== $skip_reason ) {
				$resolved_interval = $current_interval;
				$skipped[]         = array(
					'flow_id'  => (int) ( $flow['flow_id'] ?? 0 ),
					'interval' => $current_interval,
					'reason'   => $skip_reason,
				);
			}

			$this->increment( $current_distribution, $current_interval );
			$this->increment( $resolved_distribution, $resolved_interval );
			$this->increment(
				$tier_distribution,
				implode(
					'|',
					array(
						'' !== $policy['handler'] ? $policy['handler'] : 'unknown',
						'' !== $policy['heat'] ? $policy['heat'] : 'n/a',
						$current_interval,
						$resolved_interval,
					)
				)
			);

			$is_enabled     = false !== ( $scheduling['enabled'] ?? true );
			$before_budget += $is_enabled ? $this->runs_per_day( $current_interval, $intervals ) : 0.0;
			$after_budget  += $is_enabled ? $this->runs_per_day( $resolved_interval, $intervals ) : 0.0;

			if ( '' !== $skip_reason || $current_interval === $resolved_interval ) {
				continue;
			}

			$change = array(
				'flow_id'           => (int) ( $flow['flow_id'] ?? 0 ),
				'flow_name'         => (string) ( $flow['flow_name'] ?? '' ),
				'handler'           => $policy['handler'],
				'source_class'      => $policy['source_class'],
				'venue_term_id'     => $policy['venue_term_id'],
				'heat'              => $policy['heat'],
				'current_interval'  => $current_interval,
				'resolved_interval' => $resolved_interval,
				'reason'            => $policy['reason'],
				'applied'           => false,
			);

			if ( $apply ) {
				$result = call_user_func( $this->flow_updater, $flow, $resolved_interval );
				if ( is_wp_error( $result ) || false === $result ) {
					++$failures;
					$change['error'] = is_wp_error( $result ) ? $result->get_error_message() : 'Flow update failed.';
				} else {
					++$applied;
					$change['applied'] = true;
					do_action( 'datamachine_log', 'info', 'Event cadence reconciled', $change );
				}
			}

			$changes[] = $change;
		}

		ksort( $current_distribution );
		ksort( $resolved_distribution );
		ksort( $tier_distribution );
		$mode = 'status' === $operation ? 'status' : 'dry-run';
		if ( $apply ) {
			$mode = 'apply';
		}

		return array(
			'success'               => 0 === $failures,
			'mode'                  => $mode,
			'flows_total'           => count( $flows ),
			'changes_proposed'      => count( $changes ),
			'changes_applied'       => $applied,
			'failures'              => $failures,
			'current_distribution'  => $current_distribution,
			'resolved_distribution' => $resolved_distribution,
			'tier_distribution'     => $tier_distribution,
			'run_budget'            => array(
				'before_per_day' => round( $before_budget, 3 ),
				'after_per_day'  => round( $after_budget, 3 ),
				'delta_per_day'  => round( $after_budget - $before_budget, 3 ),
			),
			'changes'               => $changes,
			'skipped'               => $skipped,
		);
	}

	private function skip_reason( array $scheduling, string $interval ): string {
		if ( false === ( $scheduling['enabled'] ?? true ) ) {
			return 'inactive_flow_preserved';
		}
		if ( true === ( $scheduling[ CadencePolicy::PINNED_INTERVAL_KEY ] ?? false ) ) {
			return 'pinned_interval_preserved';
		}
		if ( 'manual' === $interval || '' === $interval ) {
			return 'manual_flow_preserved';
		}
		if ( in_array( $interval, array( 'one_time', 'cron' ), true ) || ! in_array( $interval, self::MANAGED_INTERVALS, true ) ) {
			return 'custom_interval_preserved';
		}
		return '';
	}

	private function runs_per_day( string $interval, array $intervals ): float {
		$seconds = (int) ( $intervals[ $interval ]['seconds'] ?? 0 );
		return $seconds > 0 ? DAY_IN_SECONDS / $seconds : 0.0;
	}

	private function increment( array &$distribution, string $key ): void {
		$key                  = '' !== $key ? $key : 'manual';
		$distribution[ $key ] = ( $distribution[ $key ] ?? 0 ) + 1;
	}

	private function load_flows(): array|\WP_Error {
		$ability = wp_get_ability( 'datamachine/get-flows' );
		if ( ! $ability ) {
			return new \WP_Error( 'cadence_flow_reader_missing', 'The datamachine/get-flows ability is unavailable.' );
		}

		$result = $ability->execute(
			array(
				'per_page'    => 0,
				'output_mode' => 'list',
			)
		);

		if ( is_wp_error( $result ) || false === ( $result['success'] ?? false ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error( 'cadence_flow_read_failed', $result['error'] ?? 'Unable to read flows.' );
		}

		return $result['flows'] ?? array();
	}

	private function update_flow( array $flow, string $resolved_interval ): bool|\WP_Error {
		$get_ability    = wp_get_ability( 'datamachine/get-flows' );
		$update_ability = wp_get_ability( 'datamachine/update-flow' );
		if ( ! $get_ability || ! $update_ability ) {
			return new \WP_Error( 'cadence_flow_writer_missing', 'Data Machine flow abilities are unavailable.' );
		}

		$current = $get_ability->execute(
			array(
				'flow_id'     => (int) $flow['flow_id'],
				'output_mode' => 'list',
			)
		);
		$latest  = $current['flows'][0] ?? null;
		if ( ! is_array( $latest ) ) {
			return new \WP_Error( 'cadence_flow_missing', 'Flow disappeared before cadence reconciliation.' );
		}

		$expected_interval = (string) ( $flow['scheduling_config']['interval'] ?? 'manual' );
		$latest_interval   = (string) ( $latest['scheduling_config']['interval'] ?? 'manual' );
		if ( $expected_interval !== $latest_interval ) {
			return new \WP_Error( 'cadence_schedule_changed', 'Flow schedule changed after status resolution; rerun reconciliation.' );
		}

		$scheduling             = $latest['scheduling_config'];
		$scheduling['interval'] = $resolved_interval;
		foreach ( array( 'interval_seconds', 'first_run', 'scheduled_time', 'action_id' ) as $derived_key ) {
			unset( $scheduling[ $derived_key ] );
		}

		$result = $update_ability->execute(
			array(
				'flow_id'           => (int) $flow['flow_id'],
				'scheduling_config' => $scheduling,
			)
		);

		return true === ( $result['success'] ?? false ) ? true : new \WP_Error( 'cadence_update_failed', $result['error'] ?? 'Flow update failed.' );
	}
}
