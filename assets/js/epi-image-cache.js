/**
 * Registers the ePim image worker, and tells it to forget any ePim image that
 * fails to show so a broken copy is never reused.
 *
 * Settings come from wp_localize_script as epiImageCache: { workerUrl, scope, base }.
 */
( function () {
	'use strict';

	var settings = window.epiImageCache;

	if ( ! settings || ! ( 'serviceWorker' in navigator ) ) {
		return;
	}

	navigator.serviceWorker
		.register( settings.workerUrl, { scope: settings.scope, updateViaCache: 'none' } )
		.catch( function () {} );

	// Image errors do not bubble, but they do pass the document in the capture
	// phase, before the image's own onerror swaps in the placeholder.
	document.addEventListener(
		'error',
		function ( event ) {
			var image = event.target;
			var worker = navigator.serviceWorker.controller;

			if ( ! worker || ! image || 'IMG' !== image.tagName ) {
				return;
			}

			if ( 0 === ( image.currentSrc || image.src || '' ).indexOf( settings.base ) ) {
				worker.postMessage( { type: 'epi-forget', url: image.currentSrc || image.src } );
			}
		},
		true
	);
}() );
