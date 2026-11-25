<?php
/**
 * Frontend: Stop guests and bots triggering slow cart and shop pages
 *
 * Guests cannot buy on this shop, yet search engines and bots still follow
 * add-to-cart links, and every visit asks the server to rebuild the page with
 * the cache skipped. This stops that work from being started for nothing:
 *
 *  - a guest following an add-to-cart link is sent straight back to the page;
 *  - logged-in (trade) users get WooCommerce's quick add-to-cart on shop pages;
 *  - guests are not given the cart-check script or the mini-cart;
 *  - robots.txt keeps search engines off add-to-cart links;
 *  - a shop page never lists more than 48 products.
 *
 * The guest parts only apply while "Hide cart & purchasing for guests" is on,
 * because they would break a shop where guests can buy.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The most products one shop page may list. Filterable so a site can change it
 * without editing the plugin.
 *
 * @return int
 */
function epi_trim_max_products_per_page() {
	return max( 1, (int) apply_filters( 'epi_max_products_per_page', 48 ) );
}

/**
 * Whether this visitor is a guest on a shop where guests cannot buy.
 *
 * @return bool
 */
function epi_trim_is_blocked_guest() {
	if ( is_user_logged_in() ) {
		return false;
	}

	return EPI_Feature_Registry::is_enabled( 'hide-cart-for-guests' );
}

/**
 * Whether this is a REST request. REST_REQUEST is only set after wp_loaded, so
 * the address is read as well.
 *
 * @return bool
 */
function epi_trim_is_rest_request() {
	if ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() ) {
		return true;
	}

	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only read to recognise the route.
	if ( isset( $_GET['rest_route'] ) ) {
		return true;
	}

	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only compared, never output.
	$path   = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$prefix = '/' . rest_get_url_prefix() . '/';

	return false !== strpos( $path, $prefix );
}

/**
 * Send a guest who followed an add-to-cart link back to the page, without the
 * add. Runs before WooCommerce's own add-to-cart handler (wp_loaded, 20).
 *
 * @return void
 */
function epi_trim_redirect_guest_add_to_cart() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || epi_trim_is_rest_request() ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Nothing is changed: the request is only turned away.
	if ( ! isset( $_REQUEST['add-to-cart'] ) || ! epi_trim_is_blocked_guest() ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used to build a redirect, which is validated below.

	$remove = array( 'add-to-cart', 'quantity', 'variation_id' );
	$query  = (string) wp_parse_url( $uri, PHP_URL_QUERY );

	if ( '' !== $query ) {
		parse_str( $query, $args );

		foreach ( array_keys( $args ) as $key ) {
			if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
				$remove[] = $key;
			}
		}
	}

	$clean  = remove_query_arg( $remove, $uri );
	$target = wp_validate_redirect( $clean, home_url( '/' ) );

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

	// A form post goes back as a plain page view; a link stays a temporary
	// redirect so a logged-in visitor's browser never remembers it.
	wp_safe_redirect( $target, 'POST' === $method ? 303 : 302 );
	exit;
}
add_action( 'wp_loaded', 'epi_trim_redirect_guest_add_to_cart', 1 );

/**
 * Quick (ajax) add-to-cart on shop pages for logged-in users. The setting
 * screen in the admin still shows what was saved. It answers before the
 * database is asked, because a shop that never saved the setting has no row.
 *
 * @param mixed $pre Value to short-circuit with, or false to carry on.
 * @return mixed
 */
function epi_trim_quick_add_to_cart( $pre ) {
	if ( is_admin() || ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
		return $pre;
	}

	return 'yes';
}
add_filter( 'pre_option_woocommerce_enable_ajax_add_to_cart', 'epi_trim_quick_add_to_cart' );

/**
 * Give guests no cart-check script. It is swapped for an empty one of the same
 * name, so scripts that list it as a dependency still load.
 *
 * @return void
 */
