=== BlueWorx Lab | Forum Lighting ===
Contributors: blueworx
Tags: woocommerce, elementor, product gallery, product images, site functionality
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv2 or later

Site functionality plugin for Forum Lighting: one control centre to switch the WooCommerce gallery, metadata, pricing and more on or off.

== Description ==

Provides a new Elementor widget, "External Product Images", that renders a
modern product gallery (main image + clickable thumbnails) without relying on
the native WooCommerce gallery output.

* Auto-detects the current WooCommerce product.
* Shows product and WooCommerce metadata in a searchable admin section.
* Copies metadata to the clipboard or downloads it as a JSON file.
* Keeps a full product change log for users, imports, and external APIs.
* Shows when each product was last updated and who edited it.
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

= 1.2.1 =
* Aligned the plugin text domain with the plugin slug so all translatable
  strings resolve correctly.
* Hardened admin input handling (unslashing and sanitising query and form
  data) and documented the intentional direct database queries used by the
  change log and the image-purge tool.
* Switched the N/A attribute filter to wp_strip_all_tags() and added the
  missing translators comment.

= 1.2.0 =
* Product gallery and the WooCommerce featured image now load product imagery
  directly from the external ePim asset service, built from the product image
  IDs (_thumbnail_id and _product_image_gallery) — no media-library imports.
* Added a "ePim featured image" feature toggle under Settings > BlueWorx Lab.
* Products with no image IDs (or a missing ePim asset) fall back to the bundled
  placeholder images.

= 1.1.1 =
* Fixed the [product_bullets] shortcode so it renders custom (local) "Bullet"
  product attributes, not just the legacy global pa_bullet-* taxonomies.
* Bullets now display in numeric order (Bullet 1, 2, 3...) regardless of the
  attribute order on the edit screen.

= 1.1.0 =
* Renamed the plugin to "BlueWorx Lab | Forum Lighting" and turned it into the
  site's single custom-functionality plugin. The product image gallery is now
  one feature among many.
* Added a control page under Settings > BlueWorx Lab to switch every feature on
  or off, with a confirmation prompt before switching anything off.
* Moved the site's Code Snippets scripts into the plugin (verbatim) as
  toggleable features: role-based pricing, hide prices/cart from guests,
  product bullets, fitting/datasheet/video buttons, product filter, hide
  internal and N/A attributes, restrict search to products, ACF account
  details, clean email footer, catalogue drag-ordering, the product image
  purge tool, and a user-role debug logger.
* The product metadata viewer and product change log are now toggleable too.
* IMPORTANT: after updating, disable the matching entries in the Code Snippets
  plugin to avoid duplicate-function errors.

= 1.0.6 =
* Added a full product change log with old and new values.
* Identifies changes made by users, external APIs, imports, scheduled tasks,
  and command-line jobs.
* Includes changes to product details, WooCommerce metadata, variations,
  categories, tags, product types, and product attributes.
* Added Copy metadata and Download JSON actions.
* Metadata exports now exclude WordPress editor, SEO, and Elementor fields.

= 1.0.5 =
* Added the last update date, time, and editor to product screens and the
  WooCommerce product list.

= 1.0.4 =
* Added a searchable Product Meta Data section to WooCommerce product edit
  pages, with a readable full-page view that opens in a new tab.

= 1.0.3 =
* Replaced the demo gallery images with bundled lighting-product placeholders
  (self-contained SVGs themed for a wholesale lighting supplier). Removes the
  external image-service dependency so demo images can never fail to load.

= 1.0.0 =
* Initial release.
