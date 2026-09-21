/**
 * External Product Images — gallery behaviour.
 *
 * Vanilla JS (no jQuery dependency). Clicking a thumbnail, or one of the
 * arrows, swaps the main image with a short cross-fade and keeps ARIA state
 * in sync. The arrows wrap, so the gallery loops. The enlarge button opens
 * the same images in a lightbox over the page, which steps through them with
 * its own arrows, the keyboard, or a swipe, and keeps the gallery behind in
 * step.
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

		if ( ! mainImage ) {
			gallery.dataset.epiReady = 'true';
			return;
		}

		// A single-image gallery has no thumbnails; the main image is the list.
		var images = thumbs.length
			? thumbs.map( function ( thumb ) {
				return thumb.getAttribute( 'data-epi-full' );
			} )
			: [ mainImage.getAttribute( 'src' ) ];

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
			index = ( index + images.length ) % images.length;

			var fullSrc = images[ index ];

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
			thumbs.forEach( function ( other, i ) {
				other.classList.toggle( 'is-active', i === index );

				if ( i === index ) {
					other.setAttribute( 'aria-current', 'true' );
				} else {
					other.removeAttribute( 'aria-current' );
				}
			} );

			updateLightbox();
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

		// --- Lightbox -------------------------------------------------------

		var lightbox = gallery.querySelector( '[data-epi-lightbox]' );
		var enlarge = gallery.querySelector( '[data-epi-enlarge]' );
		var lightboxImage = lightbox ? lightbox.querySelector( '[data-epi-lightbox-image]' ) : null;
		var lightboxCount = lightbox ? lightbox.querySelector( '[data-epi-lightbox-count]' ) : null;

		/**
		 * Point the lightbox at the current image, if it is open.
		 */
		function updateLightbox() {
			if ( ! lightbox || lightbox.hidden || ! lightboxImage ) {
				return;
			}

			lightboxImage.setAttribute( 'src', images[ current ] );

			if ( lightboxCount ) {
				lightboxCount.textContent = ( current + 1 ) + ' / ' + images.length;
			}
		}

		if ( lightbox && enlarge && lightboxImage ) {
			// Out from under the theme: a fixed element inside anything with a
			// transform or overflow would be clipped or mispositioned.
			document.body.appendChild( lightbox );

			var closeButton = lightbox.querySelector( '.epi-lightbox__close' );
			var touchStartX = null;

			var onKeydown = function ( event ) {
				if ( event.key === 'Escape' ) {
					close();
				} else if ( event.key === 'ArrowRight' ) {
					goTo( current + 1 );
				} else if ( event.key === 'ArrowLeft' ) {
					goTo( current - 1 );
				}
			};

			var open = function () {
				lightbox.hidden = false;
				document.body.classList.add( 'epi-lightbox-open' );
				document.addEventListener( 'keydown', onKeydown );
				updateLightbox();

				if ( closeButton ) {
					closeButton.focus();
				}
			};

			var close = function () {
				lightbox.hidden = true;
				document.body.classList.remove( 'epi-lightbox-open' );
				document.removeEventListener( 'keydown', onKeydown );
				enlarge.focus();
			};

			enlarge.addEventListener( 'click', open );

			lightbox.querySelectorAll( '[data-epi-close]' ).forEach( function ( el ) {
				el.addEventListener( 'click', close );
			} );

			var lightboxPrev = lightbox.querySelector( '[data-epi-lightbox-prev]' );
			var lightboxNext = lightbox.querySelector( '[data-epi-lightbox-next]' );

			if ( lightboxPrev ) {
				lightboxPrev.addEventListener( 'click', function () {
					goTo( current - 1 );
				} );
			}

			if ( lightboxNext ) {
				lightboxNext.addEventListener( 'click', function () {
					goTo( current + 1 );
				} );
			}

			// A swipe of more than 40px moves on; anything shorter is a tap.
			lightbox.addEventListener( 'touchstart', function ( event ) {
				touchStartX = event.touches.length === 1 ? event.touches[ 0 ].clientX : null;
			}, { passive: true } );

			lightbox.addEventListener( 'touchend', function ( event ) {
				if ( touchStartX === null || images.length < 2 ) {
					return;
				}

				var delta = event.changedTouches[ 0 ].clientX - touchStartX;
				touchStartX = null;

				if ( delta > 40 ) {
					goTo( current - 1 );
				} else if ( delta < -40 ) {
					goTo( current + 1 );
				}
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
