<?php
/**
 * Plugin Name: Forum test support — products with a SKU, without WooCommerce
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-search.spec.js
 *              so a spec can make a searchable product with a SKU. Never shipped: tests/ is
 *              outside the release allowlist.
 */

// The harness has no WooCommerce, so there is no product post type. Register a
// bare one, searchable like WooCommerce's, so the site search has products to find.
add_action(
	'init',
	static function () {
		if ( post_type_exists( 'product' ) ) {
			return;
		}

		register_post_type(
			'product',
			array(
				'public'              => true,
				'exclude_from_search' => false,
				'label'               => 'Products',
			)
		);
	}
);

// POST epi-test/v1/sku-product { title, sku } makes a published product whose
// SKU is stored where WooCommerce keeps it.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'epi-test/v1',
			'/sku-product',
			array(
				'methods'             => 'POST',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = wp_insert_post(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'post_title'  => (string) $request->get_param( 'title' ),
						)
					);

					update_post_meta( $id, '_sku', (string) $request->get_param( 'sku' ) );

					return array( 'id' => $id );
				},
			)
		);
	}
);
