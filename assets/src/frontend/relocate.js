/**
 * Power Agreement — relocate wrapper next to the actual submit button.
 *
 * `woocommerce_review_order_before_submit` is WooCommerce's canonical
 * "render right before the place-order button" hook. On a stock
 * Classic Checkout that puts our wrapper right above #place_order.
 *
 * BUT some merchants build a custom checkout layout (Elementor Pro
 * Checkout, Flexible Checkout Fields, FunnelKit, theme overrides)
 * which (a) moves the place-order button hundreds of pixels away
 * from where the hook fires, and (b) sometimes renders the
 * order-review template twice on a single page, leaving us with
 * duplicate wrappers that share an `id`.
 *
 * This script:
 *
 *   1. Finds the canonical checkout submit button —
 *      `[name="woocommerce_checkout_place_order"]` / `#place_order`.
 *      (We deliberately do NOT fall back to "the last submit on the
 *      page" anymore because that catches Apply-Coupon / Update-Cart
 *      buttons in nearby cart forms.)
 *
 *   2. Picks ONE wrapper to keep — preferring the one already inside
 *      the same <form> as the submit button — and removes any other
 *      `[data-power-agreement]` siblings that the doubled render
 *      left behind.
 *
 *   3. Moves the kept wrapper to be the immediate previous sibling
 *      of that button's row.
 *
 * Re-runs on:
 *   - DOMContentLoaded
 *   - window.load
 *   - jQuery `updated_checkout` (the official WC channel)
 *   - native `updated_checkout` (themes that re-emit on document)
 *   - a debounced MutationObserver as a safety net
 *
 * Idempotent: short-circuits if the kept wrapper is already in
 * place AND there are no duplicates.
 */
( function () {
	'use strict';

	var WRAP_SELECTOR   = '[data-power-agreement]';
	var SUBMIT_SELECTOR = 'button[name="woocommerce_checkout_place_order"], #place_order, input[name="woocommerce_checkout_place_order"]';

	function findSubmitButton() {
		// Look first inside any obvious checkout form…
		var forms = document.querySelectorAll( 'form.woocommerce-checkout, form.checkout' );
		for ( var i = 0; i < forms.length; i++ ) {
			var btn = forms[ i ].querySelector( SUBMIT_SELECTOR );
			if ( btn ) {
				return btn;
			}
		}
		// …and fall back to a document-wide search for templates that
		// don't tag the form with our expected classes. We still match
		// only by the canonical submit name, never an arbitrary
		// `[type=submit]`, so coupon / update-cart / search buttons
		// can never grab the slot.
		return document.querySelector( SUBMIT_SELECTOR );
	}

	function relocate() {
		var submit = findSubmitButton();
		if ( ! submit ) {
			return;
		}
		var wrappers = document.querySelectorAll( WRAP_SELECTOR );
		if ( ! wrappers.length ) {
			return;
		}

		// Prefer the wrapper that's already inside the same <form> as
		// the submit; otherwise just take the first one.
		var submitForm = submit.closest( 'form' );
		var keep = null;
		if ( submitForm ) {
			for ( var i = 0; i < wrappers.length; i++ ) {
				if ( wrappers[ i ].closest( 'form' ) === submitForm ) {
					keep = wrappers[ i ];
					break;
				}
			}
		}
		if ( ! keep ) {
			keep = wrappers[ 0 ];
		}

		// Drop duplicates so we never have two checkboxes / two dialogs
		// fighting over the same `name`.
		for ( var j = 0; j < wrappers.length; j++ ) {
			if ( wrappers[ j ] !== keep && wrappers[ j ].parentElement ) {
				wrappers[ j ].parentElement.removeChild( wrappers[ j ] );
			}
		}

		// Insert the kept wrapper as the previous sibling of the
		// submit button's natural row. Use the closest container
		// row so existing theme styling (gaps, dividers) remains
		// visually adjacent.
		var target = submit.closest( '.form-row, .form-group, .place-order, .actions, .wp-block-button' ) || submit;
		if ( ! target.parentElement ) {
			return;
		}
		if ( target.previousElementSibling === keep ) {
			return; // already in place
		}
		if ( keep.nextElementSibling === target ) {
			return; // already adjacent (different ancestry path)
		}
		target.parentElement.insertBefore( keep, target );
	}

	function scheduleRelocate() {
		if ( typeof window.requestAnimationFrame === 'function' ) {
			window.requestAnimationFrame( relocate );
		} else {
			setTimeout( relocate, 0 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', scheduleRelocate );
	} else {
		scheduleRelocate();
	}
	window.addEventListener( 'load', scheduleRelocate );

	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', scheduleRelocate );
	}
	document.addEventListener( 'updated_checkout', scheduleRelocate );

	if ( typeof window.MutationObserver === 'function' ) {
		var pending = false;
		var observer = new MutationObserver( function () {
			if ( pending ) {
				return;
			}
			pending = true;
			scheduleRelocate();
			setTimeout( function () { pending = false; }, 250 );
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}
} )();
