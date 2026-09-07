<?php
/**
 * Frontend: Keep product breadcrumbs on the main category path.
 *
 * WooCommerce ignores the order categories are assigned in and picks whichever
 * one sits deepest in the category tree. "Featured Products" hangs off its own
 * top-level parent, so it wins over the real product category and the
 * breadcrumb reads Home / Featured Product - Website / Featured Products.
 *
 * This picks the first category that is not in the excluded tree instead.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'woocommerce_breadcrumb_main_term',
	/**
	 * Skip the "Featured" tree when choosing the breadcrumb category.
	 *
	 * @param WP_Term   $main_term Term WooCommerce chose.
	 * @param WP_Term[] $terms     All product categories on the product.
	 * @return WP_Term
	 */
	function ( $main_term, $terms ) {
		if ( empty( $terms ) || ! is_array( $terms ) ) {
			return $main_term;
		}

		/**
		 * Category slugs whose trees never appear in a breadcrumb.
		 *
		 * @param string[] $slugs Excluded root category slugs.
		 */
		$excluded_slugs = apply_filters(
			'epi_breadcrumb_excluded_category_slugs',
			array( 'featured-product-website-2' )
		);

		$excluded_ids = array();

		foreach ( $excluded_slugs as $slug ) {
			$root = get_term_by( 'slug', $slug, 'product_cat' );

			if ( $root instanceof WP_Term ) {
				$excluded_ids[] = $root->term_id;
			}
		}

		if ( empty( $excluded_ids ) ) {
			return $main_term;
		}

		foreach ( $terms as $term ) {
			$is_excluded = false;

			foreach ( $excluded_ids as $excluded_id ) {
				if ( $term->term_id === $excluded_id || term_is_ancestor_of( $excluded_id, $term->term_id, 'product_cat' ) ) {
					$is_excluded = true;
					break;
				}
			}

			if ( ! $is_excluded ) {
				return $term;
			}
		}

		// Product is only in an excluded tree - leave WooCommerce's choice alone.
		return $main_term;
	},
	10,
	2
);
