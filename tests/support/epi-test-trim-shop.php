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
					epi_test_trim_ensure_product();

					$size = null;

					add_filter(
						'query_loop_block_query_vars',
						static function ( $query ) use ( $request ) {
							$query['posts_per_page'] = (int) $request->get_param( 'value' );
							return $query;
						}
					);
					add_filter(
						'query_loop_block_query_vars',
						static function ( $query ) use ( &$size ) {
							$size = (int) $query['posts_per_page'];
							return $query;
						},
						PHP_INT_MAX
					);

					epi_test_trim_render_block(
						array(
							'postType' => (string) $request->get_param( 'type' ),
							'perPage'  => 10,
						)
					);

					return array( 'per_page' => $size );
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
 * Render a page holding one block that lists posts.
 *
 * @param array  $query  The block's query settings.
 * @param string $layout Where its page links go: "plain", "grouped" (inside
 *                       another block), "nested" (only a second list inside it
 *                       has them) or "none".
 */
function epi_test_trim_render_block( $query, $layout = 'plain' ) {
	$attributes = array(
		'queryId' => 7,
		'query'   => $query + array(
			'order'   => 'asc',
			'orderBy' => 'title',
			'inherit' => false,
		),
	);

	$links = '<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->';

	if ( 'grouped' === $layout ) {
		$links = '<!-- wp:group --><div class="wp-block-group">' . $links . '</div><!-- /wp:group -->';
	} elseif ( 'nested' === $layout ) {
		$links = '<!-- wp:query {"queryId":8,"query":{"perPage":1,"postType":"page","inherit":false}} --><div class="wp-block-query">' . $links . '</div><!-- /wp:query -->';
	} elseif ( 'none' === $layout ) {
		$links = '';
	}

	// Sit the block inside another one, as it does in a real template.
	return do_blocks(
		'<!-- wp:group --><div class="wp-block-group"><!-- wp:query ' . wp_json_encode( $attributes ) . ' --><div class="wp-block-query">'
		. '<!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template -->'
		. $links
		. '</div><!-- /wp:query --></div><!-- /wp:group -->'
	);
}

/**
 * Stands in for the [products] shortcode's query, making the same calls in the
 * same order as WooCommerce.
 */
class EPI_Test_Trim_Shortcode {
	private $query_args;

	public function __construct( $attributes ) {
		$paginate = wc_string_to_bool( $attributes['paginate'] );

		if ( $paginate ) {
			$attributes['page'] = empty( $_GET['product-page'] ) ? 1 : absint( $_GET['product-page'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'no_found_rows'  => ! $paginate,
			'orderby'        => $attributes['orderby'],
			'order'          => 'ASC',
			'posts_per_page' => (int) $attributes['limit'],
		);

		if ( 1 < $attributes['page'] ) {
			$args['paged'] = $attributes['page'];
		}

		$args = apply_filters( 'woocommerce_shortcode_products_query', $args, $attributes, 'products' );
		$args['fields'] = 'ids';
		$this->query_args = $args;
	}

	public function run() {
		$query     = new WP_Query( $this->query_args );
		$paginated = ! $query->get( 'no_found_rows' );

		return array(
			'titles'      => array_map( 'get_the_title', $query->posts ),
			'total_pages' => $paginated ? (int) $query->max_num_pages : 1,
			'has_links'   => $paginated && $query->max_num_pages > 1,
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

					// WooCommerce's collections narrow the list from the block's own
					// settings, here to the first so many products.
					add_filter(
						'query_loop_block_query_vars',
						static function ( $query, $block ) {
							if ( ! empty( $block->context['query']['matches'] ) ) {
								$query['post__in'] = get_posts(
									array(
										'post_type'   => 'product',
										'orderby'     => 'title',
										'order'       => 'ASC',
										'numberposts' => $block->context['query']['matches'],
										'fields'      => 'ids',
									)
								);
							}

							return $query;
						},
						10,
						2
					);

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

					// Other code may start the list further on: "shift" skips that
					// many, "short_first" makes page one a product shorter.
					add_filter(
						'query_loop_block_query_vars',
						static function ( $query, $block, $page ) use ( $request ) {
							$size = (int) $query['posts_per_page'];

							if ( $request->get_param( 'shift' ) ) {
								$query['offset'] = (int) $request->get_param( 'shift' ) + $size * ( $page - 1 );
							}

							if ( $request->get_param( 'short_first' ) ) {
								$query['posts_per_page'] = 1 === $page ? $size - 1 : $size;
								$query['offset']         = 1 === $page ? 0 : $size * ( $page - 1 ) - 1;
							}

							return $query;
						},
						20,
						3
					);

					return array(
						'html' => epi_test_trim_render_block(
							array(
								'postType' => 'product',
								'perPage'  => (int) $request->get_param( 'size' ),
								'pages'    => (int) $request->get_param( 'pages' ),
								'offset'   => (int) $request->get_param( 'offset' ),
								'matches'  => (int) $request->get_param( 'matches' ),
								'orderBy'  => $request->get_param( 'orderby' ) ? (string) $request->get_param( 'orderby' ) : 'title',
							),
							$request->get_param( 'layout' ) ? (string) $request->get_param( 'layout' ) : 'plain'
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

					if ( ! function_exists( 'wc_string_to_bool' ) ) {
						// phpcs:disable
						// The same check WooCommerce makes.
						function wc_string_to_bool( $string ) {
							$string = $string ?? '';
							return is_bool( $string ) ? $string : ( 'yes' === strtolower( $string ) || 1 === $string || 'true' === strtolower( $string ) || '1' === $string );
						}
						// phpcs:enable
					}

					// WooCommerce hooks its page numbers up like this; a theme may
					// take them away again.
					add_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 10 );
					add_action( 'woocommerce_after_shop_loop', 'woocommerce_reset_loop', 999 );

					if ( $request->get_param( 'no_links' ) ) {
						remove_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 10 );
					}

					// An extension may fix where the list starts.
					if ( null !== $request->get_param( 'offset' ) ) {
						add_filter(
							'woocommerce_shortcode_products_query',
							static function ( $args ) use ( $request ) {
								$args['offset'] = (int) $request->get_param( 'offset' );
								return $args;
							}
						);
					}

					$shortcode = new EPI_Test_Trim_Shortcode(
						array(
							'limit'    => (int) $request->get_param( 'limit' ),
							'paginate' => (string) $request->get_param( 'paginate' ),
							'orderby'  => $request->get_param( 'orderby' ) ? (string) $request->get_param( 'orderby' ) : 'title',
							'page'     => 1,
						)
					);

					return $shortcode->run();
				},
			)
		);
	}
);
