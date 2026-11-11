<?php
/**
 * Site-wide WooCommerce featured image, sourced from ePim.
 *
 * Swaps the product featured image used on shop/category grids, related &
 * upsell products, cart, checkout and search results for the external ePim
 * asset URL built from the product's image ID (`_thumbnail_id`).
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the WooCommerce product image HTML.
 *
 * @since 1.2.0
 */
final class EPI_Featured_Image {

	/**
	 * Whether every product's image is swapped (the feature is switched on),
	 * or only those the ePim pull has given ePim pictures.
	 *
	 * @var bool
	 */
	private static $all = false;

	/**
	 * Register hooks: the feature is on, so every product is swapped.
	 *
	 * @return void
	 */
	public static function init() {
		self::$all = true;
		self::init_marked();
	}

	/**
	 * Register hooks for the products the ePim pull has switched. Runs
	 * whether or not the feature is on: such a product holds ePim picture
	 * numbers, which WooCommerce would otherwise look up in the media library.
	 *
	 * @return void
	 */
	public static function init_marked() {
		if ( ! has_filter( 'woocommerce_product_get_image', array( __CLASS__, 'filter_product_image' ) ) ) {
			add_filter( 'woocommerce_product_get_image', array( __CLASS__, 'filter_product_image' ), 10, 4 );
		}
	}

	/**
	 * Replace the product image HTML with an ePim-sourced <img>.
	 *
	 * @param string     $html    Original image HTML.
	 * @param WC_Product $product Product object.
	 * @param mixed      $size    Requested image size (string or array).
	 * @param array      $attr    Image attributes.
	 * @return string
	 */
	public static function filter_product_image( $html, $product, $size, $attr ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		if ( ! self::$all && ! EPI_Images_Provider::uses_epim( $product->get_id() ) ) {
			return $html;
		}

		$image_id = $product->get_image_id();

		if ( ! $image_id ) {
			return $html; // No featured image: leave WooCommerce's placeholder.
		}

		$url = EPI_Images_Provider::build_image_url( $image_id );

		if ( '' === $url ) {
			return $html;
		}

		$size_name = is_string( $size ) ? $size : 'woocommerce_thumbnail';
		$classes   = array( 'wp-post-image', 'attachment-' . $size_name, 'size-' . $size_name, 'epi-epim-image' );

		if ( ! empty( $attr['class'] ) ) {
			$classes[] = $attr['class'];
		}

		$fallback = EPI_Images_Provider::fallback_image_url();
		$on_error = $fallback ? "this.onerror=null;this.src='" . $fallback . "';" : '';

		return sprintf(
			'<img src="%1$s" alt="%2$s" class="%3$s" loading="lazy" decoding="async" onerror="%4$s" />',
			esc_url( $url ),
			esc_attr( $product->get_title() ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $on_error )
		);
	}
}
