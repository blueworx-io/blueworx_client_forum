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

/**
 * ePim as the tests see it. Three scenarios, chosen by the epi_test_epim_scenario option:
 * initial (first pull), changed (a rename, a price change, an archive), deleted (an entity
 * deletion). Shapes copy the real API, sampled 2026-10-05.
 */
function epi_test_epim_fixtures( $scenario ) {
	$categories = array(
		array( 'Id' => 1, 'Name' => 'Lighting controls', 'Description' => null, 'Alias' => null, 'UpdatedOnUTC' => '2026-05-07T06:06:35.47', 'ParentId' => null, 'PictureIds' => array() ),
		array( 'Id' => 2, 'Name' => 'Kinetic switches', 'Description' => null, 'Alias' => null, 'UpdatedOnUTC' => '2026-05-07T06:18:17.067', 'ParentId' => 1, 'PictureIds' => array() ),
		array( 'Id' => 3, 'Name' => 'Decorative', 'Description' => null, 'Alias' => null, 'UpdatedOnUTC' => '2026-05-07T06:07:58.973', 'ParentId' => null, 'PictureIds' => array() ),
	);

	$attr = static function ( $id, $name, $value, $group = 'Technical Data' ) {
		return array( 'AttributeId' => 'epim-' . $id, 'Value' => $value, 'AttributeHeaderName' => $name, 'AttributeHeaderGroup' => $group );
	};

	$a = array(
		'Id'                      => 1001,
		'IsArchived'              => false,
		'IsApprovedForPublishing' => true,
		'ProductId'               => 501,
		'Name'                    => 'Single Kinetic Switch - White',
		'SKU'                     => 'TEST-1001',
		'ProductGroupCode'        => 'TEST-1001',
		'Price'                   => 51.25,
		'PictureIds'              => array( 11, 12, 13, 14 ),
		'ProductCategoryIds'      => array( 2 ),
		'PictureIdsGrouped'       => array( 'Image' => array( 11, 12, 13 ), 'Logo' => array( 14 ) ),
		'Short_Description'       => 'Single Kinetic Switch - White',
		'SKU_Text'                => 'Kit includes a switch and a receiver.',
		'AttributeValues'         => array( $attr( 1, 'Colour', 'White' ), $attr( 2, 'Material', 'Plastic' ), $attr( 3, 'Bulb Type', '' ) ),
	);
	$b = array(
		'Id'                      => 1002,
		'IsArchived'              => false,
		'IsApprovedForPublishing' => true,
		'ProductId'               => 502,
		'Name'                    => 'Lila Flush Ceiling Light - Chrome',
		'SKU'                     => 'TEST-1002',
		'ProductGroupCode'        => '30000750',
		'Price'                   => 112.5,
		'PictureIds'              => array( 21, 22 ),
		'ProductCategoryIds'      => array( 3, 999 ),
		'PictureIdsGrouped'       => array( 'Image' => array( 21, 22 ) ),
		'Short_Description'       => 'Lila Flush Ceiling Light - Chrome',
		'SKU_Text'                => 'A swirling chrome flush fitting.',
		'AttributeValues'         => array( $attr( 1, 'Colour', 'Chrome' ) ),
	);
	$c = array(
		'Id'                      => 1003,
		'IsArchived'              => true,
		'IsApprovedForPublishing' => true,
		'ProductId'               => 503,
		'Name'                    => 'Archived Lamp',
		'SKU'                     => 'TEST-1003',
		'ProductGroupCode'        => 'TEST-1003',
		'Price'                   => 10,
		'PictureIds'              => array(),
		'ProductCategoryIds'      => array( 3 ),
		'PictureIdsGrouped'       => array(),
		'Short_Description'       => 'Archived Lamp',
		'SKU_Text'                => 'No longer sold.',
		'AttributeValues'         => array(),
	);

	$deleted = array();

	if ( 'changed' === $scenario ) {
		$a['Name']            = 'Single Kinetic Switch Kit - White';
		$a['Price']           = 55;
		$a['AttributeValues'] = array( $attr( 1, 'Colour', 'Off white' ), $attr( 2, 'Material', 'Plastic' ) );
		$b['IsArchived']      = true;
	}

	if ( 'deleted' === $scenario ) {
		$deleted = array(
			array( 'Id' => 1, 'EntityId' => 1001, 'EntityType' => 'SKU_Product_Mapping', 'TimeStamp' => '2026-10-05T10:00:00' ),
			array( 'Id' => 2, 'EntityId' => 502, 'EntityType' => 'Product', 'TimeStamp' => '2026-10-05T10:00:01' ),
		);
	}

	return array(
		'categories' => $categories,
		'variations' => array( $a, $b, $c ),
		'deleted'    => $deleted,
	);
}

