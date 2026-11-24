<?php
/**
 * Plugin Name: Forum test support — trimmed shop requests, without WooCommerce
 * Description: Test-only. Copied into the harness's mu-plugins by tests/trim-shop-requests.spec.js.
 *              Stands in for the bits of WooCommerce the feature touches (the cart-check script,
 *              the page-size filter) and reports what the plugin does to them. Never shipped:
 *              tests/ is outside the release allowlist.
 */

// WooCommerce registers and enqueues its cart-check script on every page.
add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_register_script( 'wc-cart-fragments', 'https://example.test/cart-fragments.min.js', array(), '1', true );
		wp_enqueue_script( 'wc-cart-fragments' );
	},
	20
);

add_action(
	'rest_api_init',
	static function () {
		$open = array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
		);

		// What the shop's per-page filter gives for a requested size.
		register_rest_route(
			'epi-test/v1',
			'/per-page',
			$open + array(
				'callback' => static function ( WP_REST_Request $request ) {
					return array( 'per_page' => apply_filters( 'loop_shop_per_page', (int) $request->get_param( 'value' ) ) );
				},
			)
		);

		// What a product listing asking for a given size ends up with.
		register_rest_route(
			'epi-test/v1',
			'/listing-size',
			$open + array(
				'callback' => static function ( WP_REST_Request $request ) {
					global $wp_the_query;

					if ( ! post_type_exists( 'product' ) ) {
						register_post_type( 'product', array(
							'public'      => true,
							'has_archive' => true,
						) );
					}

					$query        = new WP_Query();
					$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes the stand-in query the main one for this request only.

					// Running the query fires pre_get_posts, as a real page view does.
					$query->query(
						array(
							'post_type'      => 'product',
							'posts_per_page' => (int) $request->get_param( 'value' ),
						)
					);

					return array( 'per_page' => (int) $query->get( 'posts_per_page' ) );
				},
			)
		);

		// WooCommerce's "quick add-to-cart" setting as the front end reads it.
		register_rest_route(
			'epi-test/v1',
			'/quick-add',
			$open + array(
				'callback' => static function () {
					return array( 'value' => get_option( 'woocommerce_enable_ajax_add_to_cart', 'no' ) );
				},
			)
		);

		// A cart check, as WooCommerce answers one.
		register_rest_route(
			'epi-test/v1',
			'/cart-check',
			$open + array(
				'callback' => static function () {
					do_action( 'wc_ajax_get_refreshed_fragments' );

					return array( 'answered_by' => 'woocommerce' );
				},
			)
		);

		// Whether the mini-cart widget would be hidden.
		register_rest_route(
			'epi-test/v1',
			'/mini-cart-hidden',
			$open + array(
				'callback' => static function () {
					return array( 'hidden' => (bool) apply_filters( 'woocommerce_widget_cart_is_hidden', false ) );
				},
			)
		);
	}
);
