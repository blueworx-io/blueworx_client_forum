<?php
/**
 * Image data provider.
 *
 * This is the SINGLE place responsible for resolving the list of image URLs
 * for a product. The product's image IDs (`_thumbnail_id` and
 * `_product_image_gallery`) are read either as ePim asset IDs, turned into
 * external ePim URLs, or as WordPress media, served from the media library.
 * A product with no images falls back to the bundled placeholder set.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and normalises product image URLs.
 *
 * @since 1.0.0
 */
class EPI_Images_Provider {

	/**
	 * Get the normalised, sanitised list of image URLs for a product.
	 *
	 * The list is in strip order: the featured image first, then the gallery
	 * images in the order they were set, so whatever sits last in the gallery
	 * (the dimensional drawing) comes last.
	 *
	 * Always returns at least one URL (falls back to placeholders) so the
	 * product page can never break because images are missing.
	 *
	 * @param int    $product_id WooCommerce product ID.
	 * @param string $source     'epim' (the IDs are ePim asset IDs) or
	 *                           'woocommerce' (the IDs are media library items).
	 * @return string[] List of validated image URLs.
	 */
	public static function get_images( $product_id, $source = 'epim' ) {
		$product_id = absint( $product_id );
		$urls       = array();

		if ( $product_id ) {
			$ids = array();

			$thumbnail_id = get_post_meta( $product_id, '_thumbnail_id', true );
			if ( $thumbnail_id ) {
				$ids[] = $thumbnail_id;
			}

			$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( ! empty( $gallery ) ) {
				$ids = array_merge( $ids, explode( ',', (string) $gallery ) );
			}

			foreach ( $ids as $id ) {
				$url = 'woocommerce' === $source ? self::media_image_url( $id ) : self::build_image_url( $id );

				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}

			$urls = array_values( array_unique( $urls ) );
		}

		// Used whenever a product has no images, so the gallery never renders empty.
		if ( empty( $urls ) ) {
			$urls = self::get_placeholder_images();
		}

		/**
		 * Allow themes/other plugins to filter the final image list.
		 *
		 * @param string[] $urls       Resolved image URLs, featured first, then the gallery.
		 * @param int      $product_id Product ID.
		 * @param string   $source     'epim' or 'woocommerce'.
		 */
		return apply_filters( 'epi_product_images', $urls, $product_id, $source );
	}

	/**
	 * Build the external ePim asset URL for a single image ID.
	 *
	 * @param int|string $id ePim asset ID (stored in product meta).
	 * @return string Escaped URL, or '' when the ID is empty/invalid.
	 */
	public static function build_image_url( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return '';
		}

		$url = EPI_EPIM_IMAGE_BASE . $id . '.jpg';

		/**
		 * Filter the constructed ePim image URL (e.g. to change host or extension).
		 *
		 * @param string $url Constructed URL.
		 * @param int    $id  ePim asset ID.
		 */
		$url = apply_filters( 'epi_epim_image_url', $url, $id );

		return esc_url_raw( $url );
	}

	/**
	 * The media library URL for a single attachment ID.
	 *
	 * @param int|string $id Attachment ID (stored in product meta).
	 * @return string Escaped URL, or '' when the ID is not an image.
	 */
	public static function media_image_url( $id ) {
		$id = absint( $id );

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $id, 'large' );

		return $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * First bundled placeholder, used as the <img> onerror fallback.
	 *
	 * @return string
	 */
	public static function fallback_image_url() {
		$images = self::get_placeholder_images();

		return ! empty( $images ) ? $images[0] : '';
	}

	/**
	 * The bundled placeholder set, shown when a product has no images.
	 *
	 * A fixed set of lighting-product placeholder images bundled with the
	 * plugin (assets/images/*.svg). Fully self-contained — no external
	 * service, so they can never break or fail to load.
	 *
	 * @return string[]
	 */
	public static function get_placeholder_images() {
		$base = EPI_PLUGIN_URL . 'assets/images/';

		$images = array(
			$base . 'epi-edison-bulb.svg',
			$base . 'epi-pendant-light.svg',
			$base . 'epi-floor-lamp.svg',
			$base . 'epi-table-lamp.svg',
			$base . 'epi-chandelier.svg',
			$base . 'epi-wall-sconce.svg',
		);

		// Sanitise everything before returning.
		return array_values(
			array_filter(
				array_map( 'esc_url_raw', $images )
			)
		);
	}
}
