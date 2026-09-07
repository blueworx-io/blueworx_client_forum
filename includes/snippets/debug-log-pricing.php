<?php
/**
 * Frontend: Console Log Product Pricing (debug)
 *
 * On a single product page, prints a collapsed console group showing the
 * current user's role, the original (regular) price, the live calculated
 * price for that user, and the full breakdown of every role-based pricing
 * tier for the product.
 *
 * The per-tier formulas mirror epi_role_based_price() in
 * includes/snippets/role-based-pricing.php. If those percentages change,
 * update them here too so the debug table stays truthful.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_footer', 'blueworx_console_log_product_pricing' );

/**
 * Emit the pricing debug console group on single product pages.
 *
 * @return void
 */
function blueworx_console_log_product_pricing() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$product = wc_get_product( get_queried_object_id() );
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	// Current user role(s).
	$user = wp_get_current_user();
	if ( is_user_logged_in() && ! empty( $user->roles ) ) {
		$roles = implode( ', ', (array) $user->roles );
	} else {
		$roles = 'guest';
	}

	// Original (unfiltered) price vs. the live price WooCommerce returns for the
	// current user after role-based-pricing has run.
	$regular_raw = $product->get_regular_price();
	$regular     = is_numeric( $regular_raw ) ? (float) $regular_raw : null;
	$live_raw    = $product->get_price();
	$live        = is_numeric( $live_raw ) ? (float) $live_raw : null;

	// Raw store currency (bypasses the customer_3 EUR override filter).
	$base_currency = get_option( 'woocommerce_currency', 'GBP' );

	// Every pricing tier for this product. Keep in sync with
	// epi_role_based_price() in role-based-pricing.php.
	$tiers = array();
	if ( null !== $regular && $regular > 0 ) {
		$attribute_price = function_exists( 'epi_get_attribute_price' )
			? epi_get_attribute_price( $product, 'ECD-special-prices' )
			: null;

		$tiers = array(
			array(
				'role'     => 'base (no discount)',
				'formula'  => 'regular',
				'price'    => round( $regular, 2 ),
				'currency' => $base_currency,
			),
			array(
				'role'     => 'price_customer_1',
				'formula'  => 'regular * 0.40 (60% off)',
				'price'    => round( $regular * 0.40, 2 ),
				'currency' => $base_currency,
			),
			array(
				'role'     => 'price_customer_2',
				'formula'  => 'regular * 0.45 (55% off)',
				'price'    => round( $regular * 0.45, 2 ),
				'currency' => $base_currency,
			),
			array(
				'role'     => 'price_customer_3',
				'formula'  => 'regular * 0.40 * 1.17',
				'price'    => round( $regular * 0.40 * 1.17, 2 ),
				'currency' => 'EUR',
			),
			array(
				'role'     => 'price_customer_4 / admin',
				'formula'  => null !== $attribute_price ? 'ECD-special-prices attribute' : 'regular * 0.40 (no attribute set)',
				'price'    => round( null !== $attribute_price ? $attribute_price : $regular * 0.40, 2 ),
				'currency' => $base_currency,
			),
		);
	}

	$data = array(
		'product'  => $product->get_name(),
		'id'       => $product->get_id(),
		'sku'      => $product->get_sku(),
		'roles'    => $roles,
		'regular'  => $regular,
		'live'     => $live,
		'currency' => $base_currency,
		'tiers'    => $tiers,
	);

	$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	if ( false === $json ) {
		return;
	}
	?>
	<script>
	( function () {
		var d = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode with hex flags is script-context safe. ?>;
		console.groupCollapsed(
			'%cBlueWorx%c pricing debug — ' + d.product + ' (#' + d.id + ( d.sku ? ' | ' + d.sku : '' ) + ')',
			'font-weight:bold;color:#2563eb',
			'color:inherit'
		);
		console.log( 'User role(s):', d.roles );
		console.log( 'Original (regular) price:', d.regular, d.currency );
		console.log( 'Live calculated price (this role):', d.live, d.currency );
		if ( d.tiers && d.tiers.length ) {
			console.log( 'All pricing tiers for this product:' );
			console.table( d.tiers );
		} else {
			console.log( 'Tier breakdown unavailable (no numeric regular price – e.g. a variable product).' );
		}
		console.groupEnd();
	} )();
	</script>
	<?php
}
