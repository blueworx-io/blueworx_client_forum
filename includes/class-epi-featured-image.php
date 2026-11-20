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

		self::init_media_lookup();
	}

	/**
	 * Page builder cards (Elementor's included) and themes ask the media
	 * library for a product's featured image directly. Needs no WooCommerce,
	 * so it runs whatever is installed.
	 *
	 * @return void
	 */
	public static function init_media_lookup() {
		if ( ! has_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_attachment_image_src' ) ) ) {
			add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_attachment_image_src' ), 10, 2 );
		}

		if ( ! has_filter( 'elementor/image_size/get_attachment_image_html', array( __CLASS__, 'filter_elementor_image_html' ) ) ) {
			add_filter( 'elementor/image_size/get_attachment_image_html', array( __CLASS__, 'filter_elementor_image_html' ) );
		}
	}

	/**
	 * Name a switched product's ePim picture after the product on Elementor's
	 * cards.
	 *
	 * Elementor takes a picture's text from the media library. An ePim picture
	 * is not in it, so Elementor falls back to the picture number for screen
	 * readers and to the page's own name for the tooltip.
	 *
	 * @param string $html Elementor's <img> markup.
	 * @return string
	 */
	public static function filter_elementor_image_html( $html ) {
		$post_id = get_the_ID();

		if ( ! $post_id || ! is_string( $html ) || false === strpos( $html, EPI_EPIM_IMAGE_BASE ) ) {
			return $html;
		}

		if ( ! EPI_Images_Provider::uses_epim( $post_id ) && ! ( self::$all && 'product' === get_post_type( $post_id ) ) ) {
			return $html;
		}

		$name = esc_attr( wp_strip_all_tags( get_the_title( $post_id ) ) );

		if ( '' === $name ) {
			return $html;
		}

		// Only <img> tags showing an ePim picture are renamed.
		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $tag ) use ( $name ) {
				if ( false === strpos( $tag[0], EPI_EPIM_IMAGE_BASE ) ) {
					return $tag[0];
				}

				foreach ( array( 'alt', 'title' ) as $attribute ) {
					$pattern = '/\s' . $attribute . '="[^"]*"/i';
					// Escaped so a "$" or "\" in a name is not read as a replacement token.
					$value  = ' ' . $attribute . '="' . addcslashes( $name, '\\$' ) . '"';
					$tag[0] = preg_match( $pattern, $tag[0] )
						? preg_replace( $pattern, $value, $tag[0], 1 )
						: preg_replace( '/^<img\b/i', '<img' . $value, $tag[0], 1 );
				}

				return $tag[0];
			},
			$html
		);
	}

	/**
	 * Answer a media library lookup of the product on show's own picture
	 * number with its ePim picture.
	 *
	 * Only that product's own numbers are answered, so the rest of the media
	 * library is untouched. Within it, the number is ePim's even when it
	 * happens to match an unrelated media item.
	 *
	 * @param array|false $image         [ url, width, height, resized ], or false.
	 * @param int         $attachment_id The number asked for.
	 * @return array|false
	 */
	public static function filter_attachment_image_src( $image, $attachment_id ) {
		$post_id = get_the_ID();

		if ( ! $post_id ) {
			return $image;
		}

		$switched = EPI_Images_Provider::uses_epim( $post_id ) || ( self::$all && 'product' === get_post_type( $post_id ) );

		if ( ! $switched ) {
			return $image;
		}

		$ids = array_merge(
			array( get_post_meta( $post_id, '_thumbnail_id', true ) ),
			explode( ',', (string) get_post_meta( $post_id, '_product_image_gallery', true ) )
		);

		if ( ! in_array( absint( $attachment_id ), array_map( 'absint', $ids ), true ) ) {
			return $image;
		}

		$url = EPI_Images_Provider::build_image_url( $attachment_id );

		// ePim's standard picture is 300px square at most.
		return '' === $url ? $image : array( $url, 300, 300, false );
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
			'<img src="%1$s" alt="%2$s" class="%3$s"%4$s loading="lazy" decoding="async" onerror="%5$s" />',
			esc_url( $url ),
			esc_attr( $product->get_title() ),
			esc_attr( implode( ' ', $classes ) ),
			self::size_attributes( $size ),
			esc_attr( $on_error )
		);
	}

	/**
	 * Width and height for a size asked for in pixels, such as the small
	 * picture in WooCommerce's order emails. Without them an email shows the
	 * ePim picture at its full size, since email apps ignore the site's styles.
	 *
	 * @param mixed $size Requested image size (string or array).
	 * @return string
	 */
	private static function size_attributes( $size ) {
		if ( ! is_array( $size ) || ! isset( $size[0], $size[1] ) ) {
			return '';
		}

		$width  = absint( $size[0] );
		$height = absint( $size[1] );

		if ( ! $width || ! $height ) {
			return '';
		}

		return sprintf(
			' width="%1$d" height="%2$d" style="width:%1$dpx;height:%2$dpx;max-width:%1$dpx;object-fit:contain;"',
			$width,
			$height
		);
	}
}
