<?php
/**
 * Frontend: Hide WooCommerce Cart Buttons
 *
 * Hide all WooCommerce "Add to cart" buttons for logged out users.
 *
 * Moved verbatim from the Code Snippets plugin (two original snippet blocks,
 * combined into one toggleable feature).
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'woocommerce_is_purchasable', 'hide_add_to_cart_for_guests', 10, 2 );
function hide_add_to_cart_for_guests( $purchasable, $product ) {
    if ( ! is_user_logged_in() ) {
        return false;
    }
    return $purchasable;
}

add_action( 'init', function() {
    if ( ! is_user_logged_in() ) {
        remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
    }
});
