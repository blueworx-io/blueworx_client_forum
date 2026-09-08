/**
 * BlueWorx Lab control page.
 *
 * Two jobs:
 *
 * 1. Switching sections. Every panel is in the page at once, so a hidden panel
 *    still posts its switches and one Save covers the whole screen however the
 *    nav is left.
 * 2. Confirming before a feature is switched off. Dangerous features supply
 *    their own sterner message via data-danger-message; everything else uses a
 *    generic confirmation. If the user cancels, the switch is restored to "on".
 */
( function () {
	'use strict';

	function showSection( key ) {
		var links = document.querySelectorAll( '[data-epi-section-link]' );
		var panels = document.querySelectorAll( '[data-epi-section]' );

		Array.prototype.forEach.call( links, function ( link ) {
			var active = link.getAttribute( 'data-epi-section-link' ) === key;

			link.classList.toggle( 'is-active', active );
			if ( active ) {
				link.setAttribute( 'aria-current', 'true' );
			} else {
				link.removeAttribute( 'aria-current' );
			}
		} );

		Array.prototype.forEach.call( panels, function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-epi-section' ) !== key;
		} );
	}

	function confirmOff( toggle ) {
		var title = toggle.getAttribute( 'data-feature-title' ) || 'this feature';
		var danger = toggle.getAttribute( 'data-danger-message' );
		var message = danger
			? danger
			: 'Switch off "' + title + '"? It will stop running until you turn it back on.';

		return window.confirm( message );
	}

	function onToggle( event ) {
		var toggle = event.target;

		// Only act when a switch is being turned OFF.
		if ( toggle.checked ) {
			return;
		}

		if ( ! confirmOff( toggle ) ) {
			// User cancelled - keep the feature on.
			toggle.checked = true;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var links = document.querySelectorAll( '[data-epi-section-link]' );
		var toggles = document.querySelectorAll( '[data-epi-toggle]' );

		Array.prototype.forEach.call( links, function ( link ) {
			link.addEventListener( 'click', function () {
				showSection( link.getAttribute( 'data-epi-section-link' ) );
			} );
		} );

		Array.prototype.forEach.call( toggles, function ( toggle ) {
			toggle.addEventListener( 'change', onToggle );
		} );
	} );
} )();
