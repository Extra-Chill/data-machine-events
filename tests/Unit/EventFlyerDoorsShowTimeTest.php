<?php
/**
 * Doors/show time extraction rules for flyer imports (#922).
 *
 * A flyer reading "DOORS: 8PM  SHOW: 9PM" was saved with start 20:00 and
 * end 21:00. The extraction field descriptions are what reach the model, so
 * they must state that show time is the start, doors goes to the
 * description, and end time is only set from an explicit end.
 *
 * @package DataMachineEvents\Tests\Unit
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyer;
use DataMachineEvents\Steps\EventImport\Handlers\EventFlyer\EventFlyerSettings;
use ReflectionClass;
use WP_UnitTestCase;

class EventFlyerDoorsShowTimeTest extends WP_UnitTestCase {

	public function test_start_time_field_prefers_show_time_over_doors(): void {
		$fields = EventFlyerSettings::get_ai_extraction_fields();

		$this->assertStringContainsString( 'SHOW time', $fields['startTime'] );
		$this->assertStringContainsString( 'doors', strtolower( $fields['startTime'] ) );
	}

	public function test_end_time_field_requires_explicit_end_and_rejects_doors_show(): void {
		$fields = EventFlyerSettings::get_ai_extraction_fields();

		$this->assertStringContainsString( 'ONLY when the flyer states an explicit end', $fields['endTime'] );
		$this->assertStringContainsString( 'Never use a doors or show time as the end time', $fields['endTime'] );
		$this->assertStringContainsString( 'Leave empty otherwise', $fields['endTime'] );
	}

	public function test_description_field_carries_doors_time(): void {
		$fields = EventFlyerSettings::get_ai_extraction_fields();

		$this->assertStringContainsString( 'doors time', $fields['description'] );
	}

	public function test_ai_fields_sent_to_model_carry_doors_show_rules(): void {
		$handler = new EventFlyer();
		$method  = ( new ReflectionClass( $handler ) )->getMethod( 'buildAIExtractionFields' );
		$method->setAccessible( true );

		$ai_fields = $method->invoke( $handler, array() );

		$this->assertStringContainsString( 'SHOW time', $ai_fields['startTime']['description'] );
		$this->assertStringContainsString( 'explicit end', $ai_fields['endTime']['description'] );
	}

	public function test_vision_prompt_states_doors_show_rules(): void {
		$prompt = EventFlyer::get_vision_prompt();

		$this->assertStringContainsString( 'the SHOW time is the start time', $prompt );
		$this->assertStringContainsString( 'A doors or show time is never the end time', $prompt );
	}
}
