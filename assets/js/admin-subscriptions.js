/**
 * MMI Subscriptions — single-subscription admin screen.
 *
 * Forms carrying data-mmisub-confirm ask before submitting (e.g. a live renewal
 * charge), and every form disables its submit button once sent so a double
 * click can't post twice.
 */
( function () {
	'use strict';

	document.querySelectorAll( '.mmisub-edit form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var message = form.getAttribute( 'data-mmisub-confirm' );
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
				return;
			}
			form.querySelectorAll( '[type="submit"]' ).forEach( function ( button ) {
				button.disabled = true;
			} );
		} );
	} );
}() );
