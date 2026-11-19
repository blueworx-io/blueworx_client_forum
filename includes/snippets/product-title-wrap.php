<?php
/**
 * Frontend: Wrap long product names inside their card
 *
 * Some product names from ePim have long runs with no spaces, such as
 * "StriptoWiretoStripConnector/15cm". The browser won't break those on its
 * own, so on shop, category and search cards they ran out of the card and over
 * the next one. Let them break where they have to; ordinary names still wrap
 * between words as before.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print the wrapping rule for product card names.
 *
 * @return void
 */
function epi_product_title_wrap_styles() {
	echo '<style id="epi-product-title-wrap">'
		. '.woocommerce-loop-product__title,'
		. '.wc-block-components-product-name,'
		. '.wc-block-grid__product-title'
		. '{overflow-wrap:anywhere;}'
		. '</style>' . "\n";
}

add_action( 'wp_head', 'epi_product_title_wrap_styles', 20 );
