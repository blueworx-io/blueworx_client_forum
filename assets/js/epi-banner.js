/**
 * The banner slider: arrows, dots, swipe, keyboard, and autoplay.
 *
 * The markup already shows the first slide and hides the rest, so a page whose
 * script never loads still has a banner. This only moves between them.
 */
( function () {
	'use strict';

	function banner( root ) {
		var slides = Array.prototype.slice.call( root.querySelectorAll( '.epi-banner__slide' ) );
		var dots = Array.prototype.slice.call( root.querySelectorAll( '.epi-banner__dot' ) );
		var prev = root.querySelector( '.epi-banner__arrow--prev' );
		var next = root.querySelector( '.epi-banner__arrow--next' );
		var seconds = parseInt( root.getAttribute( 'data-autoplay' ), 10 ) || 0;
		var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var current = 0;
		var timer = null;
		var paused = false;

		if ( slides.length < 2 ) {
			return;
		}

		function show( index ) {
			current = ( index + slides.length ) % slides.length;

			slides.forEach( function ( slide, i ) {
				slide.setAttribute( 'aria-hidden', i === current ? 'false' : 'true' );
			} );
			dots.forEach( function ( dot, i ) {
				dot.setAttribute( 'aria-selected', i === current ? 'true' : 'false' );
			} );

			// The arrows and dots sit over the slide, so they follow its colours.
			root.classList.toggle( 'epi-banner--light', slides[ current ].classList.contains( 'epi-banner__slide--light' ) );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function start() {
			stop();
			if ( seconds > 0 && ! reduced && ! paused ) {
				timer = window.setInterval( function () {
					show( current + 1 );
				}, seconds * 1000 );
			}
		}

		// A move the reader made restarts the clock, so the next slide does
		// not come straight after the one they just chose.
		function go( index ) {
			show( index );
			start();
		}

		if ( prev ) {
			prev.addEventListener( 'click', function () {
				go( current - 1 );
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				go( current + 1 );
			} );
		}
		dots.forEach( function ( dot, i ) {
			dot.addEventListener( 'click', function () {
				go( i );
			} );
		} );

		root.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowLeft' === event.key ) {
				event.preventDefault();
				go( current - 1 );
			} else if ( 'ArrowRight' === event.key ) {
				event.preventDefault();
				go( current + 1 );
			}
		} );

		// Pause while the reader is on it, so nothing moves under their pointer.
		root.addEventListener( 'mouseenter', function () {
			paused = true;
			stop();
		} );
		root.addEventListener( 'mouseleave', function () {
			paused = false;
			start();
		} );
		root.addEventListener( 'focusin', function () {
			paused = true;
			stop();
		} );
		root.addEventListener( 'focusout', function ( event ) {
			if ( ! root.contains( event.relatedTarget ) ) {
				paused = false;
				start();
			}
		} );

		var touchX = null;
		root.addEventListener(
			'touchstart',
			function ( event ) {
				touchX = event.touches[ 0 ].clientX;
			},
			{ passive: true }
		);
		root.addEventListener(
			'touchend',
			function ( event ) {
				if ( null === touchX ) {
					return;
				}
				var dx = event.changedTouches[ 0 ].clientX - touchX;
				touchX = null;
				if ( Math.abs( dx ) > 40 ) {
					go( dx < 0 ? current + 1 : current - 1 );
				}
			},
			{ passive: true }
		);

		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				stop();
			} else {
				start();
			}
		} );

		show( 0 );
		start();
	}

	Array.prototype.slice.call( document.querySelectorAll( '.epi-banner' ) ).forEach( banner );
}() );
