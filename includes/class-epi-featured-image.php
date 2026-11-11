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

		// The cart and checkout pages take their pictures from WooCommerce's
		// cart, which reads the media library and never calls the filter above.
		if ( ! has_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'filter_cart_item_images' ) ) ) {
			add_filter( 'woocommerce_store_api_cart_item_images', array( __CLASS__, 'filter_cart_item_images' ), 10, 2 );
		}
	}

	/**
	 * Replace a cart item's pictures with its ePim ones.
	 *
	 * @param array $images    Image objects WooCommerce found in the media library.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function filter_cart_item_images( $images, $cart_item ) {
		$product_id = is_array( $cart_item ) && isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;

		if ( ! $product_id || ( ! self::$all && ! EPI_Images_Provider::uses_epim( $product_id ) ) ) {
			return $images;
		}

		$ids     = array( get_post_meta( $product_id, '_thumbnail_id', true ) );
		$gallery = (string) get_post_meta( $product_id, '_product_image_gallery', true );

		if ( '' !== $gallery ) {
			$ids = array_merge( $ids, explode( ',', $gallery ) );
		}

		$title = get_the_title( $product_id );
		$epim  = array();

		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			$url = EPI_Images_Provider::build_image_url( $id );

			if ( '' === $url ) {
				continue;
			}

			// The shape WooCommerce's cart expects of an image.
			$epim[] = (object) array(
				'id'        => $id,
				'src'       => $url,
				'thumbnail' => $url,
				'srcset'    => '',
				'sizes'     => '',
				'name'      => $title,
				'alt'       => $title,
			);
		}

		return $epim ? $epim : $images;
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
