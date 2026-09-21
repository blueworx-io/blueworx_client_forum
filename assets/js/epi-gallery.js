/**
 * External Product Images — gallery behaviour.
 *
 * Vanilla JS (no jQuery dependency). Clicking a thumbnail, or one of the
 * arrows, swaps the main image with a short cross-fade and keeps ARIA state
 * in sync. The arrows wrap, so the gallery loops.
 *
 * Supports multiple widget instances on a single page and is safe to run
 * again after Elementor re-renders the widget in the editor.
 */
( function () {
	'use strict';

	/**
	 * Wire up a single gallery instance.
	 *
	 * @param {HTMLElement} gallery The [data-epi-gallery] container.
	 */
	function initGallery( gallery ) {
		if ( ! gallery || gallery.dataset.epiReady === 'true' ) {
			return;
		}

		var mainImage = gallery.querySelector( '[data-epi-main]' );
		var thumbs = Array.prototype.slice.call( gallery.querySelectorAll( '[data-epi-thumb]' ) );

		if ( ! mainImage || ! thumbs.length ) {
			gallery.dataset.epiReady = 'true';
			return;
		}

		var current = thumbs.findIndex( function ( thumb ) {
			return thumb.classList.contains( 'is-active' );
		} );

		if ( current < 0 ) {
			current = 0;
		}

		/**
		 * Show image N, wrapping at either end.
		 *
		 * @param {number} index Position in the thumbnail strip.
		 */
		function goTo( index ) {
			index = ( index + thumbs.length ) % thumbs.length;

			var thumb = thumbs[ index ];
			var fullSrc = thumb.getAttribute( 'data-epi-full' );

			if ( ! fullSrc || index === current ) {
				return;
			}

			current = index;

			// Cross-fade swap.
			mainImage.classList.add( 'is-fading' );

			var swap = function () {
				mainImage.setAttribute( 'src', fullSrc );
				mainImage.classList.remove( 'is-fading' );
				mainImage.removeEventListener( 'transitionend', swap );
			};

			mainImage.addEventListener( 'transitionend', swap );

			// Fallback in case the transition event does not fire.
			window.setTimeout( swap, 300 );

			// Update active state + ARIA.
			thumbs.forEach( function ( other ) {
				other.classList.remove( 'is-active' );
				other.removeAttribute( 'aria-current' );
			} );

			thumb.classList.add( 'is-active' );
			thumb.setAttribute( 'aria-current', 'true' );
		}

		thumbs.forEach( function ( thumb, index ) {
			thumb.addEventListener( 'click', function () {
				goTo( index );
			} );
		} );

		var prev = gallery.querySelector( '[data-epi-prev]' );
		var next = gallery.querySelector( '[data-epi-next]' );

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				goTo( current - 1 );
			} );
		}

		if ( next ) {
			next.addEventListener( 'click', function () {
				goTo( current + 1 );
			} );
		}

		gallery.dataset.epiReady = 'true';
	}

	/**
	 * Initialise every gallery currently in the DOM.
	 */
	function initAll() {
		var galleries = document.querySelectorAll( '[data-epi-gallery]' );
		galleries.forEach( initGallery );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	// Re-initialise inside the Elementor editor after the widget is rendered.
	if ( window.jQuery && window.elementorFrontend ) {
		window.jQuery( window ).on( 'elementor/frontend/init', function () {
			window.elementorFrontend.hooks.addAction(
				'frontend/element_ready/epi-external-product-images.default',
				function ( $scope ) {
					var gallery = $scope[ 0 ].querySelector( '[data-epi-gallery]' );
					initGallery( gallery );
				}
			);
		} );
	}
} )();
