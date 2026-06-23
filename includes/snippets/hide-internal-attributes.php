<?php
/**
 * Frontend: Hide Specific Product Attributes
 *
 * Hide the following - Grams Weight, Product Pack Data, Waste Pack Data & Bullets
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter('woocommerce_display_product_attributes', function ($product_attributes, $product) {
    $hide_ids = [
        '18017','27544','27545','27546','27547','27548',
        '27549','27550','27551','27552','27553','27554',
        '27555','9023','9024','9025','9026','9027','27556','9849'
    ];

    $hide_id_pattern = '/^pa_epim-(' . implode('|', $hide_ids) . ')$/';

    return array_filter($product_attributes, function ($attr, $key) use ($hide_id_pattern) {
        if (preg_match($hide_id_pattern, $key)) {
            return false;
        }

        if (str_starts_with($key, 'pa_bullet-')) {
            return false;
        }

        if (str_starts_with($key, 'pa_ecd-')) {
            return false;
        }

        if (str_starts_with(strtolower($attr['label'] ?? ''), 'bullet')) {
            return false;
        }

        return true;
    }, ARRAY_FILTER_USE_BOTH);
}, 10, 2);
