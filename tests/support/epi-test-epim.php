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
 * ePim as the tests see it. Scenarios, chosen by the epi_test_epim_scenario option:
 * initial (first pull), changed (a rename, a price change, an archive), deleted (an entity
 * deletion), nosku (a record without a SKU), broken (a Variations page with no Results,
 * served by the fake below; the data here is the initial set). Shapes copy the real API,
 * sampled 2026-10-05.
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

	if ( 'nosku' === $scenario ) {
		$b['SKU'] = '';
		$c        = null;
	}

	return array(
		'categories' => $categories,
		'variations' => array_values( array_filter( array( $a, $b, $c ) ) ),
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

		$scenario = get_option( 'epi_test_epim_scenario', 'initial' );
		$data     = epi_test_epim_fixtures( $scenario );

		switch ( $path ) {
			case 'Categories':
				return epi_test_epim_response( 200, $data['categories'] );
			case 'Variations':
				if ( 'broken' === $scenario ) {
					return epi_test_epim_response( 200, array( 'Start' => 0, 'Limit' => 2, 'TotalResults' => 3 ) );
				}
				return epi_test_epim_paged( $data['variations'], $query );
			case 'DeletedEntities':
				return epi_test_epim_paged( $data['deleted'], $query );
		}

		return epi_test_epim_response( 404, array( 'message' => 'No such endpoint: ' . $path ) );
	},
	10,
	3
);

