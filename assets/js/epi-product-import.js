/**
 * Product import page.
 *
 * Two jobs: confirm before a full re-import, and open or close the raw-data
 * accordions on the detail view.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var confirmButton = event.target.closest( '[data-epi-confirm]' );
		if ( confirmButton && ! window.confirm( confirmButton.getAttribute( 'data-epi-confirm' ) ) ) {
			event.preventDefault();
			return;
		}

		var head = event.target.closest( '[data-epi-accordion] .bw-accordion__head' );
		if ( ! head ) {
			return;
		}

		var accordion = head.closest( '[data-epi-accordion]' );
		var body = accordion.querySelector( '.bw-accordion__body' );
		var open = ! accordion.classList.contains( 'is-open' );

		accordion.classList.toggle( 'is-open', open );
		head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( body ) {
			body.hidden = ! open;
		}
	} );
} )();