function epi_test_epim_response( $code, $body ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

function epi_test_epim_paged( array $all, array $query ) {
	$start = isset( $query['start'] ) ? (int) $query['start'] : 0;
	$limit = isset( $query['limit'] ) ? max( 1, (int) $query['limit'] ) : 50;

	return epi_test_epim_response(
		200,
		array(
			'Start'        => $start,
			'Limit'        => $limit,
			'TotalResults' => count( $all ),
			'Results'      => array_values( array_slice( $all, $start, $limit ) ),
		)
	);
}

// Point the pull at a host that does not exist, and answer for it here.
add_filter( 'epi_pull_api_base', static function () { return 'https://epim.test/api/'; } );
add_filter( 'epi_pull_page_size', static function () { return 2; } );

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://epim.test/api/' ) ) {
			return $pre;
		}

		$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$key     = isset( $headers['Ocp-Apim-Subscription-Key'] ) ? $headers['Ocp-Apim-Subscription-Key'] : '';

		if ( EPI_TEST_EPIM_KEY !== $key ) {
			return epi_test_epim_response( 401, array( 'statusCode' => 401, 'message' => 'Access denied due to invalid subscription key.' ) );
		}

		$parts = wp_parse_url( $url );
		$query = array();
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$path = substr( $parts['path'], strlen( '/api/' ) );

		$calls   = get_option( 'epi_test_epim_calls', array() );
		$calls[] = array( 'path' => $path, 'query' => $query );
		update_option( 'epi_test_epim_calls', $calls, false );

		$data = epi_test_epim_fixtures( get_option( 'epi_test_epim_scenario', 'initial' ) );

		switch ( $path ) {
			case 'Categories':
				return epi_test_epim_response( 200, $data['categories'] );
			case 'Variations':
				return epi_test_epim_paged( $data['variations'], $query );
			case 'DeletedEntities':
				return epi_test_epim_paged( $data['deleted'], $query );
		}

		return epi_test_epim_response( 404, array( 'message' => 'No such endpoint: ' . $path ) );
	},
	10,
	3
);

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

		register_rest_route(
			'epi-test/v1',
			'/pull/scenario',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					update_option( 'epi_test_epim_scenario', sanitize_key( $request['scenario'] ), false );
					return array( 'scenario' => get_option( 'epi_test_epim_scenario' ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/calls',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array_values( (array) get_option( 'epi_test_epim_calls', array() ) );
				},
			)
		);

		// Call the client directly. `key` overrides the saved key for one call.
		register_rest_route(
			'epi-test/v1',
			'/pull/fetch',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( $request['key'] ) {
						update_option( 'epi_pull_settings', array( 'key' => (string) $request['key'], 'images' => false ), false );
					}

					switch ( (string) $request['what'] ) {
						case 'variations':
							$result = EPI_Pull_Client::variations( '2000-01-01T00:00:00Z', (int) $request['start'] );
							break;
						case 'deleted':
							$result = EPI_Pull_Client::deleted( '2000-01-01T00:00:00Z', (int) $request['start'] );
							break;
						default:
							$result = EPI_Pull_Client::categories();
					}

					if ( $request['key'] ) {
						update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false ), false );
					}

					return is_wp_error( $result ) ? array( 'error' => $result->get_error_message(), 'code' => $result->get_error_code() ) : $result;
				},
			)
		);
	}
);
