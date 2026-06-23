/**
 * Search filtering for the product metadata viewer.
 */
( function () {
	'use strict';

	function initViewer( viewer ) {
		var search = viewer.querySelector( '[data-epi-meta-search]' );
		var rows = Array.prototype.slice.call( viewer.querySelectorAll( '[data-epi-meta-row]' ) );
		var noResults = viewer.querySelector( '[data-epi-meta-no-results]' );
		var jsonElement = viewer.querySelector( '[data-epi-meta-json]' );
		var copyButton = viewer.querySelector( '[data-epi-copy-meta]' );
		var downloadButton = viewer.querySelector( '[data-epi-download-meta]' );
		var json = jsonElement ? jsonElement.textContent.trim() : '{}';

		if ( search && rows.length ) {
			search.addEventListener( 'input', function () {
				var query = search.value.trim().toLowerCase();
				var visibleCount = 0;

				rows.forEach( function ( row ) {
					var isVisible = ! query || row.textContent.toLowerCase().indexOf( query ) !== -1;

					row.hidden = ! isVisible;

					if ( isVisible ) {
						visibleCount += 1;
					}
				} );

				if ( noResults ) {
					noResults.hidden = visibleCount > 0;
				}
			} );
		}

		if ( copyButton ) {
			copyButton.addEventListener( 'click', function () {
				copyText( json ).then( function () {
					var label = copyButton.querySelector( '[data-epi-copy-label]' );
					var original = label ? label.textContent : '';

					if ( label ) {
						label.textContent = 'Copied';

						window.setTimeout( function () {
							label.textContent = original;
						}, 1600 );
					}
				} );
			} );
		}

		if ( downloadButton ) {
			downloadButton.addEventListener( 'click', function () {
				var blob = new Blob( [ json + '\n' ], { type: 'application/json;charset=utf-8' } );
				var url = URL.createObjectURL( blob );
				var link = document.createElement( 'a' );

				link.href = url;
				link.download = downloadButton.getAttribute( 'data-epi-filename' ) || 'product-meta.json';
				document.body.appendChild( link );
				link.click();
				link.remove();
				URL.revokeObjectURL( url );
			} );
		}
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var textarea = document.createElement( 'textarea' );

			textarea.value = text;
			textarea.setAttribute( 'readonly', '' );
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();

			try {
				document.execCommand( 'copy' );
				resolve();
			} catch ( error ) {
				reject( error );
			}

			textarea.remove();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-epi-meta-viewer]' ).forEach( initViewer );
	} );
} )();
