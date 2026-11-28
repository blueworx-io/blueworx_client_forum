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
 *  - a shop page never lists more than 48 products, whether it is the shop
 *    itself, a category, tag or attribute page, a product block or a shortcode.
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
 * Whether a page size is over the limit. -1 means no limit, and WordPress reads
 * any other negative size as its positive value, so -5 is five and -100 is a
 * hundred.
 *
 * @param int $size A page size.
 * @return bool
 */
function epi_trim_size_is_over_limit( $size ) {
	$size = (int) $size;

	return -1 === $size || abs( $size ) > epi_trim_max_products_per_page();
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

	// Categories, tags and every product attribute (colour, size and so on).
	$taxonomies = array_unique( array_merge( array( 'product_cat', 'product_tag' ), get_object_taxonomies( 'product' ) ) );

	if ( ! $query->is_post_type_archive( 'product' ) && ! $query->is_tax( $taxonomies ) ) {
		return;
	}

	$max = epi_trim_max_products_per_page();

	// WordPress turns a size below -1, such as -100, into its positive value
	// once this hook has run, so that counts as 100. A size already under the
	// limit is left as it is.
	if ( epi_trim_size_is_over_limit( $query->get( 'posts_per_page' ) ) ) {
		$query->set( 'posts_per_page', $max );
	}

	// The older "showposts" setting replaces the page size after this hook.
	$showposts = (int) $query->get( 'showposts' );

	if ( 0 !== $showposts && epi_trim_size_is_over_limit( $showposts ) ) {
		$query->set( 'showposts', $max );
	}

	// WordPress applies these two after this hook has run: one swaps the page
	// size for the archive setting, the other turns paging off altogether.
	$archive = (int) $query->get( 'posts_per_archive_page' );

	if ( 0 !== $archive && epi_trim_size_is_over_limit( $archive ) ) {
		$query->set( 'posts_per_archive_page', $max );
	}

	if ( $query->get( 'nopaging' ) ) {
		$query->set( 'nopaging', false );
	}
}
add_action( 'pre_get_posts', 'epi_trim_cap_listing_query', 9999 );

/**
 * Cap the page size of a product list built by a block, such as the
 * WooCommerce Product Collection block, which sets its own query. The block
 * works out which products to skip from the size it asked for, so once the
 * size is cut, the skip is worked out again from the new size. Without that,
 * page 2 of a block that asked for 100 would start at product 101 and never
 * show products 49 to 100. The page count follows from the new size.
 *
 * @param array         $query Query arguments the block will run.
 * @param WP_Block|null $block The block, when WordPress passes it.
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

	if ( ! isset( $query['posts_per_page'] ) || ! epi_trim_size_is_over_limit( $query['posts_per_page'] ) ) {
		return $query;
	}

	$max                    = epi_trim_max_products_per_page();
	$query['posts_per_page'] = $max;

	if ( isset( $query['offset'] ) ) {
		// Products the block was told to skip at the start, apart from paging.
		$skip = 0;

		if ( is_object( $block ) && isset( $block->context['query']['offset'] ) && is_numeric( $block->context['query']['offset'] ) ) {
			$skip = (int) $block->context['query']['offset'];
		}

		$query['offset'] = ( $max * ( max( 1, (int) $page ) - 1 ) ) + $skip;
	}

	return $query;
}
add_filter( 'query_loop_block_query_vars', 'epi_trim_cap_block_query', 9999, 3 );

/**
 * Cap the page size of a product shortcode grid, such as [products],
 * [product_category] or [recent_products]. The shortcode works out its page
 * count from the query it runs, so that follows from the new size.
 *
 * @param array $query_args The query the shortcode will run.
 * @return array
 */
function epi_trim_cap_shortcode_query( $query_args ) {
	if ( is_array( $query_args ) && isset( $query_args['posts_per_page'] ) && epi_trim_size_is_over_limit( $query_args['posts_per_page'] ) ) {
		$query_args['posts_per_page'] = epi_trim_max_products_per_page();
	}

	return $query_args;
}
add_filter( 'woocommerce_shortcode_products_query', 'epi_trim_cap_shortcode_query', 9999 );