// The pull refuses to start without WooCommerce on a real site. The harness is
// the one place it may write plain posts instead.
add_filter( 'epi_pull_allow_without_woocommerce', '__return_true' );

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
			// Kept so a test can check the loopback carries the cron lock.
			$calls   = get_option( 'epi_test_cron_calls', array() );
			$calls[] = array( 'url' => $url );
			update_option( 'epi_test_cron_calls', $calls, false );

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

					// 'any' leaves out the bin, so binned products are named.
					foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => array( 'any', 'trash' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
						wp_delete_post( $id, true );
					}

					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
						wp_delete_term( $term_id, 'product_cat' );
					}

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'epi_pull_items' );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'epi_pull_runs' );

					foreach ( array( 'epi_pull_lock', 'epi_pull_category_map', 'epi_test_epim_scenario', 'epi_test_epim_calls', 'epi_test_cron_calls' ) as $option ) {
						delete_option( $option );
					}

					// Existing tests are real pulls, so test mode starts off.
					update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false, 'test' => false ), false );

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
						update_option( 'epi_pull_settings', array( 'key' => (string) $request['key'], 'images' => false, 'test' => false ), false );
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
						update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false, 'test' => false ), false );
					}

					return is_wp_error( $result ) ? array( 'error' => $result->get_error_message(), 'code' => $result->get_error_code() ) : $result;
				},
			)
		);
		register_rest_route(
			'epi-test/v1',
			'/pull/map',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return EPI_Pull_Mapper::map( (array) $request['raw'] );
				},
			)
		);

		// A category the site already had before the pull existed.
		register_rest_route(
			'epi-test/v1',
			'/pull/category',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$term = wp_insert_term( (string) $request['name'], 'product_cat' );
					return is_wp_error( $term ) ? array( 'error' => $term->get_error_message() ) : $term;
				},
			)
		);

		// Sync a category list, then report every term as name, parent name, ePim id.
		register_rest_route(
			'epi-test/v1',
			'/pull/categories',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$map   = EPI_Pull_Categories::sync( (array) $request['categories'], ! empty( $request['dry_run'] ) );
					$terms = array();

					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) ) as $term ) {
						$parent  = $term->parent ? get_term( $term->parent, 'product_cat' ) : null;
						$terms[] = array(
							'name'   => $term->name,
							'parent' => $parent instanceof WP_Term ? $parent->name : '',
							'epim'   => (int) get_term_meta( $term->term_id, EPI_Pull_Categories::META, true ),
						);
					}

					return array( 'map' => (object) $map, 'terms' => $terms );
				},
			)
		);

		// Every product category, as name and ePim id.
		register_rest_route(
			'epi-test/v1',
			'/pull/terms',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array_values(
						array_map(
							static function ( $term ) {
								return array(
									'name' => $term->name,
									'epim' => (int) get_term_meta( $term->term_id, EPI_Pull_Categories::META, true ),
								);
							},
							get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) )
						)
					);
				},
			)
		);

		// Map and apply one raw record, with the fixture categories in place.
		register_rest_route(
			'epi-test/v1',
			'/pull/apply',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$fixtures = epi_test_epim_fixtures( 'initial' );
					$map      = EPI_Pull_Categories::sync( $fixtures['categories'] );

					return EPI_Pull_Writer::apply( EPI_Pull_Mapper::map( (array) $request['raw'] ), $map, ! empty( $request['images'] ) );
				},
			)
		);

		// A product that existed before the pull: SKU, no ePim id, and
		// optionally the pictures ePim's push gave it.
		register_rest_route(
			'epi-test/v1',
			'/pull/product',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = wp_insert_post(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'post_title'  => (string) $request['title'],
						)
					);
					update_post_meta( $id, '_sku', (string) $request['sku'] );

					if ( null !== $request['thumbnail'] ) {
						update_post_meta( $id, '_thumbnail_id', (string) $request['thumbnail'] );
					}

					if ( null !== $request['gallery'] ) {
						update_post_meta( $id, '_product_image_gallery', (string) $request['gallery'] );
					}

					return array( 'id' => (int) $id );
				},
			)
		);

		// Move the product with a SKU to the bin.
		register_rest_route(
			'epi-test/v1',
			'/pull/trash',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$ids = get_posts(
						array(
							'post_type'      => 'product',
							'post_status'    => 'any',
							'posts_per_page' => 1,
							'fields'         => 'ids',
							'meta_key'       => '_sku',
							'meta_value'     => (string) $request['sku'],
						)
					);

					if ( ! $ids ) {
						return array( 'id' => 0 );
					}

					wp_trash_post( (int) $ids[0] );

					return array( 'id' => (int) $ids[0] );
				},
			)
		);

		// Make the cron loopback a batch would make at shutdown, with no
		// cron lock held, and report the lock it set and the request it sent.
		register_rest_route(
			'epi-test/v1',
			'/pull/loopback',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					// This request may itself have spawned cron on load; only the loopback's call counts.
					delete_option( 'epi_test_cron_calls' );
					delete_transient( 'doing_cron' );
					EPI_Pull_Runner::loopback();

					return array(
						'transient' => get_transient( 'doing_cron' ),
						'calls'     => get_option( 'epi_test_cron_calls', array() ),
					);
				},
			)
		);

		// What the site holds for a SKU. `count` says how many products carry it.
		register_rest_route(
			'epi-test/v1',
			'/pull/product/(?P<sku>[^/]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$ids = get_posts(
						array(
							'post_type'      => 'product',
							// The writer's own list: 'any' would hide a binned product.
							'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'meta_key'       => '_sku',
							'meta_value'     => (string) $request['sku'],
						)
					);

					if ( ! $ids ) {
						return array( 'id' => 0, 'count' => 0 );
					}

					$id         = (int) $ids[0];
					$post       = get_post( $id );
					$read       = EPI_Pull_Writer::read( $id );
					$categories = array();

					foreach ( $read['categories'] as $term_id ) {
						$term         = get_term( $term_id, 'product_cat' );
						$categories[] = $term instanceof WP_Term ? $term->name : (string) $term_id;
					}

					return array(
						'id'              => $id,
						'count'           => count( $ids ),
						'status'          => $post->post_status,
						'title'           => $post->post_title,
						'content'         => $post->post_content,
						'sku'             => $read['sku'],
						'price'           => $read['price'],
						'epim_id'         => (int) get_post_meta( $id, '_epim_variation_id', true ),
						'epim_product_id' => (int) get_post_meta( $id, '_epim_product_id', true ),
						'categories'      => $categories,
						'attributes'      => (object) $read['attributes'],
						'thumbnail'       => (string) get_post_meta( $id, '_thumbnail_id', true ),
						'gallery'         => (string) get_post_meta( $id, '_product_image_gallery', true ),
						'synced'          => (string) get_post_meta( $id, '_epim_synced_at', true ),
					);
				},
			)
		);

		// Set the pull's settings directly.
		register_rest_route(
			'epi-test/v1',
			'/pull/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					// Only what the request names is changed, so a call with just a key keeps the rest.
					$values = array( 'key' => (string) $request['key'] );

					foreach ( array( 'images', 'test' ) as $flag ) {
						if ( $request->has_param( $flag ) ) {
							$values[ $flag ] = ! empty( $request[ $flag ] );
						}
					}

					EPI_Pull_Settings::save( $values );
					return EPI_Pull_Settings::get();
				},
			)
		);

		// What a fresh install gets: the option deleted, then read back.
		register_rest_route(
			'epi-test/v1',
			'/pull/settings-default',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					delete_option( 'epi_pull_settings' );
					$settings = EPI_Pull_Settings::get();
					update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false, 'test' => false ), false );
					return $settings;
				},
			)
		);

		$run_to_array = static function ( $run ) {
			return $run ? (array) $run : null;
		};

		register_rest_route(
			'epi-test/v1',
			'/pull/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$started = EPI_Pull_Runner::start( 'manual', ! empty( $request['full'] ), array( 'category_id' => (int) $request['category_id'] ) );
					return is_wp_error( $started ) ? array( 'error' => $started->get_error_code() ) : array( 'run_id' => $started );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/drain',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) use ( $run_to_array ) {
					return $run_to_array( EPI_Pull_Runner::drain( (int) $request['run_id'] ) );
				},
			)
		);

		// Start and run to the end in one go. Calls are cleared first so a
		// test can see exactly what this pull asked ePim for.
		register_rest_route(
			'epi-test/v1',
			'/pull/pull',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) use ( $run_to_array ) {
					delete_option( 'epi_test_epim_calls' );
					$started = EPI_Pull_Runner::start( 'manual', ! empty( $request['full'] ), array( 'category_id' => (int) $request['category_id'] ) );

					if ( is_wp_error( $started ) ) {
						return array( 'error' => $started->get_error_code(), 'message' => $started->get_error_message() );
					}

					return $run_to_array( EPI_Pull_Runner::drain( $started ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/runs',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array_map( static function ( $run ) { return (array) $run; }, EPI_Pull_Store::get_runs( 1, 100 ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/runs/(?P<id>\d+)/items',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return EPI_Pull_Store::get_items( (int) $request['id'], 1, 100 );
				},
			)
		);

		// A finished run from some days ago, with one item.
		register_rest_route(
			'epi-test/v1',
			'/pull/seed-run',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$run_id = EPI_Pull_Store::create_run( 'auto', '', false );
					EPI_Pull_Store::update_run(
						$run_id,
						array(
							'started_at' => gmdate( 'Y-m-d H:i:s', time() - (int) $request['days_ago'] * DAY_IN_SECONDS ),
							'status'     => 'done',
						)
					);
					EPI_Pull_Store::add_item( $run_id, array( 'sku' => 'SEED', 'name' => 'Seed', 'action' => 'added' ) );

					return array( 'id' => $run_id );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/prune',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array( 'removed' => EPI_Pull_Store::prune( EPI_Pull_Runner::KEEP_DAYS ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/schedule',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					// The harness never fires cron, so an overdue slot would sit in the
					// past for ever. Re-arm as the daily job itself does, then report.
					// What the boot hook scheduled before this route touched it.
					$had = (int) wp_next_scheduled( EPI_Pull_Runner::DAILY_HOOK );

					wp_clear_scheduled_hook( EPI_Pull_Runner::DAILY_HOOK );
					EPI_Pull_Runner::ensure_schedule();

					$next = (int) wp_next_scheduled( EPI_Pull_Runner::DAILY_HOOK );

					return array(
						'had'        => $had,
						'next'       => $next,
						'local_time' => $next ? wp_date( 'H:i', $next ) : '',
						'recurrence' => $next ? wp_get_schedule( EPI_Pull_Runner::DAILY_HOOK ) : '',
					);
				},
			)
		);

		// A lock some minutes old, as a run that died mid-batch would leave.
		register_rest_route(
			'epi-test/v1',
			'/pull/lock',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( $request['placeholder'] ) {
						update_option( 'epi_pull_lock', array( 'run_id' => 0, 'time' => time() - (int) $request['minutes_ago'] * MINUTE_IN_SECONDS ), false );

						return array( 'run_id' => 0 );
					}

					$run_id = EPI_Pull_Store::create_run( 'auto', '', false );
					EPI_Pull_Store::update_run( $run_id, array( 'status' => 'running' ) );
					update_option( 'epi_pull_lock', array( 'run_id' => $run_id, 'time' => time() - (int) $request['minutes_ago'] * MINUTE_IN_SECONDS ), false );

					return array( 'run_id' => $run_id );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/changes/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return class_exists( 'EPI_Product_Change_Log' ) ? EPI_Product_Change_Log::get_updates( (int) $request['id'] ) : array();
				},
			)
		);

		// Run exactly one batch with no time budget, as cron would, and report
		// which batch event got queued next.
		register_rest_route(
			'epi-test/v1',
			'/pull/batch-once',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$run_id    = (int) $request['run_id'];
					$no_budget = static function () { return 0; };
					add_filter( 'epi_pull_batch_seconds', $no_budget );
					EPI_Pull_Runner::batch( $run_id, 1, true );
					remove_filter( 'epi_pull_batch_seconds', $no_budget );
					$run = EPI_Pull_Store::get_run( $run_id );

					return array(
						'stage'   => $run ? $run->stage : '',
						'batches' => $run ? (int) $run->batches : 0,
						'next'    => (bool) wp_next_scheduled( EPI_Pull_Runner::BATCH_HOOK, array( $run_id, $run ? (int) $run->batches : 0 ) ),
					);
				},
			)
		);
	}
);
