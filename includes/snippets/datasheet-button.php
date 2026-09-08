<?php
/**
 * Frontend: Create Data Sheet Url
 *
 * Build a dynamic url to show product data sheet.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// === Dynamic Datasheet (non-generate) Button Shortcode ===
add_shortcode(
	'datasheet_button_alt',
	function () {
		if ( ! is_product() ) {
			return '';
		}

		global $product;
		if ( ! $product ) {
			return '';
		}

		$sku = $product->get_sku();
		if ( ! $sku ) {
			return '';
		}

		// Build datasheet URL
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- A query-string value: urlencode() is the right encoding here, and the datasheet service expects it.
		$url = 'https://forum-datasheets.epim.online/Datasheet?part=' . urlencode( $sku ) . '&template=ForumLightingDatasheet';

		// Output Elementor-style button with unique ID
		return '
    <div id="secondary-custom-button" class="elementor-button-wrapper">
        <a href="' . esc_url( $url ) . '" class="elementor-button elementor-size-sm elementor-animation-flip" target="_blank" rel="noopener">
            <span class="elementor-button-content-wrapper">
                <span class="ui-btn-anim-wrapp">
                    <span class="elementor-button-text">SPEC SHEET</span>
                    <span class="elementor-button-text">SPEC SHEET</span>
                </span>
            </span>
        </a>
    </div>';
	}
);
