/**
 * Power Agreement — Classic Checkout accordion behaviour.
 *
 * Vanilla JS, no jQuery. Handles:
 *   - click on the toggle button to expand/collapse the agreement body
 *   - keeping aria-expanded and the [hidden] attribute in sync
 *
 * Uses event delegation on `document` for the click handler so the
 * accordion keeps working after WC's `updated_checkout` ajax fragment
 * refresh replaces the order-review markup (which would otherwise
 * orphan any directly-bound listeners). `updated_checkout` is a
 * jQuery custom event that does not bubble to native listeners, so
 * we never have to listen for it.
 */
( function () {
	'use strict';

	var TOGGLE_SELECTOR = '[data-power-agreement-toggle]';

	function setExpanded( toggle, expanded ) {
		toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		var controlsId = toggle.getAttribute( 'aria-controls' );
		if ( ! controlsId ) {
			return;
		}
		var panel = document.getElementById( controlsId );
		if ( ! panel ) {
			return;
		}
		if ( expanded ) {
			panel.removeAttribute( 'hidden' );
			panel.classList.add( 'is-open' );
		} else {
			panel.setAttribute( 'hidden', '' );
			panel.classList.remove( 'is-open' );
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest
			? event.target.closest( TOGGLE_SELECTOR )
			: null;
		if ( ! toggle ) {
			return;
		}
		event.preventDefault();
		var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
		setExpanded( toggle, ! expanded );
	} );
} )();
