<?php
/**
 * Event cadence WP-CLI command.
 *
 * @package DataMachineEvents\Cli
 */

namespace DataMachineEvents\Cli;

use DataMachineEvents\Abilities\SchedulingAbilities;
use WP_CLI;
use WP_CLI_Command;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and reconcile event flow cadence policy.
 */
final class SchedulingCommand extends WP_CLI_Command {

	/**
	 * Emit cadence status when no subcommand is supplied.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$this->status( $args, $assoc_args );
	}

	/**
	 * Emit current versus resolved cadence and run-budget projection as JSON.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events scheduling status
	 */
	public function status( array $args, array $assoc_args ): void {
		$this->render( ( new SchedulingAbilities() )->execute_status( array() ) );
	}

	/**
	 * Reconcile cadence policy. Defaults to a non-mutating dry-run.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Explicitly preview changes without writes (the default).
	 *
	 * [--apply]
	 * : Apply proposed interval changes through datamachine/update-flow.
	 *
	 * ## EXAMPLES
	 *
	 *     wp data-machine-events scheduling reconcile --dry-run
	 *     wp data-machine-events scheduling reconcile --apply
	 */
	public function reconcile( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['apply'], $assoc_args['dry-run'] ) ) {
			WP_CLI::error( '--apply and --dry-run are mutually exclusive.' );
		}

		$this->render(
			( new SchedulingAbilities() )->execute_reconcile(
				array( 'apply' => isset( $assoc_args['apply'] ) )
			)
		);
	}

	private function render( array|\WP_Error $result ): void {
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( false === ( $result['success'] ?? true ) ) {
			WP_CLI::halt( 1 );
		}
	}
}
