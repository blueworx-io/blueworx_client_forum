<?php
/**
 * Frontend: Show the SKU on shop and category cards
 *
 * Prints "SKU: 12345678" under the product name on every shop, category and
 * search listing card. ePim's own helper plugin (ePim Default Site
 * Modifications) used to do this; it is switched off now that products come
 * from the plugin's own pull, so the line is printed here instead, with the
 * same markup the site already styles (it takes the brand red from the card
 * link it sits in).
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print the SKU line on a listing card. A product without a SKU gets nothing,
 * rather than an empty "SKU:".
 *
 * @return void
 */
function epi_shop_card_sku() {
	global $product;

	// ePim's plugin, if someone switches it back on, prints its own line.
	if ( ! $product instanceof WC_Product || function_exists( 'epsm_display_sku' ) ) {
		return;
	}

	$sku = trim( (string) $product->get_sku() );

	if ( '' === $sku ) {
		return;
	}

	echo '<div class="product-sku">' . sprintf(
		/* translators: %s: product SKU. */
		esc_html__( 'SKU: %s', 'blueworx_client_forum' ),
		esc_html( $sku )
	) . '</div>';
}

add_action( 'woocommerce_after_shop_loop_item_title', 'epi_shop_card_sku', 10 );
