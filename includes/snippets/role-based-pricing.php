<?php
/**
 * Frontend: Display Dynamic User Pricing
 *
 * Display pricing depending on which role a user has been assigned.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamic pricing based on user role (percentage discounts)
 *
 * price_customer_1 â 60% off (pays 40%)
 * price_customer_2 â 55% off (pays 45%)
 * price_customer_3 â 60% off then * 1.17, shown as EUR
 * price_customer_4 â fixed price from product attribute "ECD-special-prices" (fallback: price_customer_1 * 0.40)
 * Admins & shop managers â price_customer_4 attribute price (fallback: price_customer_1 * 0.40)
 * All others â no discount
 */
add_filter('woocommerce_product_get_price', 'custom_role_based_price', 10, 2);
add_filter('woocommerce_product_variation_get_price', 'custom_role_based_price', 10, 2);
function custom_role_based_price( $price, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $price;
	}
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $price;
	}
	$user = wp_get_current_user();
	if ( ! $user || ! $user->ID ) {
		return $price;
	}
	$roles = (array) $user->roles;
	$data       = $product->get_data();
	$base_price = isset( $data['regular_price'] ) && $data['regular_price'] !== ''
		? (float) $data['regular_price']
		: 0.0;
	if ( $base_price <= 0 ) {
		return $base_price;
	}
	// Admins + shop managers â price_customer_4 attribute price, fallback to price_customer_1
	if ( in_array( 'administrator', $roles, true ) || in_array( 'shop_manager', $roles, true ) ) {
		$attribute_price = custom_get_attribute_price( $product, 'ECD-special-prices' );
		if ( $attribute_price !== null ) {
			return $attribute_price;
		}
		return $base_price * 0.40;
	}
	if ( in_array( 'price_customer_1', $roles, true ) ) {
		return $base_price * 0.40;
	}
	if ( in_array( 'price_customer_2', $roles, true ) ) {
		return $base_price * 0.45;
	}
	if ( in_array( 'price_customer_3', $roles, true ) ) {
		return $base_price * 0.40 * 1.17;
	}
	if ( in_array( 'price_customer_4', $roles, true ) ) {
		$attribute_price = custom_get_attribute_price( $product, 'ECD-special-prices' );
		if ( $attribute_price !== null ) {
			return $attribute_price;
		}
		return $base_price * 0.40;
	}
	// Everyone else
	return $base_price;
}
/**
 * Helper: retrieve the fixed price from a global taxonomy product attribute.
 * WooCommerce prefixes global attribute slugs with 'pa_' and runs the name
 * through sanitize_title(), so "ECD-special-prices" becomes "pa_ecd-special-prices".
 * get_options() returns term IDs for taxonomy attributes, so we resolve the
 * term to get its name, which holds the actual price value.
 *
 * @param WC_Product $product
 * @param string     $attribute_slug  Base slug without 'pa_' prefix.
 * @return float|null
 */
function custom_get_attribute_price( $product, $attribute_slug ) {
	$attributes = $product->get_attributes();
	$key        = 'pa_' . sanitize_title( $attribute_slug );
	if ( ! isset( $attributes[ $key ] ) ) {
		return null;
	}
	$attribute = $attributes[ $key ];
	$options   = $attribute->get_options();
	if ( empty( $options ) ) {
		return null;
	}
	$term = get_term( reset( $options ), $key );
	if ( is_wp_error( $term ) || ! $term instanceof WP_Term ) {
		return null;
	}
	$value = trim( $term->name );
	if ( ! is_numeric( $value ) ) {
		return null;
	}
	return (float) $value;
}
/**
 * Force EUR currency for price_customer_3 only
 */
add_filter( 'woocommerce_currency', function ( $currency ) {
	$user = wp_get_current_user();
	if ( ! $user || ! $user->ID ) {
		return $currency;
	}
	$roles = (array) $user->roles;
	if ( in_array( 'price_customer_3', $roles, true ) ) {
		return 'EUR';
	}
	return $currency;
} );
/**
 * Shortcode for Elementor / manual placement
 */
add_shortcode( 'dynamic_product_price', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return '';
	}
	$price_html = wc_price( $product->get_price() );
	return '<p class="price">' . $price_html . ' <span class="ex-vat-label">ex. VAT | Net Trade Including Discount</span></p>';
} );
