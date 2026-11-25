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
					epi_test_trim_ensure_product();

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

					$listing = array( 'post_type' => 'product' );

					// The listing of one term, such as a colour, instead of the whole shop.
					$taxonomy = (string) $request->get_param( 'tax' );

					if ( '' !== $taxonomy ) {
						register_taxonomy(
							$taxonomy,
							(string) $request->get_param( 'tax_for' ),
							array(
								'public'    => true,
								'query_var' => true,
								'rewrite'   => false,
							)
						);

						$term    = wp_insert_term( 'Cap term', $taxonomy, array( 'slug' => 'cap-term' ) );
						$listing = array( $taxonomy => 'cap-term' );
					}

					// Running the query fires pre_get_posts, as a real page view does.
					$query->query( $listing + array( 'posts_per_page' => (int) $request->get_param( 'value' ) ) + $overrides );

					if ( isset( $term ) && ! is_wp_error( $term ) ) {
						wp_delete_term( $term['term_id'], $taxonomy );
					}

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

/**
 * Make sure this request has a "product" post type. Another spec's stand-in, or
 * WooCommerce itself, may already have registered one.
 */
function epi_test_trim_ensure_product() {
	if ( post_type_exists( 'product' ) ) {
		return;
	}

	register_post_type(
		'product',
		array(
			'public'      => true,
			'has_archive' => true,
		)
	);
}

/**
 * Stands in for the [products] shortcode's query and page numbers, making the
 * same calls in the same order as WooCommerce.
 */
class EPI_Test_Trim_Shortcode {
	private $attributes;
	private $query_args;

	public function __construct( $attributes ) {
		$paginate = (bool) $attributes['paginate'];

		if ( $paginate ) {
			$attributes['page'] = empty( $_GET['product-page'] ) ? 1 : absint( $_GET['product-page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'no_found_rows'  => ! $paginate,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'posts_per_page' => (int) $attributes['limit'],
		);

		if ( 1 < $attributes['page'] ) {
			$args['paged'] = $attributes['page'];
		}

		if ( $attributes['ids'] ) {
			$args['post__in'] = $attributes['ids'];
		}

		$this->attributes = $attributes;
		$args             = apply_filters( 'woocommerce_shortcode_products_query', $args, $attributes, 'products' );
		$args['fields']   = 'ids';
		$this->query_args = $args;
	}

	public function get_query_args() {
		return $this->query_args;
	}

	public function get_type() {
		return 'products';
	}

	public function run() {
		$query     = new WP_Query( $this->query_args );
		$paginated = ! $query->get( 'no_found_rows' );

		$results = apply_filters(
			'woocommerce_shortcode_products_query_results',
			(object) array(
				'ids'          => wp_parse_id_list( $query->posts ),
				'total'        => $paginated ? (int) $query->found_posts : count( $query->posts ),
				'total_pages'  => $paginated ? (int) $query->max_num_pages : 1,
				'per_page'     => (int) $query->get( 'posts_per_page' ),
				'current_page' => $paginated ? (int) max( 1, $query->get( 'paged', 1 ) ) : 1,
			),
			$this
		);

		ob_start();

		if ( $results->ids ) {
			do_action( 'woocommerce_shortcode_after_products_loop', $this->attributes );
		}

		return array(
			'titles'      => array_map( 'get_the_title', $results->ids ),
			'total_pages' => $results->total_pages,
			'html'        => ob_get_clean(),
		);
	}
}

add_action(
	'rest_api_init',
	static function () {
		$open = array( 'permission_callback' => '__return_true' );

		// Make, or remove, a catalogue to page through. Written straight to the
		// database, so nothing that watches product saves is set off.
		register_rest_route(
			'epi-test/v1',
			'/cap-products',
			$open + array(
				'methods'  => 'POST',
				'callback' => static function ( WP_REST_Request $request ) {
					global $wpdb;

					$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_name LIKE 'epi-cap-%'" );

					for ( $i = 1; $i <= (int) $request->get_param( 'count' ); $i++ ) {
						$wpdb->insert(
							$wpdb->posts,
							array(
								'post_type'     => 'product',
								'post_status'   => 'publish',
								'post_title'    => sprintf( 'Cap %03d', $i ),
								'post_name'     => sprintf( 'epi-cap-%03d', $i ),
								'post_date'     => '2026-01-01 00:00:00',
								'post_date_gmt' => '2026-01-01 00:00:00',
							)
						);
					}

					return array( 'ok' => true );
				},
			)
		);

		// A page holding one product block, rendered as a page view renders it.
		register_rest_route(
			'epi-test/v1',
			'/cap-block',
			$open + array(
				'methods'  => 'GET',
				'callback' => static function ( WP_REST_Request $request ) {
					epi_test_trim_ensure_product();

					$attributes = array(
						'queryId' => 7,
						'query'   => array(
							'postType' => 'product',
							'perPage'  => (int) $request->get_param( 'size' ),
							'pages'    => (int) $request->get_param( 'pages' ),
							'offset'   => (int) $request->get_param( 'offset' ),
							'order'    => 'asc',
							'orderBy'  => 'title',
							'inherit'  => false,
						),
					);

					$numbers = $request->get_param( 'no_numbers' )
						? ''
						: '<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->';

					// A theme or plugin may raise the size itself.
					if ( $request->get_param( 'raise' ) ) {
						add_filter(
							'query_loop_block_query_vars',
							static function ( $query ) use ( $request ) {
								$query['posts_per_page'] = (int) $request->get_param( 'raise' );
								return $query;
							}
						);
					}

					return array(
						'html' => do_blocks(
							'<!-- wp:query ' . wp_json_encode( $attributes ) . ' --><div class="wp-block-query">'
							. '<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template -->'
							. $numbers
							. '</div><!-- /wp:query -->'
						),
					);
				},
			)
		);

		// A page holding one [products] shortcode.
		register_rest_route(
			'epi-test/v1',
			'/cap-shortcode',
			$open + array(
				'methods'  => 'GET',
				'callback' => static function ( WP_REST_Request $request ) {
					epi_test_trim_ensure_product();

					if ( ! function_exists( 'wc_get_template' ) ) {
						// phpcs:disable
						function wc_get_template( $name, $args = array() ) {
							echo '<nav class="woocommerce-pagination">' . paginate_links(
								array(
									'base'     => $args['base'],
									'format'   => $args['format'],
									'add_args' => false,
									'current'  => max( 1, $args['current'] ),
									'total'    => $args['total'],
								)
							) . '</nav>';
						}
						// phpcs:enable
					}

					$ids = array();

					if ( $request->get_param( 'ids' ) ) {
						$ids = get_posts(
							array(
								'post_type'   => 'product',
								'numberposts' => (int) $request->get_param( 'ids' ),
								'fields'      => 'ids',
							)
						);
					}

					$shortcode = new EPI_Test_Trim_Shortcode(
						array(
							'limit'    => (int) $request->get_param( 'limit' ),
							'paginate' => (bool) $request->get_param( 'paginate' ),
							'page'     => max( 1, (int) $request->get_param( 'start' ) ),
							'ids'      => $ids,
						)
					);

					return $shortcode->run();
				},
			)
		);
	}
);
