<?php
/**
 * Frontend: Show SKU instead of description in search results
 *
 * The theme's search cards print the product short description under the
 * title. Swap that for the product SKU, styled to match the "SKU: 12345678"
 * line on the shop archive cards. Products without a SKU show nothing rather
 * than falling back to the description.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replace the excerpt on search-result product cards with the SKU.
 *
 * @param string       $excerpt Excerpt text.
 * @param WP_Post|null $post    Post object, when the filter supplies one.
 * @return string
 */
function epi_search_result_sku_excerpt( $excerpt, $post = null ) {
	if ( is_admin() || ! is_search() || ! function_exists( 'wc_get_product' ) ) {
		return $excerpt;
	}

	$post = $post ? get_post( $post ) : get_post();

	if ( ! $post || 'product' !== $post->post_type ) {
		return $excerpt;
	}

	$product = wc_get_product( $post->ID );
	$sku     = $product ? trim( (string) $product->get_sku() ) : '';

	if ( '' === $sku ) {
		return '';
	}

	return '<span class="product-sku epi-search-sku">' . sprintf(
		/* translators: %s: product SKU. */
		esc_html__( 'SKU: %s', 'blueworx_client_forum' ),
		esc_html( $sku )
	) . '</span>';
}

add_filter( 'get_the_excerpt', 'epi_search_result_sku_excerpt', 20, 2 );
add_filter( 'the_excerpt', 'epi_search_result_sku_excerpt', 20 );

/**
 * Match the shop archive's SKU colour on the search cards.
 *
 * The archive line inherits the brand red from its card link; the search card
 * sits in a different wrapper, so state it here.
 *
 * @return void
 */
function epi_search_result_sku_styles() {
	if ( ! is_search() ) {
		return;
	}

	echo '<style id="epi-search-sku">.epi-search-sku{color:#a71f31;}</style>' . "\n";
}

add_action( 'wp_head', 'epi_search_result_sku_styles', 20 );