function epi_trim_drop_cart_fragments() {
	if ( ! epi_trim_is_blocked_guest() ) {
		return;
	}

	wp_deregister_script( 'wc-cart-fragments' );
	wp_register_script( 'wc-cart-fragments', false, array( 'jquery' ), EPI_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'epi_trim_drop_cart_fragments', 100 );

/**
 * Answer a guest's cart check with nothing, in case a cached page still asks.
 *
 * @return void
 */
function epi_trim_answer_guest_cart_check() {
	if ( ! epi_trim_is_blocked_guest() ) {
		return;
	}

	wp_send_json(
		array(
			'fragments' => new stdClass(),
			'cart_hash' => '',
		)
	);
}
add_action( 'wc_ajax_get_refreshed_fragments', 'epi_trim_answer_guest_cart_check', 0 );
add_action( 'wp_ajax_nopriv_woocommerce_get_refreshed_fragments', 'epi_trim_answer_guest_cart_check', 0 );

/**
 * Hide the mini-cart widget from guests.
 *
 * @param bool $hidden Whether WooCommerce hides the widget.
 * @return bool
 */
function epi_trim_hide_mini_cart( $hidden ) {
	return epi_trim_is_blocked_guest() ? true : $hidden;
}
add_filter( 'woocommerce_widget_cart_is_hidden', 'epi_trim_hide_mini_cart' );

/**
 * Skip the Mini-Cart block for guests before it renders, so it never loads
 * cart data, queues its scripts or schedules the cart drawer.
 *
 * @param string|null $pre          Markup to use instead of rendering, or null.
 * @param array       $parsed_block The block about to render.
 * @return string|null
 */
function epi_trim_skip_mini_cart_block( $pre, $parsed_block ) {
	if ( isset( $parsed_block['blockName'] ) && 'woocommerce/mini-cart' === $parsed_block['blockName'] && epi_trim_is_blocked_guest() ) {
		return '';
	}

	return $pre;
}
add_filter( 'pre_render_block', 'epi_trim_skip_mini_cart_block', 10, 2 );

/**
 * Ask search engines to stay off add-to-cart links.
 *
 * @param string $output The robots.txt body.
 * @return string
 */
function epi_trim_robots_txt( $output ) {
	return rtrim( (string) $output ) . "\n\nUser-agent: *\nDisallow: /*add-to-cart=\n";
}
add_filter( 'robots_txt', 'epi_trim_robots_txt', 99 );

/**
 * Cap the per-page count WooCommerce asks for.
 *
 * @param mixed $per_page Products per page.
 * @return int
 */
function epi_trim_cap_per_page( $per_page ) {
	return min( (int) $per_page, epi_trim_max_products_per_page() );
}
add_filter( 'loop_shop_per_page', 'epi_trim_cap_per_page', 9999 );

/**
 * Whether a requested page size is over the limit. WordPress reads -1 as "all"
 * and any other size below zero, such as -100, as its positive value.
 *
 * @param mixed $size Products asked for.
 * @return bool
 */
function epi_trim_is_over_limit( $size ) {
	$size = (int) $size;

	return -1 === $size || abs( $size ) > epi_trim_max_products_per_page();
}

/**
 * Cap a product listing that asked for more than the limit by another route,
 * such as a page-size setting on the address. Runs after WooCommerce's own
 * query set-up (priority 10).
 *
 * @param WP_Query $query The query.
 * @return void
 */
function epi_trim_cap_listing_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	// The same listings WooCommerce treats as shop pages: the shop itself and
	// every product taxonomy, attributes such as colour included.
	$taxonomies = get_object_taxonomies( 'product' );

	if ( ! $query->is_post_type_archive( 'product' ) && ! ( $taxonomies && $query->is_tax( $taxonomies ) ) ) {
		return;
	}

	$max = epi_trim_max_products_per_page();

	// WordPress lets the last two replace the page size after this hook has run.
	foreach ( array( 'posts_per_page', 'showposts', 'posts_per_archive_page' ) as $setting ) {
		if ( epi_trim_is_over_limit( $query->get( $setting ) ) ) {
			$query->set( $setting, $max );
		}
	}

	// This turns paging off altogether.
	if ( $query->get( 'nopaging' ) ) {
		$query->set( 'nopaging', false );
	}
}
add_action( 'pre_get_posts', 'epi_trim_cap_listing_query', 9999 );

/**
 * Whether a block's own query settings ask for more products than the limit. A
 * block that shows the page's main listing is capped with that listing instead.
 *
 * @param mixed $settings The block's "query" settings.
 * @return bool
 */
function epi_trim_is_oversized_block( $settings ) {
	if ( ! is_array( $settings ) || ! empty( $settings['inherit'] ) || ! isset( $settings['perPage'] ) ) {
		return false;
	}

	if ( ! isset( $settings['postType'] ) || ! in_array( 'product', (array) $settings['postType'], true ) ) {
		return false;
	}

	return is_numeric( $settings['perPage'] ) && absint( $settings['perPage'] ) > epi_trim_max_products_per_page();
}

