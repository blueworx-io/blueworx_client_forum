<?php
/**
 * Frontend: Hide N/A Attributes
 *
 * Ensure all attributes without a value are hidden from the Frontend
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter('woocommerce_display_product_attributes', function ($product_attributes) {
    foreach ($product_attributes as $key => $attribute) {
        $value = trim(strip_tags($attribute['value'] ?? ''));
        if ($value === '' || preg_match('/^(N\/?A)$/i', $value)) {
            unset($product_attributes[$key]);
        }
    }
    return $product_attributes;
});
