<?php
/**
 * Event OG Card Template
 *
 * Renders a 1200x630 Open Graph card for event posts. Uses the Data Machine
 * GDRenderer + TemplateInterface contract so the card is produced by the
 * core `datamachine/render-image-template` ability.
 *
 * Layout:
 *   - Branded background (Extra Chill orange/red gradient feel via solid blocks)
 *   - Date pill (top-left)
 *   - Event title (centered, wrapped)
 *   - Venue + city footer
 *   - "Extra Chill Events" branding strip
 *
 * Required data fields:
 *   - event_name (string)
 *   - date_label (string)  e.g. "May 16, 2026"
 *   - venue (string)       e.g. "Charleston Pour House"
 *   - city (string)        e.g. "Charleston, SC"
 *
 * @package DataMachineEvents\Templates
 * @since 0.30.0
 */

namespace DataMachineEvents\Templates;

use DataMachine\Abilities\Media\TemplateInterface;
use DataMachine\Abilities\Media\GDRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EventOgCardTemplate implements TemplateInterface {

	public function get_id(): string {
		return 'event_og_card';
	}

	public function get_name(): string {
		return 'Event OG Card';
	}

	public function get_description(): string {
		return 'Open Graph (1200x630) card for event posts. Renders title, date, venue, and city on a branded background.';
	}

	public function get_fields(): array {
		return array(
			'event_name' => array(
				'label'    => 'Event Name',
				'type'     => 'string',
				'required' => true,
			),
			'date_label' => array(
				'label'    => 'Date Label',
				'type'     => 'string',
				'required' => true,
			),
			'venue'      => array(
				'label'    => 'Venue',
				'type'     => 'string',
				'required' => false,
			),
			'city'       => array(
				'label'    => 'City',
				'type'     => 'string',
				'required' => false,
			),
		);
	}

	public function get_default_preset(): string {
		return 'open_graph';
	}

	public function render( array $data, GDRenderer $renderer, array $options = array() ): array {
		$preset = $options['preset'] ?? $this->get_default_preset();
		$format = $options['format'] ?? 'png';
		$context = $options['context'] ?? array();

		$renderer->create_canvas( $preset );

		$width  = $renderer->get_width();
		$height = $renderer->get_height();

		// Brand palette — Extra Chill orange + dark backgrounds.
		$bg_dark    = $renderer->color_hex( 'bg_dark', '#0f0f0f' );
		$bg_band    = $renderer->color_hex( 'bg_band', '#1a1a1a' );
		$accent     = $renderer->color_hex( 'accent', '#ff6b35' );
		$text_white = $renderer->color_hex( 'text_white', '#ffffff' );
		$text_muted = $renderer->color_hex( 'text_muted', '#b8b8b8' );
		$pill_bg    = $renderer->color_hex( 'pill_bg', '#ff6b35' );

		// Backgrounds.
		$renderer->fill( $bg_dark );

		// Top accent band (subtle).
		$renderer->filled_rect( 0, 0, $width, 8, $accent );

		// Footer band where venue/city sits.
		$footer_band_y = (int) ( $height * 0.78 );
		$renderer->filled_rect( 0, $footer_band_y, $width, $height, $bg_band );

		// Bottom branding strip.
		$brand_strip_y = $height - 56;
		$renderer->filled_rect( 0, $brand_strip_y, $width, $height, $accent );

		// Fonts — try theme fonts, fallback to system.
		$renderer->register_font( 'header', 'Oswald-Bold.ttf' );
		$renderer->register_font( 'body', 'Inter-Medium.ttf' );
		$renderer->register_font( 'mono', 'Inter-Bold.ttf' );

		$padding      = 64;
		$content_max  = $width - ( $padding * 2 );

		// Date pill (top-left).
		$date_label = $this->normalize_text( $data['date_label'] ?? '' );
		if ( $date_label ) {
			$pill_padding_x = 24;
			$pill_padding_y = 14;
			$pill_font_size = 28;
			$pill_text_w    = $renderer->measure_text_width( strtoupper( $date_label ), $pill_font_size, 'mono' );
			$pill_w         = $pill_text_w + ( $pill_padding_x * 2 );
			$pill_h         = $pill_font_size + ( $pill_padding_y * 2 );
			$pill_x         = $padding;
			$pill_y         = $padding;

			$renderer->filled_rect( $pill_x, $pill_y, $pill_x + $pill_w, $pill_y + $pill_h, $pill_bg );
			$renderer->draw_text(
				strtoupper( $date_label ),
				$pill_font_size,
				$pill_x + $pill_padding_x,
				$pill_y + $pill_padding_y + $pill_font_size,
				$text_white,
				'mono'
			);
		}

		// Event title — large, wrapped, vertically positioned in upper-mid area.
		$event_name = $this->normalize_text( $data['event_name'] ?? '' );
		if ( $event_name ) {
			$title_font_size = $this->fit_title_size( $event_name );
			$title_y         = (int) ( $height * 0.28 );
			$renderer->draw_text_wrapped(
				$event_name,
				$title_font_size,
				$padding,
				$title_y,
				$text_white,
				'header',
				$content_max,
				1.2,
				'left'
			);
		}

		// Venue + city in footer band.
		$venue = $this->normalize_text( $data['venue'] ?? '' );
		$city  = $this->normalize_text( $data['city'] ?? '' );

		$venue_font_size = 36;
		$city_font_size  = 28;
		$venue_y         = $footer_band_y + 36;

		if ( $venue ) {
			$renderer->draw_text(
				$venue,
				$venue_font_size,
				$padding,
				$venue_y + $venue_font_size,
				$text_white,
				'header'
			);
		}

		if ( $city ) {
			$city_y = $venue ? $venue_y + $venue_font_size + 18 : $venue_y;
			$renderer->draw_text(
				$city,
				$city_font_size,
				$padding,
				$city_y + $city_font_size,
				$text_muted,
				'body'
			);
		}

		// Branding text on bottom strip.
		$brand_text      = 'EXTRA CHILL EVENTS';
		$brand_font_size = 22;
		$brand_text_y    = $brand_strip_y + ( ( $height - $brand_strip_y ) / 2 ) + ( $brand_font_size / 3 );
		$renderer->draw_text(
			$brand_text,
			$brand_font_size,
			$padding,
			(int) $brand_text_y,
			$text_white,
			'mono'
		);

		// Save.
		$filename = sprintf( 'event-og-%d.%s', time(), 'jpeg' === $format ? 'jpg' : 'png' );

		if ( ! empty( $context ) && isset( $context['pipeline_id'], $context['flow_id'] ) ) {
			$path = $renderer->save_to_repository( $filename, $context, $format );
		} else {
			$path = $renderer->save_temp( $format );
		}

		$renderer->destroy();

		return $path ? array( $path ) : array();
	}

	/**
	 * Choose a title font size based on text length so long titles still fit.
	 *
	 * @param string $title Event title.
	 * @return int Font size in points.
	 */
	private function fit_title_size( string $title ): int {
		$len = mb_strlen( $title );

		if ( $len <= 30 ) {
			return 84;
		}
		if ( $len <= 50 ) {
			return 72;
		}
		if ( $len <= 80 ) {
			return 60;
		}
		return 52;
	}

	/**
	 * Strip HTML entities and trim whitespace for clean GD rendering.
	 *
	 * @param string $text Raw text from post data.
	 * @return string Cleaned text.
	 */
	private function normalize_text( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );
		return trim( $text );
	}
}
