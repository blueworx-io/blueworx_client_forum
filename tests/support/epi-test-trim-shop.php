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
					global $wp_the_query, $wp_post_types;

					// Another spec's stand-in may have registered "product" with no archive,
					// so make sure this request has one either way.
					if ( ! post_type_exists( 'product' ) ) {
						register_post_type(
							'product',
							array(
								'public'      => true,
								'has_archive' => true,
							)
						);
					}

					$wp_post_types['product']->has_archive = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only: this request only.

					$query        = new WP_Query();
					$wp_the_query = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Makes the stand-in query the main one for this request only.

					// A theme or plugin may also set the archive page size, or turn paging off.
					$overrides = array();

					if ( null !== $request->get_param( 'archive' ) ) {
						$overrides['posts_per_archive_page'] = (int) $request->get_param( 'archive' );
					}

					if ( null !== $request->get_param( 'showposts' ) ) {
						$overrides['showposts'] = (int) $request->get_param( 'showposts' );
					}

					if ( $request->get_param( 'nopaging' ) ) {
						$overrides['nopaging'] = true;
					}

					// Running the query fires pre_get_posts, as a real page view does.
					$query->query(
						array(
							'post_type'      => 'product',
							'posts_per_page' => (int) $request->get_param( 'value' ),
						) + $overrides
					);

					return array(
						'per_page' => (int) $query->get( 'posts_per_page' ),
						'archive'  => (int) $query->get( 'posts_per_archive_page' ),
						'nopaging'  => (bool) $query->get( 'nopaging' ),
						'showposts' => (int) $query->get( 'showposts' ),
					);
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

		// What a block's own product query (Product Collection) ends up with.
		register_rest_route(
			'epi-test/v1',
			'/block-query-size',
			$open + array(
				'callback' => static function ( WP_REST_Request $request ) {
					$vars = apply_filters(
						'query_loop_block_query_vars',
						array(
							'post_type'      => (string) $request->get_param( 'type' ),
							'posts_per_page' => (int) $request->get_param( 'value' ),
						),
						null,
						1
					);

					return array( 'per_page' => (int) $vars['posts_per_page'] );
				},
			)
		);

		// What the Mini-Cart block would print, or null when it is left to render itself.
		register_rest_route(
			'epi-test/v1',
			'/mini-cart-block',
			$open + array(
				'callback' => static function () {
					return array( 'html' => apply_filters( 'pre_render_block', null, array( 'blockName' => 'woocommerce/mini-cart' ), null ) );
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
