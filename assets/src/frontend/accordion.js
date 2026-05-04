/**
 * Power Agreement — Classic Checkout accordion behaviour.
 *
 * Vanilla JS, no jQuery. Handles:
 *   - click on the toggle button to expand/collapse the agreement body
 *   - keeping aria-expanded and the [hidden] attribute in sync
 *
 * Survives WC's ajax fragment refreshes by re-binding any new
 * accordions after each `updated_checkout` event using event delegation
 * on the document, plus an "initialised" flag to avoid duplicates.
 */
( function () {
	'use strict';

	var TOGGLE_SELECTOR = '[data-power-agreement-toggle]';
	var FLAG_ATTR       = 'data-power-agreement-bound';

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

	function bind( toggle ) {
		if ( toggle.getAttribute( FLAG_ATTR ) === '1' ) {
			return;
		}
		toggle.setAttribute( FLAG_ATTR, '1' );
		toggle.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
			setExpanded( toggle, ! expanded );
		} );
	}

	function init() {
		var toggles = document.querySelectorAll( TOGGLE_SELECTOR );
		for ( var i = 0; i < toggles.length; i++ ) {
			bind( toggles[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	// Re-bind on WC fragment refreshes.
	document.addEventListener( 'updated_checkout', init );
	// jQuery is the official channel, but listening to the native event covers
	// custom themes that re-emit it on document.
} )();
