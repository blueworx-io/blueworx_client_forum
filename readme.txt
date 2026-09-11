=== BlueWorx Lab | Forum Lighting ===
Contributors: blueworx
Tags: woocommerce, elementor, product gallery, product images, site functionality
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later

Site functionality plugin for Forum Lighting: one control centre to switch the WooCommerce gallery, metadata, pricing and more on or off.

== Description ==

Provides a new Elementor widget, "Forum: Product Image Gallery", that renders a
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
5. Drag in the "Forum: Product Image Gallery" widget from the WooCommerce / General
   category. Save.

== Switching from placeholders to product meta ==

Open includes/class-epi-images-provider.php:

1. Set META_KEY to your real meta key.
2. Uncomment the "STEP 1 — READ FROM PRODUCT META" block in get_images().

The stored value may be a JSON array of URLs OR a comma-separated list — both
are handled automatically. Placeholders remain as a safety fallback.

== Changelog ==

= 1.8.0 =
* Forum Pages: long-form support pages with every heading, paragraph, photo and
  button editable in one place, under Forum Pages in the menu.
* Each section of a Forum Page can be switched off, and the page numbers its
  sections and builds its "on this page" links from whichever ones are left.
* Drop [forum_page] into an Elementor template for Forum Pages and the site's own
  header and footer stay in charge of the page.

= 1.7.1 =
* The Save changes bar now sits along the bottom of the window on every admin
  screen, instead of coming to rest halfway up a short page.
* Every on/off toggle now sits on the right of the setting it switches, lined
  up down the right edge of the panel.
* A toggle you cannot use, because the plugin it needs is not active, now shows
  as unavailable rather than lighting up when you click it.

= 1.7.0 =
* The last three admin screens now match the rest of the plugin: the product
  metadata section on the product edit page, the product change log, and
  Tools > Purge Product Images. Same buttons, same tables, same notices as
  Settings > BlueWorx Lab.
* The metadata page and the purge tool now run the full width of the screen.
* The purge tool reads its figures as plain counts rather than a wall of bold
  text, and the long image lists scroll in place instead of pushing the page
  down.

= 1.6.0 =
* Settings > BlueWorx Lab has been rebuilt on the shared BlueWorx admin design.
  Feature groups are now sections you switch between rather than one long list,
  and the Save button stays in view. Everything still saves in one go, whichever
  section you are looking at.
* The plugin now updates itself on the site from a published release, so nobody
  uploads a zip any more.
* Uninstalling the plugin now removes the settings and the change-log table it
  created, and leaves everything else alone.
* Every change to the plugin is now checked automatically before it can go out.
* IMPORTANT: the imported Code Snippets scripts now use their own function
  names. If any of the matching entries are still switched on in the Code
  Snippets plugin, switch them off - with the names no longer clashing, both
  copies would run.

= 1.5.5 =
* "ECD special prices" no longer shows in the product attribute list on the
  website. The existing rule missed it because of how WooCommerce names the
  rows.

= 1.5.4 =
* Gallery widget is now called "Forum: Product Image Gallery" in the Elementor
  panel. Existing pages using it are unaffected.

= 1.5.3 =
* Product gallery: the featured image stays in the thumbnail row and is
  marked more clearly as the one currently showing.

= 1.5.1 =
* Search result SKUs now read "SKU: 12345678" in the same red as the shop
  listing cards.

= 1.5.0 =
* Search results now show the product SKU instead of the short description.
  Products without a SKU show nothing in its place.
* Added a "Show SKU in search results" toggle under Settings > BlueWorx Lab.

= 1.4.0 =
* Product breadcrumbs now show the main category path again. Products also
  assigned to "Featured Products" were showing that path instead, because
  WooCommerce picks the category nested deepest rather than the one the
  connector sends first.
* Added a "Main category breadcrumbs" toggle under Settings > BlueWorx Lab.

= 1.3.0 =
* Added a "Log product pricing (debug)" feature. On single product pages it
  prints the current user role, the original vs live calculated price, and
  every role-based pricing tier to the browser console. Reminder: visible to
  every visitor while switched on, so turn it off after debugging.
* Added a "Debug logging" panel at the top of Settings > BlueWorx Lab that
  groups the console-logging switches (user role, product pricing) in one
  place for quick on/off toggling while troubleshooting.

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
