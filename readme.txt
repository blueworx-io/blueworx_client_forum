=== External Product Images ===
Contributors: blueworx
Tags: woocommerce, elementor, product gallery, product images
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later

Custom Elementor widget that replaces the WooCommerce Product Images widget on
single product pages with a self-contained, responsive external image gallery.

== Description ==

Provides a new Elementor widget, "External Product Images", that renders a
modern product gallery (main image + clickable thumbnails) without relying on
the native WooCommerce gallery output.

* Auto-detects the current WooCommerce product.
* Uses placeholder images now; switches to product meta image URLs later by
  changing a single file.
* Loads CSS/JS only on pages where the widget is used.
* Fully responsive and accessible. Vanilla JS, no jQuery dependency.

== Setup ==

1. Install and activate WooCommerce and Elementor (Pro optional).
2. Install and activate this plugin.
3. Edit a single product template (Templates > Theme Builder > Single Product,
   or any Elementor-built single product page).
4. Delete the existing "Product Images" (woocommerce-product-images.default)
   widget.
5. Drag in the "External Product Images" widget from the WooCommerce / General
   category. Save.

== Switching from placeholders to product meta ==

Open includes/class-epi-images-provider.php:

1. Set META_KEY to your real meta key.
2. Uncomment the "STEP 1 — READ FROM PRODUCT META" block in get_images().

The stored value may be a JSON array of URLs OR a comma-separated list — both
are handled automatically. Placeholders remain as a safety fallback.

== Changelog ==

= 1.0.3 =
* Replaced the demo gallery images with bundled lighting-product placeholders
  (self-contained SVGs themed for a wholesale lighting supplier). Removes the
  external image-service dependency so demo images can never fail to load.

= 1.0.0 =
* Initial release.
