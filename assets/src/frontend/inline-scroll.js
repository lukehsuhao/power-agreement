/**
 * Power Agreement — inline-scroll display mode.
 *
 * Wires three things via document-level event delegation so we keep
 * working after WC's ajax fragment refresh of the order review:
 *
 *   1. preview box click / keyboard activation → open the <dialog>
 *   2. close affordances ([data-power-agreement-close], backdrop click)
 *      → close the <dialog>
 *   3. two-way sync between the outer consent checkbox (the actual
 *      form field that gets submitted) and the inner mirror checkbox
 *      that lives inside the modal — so the customer can tick/untick
 *      from either place and see the same state.
 *
 * Native <dialog>.showModal() gives us focus trap, ESC-to-close, and
 * a backdrop for free; we only orchestrate intents.
 */
( function () {
	'use strict';

	function findDialog( origin ) {
		var id = origin.getAttribute( 'aria-controls' );
		if ( id ) {
			var byId = document.getElementById( id );
			if ( byId ) {
				return byId;
			}
		}
		var wrap = origin.closest( '.power-agreement' );
		return wrap ? wrap.querySelector( '[data-power-agreement-modal]' ) : null;
	}

	function findOuterConsent( wrap ) {
		return wrap
			? wrap.querySelector( 'input[data-power-agreement-consent="outer"]' )
			: null;
	}

	function findInnerConsent( wrap ) {
		return wrap
			? wrap.querySelector( 'input[data-power-agreement-consent="inner"]' )
			: null;
	}

	function syncFromOuter( wrap ) {
		var outer = findOuterConsent( wrap );
		var inner = findInnerConsent( wrap );
		if ( outer && inner ) {
			inner.checked = outer.checked;
		}
	}

	function open( dialog, wrap ) {
		if ( ! dialog ) {
			return;
		}
		// Make sure the inner mirror reflects the outer state when the
		// modal opens — even if the user changed the outer checkbox
		// without touching the modal in this session.
		syncFromOuter( wrap );
		if ( typeof dialog.showModal === 'function' ) {
			try {
				dialog.showModal();
				return;
			} catch ( e ) {
				// Already open or unsupported invocation — fall through.
			}
		}
		dialog.setAttribute( 'open', '' );
		dialog.classList.add( 'is-open' );
	}

	function close( dialog ) {
		if ( ! dialog ) {
			return;
		}
		if ( typeof dialog.close === 'function' && dialog.open ) {
			try {
				dialog.close();
				return;
			} catch ( e ) {
				// Fall through to manual close.
			}
		}
		dialog.removeAttribute( 'open' );
		dialog.classList.remove( 'is-open' );
	}

	// Click handlers: open / close.
	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.closest ) {
			return;
		}

		var closeTrigger = target.closest( '[data-power-agreement-close]' );
		if ( closeTrigger ) {
			event.preventDefault();
			var dlg = closeTrigger.closest( '[data-power-agreement-modal]' );
			close( dlg );
			return;
		}

		var openTrigger = target.closest( '[data-power-agreement-open]' );
		if ( openTrigger ) {
			event.preventDefault();
			var wrap = openTrigger.closest( '.power-agreement' );
			open( findDialog( openTrigger ), wrap );
		}
	} );

	// Keyboard activation (Enter / Space) for the role="button" preview.
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Enter' && event.key !== ' ' ) {
			return;
		}
		var trigger = event.target && event.target.closest
			? event.target.closest( '[data-power-agreement-open]' )
			: null;
		if ( ! trigger ) {
			return;
		}
		event.preventDefault();
		var wrap = trigger.closest( '.power-agreement' );
		open( findDialog( trigger ), wrap );
	} );

	// Click outside the dialog content (on the backdrop) closes it.
	document.addEventListener( 'click', function ( event ) {
		var dialog = event.target;
		if ( ! dialog || ! dialog.matches || ! dialog.matches( '[data-power-agreement-modal]' ) ) {
			return;
		}
		if ( ! dialog.open ) {
			return;
		}
		var rect = dialog.getBoundingClientRect();
		var inside =
			event.clientX >= rect.left &&
			event.clientX <= rect.right &&
			event.clientY >= rect.top &&
			event.clientY <= rect.bottom;
		if ( ! inside ) {
			close( dialog );
		}
	} );

	// Two-way sync between outer and inner consent checkboxes.
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input || ! input.matches ) {
			return;
		}
		var role = input.getAttribute( 'data-power-agreement-consent' );
		if ( role !== 'outer' && role !== 'inner' ) {
			return;
		}
		var wrap = input.closest( '.power-agreement' );
		if ( ! wrap ) {
			return;
		}
		var sibling = role === 'outer'
			? findInnerConsent( wrap )
			: findOuterConsent( wrap );
		if ( sibling && sibling.checked !== input.checked ) {
			sibling.checked = input.checked;
		}
	} );
} )();
