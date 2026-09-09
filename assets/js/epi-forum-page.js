/**
 * Forum Page behaviour: the four-step sequence, and the FAQ accordion.
 *
 * Both start from markup that already works. This file takes a stacked list
 * and makes it one-at-a-time — so a page whose script fails to load is still
 * a page somebody can read.
 */
( function () {
	'use strict';

	function steps( section ) {
		var tabs = Array.prototype.slice.call( section.querySelectorAll( '[role="tab"]' ) );
		var panels = Array.prototype.slice.call( section.querySelectorAll( '[role="tabpanel"]' ) );

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

		show( 0 );
	}

	function faqs( section ) {
		var buttons = Array.prototype.slice.call( section.querySelectorAll( 'button[aria-expanded]' ) );

		buttons.forEach( function ( button ) {
			var answer = document.getElementById( button.getAttribute( 'aria-controls' ) );

			if ( ! answer ) {
				return;
			}

			button.setAttribute( 'aria-expanded', 'false' );
			answer.hidden = true;

			button.addEventListener( 'click', function () {
				var open = 'true' === button.getAttribute( 'aria-expanded' );
				button.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				answer.hidden = open;
			} );
		} );
	}

	Array.prototype.slice.call( document.querySelectorAll( '[data-epi-steps]' ) ).forEach( steps );
	Array.prototype.slice.call( document.querySelectorAll( '[data-epi-faqs]' ) ).forEach( faqs );
}() );
