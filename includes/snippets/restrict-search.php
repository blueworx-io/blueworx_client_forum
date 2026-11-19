<?php
/**
 * Frontend: Restrict Search to Products & Sku Only
 *
 * Ensure other post types are not included in the global search results and display Sku.
 *
 * Moved verbatim from the Code Snippets plugin. The SKU search below was added
 * afterwards.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
			// Restrict to WooCommerce products
			$query->set( 'post_type', array( 'product' ) );

			// Preserve sorting and pagination. These are read-only, bookmarkable query vars
			// (no form submission), so nonce verification does not apply.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			if ( isset( $_GET['orderby'] ) ) {
				$query->set( 'orderby', sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) );
			}
			if ( isset( $_GET['order'] ) ) {
				$query->set( 'order', sanitize_text_field( wp_unslash( $_GET['order'] ) ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}
	}
);

/**
 * Let the site search find a product by its SKU.
 *
 * WordPress only searches names and descriptions, and most of Forum's product
 * names don't hold the code, so a code search came back empty. Spaces are
 * ignored on both sides, because some SKUs arrive from ePim with a stray space
 * in them ("VSC-45577- SBRS"). A variation's SKU finds its parent product.
 *
 * @param string   $search The search part of the WHERE clause.
 * @param WP_Query $query  The query being run.
 * @return string
 */
function epi_search_by_sku( $search, $query ) {
	if ( '' === trim( $search ) || is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return $search;
	}

	$term = preg_replace( '/\s+/', '', (string) $query->get( 's' ) );

	// Too short to be a code; matching it would pull in half the shop.
	if ( strlen( $term ) < 3 ) {
		return $search;
	}

	global $wpdb;

	$like = '%' . $wpdb->esc_like( $term ) . '%';

	$sku_match = $wpdb->prepare(
		"{$wpdb->posts}.ID IN (
			SELECT IF( sku_post.post_parent > 0, sku_post.post_parent, sku_post.ID )
			FROM {$wpdb->postmeta} AS sku_meta
			INNER JOIN {$wpdb->posts} AS sku_post ON sku_post.ID = sku_meta.post_id
			WHERE sku_meta.meta_key = '_sku'
			AND REPLACE( sku_meta.meta_value, ' ', '' ) LIKE %s
		)",
		$like
	);

	// WordPress hands over " AND (name matches) [AND (no password)]". Keep all
	// of that as one side, and let a SKU match stand in for it.
	$rest = preg_replace( '/^\s*AND\s+/i', '', $search );

	return " AND ( {$sku_match} OR ( {$rest} ) ) ";
}

add_filter( 'posts_search', 'epi_search_by_sku', 10, 2 );
