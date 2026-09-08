<?php
/**
 * Frontend: Generate Video Preview Button
 *
 * This snippet generates a button that allows users to view the products video.
 * It also hides the video attribute from the WooCommerce attributes list.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// === Dynamic Product Video Instructions Button Shortcode ===
add_shortcode(
	'product_video_button',
	function () {
		if ( ! is_product() ) {
			return '';
		}

		global $product;
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$video_url = '';

		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( $key === 'pa_video' || strtolower( $attr->get_name() ) === 'video' ) {
				$values = $attr->get_options();

				foreach ( $values as $val_id ) {
					$term = get_term( $val_id );

					if ( $term && ! is_wp_error( $term ) ) {
						$video_url = trim( $term->name );
						break;
					}
				}
			}
		}

		if ( empty( $video_url ) || ! filter_var( $video_url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		return '
    <div id="primary-custom-button" class="elementor-button-wrapper">
        <a href="' . esc_url( $video_url ) . '" class="elementor-button elementor-size-sm elementor-animation-flip" target="_blank" rel="noopener">
            <span class="elementor-button-content-wrapper">
                <span class="ui-btn-anim-wrapp">
                    <span class="elementor-button-text">VIDEO</span>
                    <span class="elementor-button-text">VIDEO</span>
                </span>
            </span>
        </a>
    </div>';
	}
);

// Hide Video from WooCommerce attributes list
add_filter(
	'woocommerce_display_product_attributes',
	function ( $attributes, $product ) {
		foreach ( $attributes as $key => $attribute ) {
			if (
			$key === 'pa_video' ||
			strtolower( $attribute['label'] ) === 'video'
			) {
				unset( $attributes[ $key ] );
			}
		}

		return $attributes;
	},
	10,
	2
);
