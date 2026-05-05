/**
 * Power Agreement — relocate wrapper next to the actual submit button.
 *
 * `woocommerce_review_order_before_submit` is WooCommerce's canonical
 * "render right before the place-order button" hook. On a stock
 * Classic Checkout that puts our wrapper right above #place_order.
 *
 * BUT some merchants build a custom checkout layout via Elementor
 * Pro's Checkout widget, Flexible Checkout Fields, FunnelKit, or a
 * theme's own template, which moves the submit button out of the
 * standard `.form-row.place-order` slot — sometimes to the very
 * bottom of a separately-rendered billing column. The PHP hook
 * still fires (so our wrapper is in the DOM), it's just hundreds
 * of pixels away from the button the customer actually clicks.
 *
 * This script finds whichever button looks like the real submit
 * action inside the checkout `<form>`, and detaches our wrapper to
 * be the immediate previous sibling of that button's row. It runs:
 *   - once on DOMContentLoaded
 *   - again on `load`, in case other plugins inject late
 *   - again on every WC ajax fragment refresh (`updated_checkout`)
 *   - additionally as a fallback through a MutationObserver on the
 *     form, debounced, in case `updated_checkout` never fires
 *     (some customized checkouts disable the standard ajax flow)
 *
 * It's idempotent: skips work if the wrapper is already adjacent to
 * the submit button.
 */
( function () {
	'use strict';

	var WRAP_SELECTOR  = '[data-power-agreement]';
	var FORM_SELECTORS = 'form.woocommerce-checkout, form.checkout, form.woocommerce-cart-form, form[name="checkout"]';

	function findCheckoutForm() {
		var form = document.querySelector( FORM_SELECTORS );
		if ( form ) {
			return form;
		}
		// Some Elementor templates wrap the checkout in their own form
		// without our usual class names; fall back to the form that
		// contains the submit button most likely to be the place-order.
		var btn = document.querySelector( 'button[name="woocommerce_checkout_place_order"], #place_order' );
		return btn ? btn.closest( 'form' ) : null;
	}

	function findSubmitButton( form ) {
		// Preferred: the button explicitly named for the WC checkout submit.
		var named = form.querySelector(
			'button[name="woocommerce_checkout_place_order"], #place_order, input[name="woocommerce_checkout_place_order"]'
		);
		if ( named ) {
			return named;
		}
		// Fallback: the LAST submit-type control inside the form. Iterating
		// in DOM order matches what the user visually treats as "the final
		// action". Skip controls inside our own modal so we never relocate
		// to "Confirm" inside the agreement dialog.
		var submits = form.querySelectorAll( 'button[type="submit"], input[type="submit"]' );
		for ( var i = submits.length - 1; i >= 0; i-- ) {
			var s = submits[ i ];
			if ( s.closest( '[data-power-agreement-modal]' ) ) {
				continue;
			}
			if ( s.closest( WRAP_SELECTOR ) ) {
				continue;
			}
			return s;
		}
		return null;
	}

	function relocate() {
		var wrap = document.querySelector( WRAP_SELECTOR );
		if ( ! wrap ) {
			return;
		}
		var form = findCheckoutForm();
		if ( ! form ) {
			return;
		}
		var submit = findSubmitButton( form );
		if ( ! submit ) {
			return;
		}
		// Insert wrap as the previous sibling of the submit button's row
		// (or the button itself, if it has no obvious row container).
		var target = submit.closest( '.form-row, .form-group, .place-order, .wp-block-button' ) || submit;
		if ( ! target.parentElement ) {
			return;
		}
		// Already in the right spot? (idempotent on standard layouts.)
		if ( target.previousElementSibling === wrap ) {
			return;
		}
		// Don't move if already inside the same parent and immediately precedes target.
		if ( wrap.nextElementSibling === target ) {
			return;
		}
		target.parentElement.insertBefore( wrap, target );
	}

	function scheduleRelocate() {
		// Defer to next tick so callers can finish their own DOM mutations
		// before we run; avoids racing with WC's own render of the
		// place-order button row after `updated_checkout`.
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

	// jQuery custom event — the official WC channel.
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', scheduleRelocate );
	}
	// Native fallback for themes that re-emit the event on document.
	document.addEventListener( 'updated_checkout', scheduleRelocate );

	// Final safety net: a debounced MutationObserver on the body. Cheap
	// because we only act when our wrapper is detached from the submit
	// button's neighbourhood; the observer itself just calls relocate(),
	// which short-circuits in the common case.
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
