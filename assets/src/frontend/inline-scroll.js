/**
 * Power Agreement — inline-scroll display mode.
 *
 * Wires the preview-box → <dialog> open/close flow. Uses event
 * delegation on `document` so the same listener works after WC's
 * ajax fragment refresh of the order review section.
 *
 * `<dialog>.showModal()` gives us focus trap, ESC-to-close, and a
 * backdrop for free, so we only need to hand off open/close intents.
 *
 * Backwards compatibility: when `<dialog>` isn't supported (Safari
 * < 15.4 or older Firefox), we fall back to toggling a `.is-open`
 * class on the dialog element so our CSS can still show it as a
 * pseudo-modal. No fancy focus trap in that path; it's a graceful
 * degradation, not a primary code path.
 */
( function () {
	'use strict';

	function findDialog( origin ) {
		// First try aria-controls, then fallback to nearest .power-agreement.
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

	function open( dialog ) {
		if ( ! dialog ) {
			return;
		}
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

	// Open the modal when the preview is clicked or activated via keyboard.
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
			open( findDialog( openTrigger ) );
		}
	} );

	// Keyboard activation for the role="button" preview.
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
		open( findDialog( trigger ) );
	} );

	// Click outside the dialog (on its backdrop, which renders inside the
	// dialog element itself) closes the modal. We can detect this because
	// a backdrop click hits the dialog node directly, not its children.
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
} )();
