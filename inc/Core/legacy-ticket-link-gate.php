<?php
/**
 * Legacy Inline Affiliate Ticket Link Gate
 *
 * Import-time AI generation wrote the affiliate ticket URL as an inline
 * prose anchor inside `post_content` for events published before this
 * compliance pass (~36,627 published events at the time of writing) — a
 * surface the Event Details block's ticket-button gating never touched
 * because it isn't the block, it's plain post content.
 *
 * This `the_content` filter rewrites any such anchor into the same
 * JS-gated form as the Event Details block's ticket button: no `href`,
 * just `data-ticket-ref` on the anchor. Render-time rewrite, no DB
 * migration — it works retroactively across every existing post and also
 * catches future drift if the import pipeline ever regresses.
 *
 * @package DataMachineEvents\Core
 * @since   0.62.0
 */

namespace DataMachineEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'the_content', __NAMESPACE__ . '\\data_machine_events_gate_legacy_affiliate_ticket_links', 20 );

/**
 * Rewrite inline affiliate ticket anchors in event post content.
 *
 * Scoped to `data_machine_events` posts only (singular event views and
 * event archive/taxonomy loops both run `the_content` against the actual
 * event post being displayed, so a post-type check covers both without
 * needing separate `is_singular()` / `is_post_type_archive()` branches).
 * Bails via a cheap `stripos()` host scan before touching the HTML parser,
 * so the overwhelming majority of requests (non-event posts, and event
 * posts with no affiliate anchor) pay only the cost of one post_type check
 * plus a handful of string scans.
 *
 * @param string $content Post content, already run through other `the_content` filters.
 * @return string Filtered content.
 */
function data_machine_events_gate_legacy_affiliate_ticket_links( string $content ): string {
	if ( '' === $content || false === strpos( $content, '<a ' ) ) {
		return $content;
	}

	$post = get_post();
	if ( ! $post || Event_Post_Type::POST_TYPE !== $post->post_type ) {
		return $content;
	}

	$host_hit = false;
	foreach ( data_machine_events_affiliate_ticket_hosts() as $affiliate_host ) {
		if ( '' !== $affiliate_host && false !== stripos( $content, (string) $affiliate_host ) ) {
			$host_hit = true;
			break;
		}
	}
	if ( ! $host_hit ) {
		return $content;
	}

	$rewritten = data_machine_events_rewrite_affiliate_anchors( $content, $post->ID );
	if ( $rewritten !== $content ) {
		wp_enqueue_script( 'data-machine-events-ticket-link-gate' );
	}

	return $rewritten;
}

/**
 * Rewrite every affiliate-hosted `<a href>` in `$content` into the gated form.
 *
 * Uses `WP_HTML_Tag_Processor` rather than regex/DOMDocument — it edits only
 * the matched anchor's attributes and leaves every other byte of the
 * (possibly malformed, AI-generated) markup untouched.
 *
 * @param string $content Post content containing at least one affiliate host string.
 * @param int    $post_id Current post ID, used as the ticket ref.
 * @return string Rewritten content, or the original if nothing matched.
 */
function data_machine_events_rewrite_affiliate_anchors( string $content, int $post_id ): string {
	$processor = new \WP_HTML_Tag_Processor( $content );
	$changed   = false;

	while ( $processor->next_tag( 'A' ) ) {
		$href = $processor->get_attribute( 'href' );
		if ( ! is_string( $href ) || '' === $href ) {
			continue;
		}
		if ( ! data_machine_events_is_affiliate_ticket_url( $href ) ) {
			continue;
		}

		$processor->remove_attribute( 'href' );
		$processor->set_attribute( 'data-ticket-ref', (string) $post_id );
		$processor->set_attribute( 'role', 'button' );
		$processor->set_attribute( 'tabindex', '0' );
		$processor->set_attribute( 'rel', 'noopener nofollow' );
		$changed = true;
	}

	return $changed ? $processor->get_updated_html() : $content;
}
