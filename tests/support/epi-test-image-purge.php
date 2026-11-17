<?php
/**
 * Plugin Name: Forum test support — image purge
 * Description: Test-only. Copied into the harness's mu-plugins by tests/image-purge.spec.js.
 *              Stands in for WooCommerce's product type, loads the purge tool, makes every scan
 *              step as short as it can be, and sets up the images a spec checks.
 *              Never shipped: tests/ is outside the release allowlist.
 */

// CI's harness has no WooCommerce. The purge tool only needs posts of type
// "product", so a plain post type stands in.
add_action(
	'init',
	static function () {
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'label'    => 'Products',
					'public'   => false,
					'show_ui'  => true,
					'supports' => array( 'title', 'custom-fields' ),
				)
			);
		}

		// The tool sits behind WooCommerce and an off-by-default switch. Load
		// it the way the plugin does when both are on.
		if ( defined( 'EPI_PLUGIN_DIR' ) && ! class_exists( 'EPI_One_Time_WC_Product_Image_Purge' ) ) {
			require_once EPI_PLUGIN_DIR . 'includes/snippets/class-epi-one-time-wc-product-image-purge.php';
		}
	},
	99
);

// Short steps, so even the harness's small site takes more than one step to
// scan, without making the spec slow.
add_filter(
	'epi_image_purge_step_seconds',
	static function () {
		return 1;
	}
);

add_action(
	'rest_api_init',
	static function () {
		$admin_only = static function () {
			return current_user_can( 'manage_options' );
		};

		// A product with its own media pictures, one of which a page also
		// shows, and an ePim-switched product whose picture number happens to
		// be a media item's too.
		register_rest_route(
			'epi-test/v1',
			'/purge-fixture',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					delete_option( 'one_time_wc_product_image_purge_plan' );
					delete_option( 'one_time_wc_product_image_purge_scan' );

					$image = static function ( $title ) {
						return wp_insert_attachment(
							array(
								'post_title'     => $title,
								'post_mime_type' => 'image/jpeg',
								'post_status'    => 'inherit',
							),
							'epi-test/' . sanitize_title( $title ) . '-' . wp_rand() . '.jpg'
						);
					};

					$only   = $image( 'Purge test product only' );
					$shared = $image( 'Purge test also on a page' );
					$clash  = $image( 'Purge test same number as an ePim picture' );

					$media_product = wp_insert_post(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'post_title'  => 'Purge test media product',
						)
					);
					update_post_meta( $media_product, '_thumbnail_id', (string) $only );
					update_post_meta( $media_product, '_product_image_gallery', (string) $shared );

					wp_insert_post(
						array(
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_title'   => 'Purge test page',
							'post_content' => '<img class="wp-image-' . $shared . '" alt="">',
						)
					);

					// Picture first: once marked, the plugin guards the picture.
					$switched = wp_insert_post(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'post_title'  => 'Purge test switched product',
						)
					);
					update_post_meta( $switched, '_thumbnail_id', (string) $clash );
					update_post_meta( $switched, '_epim_pictures', '1' );

					return compact( 'only', 'shared', 'clash', 'media_product', 'switched' );
				},
			)
		);

		// Which of the fixture's images still exist, and what the products point at.
		register_rest_route(
			'epi-test/v1',
			'/purge-state',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$exists = array();

					foreach ( array( 'only', 'shared', 'clash' ) as $key ) {
						$exists[ $key ] = 'attachment' === get_post_type( (int) $request[ $key ] );
					}

					return array(
						'exists'          => $exists,
						'media_thumbnail' => (string) get_post_meta( (int) $request['media_product'], '_thumbnail_id', true ),
						'switched_thumb'  => (string) get_post_meta( (int) $request['switched'], '_thumbnail_id', true ),
					);
				},
			)
		);
	}
);
