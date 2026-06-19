<?php
/**
 * Image data provider.
 *
 * This is the SINGLE place responsible for resolving the list of image URLs
 * for a product. Right now it returns placeholder images. When the product
 * meta is ready, the only thing you need to change lives in this file
 * (see the clearly marked section in get_images()).
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
	 * ============================================================
	 *  FUTURE PRODUCT META INTEGRATION — CHANGE THIS WHEN READY
	 * ============================================================
	 *
	 * The meta key that will hold the external image URLs.
	 *
	 * The stored value may be EITHER:
	 *   - a JSON array of URLs:        ["https://…/a.jpg","https://…/b.jpg"]
	 *   - a comma-separated list:      https://…/a.jpg, https://…/b.jpg
	 *
	 * Both formats are handled automatically by parse_meta_value().
	 *
	 * @var string
	 */
	const META_KEY = '_epi_external_image_urls';

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
		 *  STEP 1 — READ FROM PRODUCT META  (currently DISABLED)
		 * ------------------------------------------------------------------
		 *
		 * When the meta is populated, uncomment the block below. It reads the
		 * raw meta value and normalises it (JSON array OR comma-separated)
		 * into a clean array of sanitised URLs.
		 *
		 * No other change is required anywhere else in the plugin.
		 */
		/*
		if ( $product_id ) {
			$raw  = get_post_meta( $product_id, self::META_KEY, true );
			$urls = self::parse_meta_value( $raw );
		}
		*/

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
	 * Normalise a raw meta value into a clean array of URLs.
	 *
	 * Accepts a JSON array string or a comma-separated string. Every URL is
	 * validated and sanitised, satisfying requirement #20.
	 *
	 * @param mixed $raw Raw meta value.
	 * @return string[] Sanitised URLs.
	 */
	public static function parse_meta_value( $raw ) {
		if ( empty( $raw ) ) {
			return array();
		}

		// If it's already an array (e.g. stored as array meta), use it directly.
		if ( is_array( $raw ) ) {
			$candidates = $raw;
		} else {
			$raw        = trim( (string) $raw );
			$candidates = array();

			// Try JSON array first.
			$decoded = json_decode( $raw, true );

			if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
				$candidates = $decoded;
			} else {
				// Fall back to comma-separated list.
				$candidates = explode( ',', $raw );
			}
		}

		$urls = array();

		foreach ( $candidates as $candidate ) {
			$url = esc_url_raw( trim( (string) $candidate ) );

			// Keep only well-formed http(s) URLs.
			if ( $url && preg_match( '#^https?://#i', $url ) ) {
				$urls[] = $url;
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Demo image set used until product meta is wired up.
	 *
	 * ============================================================
	 *  DEMO IMAGES — temporary, shown on EVERY product
	 * ============================================================
	 *
	 * A fixed set of 7 real photos (Picsum, free stock service). The same set
	 * is shown for all products on purpose — this is purely for demoing the
	 * gallery layout/behaviour while the real product data is prepared.
	 *
	 * Fixed seeds keep each image stable across page loads (no random swapping
	 * on refresh). To swap in your own demo images, just replace the URLs in
	 * the array below. When the real data feed is ready, enable STEP 1 in
	 * get_images() and these are automatically used only as a fallback.
	 *
	 * @return string[]
	 */
	public static function get_placeholder_images() {
		$images = array(
			'https://picsum.photos/seed/epi-product-1/900/900',
			'https://picsum.photos/seed/epi-product-2/900/900',
			'https://picsum.photos/seed/epi-product-3/900/900',
			'https://picsum.photos/seed/epi-product-4/900/900',
			'https://picsum.photos/seed/epi-product-5/900/900',
			'https://picsum.photos/seed/epi-product-6/900/900',
			'https://picsum.photos/seed/epi-product-7/900/900',
		);

		// Sanitise everything before returning.
		return array_values(
			array_filter(
				array_map( 'esc_url_raw', $images )
			)
		);
	}
}
