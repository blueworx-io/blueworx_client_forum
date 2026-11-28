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
 *  - a list with page numbers never shows more than 48 products a page.
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
 * Whether a block has page numbers, or a "next page" link, for its own list.
 * Those of another list nested inside it do not count.
 *
 * @param array $blocks The blocks inside it, as parsed.
 * @return bool
 */
function epi_trim_has_page_links( $blocks ) {
	foreach ( $blocks as $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

		if ( in_array( $name, array( 'core/query-pagination-numbers', 'core/query-pagination-next' ), true ) ) {
			return true;
		}

		if ( in_array( $name, array( 'core/query', 'woocommerce/product-collection' ), true ) ) {
			continue;
		}

		if ( ! empty( $block['innerBlocks'] ) && epi_trim_has_page_links( $block['innerBlocks'] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Mark the lists that have page links of their own, for the cap below.
 *
 * Blocks inside others are marked with the outermost one, because their
 * settings are read as soon as that one starts. A list inside a synced pattern
 * is only expanded later, so it is not marked and keeps the size it was set to.
 *
 * @param array         $block  The block about to render, as parsed.
 * @param array         $source The same block before any changes.
 * @param WP_Block|null $parent The block it sits in, if any.
 * @return array
 */
function epi_trim_mark_block( $block, $source = array(), $parent = null ) {
	return null === $parent ? epi_trim_mark_block_tree( $block ) : $block;
}
add_filter( 'render_block_data', 'epi_trim_mark_block', 9999, 3 );

/**
 * Mark one block, and every block inside it.
 *
 * @param array $block The block, as parsed.
 * @return array
 */
function epi_trim_mark_block_tree( $block ) {
	if ( empty( $block['innerBlocks'] ) ) {
		return $block;
	}

	if ( in_array( $block['blockName'], array( 'core/query', 'woocommerce/product-collection' ), true ) && isset( $block['attrs']['query'] ) && is_array( $block['attrs']['query'] ) ) {
		// Related products are picked afresh, in random order, for each page.
		$kinds   = array_intersect_key( $block['attrs'], array_flip( array( 'namespace', 'collection' ) ) );
		$related = array_intersect( $kinds, array( 'woocommerce/related-products', 'woocommerce/product-collection/related' ) );

		if ( ! $related && epi_trim_has_page_links( $block['innerBlocks'] ) ) {
			$block['attrs']['query']['epiTrim'] = true;
		}
	}

	$block['innerBlocks'] = array_map( 'epi_trim_mark_block_tree', $block['innerBlocks'] );

	return $block;
}

/**
 * Whether a block's pages follow on from each other in the plain way, starting
 * at its first product, so smaller pages still reach everything. Looked at with
 * every other filter applied, and the same whichever page is being shown.
 *
 * @param WP_Block $block The block.
 * @return bool
 */
function epi_trim_has_plain_pages( $block ) {
	$first  = build_query_vars_from_query_block( $block, 1 );
	$second = build_query_vars_from_query_block( $block, 2 );

	if ( ! isset( $first['offset'], $second['offset'], $first['posts_per_page'], $second['posts_per_page'] ) ) {
		return false;
	}

	if ( 0 !== (int) $first['offset'] || (int) $first['posts_per_page'] !== (int) $second['posts_per_page'] ) {
		return false;
	}

	$sizes = array( (int) $first['posts_per_page'] );

	// Other code may raise the size and leave the start of each page alone.
	if ( isset( $block->context['query']['perPage'] ) ) {
		$sizes[] = absint( $block->context['query']['perPage'] );
	}

	return in_array( (int) $second['offset'], $sizes, true );
}

/**
 * Cap a product block that has page links, with each page starting where the
 * one before it now ends. Left as it is: a block held to a number of pages, one
 * in random order (which is shuffled again for each page), and one whose list
 * or pages start anywhere but the plain place.
 *
 * @param array         $query Query arguments the block will run.
 * @param WP_Block|null $block The block.
 * @param int           $page  The page being shown.
 * @return array
 */
function epi_trim_cap_block_query( $query, $block = null, $page = 1 ) {
	static $checking = false;

	if ( $checking || ! isset( $block->context['query']['epiTrim'] ) || ! empty( $block->context['query']['pages'] ) ) {
		return $query;
	}

	if ( ! isset( $query['post_type'], $query['posts_per_page'] ) || ! in_array( 'product', (array) $query['post_type'], true ) ) {
		return $query;
	}

	if ( ! epi_trim_is_over_limit( $query['posts_per_page'] ) || ( isset( $query['orderby'] ) && 'rand' === $query['orderby'] ) ) {
		return $query;
	}

	$checking = true;
	$plain    = epi_trim_has_plain_pages( $block );
	$checking = false;

	if ( $plain ) {
		$max = epi_trim_max_products_per_page();

		$query['posts_per_page'] = $max;
		$query['offset']         = $max * ( max( 1, (int) $page ) - 1 );
	}

	return $query;
}
add_filter( 'query_loop_block_query_vars', 'epi_trim_cap_block_query', 9999, 3 );

/**
 * Cap a product grid built by a WooCommerce shortcode such as [products], when
 * WooCommerce prints page numbers under it. One without them is left as it is,
 * because the products past the limit could not be reached. So is one in random
 * order, which is shuffled again for each page, and one with a fixed start
 * point, even zero, which makes WordPress ignore the page being shown.
 *
 * @param array $args       Query arguments the shortcode will run.
 * @param array $attributes The shortcode's settings.
 * @return array
 */
function epi_trim_cap_shortcode_query( $args, $attributes = array() ) {
	if ( ! is_array( $args ) || ! isset( $args['posts_per_page'] ) || ! epi_trim_is_over_limit( $args['posts_per_page'] ) ) {
		return $args;
	}

	if ( ! function_exists( 'wc_string_to_bool' ) || ! isset( $attributes['paginate'] ) || ! wc_string_to_bool( $attributes['paginate'] ) ) {
		return $args;
	}

	// A theme may have taken WooCommerce's page numbers away.
	if ( false === has_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination' ) ) {
		return $args;
	}

	if ( array_key_exists( 'offset', $args ) || ( isset( $args['orderby'] ) && 'rand' === $args['orderby'] ) ) {
		return $args;
	}

	$args['posts_per_page'] = epi_trim_max_products_per_page();
	$args['no_found_rows']  = false;

	return $args;
}
add_filter( 'woocommerce_shortcode_products_query', 'epi_trim_cap_shortcode_query', 9999, 2 );
