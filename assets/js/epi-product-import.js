/**
 * Product import page.
 *
 * Two jobs: confirm before a full re-import, and open or close the raw ePim
 * record under a product on the detail view.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var confirmButton = event.target.closest( '[data-epi-confirm]' );
		if ( confirmButton && ! window.confirm( confirmButton.getAttribute( 'data-epi-confirm' ) ) ) {
			event.preventDefault();
			return;
		}

		var toggle = event.target.closest( '[data-epi-raw]' );
		if ( ! toggle ) {
			return;
		}

		var row = document.getElementById( toggle.getAttribute( 'aria-controls' ) );
		var open = 'true' !== toggle.getAttribute( 'aria-expanded' );

		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( row ) {
			row.hidden = ! open;
		}
	} );
} )();
