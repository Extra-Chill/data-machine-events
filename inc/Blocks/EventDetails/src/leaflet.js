/**
 * Leaflet runtime for the Event Details venue map.
 *
 * The legacy venue map initializer consumes the global browser API, while
 * webpack keeps the dependency pinned and served from the plugin build.
 */

/**
 * External dependencies
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';

window.L = L;
