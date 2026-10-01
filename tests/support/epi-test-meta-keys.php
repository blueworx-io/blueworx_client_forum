<?php
/**
 * Plugin Name: Forum test support — product meta key check over REST
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-meta-keys.spec.js
 *              so a spec can ask which fields the change log and meta viewer treat as product data.
 *              Never shipped: tests/ is outside the release allowlist.
 */

// The harness has no WooCommerce, so the plugin never loads its product
// classes. The key check is a plain function of the key, so load the class
// directly and answer for each key the spec sends.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'epi-test/v1',
			'/meta-keys',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! class_exists( 'EPI_Product_Meta' ) ) {
						require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-meta.php';
					}

					$result = array();

					foreach ( (array) $request->get_param( 'keys' ) as $key ) {
						$key            = (string) $key;
						$result[ $key ] = EPI_Product_Meta::is_product_meta_key( $key );
					}

					return $result;
				},
			)
		);
	}
);
