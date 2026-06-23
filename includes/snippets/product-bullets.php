<?php
/**
 * Frontend: Display Attributes as Bullets
 *
 * Render the attribute bullets in a frontend list via shortcode
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode('product_bullets', function () {
    global $product;
    if (! $product instanceof WC_Product) return '';

    $bullets = [];

    foreach ($product->get_attributes() as $key => $attr) {
        if (strpos($key, 'pa_bullet-') === 0) {
            $values = $attr->get_options();

            foreach ($values as $val_id) {
                $term = get_term($val_id);

                if ($term && !is_wp_error($term)) {
                    $value = trim($term->name);

                    if ($value !== '' && !preg_match('/^(N\/?A)$/i', $value)) {
                        $bullets[] = esc_html(ucfirst(strtolower($value)));
                    }
                }
            }
        }
    }

    if (empty($bullets)) return '';

    $output = '<ul class="product-bullets">';

    foreach ($bullets as $b) {
        $output .= '<li>' . $b . '</li>';
    }

    $output .= '</ul>';

    return $output;
});
