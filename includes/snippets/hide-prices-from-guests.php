<?php
/**
 * Frontend: Hide Price From Logged Out Users
 *
 * Only show price to users that are logged in, ex vat is displayed.
 *
 * Moved from the Code Snippets plugin. The only edit made during the move is the
 * corrupted pound sign in the output below ("Â£" -> "£"), so the price renders
 * correctly as it does on the live site.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'woocommerce_get_price_html', 'epi_show_ex_vat_price_logged_in_only', 100, 2 );

function epi_show_ex_vat_price_logged_in_only( $price, $product ) {

	// Guests see nothing
	if ( ! is_user_logged_in() ) {
		return '';
	}

	// Logged-in users see EX VAT price only
	$price_ex_vat = wc_get_price_excluding_tax( $product );

	return '<span class="price"><span class="woocommerce-Price-amount amount">£'
		. wc_format_decimal( $price_ex_vat, 2 )
		. ' ex. VAT</span></span>';
}
