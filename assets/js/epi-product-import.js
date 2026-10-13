/**
 * Product import page.
 *
 * Confirms before a full re-import.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var confirmButton = event.target.closest( '[data-epi-confirm]' );
		if ( confirmButton && ! window.confirm( confirmButton.getAttribute( 'data-epi-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
} )();
