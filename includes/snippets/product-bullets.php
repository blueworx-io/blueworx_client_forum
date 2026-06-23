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
    $seq     = 0;

    foreach ($product->get_attributes() as $key => $attr) {
        if (! $attr instanceof WC_Product_Attribute) {
            continue;
        }

        // Match both custom "Bullet N" attributes and legacy pa_bullet-* taxonomies.
        $name       = (string) $attr->get_name();
        $normalised = preg_replace('/^pa_/', '', $name);

        $is_bullet = (bool) preg_match('/^bullet/i', trim($normalised))
            || strpos($key, 'pa_bullet-') === 0
            || strpos($key, 'bullet-') === 0;

        if (! $is_bullet) {
            continue;
        }

        // Sort position taken from the first number in the name (Bullet 1, 2, 3...).
        $order = PHP_INT_MAX;
        if (preg_match('/(\d+)/', $name, $m)) {
            $order = (int) $m[1];
        }

        foreach ($attr->get_options() as $option) {
            if ($attr->is_taxonomy()) {
                // Global taxonomy attribute: options are term IDs.
                $term  = get_term($option);
                $value = ($term && ! is_wp_error($term)) ? $term->name : '';
            } else {
                // Custom (local) attribute: options are the raw string values.
                $value = is_scalar($option) ? (string) $option : '';
            }

            $value = trim($value);

            if ($value === '' || preg_match('/^(N\/?A)$/i', $value)) {
                continue;
            }

            $bullets[] = [
                'order' => $order,
                'seq'   => $seq++,
                'text'  => esc_html(ucfirst(strtolower($value))),
            ];
        }
    }

    if (empty($bullets)) return '';

    // Order by bullet number, falling back to discovery order for ties.
    usort($bullets, function ($a, $b) {
        return [$a['order'], $a['seq']] <=> [$b['order'], $b['seq']];
    });

    $output = '<ul class="product-bullets">';

    foreach ($bullets as $b) {
        $output .= '<li>' . $b['text'] . '</li>';
    }

    $output .= '</ul>';

    return $output;
});
