<?php
/**
 * EventOgCardTemplate geometry regression tests (issue #853).
 *
 * The bug: the venue/city footer block was positioned with a fixed
 * top-down offset from $footer_band_y, so the city line's baseline sat
 * below the top of the black brand strip on every card that had both a
 * venue and a city. These tests render real cards through the actual
 * GDRenderer/GD pipeline and inspect the resulting PNG's pixels — the
 * bug is a rendering defect, so the regression guard has to look at
 * rendered pixels rather than reasoning about the geometry in the
 * abstract.
 *
 * This suite registers `datamachine/image_template/brand_tokens` with a
 * bundled test-fixture font (tests/fixtures/fonts/DejaVuSans.ttf) for the
 * duration of each test. Without it, `register_font()` falls through to
 * GDRenderer's system fallback path
 * (/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf), which does not exist
 * in every CI/sandbox filesystem this suite runs in (confirmed missing in
 * the WordPress Playground PHP-WASM runner used by this repo's CI — GD
 * itself has full FreeType support there, `gd_info()['FreeType Support']`
 * is 1, but `imagettftext()` has nothing to open). A font that can't be
 * opened draws nothing and every pixel assertion below would read null
 * regardless of whether the geometry fix is correct — bundling a fixture
 * font makes the test self-contained instead of dependent on host fonts.
 *
 * @package DataMachineEvents\Tests\Unit
 * @since   0.90.0
 */

namespace DataMachineEvents\Tests\Unit;

use DataMachine\Abilities\Media\GDRenderer;
use DataMachineEvents\Templates\EventOgCardTemplate;
use WP_UnitTestCase;

class EventOgCardTemplateTest extends WP_UnitTestCase {

	/**
	 * Top edge of the bottom brand strip. Mirrors the constant computed
	 * inside EventOgCardTemplate::render() — height (630) minus strip
	 * height (64).
	 */
	private const BRAND_STRIP_Y = 566;

	/**
	 * Absolute path to the bundled test-fixture font (DejaVu Sans — the
	 * same font GDRenderer already uses as its system fallback, so this
	 * is a realistic stand-in, not a synthetic one).
	 */
	private const FIXTURE_FONT = __DIR__ . '/../fixtures/fonts/DejaVuSans.ttf';

	public function setUp(): void {
		parent::setUp();

		$this->assertFileExists(
			self::FIXTURE_FONT,
			'Test fixture font is missing — rendering assertions below cannot work without it'
		);

		add_filter( 'datamachine/image_template/brand_tokens', array( $this, 'provide_fixture_brand_tokens' ) );
	}

	public function tearDown(): void {
		remove_filter( 'datamachine/image_template/brand_tokens', array( $this, 'provide_fixture_brand_tokens' ) );

		parent::tearDown();
	}

	/**
	 * Supply the bundled fixture font for both the heading and body font
	 * roles, so GD always has a real, openable font file regardless of
	 * what (if anything) the host environment provides.
	 *
	 * @param array $tokens Default/incoming brand tokens.
	 * @return array
	 */
	public function provide_fixture_brand_tokens( array $tokens ): array {
		$tokens['fonts'] = array_merge(
			(array) ( $tokens['fonts'] ?? array() ),
			array(
				'heading' => self::FIXTURE_FONT,
				'body'    => self::FIXTURE_FONT,
				'brand'   => self::FIXTURE_FONT,
			)
		);

		return $tokens;
	}

	/**
	 * Render a card and return the temp PNG path.
	 *
	 * @param array $data Template data fields.
	 * @return string Path to the rendered PNG.
	 */
	private function render_card( array $data ): string {
		$renderer = new GDRenderer();
		$template = new EventOgCardTemplate();

		$paths = $template->render(
			$data,
			$renderer,
			array(
				'preset' => 'open_graph',
				'format' => 'png',
			)
		);

		$this->assertNotEmpty( $paths, 'Template should produce at least one rendered file' );
		$this->assertFileExists( $paths[0] );

		return $paths[0];
	}

	/**
	 * Find the lowest row (largest Y) within a column band that contains
	 * a non-background pixel — i.e. the visual bottom edge of whatever
	 * text is drawn there.
	 *
	 * @param string $path Path to a rendered PNG.
	 * @return int|null Lowest text row, or null if nothing was drawn.
	 */
	private function lowest_text_row( string $path ): ?int {
		$image = imagecreatefrompng( $path );
		$this->assertNotFalse( $image, 'Rendered file must be a readable PNG' );

		$x_start = 64;
		$x_end   = 400;
		$y_start = 460; // Just below the top of the footer surface band.
		$y_end   = self::BRAND_STRIP_Y;

		$lowest = null;

		for ( $y = $y_start; $y < $y_end; $y++ ) {
			for ( $x = $x_start; $x < $x_end; $x++ ) {
				$rgb = imagecolorat( $image, $x, $y );
				$red = ( $rgb >> 16 ) & 0xFF;

				// The surface band is #f1f5f9 (red channel 241). Anything
				// meaningfully darker is glyph ink (venue is near-black,
				// city is mid-grey #6b7280).
				if ( $red < 230 ) {
					$lowest = $y;
				}
			}
		}

		imagedestroy( $image );

		return $lowest;
	}

	public function test_venue_and_city_clear_the_brand_strip(): void {
		$path = $this->render_card(
			array(
				'event_name' => 'Regression Guard Event',
				'date_label' => 'Jan 1, 2027',
				'venue'      => 'Lo-Fi Brewing',
				'city'       => 'Charleston, SC',
			)
		);

		$lowest = $this->lowest_text_row( $path );
		wp_delete_file( $path );

		$this->assertNotNull( $lowest, 'Expected venue/city text to render something' );
		$this->assertLessThan(
			self::BRAND_STRIP_Y,
			$lowest,
			'City text baseline must clear the brand strip — this is the exact defect from issue #853'
		);
	}

	public function test_venue_only_clears_the_brand_strip(): void {
		$path = $this->render_card(
			array(
				'event_name' => 'Venue Only Event',
				'date_label' => 'Jan 2, 2027',
				'venue'      => 'The Royal American',
				'city'       => '',
			)
		);

		$lowest = $this->lowest_text_row( $path );
		wp_delete_file( $path );

		$this->assertNotNull( $lowest, 'Expected venue text to render something' );
		$this->assertLessThan( self::BRAND_STRIP_Y, $lowest );
	}

	public function test_city_only_clears_the_brand_strip(): void {
		$path = $this->render_card(
			array(
				'event_name' => 'City Only Event',
				'date_label' => 'Jan 3, 2027',
				'venue'      => '',
				'city'       => 'Austin, TX',
			)
		);

		$lowest = $this->lowest_text_row( $path );
		wp_delete_file( $path );

		$this->assertNotNull( $lowest, 'Expected city text to render something' );
		$this->assertLessThan( self::BRAND_STRIP_Y, $lowest );
	}

	public function test_neither_venue_nor_city_renders_without_error(): void {
		$path = $this->render_card(
			array(
				'event_name' => 'Bare Event',
				'date_label' => 'Jan 4, 2027',
				'venue'      => '',
				'city'       => '',
			)
		);

		// The geometry branch that skips both lines must not throw
		// (undefined-variable) when neither field is present.
		$this->assertFileExists( $path );

		wp_delete_file( $path );
	}
}
