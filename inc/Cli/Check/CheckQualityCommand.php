<?php
/**
 * Run unified event quality audit.
 *
 * Thin CLI wrapper around the event-quality-audit ability.
 *
 * @package DataMachineEvents\Cli\Check
 */

namespace DataMachineEvents\Cli\Check;

use DataMachineEvents\Abilities\EventQualityAuditAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CheckQualityCommand {

	/**
	 * Run unified event quality audit.
	 *
	 * ## OPTIONS
	 *
	 * [--scope=<scope>]
	 * : Which events to scan.
	 * ---
	 * default: upcoming
	 * options:
	 *   - upcoming
	 *   - past
	 *   - all
	 * ---
	 *
	 * [--days-ahead=<days>]
	 * : Days to look ahead for upcoming scope.
	 * ---
	 * default: 90
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Max rows to show per category.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param array $args Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$abilities = new EventQualityAuditAbilities();
		$result    = $abilities->executeAudit(
			array(
				'scope'      => $assoc_args['scope'] ?? 'upcoming',
				'days_ahead' => (int) ( $assoc_args['days-ahead'] ?? 90 ),
				'limit'      => (int) ( $assoc_args['limit'] ?? 25 ),
			)
		);

		if ( isset( $result['error'] ) ) {
			\WP_CLI::error( $result['error'] );
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			\WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT ) );
			return;
		}

		\WP_CLI::log( sprintf( 'Scanned %d events (%s scope)', $result['total_scanned'] ?? 0, $result['scope'] ?? 'unknown' ) );
		\WP_CLI::log( $result['message'] ?? '' );
		\WP_CLI::log( '' );

		$rows = array(
			array( 'Category' => 'Missing Start Date', 'Count' => $result['missing_start_date']['count'] ?? 0 ),
			array( 'Category' => 'Missing Start Time', 'Count' => $result['missing_start_time']['count'] ?? 0 ),
			array( 'Category' => 'Missing Venue', 'Count' => $result['missing_venue']['count'] ?? 0 ),
			array( 'Category' => 'Probable Duplicates', 'Count' => $result['probable_duplicates']['count'] ?? 0 ),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Category', 'Count' ) );
		\WP_CLI::log( '' );

		if ( ! empty( $result['culprit_flows'] ) ) {
			\WP_CLI::log( '--- Top Culprit Flows ---' );
			$flow_rows = array();
			foreach ( $result['culprit_flows'] as $flow ) {
				$flow_rows[] = array(
					'Flow ID' => $flow['flow_id'] ?? 0,
					'Flow'    => $flow['flow_name'] ?? '',
					'Count'   => $flow['count'] ?? 0,
				);
			}
			\WP_CLI\Utils\format_items( 'table', $flow_rows, array( 'Flow ID', 'Flow', 'Count' ) );
		}
	}
}
