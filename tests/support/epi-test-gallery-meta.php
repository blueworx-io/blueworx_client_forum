<?php
/**
 * Plugin Name: Forum test support — gallery meta over REST
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-gallery.spec.js
 *              so a spec can set a page's image gallery the way WooCommerce sets a product's.
 *              Never shipped: tests/ is outside the release allowlist.
 */

// [epi_test_featured] asks for the page's featured image the way Elementor's
// product card does: the picture address first, then the <img> itself.
add_shortcode(
	'epi_test_featured',
	static function () {
		$id  = get_post_thumbnail_id();
		$src = wp_get_attachment_image_src( $id, 'full' );

		return sprintf(
			'<p class="epi-test-featured-src">%1$s</p><div class="epi-test-featured-image">%2$s</div>',
			$src ? esc_url( $src[0] ) : 'none',
			wp_get_attachment_image( $id, 'medium' )
		);
	}
);

// WooCommerce keeps a product's gallery in `_product_image_gallery`. It is
// protected meta, so REST refuses it unless someone registers it. The harness
// has no WooCommerce, so the spec stands up a page in a product's place.
add_action(
	'init',
	static function () {
		// `_epim_pictures` is the mark the ePim pull leaves on a product it has
		// given ePim pictures; a spec sets it the same way.
		// `_thumbnail_id` lets a spec give a page an ePim picture number as its
		// featured image, which REST's featured_media refuses as no media item.
		foreach ( array( '_product_image_gallery', '_epim_pictures', '_thumbnail_id' ) as $key ) {
			register_post_meta(
				'page',
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static function () {
						return current_user_can( 'edit_pages' );
					},
				)
			);
		}
	}
);
