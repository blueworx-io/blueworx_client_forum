<?php
/**
 * Frontend: Create Fitting Instruction Url
 *
 * Build a dynamic url to show product fitting sheet.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// === Dynamic Fitting Instructions Button Shortcode ===
add_shortcode(
	'fitting_instructions_button',
	function () {
		if ( ! is_product() ) {
			return ''; // only on single product pages
		}

		global $product;
		if ( ! $product ) {
			return '';
		}

		// get SKU
		$sku = $product->get_sku();
		if ( empty( $sku ) ) {
			return '';
		}

		// build final URL (SKU + "ins.pdf")
		$url = sprintf(
			'https://dam.epim.online/forumlightingsolutions-fa30efa8-a381-454b-bacc-98b71331f5a2/Fitting%%20Instructions/%sins.pdf',
			$sku
		);

		// check if the file exists remotely
		$response = wp_remote_head( $url );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return ''; // donât render if file missing or unreachable
		}

		// return Elementor-compatible button HTML (unchanged)
		return '
    <div id="primary-custom-button" class="elementor-button-wrapper">
        <a href="' . esc_url( $url ) . '" class="elementor-button elementor-size-sm elementor-animation-flip" target="_blank" rel="noopener">
            <span class="elementor-button-content-wrapper">
                <span class="ui-btn-anim-wrapp">
                    <span class="elementor-button-text">FITTING INSTRUCTIONS</span>
                    <span class="elementor-button-text">FITTING INSTRUCTIONS</span>
                </span>
            </span>
        </a>
    </div>';
	}
);
