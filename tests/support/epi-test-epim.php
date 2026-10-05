<?php
/**
 * Plugin Name: Forum test support — ePim pull
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-import.spec.js.
 *              Stands a plain post type and taxonomy in for WooCommerce, fakes ePim at the HTTP
 *              transport, and offers routes that run a pull without waiting on cron.
 *              Never shipped: tests/ is outside the release allowlist.
 */

if ( ! defined( 'EPI_TEST_EPIM_KEY' ) ) {
	define( 'EPI_TEST_EPIM_KEY', 'epim-test-key' );
}

// CI's harness has no WooCommerce. The pull writes posts of type "product" with
// "product_cat" terms, so plain ones stand in. Guarded, because the change log's
// support file registers the same post type.
add_action(
	'init',
	static function () {
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'label'        => 'Products',
					'public'       => false,
					'show_ui'      => true,
					'show_in_rest' => true,
					'rest_base'    => 'product',
					'supports'     => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy(
				'product_cat',
				'product',
				array(
					'label'        => 'Product categories',
					'hierarchical' => true,
					'public'       => false,
					'show_ui'      => true,
				)
			);
		}

		if ( ! get_user_by( 'login', 'epi-test-editor' ) ) {
			wp_insert_user(
				array(
					'user_login' => 'epi-test-editor',
					'user_pass'  => 'editor-test-pw',
					'role'       => 'editor',
				)
			);
		}
	}
);

// WordPress tries to start cron with a request to itself on most page loads. On
// the single-threaded test server that request would queue behind the one that
// made it, and in these tests it would race the route that runs batches directly.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'wp-cron.php' ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return $pre;
	},
	5,
	3
);

add_action(
	'rest_api_init',
	static function () {
		$admin_only = static function () {
			return current_user_can( 'manage_options' );
		};

		// Whether the pull's tables exist.
		register_rest_route(
			'epi-test/v1',
			'/pull/tables',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					global $wpdb;

					$exists = static function ( $table ) use ( $wpdb ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
					};

					return array(
						'runs'  => $exists( $wpdb->prefix . 'epi_pull_runs' ),
						'items' => $exists( $wpdb->prefix . 'epi_pull_items' ),
					);
				},
			)
		);

		// Back to a clean slate: no products, no categories, no runs, no lock.
		register_rest_route(
			'epi-test/v1',
			'/pull/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					global $wpdb;

					foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
						wp_delete_post( $id, true );
					}

					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
						wp_delete_term( $term_id, 'product_cat' );
					}

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'epi_pull_items' );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'epi_pull_runs' );

					foreach ( array( 'epi_pull_lock', 'epi_pull_category_map', 'epi_test_epim_scenario', 'epi_test_epim_calls' ) as $option ) {
						delete_option( $option );
					}

					update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false ), false );

					return array( 'ok' => true );
				},
			)
		);
	}
);
