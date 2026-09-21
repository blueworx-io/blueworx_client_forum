<?php
/**
 * Plugin Name: Forum test support — gallery meta over REST
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-gallery.spec.js
 *              so a spec can set a page's image gallery the way WooCommerce sets a product's.
 *              Never shipped: tests/ is outside the release allowlist.
 */

// WooCommerce keeps a product's gallery in `_product_image_gallery`. It is
// protected meta, so REST refuses it unless someone registers it. The harness
// has no WooCommerce, so the spec stands up a page in a product's place.
add_action(
	'init',
	static function () {
		register_post_meta(
			'page',
			'_product_image_gallery',
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
);