/**
 * Cap a product block at its settings, before WordPress or WooCommerce works
 * out where each page starts. A block held to a number of pages gets as many
 * smaller pages as it needs to show the same products.
 *
 * @param array $context The block's context.
 * @return array
 */
function epi_trim_cap_block_context( $context ) {
	if ( ! isset( $context['query'] ) || ! epi_trim_is_oversized_block( $context['query'] ) ) {
		return $context;
	}

	$max   = epi_trim_max_products_per_page();
	$size  = absint( $context['query']['perPage'] );
	$pages = isset( $context['query']['pages'] ) ? absint( $context['query']['pages'] ) : 0;

	$context['query']['perPage'] = $max;

	if ( $pages > 0 ) {
		$context['query']['pages'] = (int) ceil( $pages * $size / $max );
	}

	return $context;
}
add_filter( 'render_block_context', 'epi_trim_cap_block_context', 9999 );

/**
 * Cap the query of a product block whose size other code has raised. Each page
 * starts where the one before it now ends.
 *
 * @param array         $query Query arguments the block will run.
 * @param WP_Block|null $block The block.
 * @param int           $page  The page being shown.
 * @return array
 */
function epi_trim_cap_block_query( $query, $block = null, $page = 1 ) {
	if ( ! is_array( $query ) || ! isset( $query['post_type'] ) ) {
		return $query;
	}

	if ( ! in_array( 'product', (array) $query['post_type'], true ) ) {
		return $query;
	}

	if ( ! isset( $query['posts_per_page'] ) || ! epi_trim_is_over_limit( $query['posts_per_page'] ) ) {
		return $query;
	}

	$max = epi_trim_max_products_per_page();

	$query['posts_per_page'] = $max;

	if ( isset( $query['offset'], $block->context['query'] ) ) {
		$skip = isset( $block->context['query']['offset'] ) ? absint( $block->context['query']['offset'] ) : 0;

		$query['offset'] = $skip + $max * ( max( 1, (int) $page ) - 1 );
	}

	return $query;
}
add_filter( 'query_loop_block_query_vars', 'epi_trim_cap_block_query', 9999, 3 );

/**
 * Give a product block that was cut down page numbers, if it was built without
 * them, so the rest of its products can still be reached.
 *
 * @param string        $content  The block's markup.
 * @param array         $parsed   The block as parsed.
 * @param WP_Block|null $instance The block.
 * @return string
 */
function epi_trim_add_block_page_numbers( $content, $parsed, $instance = null ) {
	if ( ! isset( $parsed['blockName'] ) || ! in_array( $parsed['blockName'], array( 'core/query', 'woocommerce/product-collection' ), true ) ) {
		return $content;
	}

	if ( ! $instance instanceof WP_Block || ! isset( $instance->attributes['query'] ) || ! epi_trim_is_oversized_block( $instance->attributes['query'] ) ) {
		return $content;
	}

	$end = strrpos( $content, '</' );

	if ( false === $end || false !== strpos( $content, 'wp-block-query-pagination' ) ) {
		return $content;
	}

	$context = $instance->context;

	foreach ( (array) $instance->block_type->provides_context as $name => $attribute ) {
		if ( array_key_exists( $attribute, $instance->attributes ) ) {
			$context[ $name ] = $instance->attributes[ $attribute ];
		}
	}

	$numbers = parse_blocks( '<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->' );
	$block   = new WP_Block( $numbers[0], epi_trim_cap_block_context( $context ) );

	return substr_replace( $content, $block->render(), $end, 0 );
}
add_filter( 'render_block', 'epi_trim_add_block_page_numbers', 10, 3 );

/**
 * Cap a product grid built by a WooCommerce shortcode such as [products]. One
 * with page numbers of its own just gets smaller pages. One without is split
 * into pages here, so the rest of its products can still be reached.
 *
 * @param array  $args       Query arguments the shortcode will run.
 * @param array  $attributes The shortcode's settings.
 * @param string $type       Which shortcode it is.
 * @return array
 */
