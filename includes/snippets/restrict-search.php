<?php
/**
 * Frontend: Restrict Search to Products & Sku Only
 *
 * Ensure other post types are not included in the global search results and display Sku.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('pre_get_posts', function($query) {
	if (!is_admin() && $query->is_main_query() && $query->is_search()) {
		// Restrict to WooCommerce products
		$query->set('post_type', ['product']);

		// Preserve sorting and pagination. These are read-only, bookmarkable query vars
		// (no form submission), so nonce verification does not apply.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (isset($_GET['orderby'])) {
			$query->set('orderby', sanitize_text_field(wp_unslash($_GET['orderby'])));
		}
		if (isset($_GET['order'])) {
			$query->set('order', sanitize_text_field(wp_unslash($_GET['order'])));
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
});
