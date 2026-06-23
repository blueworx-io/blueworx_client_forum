/**
 * BlueWorx Lab control page.
 *
 * Confirms before a feature is switched off. Dangerous features supply their
 * own sterner message via data-danger-message; everything else uses a generic
 * confirmation. If the user cancels, the switch is restored to "on".
 */
( function () {
	'use strict';

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
		var toggles = document.querySelectorAll( '[data-epi-toggle]' );

		Array.prototype.forEach.call( toggles, function ( toggle ) {
			toggle.addEventListener( 'change', onToggle );
		} );
	} );
} )();
