/**
 * Forum Page behaviour: the four-step sequence, the FAQ accordion, and the
 * "on this page" bar following the reader down the page.
 *
 * Everything starts from markup that already works. This file takes a stacked
 * list and makes it one-at-a-time — so a page whose script fails to load is
 * still a page somebody can read.
 */
( function () {
	'use strict';

	function steps( section ) {
		var tabs = Array.prototype.slice.call( section.querySelectorAll( '[role="tab"]' ) );
		var panels = Array.prototype.slice.call( section.querySelectorAll( '[role="tabpanel"]' ) );
		var nexts = Array.prototype.slice.call( section.querySelectorAll( '[data-epi-next]' ) );

		if ( ! tabs.length ) {
			return;
		}

		function show( index ) {
			tabs.forEach( function ( tab, i ) {
				tab.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
				tab.tabIndex = i === index ? 0 : -1;
			} );
			panels.forEach( function ( panel, i ) {
				panel.hidden = i !== index;
			} );
		}

		tabs.forEach( function ( tab, i ) {
			tab.addEventListener( 'click', function () {
				show( i );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;
				if ( 'ArrowRight' === event.key || 'ArrowDown' === event.key ) {
					next = i + 1;
				} else if ( 'ArrowLeft' === event.key || 'ArrowUp' === event.key ) {
					next = i - 1;
				}
				if ( null === next ) {
					return;
				}
				event.preventDefault();
				var target = ( next + tabs.length ) % tabs.length;
				tabs[ target ].focus();
				show( target );
			} );
		} );

		// "Next step" only makes sense once the steps show one at a time.
		nexts.forEach( function ( button, i ) {
			button.hidden = false;
			button.addEventListener( 'click', function () {
				var target = ( i + 1 ) % tabs.length;
				show( target );
				tabs[ target ].focus();
			} );
		} );

		show( 0 );
	}

	function faqs( section ) {
		var buttons = Array.prototype.slice.call( section.querySelectorAll( 'button[aria-expanded]' ) );

		buttons.forEach( function ( button, i ) {
			var answer = document.getElementById( button.getAttribute( 'aria-controls' ) );
			if ( ! answer ) {
				return;
			}
			// The first question stays open, as in the design.
			button.setAttribute( 'aria-expanded', 0 === i ? 'true' : 'false' );
			answer.hidden = 0 !== i;
			button.addEventListener( 'click', function () {
				var open = 'true' === button.getAttribute( 'aria-expanded' );
				button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				answer.hidden = open;
			} );
		} );
	}

	function bar( nav ) {
		var links = Array.prototype.slice.call( nav.querySelectorAll( 'a[href^="#"]' ) );
		var sections = links
			.map( function ( link ) {
				return document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );
			} )
			.filter( Boolean );

		if ( ! sections.length || ! ( 'IntersectionObserver' in window ) ) {
			if ( links.length ) {
				links[ 0 ].setAttribute( 'aria-current', 'true' );
			}
			return;
		}

		function mark( id ) {
			links.forEach( function ( link ) {
				if ( link.getAttribute( 'href' ) === '#' + id ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						mark( entry.target.id );
					}
				} );
			},
			{ rootMargin: '-40% 0px -55% 0px' }
		);

		sections.forEach( function ( section ) {
			observer.observe( section );
		} );

		mark( sections[ 0 ].id );
	}

	Array.prototype.slice.call( document.querySelectorAll( '[data-epi-steps]' ) ).forEach( steps );
	Array.prototype.slice.call( document.querySelectorAll( '[data-epi-faqs]' ) ).forEach( faqs );
	Array.prototype.slice.call( document.querySelectorAll( '.epi-forum-page__bar' ) ).forEach( bar );
}() );
