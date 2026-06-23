<?php
/**
 * Image data provider.
 *
 * This is the SINGLE place responsible for resolving the list of image URLs
 * for a product. It builds external ePim asset URLs from the product's image
 * IDs (`_thumbnail_id` + `_product_image_gallery`) and falls back to the
 * bundled placeholder set when a product has no IDs.
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
	 * Always returns at least one URL (falls back to placeholders) so the
	 * product page can never break because images are missing.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string[] List of validated image URLs.
	 */
	public static function get_images( $product_id ) {
		$product_id = absint( $product_id );
		$urls       = array();

		/**
		 * ------------------------------------------------------------------
		 *  STEP 1 — BUILD URLS FROM ePim IMAGE IDS
		 * ------------------------------------------------------------------
		 *
		 * Featured image (`_thumbnail_id`) first, then the gallery IDs
		 * (`_product_image_gallery`, comma-separated). These are ePim asset
		 * IDs, not WordPress attachments — we construct external URLs and
		 * never import anything into the media library.
		 */
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
				$url = self::build_image_url( $id );

				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}

			$urls = array_values( array_unique( $urls ) );
		}

		/**
		 * ------------------------------------------------------------------
		 *  STEP 2 — FALLBACK TO PLACEHOLDERS
		 * ------------------------------------------------------------------
		 *
		 * Used now (no meta yet) and forever as a safety net when a product
		 * has no external images. This keeps requirement #13/#14 satisfied.
		 */
		if ( empty( $urls ) ) {
			$urls = self::get_placeholder_images();
		}

		/**
		 * Allow themes/other plugins to filter the final image list.
		 *
		 * @param string[] $urls       Resolved image URLs.
		 * @param int      $product_id Product ID.
		 */
		return apply_filters( 'epi_product_images', $urls, $product_id );
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
	 * First bundled placeholder, used as the <img> onerror fallback.
	 *
	 * @return string
	 */
	public static function fallback_image_url() {
		$images = self::get_placeholder_images();

		return ! empty( $images ) ? $images[0] : '';
	}

	/**
	 * Demo image set used until product meta is wired up.
	 *
	 * ============================================================
	 *  DEMO IMAGES — temporary, shown on EVERY product
	 * ============================================================
	 *
	 * A fixed set of lighting-product placeholder images bundled with the
	 * plugin (assets/images/*.svg). Themed for a wholesale lighting supplier
	 * and fully self-contained — no external service, so they can never break
	 * or fail to load. The same set is shown for all products on purpose: this
	 * is purely for demoing the gallery layout/behaviour while the real product
	 * data is prepared.
	 *
	 * To swap in your own demo images, drop files into assets/images/ and
	 * replace the file names below. When the real data feed is ready, enable
	 * STEP 1 in get_images() and these are automatically used only as a
	 * fallback.
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