function epi_trim_cap_shortcode_query( $args, $attributes = array(), $type = 'products' ) {
	if ( ! is_array( $args ) || ! isset( $args['posts_per_page'] ) || ! epi_trim_is_over_limit( $args['posts_per_page'] ) ) {
		return $args;
	}

	$max = epi_trim_max_products_per_page();

	// One product, or a hand-picked list that fits, cannot go over.
	if ( ! empty( $args['p'] ) || ( ! empty( $args['post__in'] ) && count( (array) $args['post__in'] ) <= $max ) ) {
		return $args;
	}

	$size = (int) $args['posts_per_page'];

	$args['posts_per_page'] = $max;

	if ( isset( $attributes['paginate'] ) && filter_var( $attributes['paginate'], FILTER_VALIDATE_BOOLEAN ) ) {
		return $args;
	}

	$limit = -1 === $size ? 0 : abs( $size );
	$paged = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
	$skip  = isset( $args['offset'] ) ? absint( $args['offset'] ) : $limit * ( $paged - 1 );

	// Its own page number on the address, so paging one grid leaves any other
	// grid on the page where it was.
	$name = 'products-page-' . substr( md5( $type . wp_json_encode( $args ) . $size ), 0, 8 );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which page to show.
	$page = isset( $_GET[ $name ] ) && is_scalar( $_GET[ $name ] ) ? max( 1, absint( wp_unslash( $_GET[ $name ] ) ) ) : 1;

	if ( $limit > 0 ) {
		$page = min( $page, (int) ceil( $limit / $max ) );

		$args['posts_per_page'] = min( $max, $limit - $max * ( $page - 1 ) );
	}

	unset( $args['paged'] );

	$args['offset']        = $skip + $max * ( $page - 1 );
	$args['no_found_rows'] = false;
	$args['epi_trim']      = compact( 'name', 'page', 'limit', 'skip' );

	return $args;
}
add_filter( 'woocommerce_shortcode_products_query', 'epi_trim_cap_shortcode_query', 9999, 3 );

/**
 * Work out how many pages a shortcode grid split above has, and put page
 * numbers under it.
 *
 * @param object $results   What the shortcode's query found.
 * @param object $shortcode The shortcode.
 * @return object
 */
function epi_trim_page_shortcode_results( $results, $shortcode ) {
	global $epi_trim_shortcode_pages;

	if ( ! is_object( $results ) || ! is_object( $shortcode ) || ! method_exists( $shortcode, 'get_query_args' ) || ! method_exists( $shortcode, 'get_type' ) ) {
		return $results;
	}

	$args = $shortcode->get_query_args();
	$hook = 'woocommerce_shortcode_after_' . $shortcode->get_type() . '_loop';

	unset( $epi_trim_shortcode_pages[ $hook ] );

	if ( empty( $args['epi_trim'] ) ) {
		return $results;
	}

	$split = $args['epi_trim'];
	$total = max( 0, (int) $results->total - $split['skip'] );

	if ( $split['limit'] > 0 ) {
		$total = min( $total, $split['limit'] );
	}

	// WooCommerce may keep the original for the next visit, so change a copy.
	$results               = clone $results;
	$results->total        = $total;
	$results->total_pages  = (int) ceil( $total / epi_trim_max_products_per_page() );
	$results->per_page     = epi_trim_max_products_per_page();
	$results->current_page = $split['page'];

	if ( $results->total_pages > 1 ) {
		$epi_trim_shortcode_pages[ $hook ] = array(
			'total'   => $results->total_pages,
			'current' => $results->current_page,
			'base'    => esc_url_raw( add_query_arg( $split['name'], '%#%', false ) ),
			'format'  => '?' . $split['name'] . '=%#%',
		);

		add_action( $hook, 'epi_trim_print_shortcode_page_numbers' );
	}

	return $results;
}
add_filter( 'woocommerce_shortcode_products_query_results', 'epi_trim_page_shortcode_results', 9999, 2 );

/**
 * Print the page numbers of a shortcode grid split above.
 *
 * @return void
 */
function epi_trim_print_shortcode_page_numbers() {
	global $epi_trim_shortcode_pages;

	$hook = current_action();

	if ( empty( $epi_trim_shortcode_pages[ $hook ] ) || ! function_exists( 'wc_get_template' ) ) {
		return;
	}

	wc_get_template( 'loop/pagination.php', $epi_trim_shortcode_pages[ $hook ] );

	unset( $epi_trim_shortcode_pages[ $hook ] );
}
