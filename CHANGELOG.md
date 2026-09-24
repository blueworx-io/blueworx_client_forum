# Changelog

All notable changes to this plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.12.2]

### Fixed
- The featured image is now the first thumbnail in the gallery, not the last, so
  the last gallery picture (the dimensional drawing) comes last.

## [1.12.1]

### Added
- The product gallery widget can now take its pictures from the WooCommerce gallery
  (the media library) as well as ePim. Pick the source on the widget.
- Arrows on the main image that loop through every picture, and the
  [forum_product_gallery] shortcode for placing the gallery without Elementor.
- An enlarge button on the main image that opens the pictures full size over the
  page, with arrows, swipe and keyboard to move through them. Works on phones.

### Changed
- The featured image opens the gallery and sits last in the thumbnail strip, so
  stepping on from it runs through the gallery and back round.

## [1.11.1]

### Fixed
- Product bullets now show exactly as typed on the product. "IP44 Rating" no longer
  becomes "Ip44 rating".

## [1.11.0]

### Added
- Banner slider: an Elementor widget ("Forum: Banner slider") with the three designed
  slides built in. Every word, button, photo and panel colour is editable; it moves on by
  itself at a time you choose, and the arrows and dots always work. `[forum_banner]`
  places the designed slides anywhere without Elementor.

## [1.10.0]

### Added
- "The range" section on a Forum Page: enter each product's SKU and a one-line
  description, and the name, photo and link come from the product itself.

## [1.9.1]

### Fixed
- Forum Pages now match the design: content sits in a centred column on wide screens,
  the switch diagram and step drawings are in, the steps show one at a time, and every
  section is laid out as drawn.
- Without an Elementor template a Forum Page now runs the full width of the window
  instead of sitting in the theme's narrow column.
- Changes to the page's styling reach visitors straight away instead of waiting on a
  browser cache.

## [1.9.0]

### Added
- The Kinetic wireless switches page comes with the plugin, filled in and ready to edit
  under Forum Pages. It is added once; edit or delete it as you like.
- Every Forum Page has its own shortcode, shown in the Forum Pages list, so a page can be
  dropped into any Elementor page with `[forum_page id="…"]`.

## [1.8.0]

### Added
- Forum Pages: long-form support pages with every heading, paragraph, photo and button
  editable in one place, under Forum Pages in the menu.
- Each section of a Forum Page can be switched off, and the page numbers its sections and
  builds its "on this page" links from whichever ones are left.
- Drop `[forum_page]` into an Elementor template for Forum Pages and the site's own header
  and footer stay in charge of the page. See `docs/elementor-template.md`.
- The Forum Pages menu lists just the pages and Add New. Each page is edited from the
  list, and the menu shows the list as where you are while you edit.

## [1.7.1]

### Fixed
- The Save changes bar now sits along the bottom of the window on every admin screen, instead of coming to rest halfway up a short page.
- Every on/off toggle now sits on the right of the setting it switches, lined up down the right edge of the panel.
- A toggle you cannot use — because the plugin it needs is not active — now shows as unavailable rather than lighting up when you click it.

## [1.7.0]

### Changed
- The last three admin screens are on the shared BlueWorx admin design system: the product metadata section on the product edit page, the product change log, and Tools > Purge Product Images. Every screen the plugin draws now looks like the same plugin.
- The standalone metadata page and the purge tool run the full width of the screen.
- The purge tool shows its figures as counts rather than a wall of bold text, and long image lists scroll in place instead of pushing the page down.
- The change log's pager is the design system's, not WordPress's.
- The copy of the shared BlueWorx admin design is up to date with the Foundation.

### Removed
- The catch-up flag on the admin design check in CI. A screen that is not built from the design system now fails a pull request.

## [1.6.0]

### Added
- The plugin now updates itself on the site from a published release, the same way any other plugin updates. Nobody uploads a zip any more.
- Every change to the plugin is now checked automatically before it can be merged: coding standards, the version and changelog, what would ship to the site, and browser tests against a real WordPress.
- A script that builds the installable zip and refuses to hand over one that would not install.
- Uninstalling the plugin now removes the settings and the change-log table it created. It leaves everything else alone.

