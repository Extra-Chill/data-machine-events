/**
 * Ticket Link Gate
 *
 * Ticketmaster (and other affiliate networks) asked us to stop rendering
 * affiliate ticket URLs as static HTML — crawlers were firing affiliate
 * click events with no genuine user interaction behind them. Any anchor
 * carrying `data-ticket-ref` is rendered WITHOUT an `href`; this script is
 * the only thing that ever assembles one, and only in response to a real
 * pointer or keyboard interaction.
 *
 * This script never knows the destination URL or any affiliate ID — only
 * the event's post ID (the "ref"). The real destination is resolved
 * server-side, first-party, at `/wp-json/extrachill/v1/events/tickets/<ref>/go`.
 *
 * Gating is keyed on `pointerdown` (not `click`) so the href exists by the
 * time the browser evaluates the click/auxclick default action. That is
 * what keeps middle-click, ⌘/Ctrl-click, and "open link in new tab" working
 * exactly as they would for a normal `<a href>` — pointerdown always fires
 * before click/auxclick in the native event sequence, regardless of which
 * mouse button triggered it. `click` is also gated as a safety net for
 * activation paths that skip pointerdown (e.g. assistive technology).
 * `keydown` (Enter/Space) is handled explicitly because an anchor with no
 * `href` is not guaranteed to be keyboard-activatable by the browser's
 * built-in link behavior.
 *
 * @see https://github.com/Extra-Chill/data-machine-events/issues/816
 */
( function () {
	'use strict';

	var REF_ATTR = 'data-ticket-ref';
	var GATE_SELECTOR = '[' + REF_ATTR + ']';

	/**
	 * Build the first-party redirect URL for a ticket ref.
	 *
	 * @param {string} ref Event post ID.
	 * @return {string} First-party redirect path.
	 */
	function resolveHref( ref ) {
		return '/wp-json/extrachill/v1/events/tickets/' + encodeURIComponent( ref ) + '/go';
	}

	/**
	 * Find the nearest gated anchor for an event target, if any.
	 *
	 * @param {EventTarget|null} target Event target.
	 * @return {Element|null} The gated element, or null.
	 */
	function findGatedElement( target ) {
		if ( ! target || typeof target.closest !== 'function' ) {
			return null;
		}
		return target.closest( GATE_SELECTOR );
	}

	/**
	 * Assign the redirect href if it has not been assigned yet.
	 *
	 * Idempotent: pointerdown, click, and keydown all funnel through this,
	 * so whichever fires first wins and the rest are no-ops.
	 *
	 * @param {Element} element Gated element.
	 * @return {boolean} True if a redirect href is now present.
	 */
	function ensureHref( element ) {
		if ( element.hasAttribute( 'href' ) ) {
			return true;
		}
		var ref = element.getAttribute( REF_ATTR );
		if ( ! ref ) {
			return false;
		}
		element.setAttribute( 'href', resolveHref( ref ) );
		return true;
	}

	function handlePointerOrClick( event ) {
		var element = findGatedElement( event.target );
		if ( ! element ) {
			return;
		}
		ensureHref( element );
	}

	function handleKeydown( event ) {
		if ( 'Enter' !== event.key && ' ' !== event.key && 'Spacebar' !== event.key ) {
			return;
		}
		var element = findGatedElement( event.target );
		if ( ! element ) {
			return;
		}
		// An <a> without href is not a native link for keyboard-activation
		// purposes in every browser, so the default keydown behavior can't
		// be trusted to fire a click. Take over explicitly: stop the
		// default (e.g. page scroll on Space), assign the href, then
		// trigger the same activation path pointerdown/click would.
		event.preventDefault();
		if ( ensureHref( element ) && typeof element.click === 'function' ) {
			element.click();
		}
	}

	document.addEventListener( 'pointerdown', handlePointerOrClick, true );
	document.addEventListener( 'click', handlePointerOrClick, true );
	document.addEventListener( 'keydown', handleKeydown, true );
} )();
