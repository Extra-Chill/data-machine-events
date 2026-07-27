<?php
/**
 * Event cadence abilities.
 *
 * @package DataMachineEvents\Abilities
 */

namespace DataMachineEvents\Abilities;

use DataMachineEvents\Core\CadenceReconciler;

defined( 'ABSPATH' ) || exit;

final class SchedulingAbilities {

	private static bool $registered = false;

	public function __construct() {
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'data-machine-events/get-cadence-status',
			array(
				'label'               => __( 'Get Event Cadence Status', 'data-machine-events' ),
				'description'         => __( 'Project current and policy-resolved event flow cadence without writing.', 'data-machine-events' ),
				'category'            => AbilityCategories::SETTINGS,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute_status' ),
				'permission_callback' => AbilityPermissions::canRead(),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		wp_register_ability(
			'data-machine-events/reconcile-cadence',
			array(
				'label'               => __( 'Reconcile Event Cadence', 'data-machine-events' ),
				'description'         => __( 'Dry-run or apply event flow cadence policy through Data Machine flow abilities.', 'data-machine-events' ),
				'category'            => AbilityCategories::SETTINGS,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'apply' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute_reconcile' ),
				'permission_callback' => AbilityPermissions::canWrite(),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	public function execute_status( array $input ): array|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return ( new CadenceReconciler() )->reconcile( false, 'status' );
	}

	public function execute_reconcile( array $input ): array|\WP_Error {
		return ( new CadenceReconciler() )->reconcile( true === ( $input['apply'] ?? false ) );
	}

	private function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'success'               => array( 'type' => 'boolean' ),
				'mode'                  => array( 'type' => 'string' ),
				'flows_total'           => array( 'type' => 'integer' ),
				'changes_proposed'      => array( 'type' => 'integer' ),
				'changes_applied'       => array( 'type' => 'integer' ),
				'failures'              => array( 'type' => 'integer' ),
				'current_distribution'  => array( 'type' => 'object' ),
				'resolved_distribution' => array( 'type' => 'object' ),
				'tier_distribution'     => array( 'type' => 'object' ),
				'run_budget'            => array( 'type' => 'object' ),
				'changes'               => array( 'type' => 'array' ),
				'skipped'               => array( 'type' => 'array' ),
			),
		);
	}
}
