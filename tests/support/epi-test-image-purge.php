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
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;

					delete_option( 'one_time_wc_product_image_purge_plan' );
					delete_option( 'one_time_wc_product_image_purge_scan' );

					// Earlier runs and other specs leave images behind, attached to
					// nothing or pointed at by products. Set them aside — attached
					// to a page of their own, and no product pointing at them — so
					// an unused image scan sees only this fixture's, and its
					// preview list is short enough to show them all.
					// Plain single-table queries: the harness's database may not
					// take MySQL's joined UPDATE.
					if ( $request['set_aside'] ) {
						$products = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')" );

						foreach ( array_chunk( array_map( 'absint', $products ), 500 ) as $chunk ) {
							$wpdb->query(
								"DELETE FROM {$wpdb->postmeta}
                                 WHERE meta_key IN ('_thumbnail_id', '_product_image_gallery')
                                 AND post_id IN (" . implode( ',', $chunk ) . ')'
							);
						}

						$holder = wp_insert_post(
							array(
								'post_type'   => 'page',
								'post_status' => 'draft',
								'post_title'  => 'Purge test images set aside',
							)
						);
						$posts  = array_map( 'absint', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type <> 'attachment'" ) );
						$loose  = $wpdb->get_results( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_type = 'attachment'" );
						$exists = array_fill_keys( $posts, true );

						foreach ( $loose as $attachment ) {
							if ( ! $attachment->post_parent || ! isset( $exists[ (int) $attachment->post_parent ] ) ) {
								$wpdb->update( $wpdb->posts, array( 'post_parent' => $holder ), array( 'ID' => (int) $attachment->ID ) );
							}
						}

						wp_cache_flush();
					}

					$files = array();
					$image = static function ( $title, $parent = 0 ) use ( &$files ) {
						$file = 'epi-test/' . sanitize_title( $title ) . '-' . wp_rand() . '.jpg';
						$id   = wp_insert_attachment(
							array(
								'post_title'     => $title,
								'post_mime_type' => 'image/jpeg',
								'post_status'    => 'inherit',
							),
							$file,
							$parent
						);

						$files[ $id ] = $file;
						return $id;
					};

					$only   = $image( 'Purge test product only' );
					$shared = $image( 'Purge test also on a page' );
					$clash  = $image( 'Purge test same number as an ePim picture' );

					// Images no product points at: one used nowhere, one a page
					// shows by its file name (a resized copy of it), and one
					// attached to a page.
					$unused       = $image( 'Purge test unused' );
					$used_by_name = $image( 'Purge test used by file name' );
					$page_id      = wp_insert_post(
						array(
							'post_type'    => 'page',
							'post_status'  => 'publish',
							'post_title'   => 'Purge test page by file name',
							'post_content' => '<img src="/wp-content/uploads/' . preg_replace( '/\.jpg$/', '-300x300.jpg', $files[ $used_by_name ] ) . '" alt="">',
						)
					);
					$on_page      = $image( 'Purge test attached to a page', $page_id );

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

					return compact( 'only', 'shared', 'clash', 'media_product', 'switched', 'unused', 'used_by_name', 'on_page' );
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

					foreach ( array( 'only', 'shared', 'clash', 'unused', 'used_by_name', 'on_page' ) as $key ) {
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