### Changed
- The functions the imported Code Snippets scripts declare are now prefixed, so they cannot collide with anything else on the site. If any of the matching entries are still switched on in the Code Snippets plugin, switch them off: with the names no longer clashing, both copies would run.
- Settings > BlueWorx Lab is rebuilt on the shared BlueWorx admin design system: a proper page header, one section per feature group instead of one long list, and a save bar that stays in view. Every feature still saves in one go, whichever section you are looking at.

## [1.5.5]

### Fixed
- "ECD special prices" no longer shows in the product attribute list on the website. The existing rule missed it because of how WooCommerce names the rows.

## [1.5.4]

### Changed
- The gallery widget is now called "Forum: Product Image Gallery" in the Elementor panel. Existing pages using it are unaffected.

## [1.5.3]

### Changed
- Product gallery: the featured image stays in the thumbnail row and is marked more clearly as the one currently showing.

## [1.5.1]

### Changed
- Search result SKUs now read "SKU: 12345678" in the same red as the shop listing cards.

## [1.5.0]

### Added
- Search results show the product SKU instead of the short description. Products without a SKU show nothing in its place.
- A "Show SKU in search results" toggle under Settings > BlueWorx Lab.

## [1.4.0]

### Added
- A "Main category breadcrumbs" toggle under Settings > BlueWorx Lab.

### Fixed
- Product breadcrumbs show the main category path again. Products also assigned to "Featured Products" were showing that path instead, because WooCommerce picks the category nested deepest rather than the one the connector sends first.

## [1.3.0]

### Added
- A "Log product pricing (debug)" feature. On single product pages it prints the current user role, the original vs live calculated price, and every role-based pricing tier to the browser console. It is visible to every visitor while switched on, so turn it off after debugging.
- A "Debug logging" panel at the top of Settings > BlueWorx Lab that groups the console-logging switches in one place.

## [1.2.1]

### Changed
- Aligned the plugin text domain with the plugin slug so all translatable strings resolve correctly.
- Hardened admin input handling (unslashing and sanitising query and form data) and documented the intentional direct database queries used by the change log and the image-purge tool.
- Switched the N/A attribute filter to `wp_strip_all_tags()` and added the missing translators comment.

## [1.2.0]

### Added
- A "ePim featured image" feature toggle under Settings > BlueWorx Lab.

### Changed
- The product gallery and the WooCommerce featured image load product imagery directly from the external ePim asset service, built from the product image IDs (`_thumbnail_id` and `_product_image_gallery`) — no media-library imports.
- Products with no image IDs, or a missing ePim asset, fall back to the bundled placeholder images.

## [1.1.1]

### Fixed
- The `[product_bullets]` shortcode renders custom (local) "Bullet" product attributes, not just the legacy global `pa_bullet-*` taxonomies.
- Bullets display in numeric order (Bullet 1, 2, 3…) regardless of the attribute order on the edit screen.

## [1.1.0]

### Added
- A control page under Settings > BlueWorx Lab to switch every feature on or off, with a confirmation prompt before switching anything off.
- The site's Code Snippets scripts, moved into the plugin verbatim as toggleable features: role-based pricing, hide prices/cart from guests, product bullets, fitting/datasheet/video buttons, product filter, hide internal and N/A attributes, restrict search to products, ACF account details, clean email footer, catalogue drag-ordering, the product image purge tool, and a user-role debug logger.
- Toggles for the product metadata viewer and the product change log.

### Changed
- Renamed the plugin to "BlueWorx Lab | Forum Lighting" and turned it into the site's single custom-functionality plugin. The product image gallery is now one feature among many.
- After updating, disable the matching entries in the Code Snippets plugin to avoid duplicate-function errors.

## [1.0.6]

### Added
- A full product change log with old and new values, identifying changes made by users, external APIs, imports, scheduled tasks and command-line jobs. It covers product details, WooCommerce metadata, variations, categories, tags, product types and product attributes.
- Copy metadata and Download JSON actions.

### Changed
- Metadata exports exclude WordPress editor, SEO and Elementor fields.

## [1.0.5]

### Added
- The last update date, time and editor on product screens and the WooCommerce product list.

## [1.0.4]

### Added
- A searchable Product Meta Data section on WooCommerce product edit pages, with a readable full-page view that opens in a new tab.

## [1.0.3]

### Changed
- Replaced the demo gallery images with bundled lighting-product placeholders, removing the external image-service dependency so demo images can never fail to load.

## [1.0.0]

### Added
- Initial release.
