# ePim External Product Images — Gallery & Featured Image

**Date:** 2026-06-23
**Status:** Approved design, pending spec review
**Target version:** 1.2.0 (minor — new feature)

## Goal

Wire the gallery widget (and the site-wide WooCommerce featured image) to pull
product imagery directly from the external **ePim** asset service, constructing
image URLs from the IDs already stored in product meta. This lets the site show
real product images **without importing anything into the WordPress media
library** — the gallery built in this plugin currently shows bundled
placeholders.

## Background / current state

- Plugin: `external-product-images` (display name *BlueWorx Lab | Forum
  Lighting*), currently v1.1.1. Pure PHP/CSS/JS, no build step.
- The Elementor **gallery widget** (`EPI_Widget`) renders a main image plus
  clickable thumbnails from a flat array of URLs returned by
  `EPI_Images_Provider::get_images( $product_id )`.
- `EPI_Images_Provider` currently returns bundled placeholder SVGs. It contains
  a dormant, commented-out "STEP 1" that was designed to read external image
  **URLs** from a custom meta key `_epi_external_image_urls` (JSON or
  comma-separated). That mechanism is unused and will be removed.
- Product image data actually lives in the **native WooCommerce fields**, but
  the values are **ePim asset IDs**, not WordPress attachment IDs:
  - `_thumbnail_id` → a single ID, e.g. `819382` (the featured image).
  - `_product_image_gallery` → comma-separated IDs, e.g.
    `819383,819384,819385,819386,819387` (the gallery).
- ePim serves each asset at a predictable URL:
  `https://epim.online/webproduct/assetimage/{ID}.jpg`
  (confirmed examples: `…/assetimage/819331.jpg`, `…/assetimage/1188698.jpg`).

## Key decisions (from brainstorming)

1. **IDs → external URLs, never stored on WordPress.** All imagery is fetched
   live from ePim by constructing URLs from the stored IDs.
2. **Both surfaces.** (a) The gallery widget's main image + thumbnails, and
   (b) the site-wide WooCommerce featured image (shop/category grids, related &
   upsell, cart, checkout, search results).
3. **Gallery composition:** main/large image = `_thumbnail_id` (featured), which
   is also the first (active) thumbnail; the `_product_image_gallery` IDs follow
   straight after as the remaining thumbnails.
4. **Featured-image interception via `woocommerce_product_get_image`**
   (Approach 1). Product-context-aware and safe; chosen over low-level WordPress
   attachment hooks which would have to guess whether an ID is an ePim ID vs a
   real attachment.
5. **Shared URL builder.** One method builds every ePim URL so the gallery and
   the featured-image hook stay consistent.
6. **Separate feature toggle.** The site-wide featured image is its own on/off
   feature (`epim-featured-image`) in the BlueWorx Lab list, independent of the
   gallery widget feature.
7. **No remote existence checks.** Images are constructed and rendered directly;
   a missing ePim asset degrades to the bundled placeholder via an `onerror`
   handler (HEAD-checking every thumbnail on shop pages would be too slow).
8. **`.jpg` assumed for all assets**, hardcoded in the builder but overridable
   through a filter, so a future format change needs no code edit.

## Architecture

Three pieces, all reusing one URL builder.

### Shared URL builder — `EPI_Images_Provider::build_image_url( $id )`

- New constant in `external-product-images.php`:
  `define( 'EPI_EPIM_IMAGE_BASE', 'https://epim.online/webproduct/assetimage/' );`
- `build_image_url( $id )`:
  1. `$id = absint( $id );` return `''` if `0`.
  2. `$url = EPI_EPIM_IMAGE_BASE . $id . '.jpg';`
  3. `$url = apply_filters( 'epi_epim_image_url', $url, $id );`
  4. return `esc_url_raw( $url )`.

### Gallery source — `EPI_Images_Provider::get_images()`

Replace the dormant `_epi_external_image_urls` STEP 1 (and remove the now-unused
`META_KEY` constant and `parse_meta_value()` method) with ePim-ID sourcing:

1. Read `_thumbnail_id` → `build_image_url()` → featured URL.
2. Read `_product_image_gallery`, `explode( ',', … )`, `build_image_url()` each.
3. Compose `array( featured, …gallery )`, filter out empties,
   `array_unique()` to de-dupe.
4. If empty → existing `get_placeholder_images()` fallback.
5. Continue to run the existing `epi_product_images` filter on the final list.

The widget (`EPI_Widget`) needs **no structural change** — it already treats
`images[0]` as the main image and renders the whole list as thumbnails. The
only edit is the editor-panel notice text, which still claims images "currently
use placeholders."

### Site-wide featured image — `EPI_Featured_Image` (new)

- New file `includes/class-epi-featured-image.php`, class `EPI_Featured_Image`
  with a static `init()` that registers the hook (mirrors the existing
  `EPI_Product_Meta::init()` pattern).
- `add_filter( 'woocommerce_product_get_image', …, 10, 6 )` with signature
  `( $html, $product, $size, $attr, $placeholder, $image )`:
  1. `$image_id = $product->get_image_id();` (reads `_thumbnail_id`).
  2. If empty → return `$html` unchanged (WooCommerce placeholder).
  3. Else build the ePim URL and return an `<img>` that preserves the classes
     WooCommerce themes expect: `attachment-{$size}`, `wp-post-image`, plus any
     `$attr['class']` passed in; `alt` = product title; `loading="lazy"`;
     `onerror` → bundled placeholder.
- Registered in `EPI_Feature_Registry::definitions()` as a new feature:
  - id `epim-featured-image`, group `product-display`, dependency `woocommerce`,
    `dangerous => false`, boot → `array( 'EPI_Featured_Image', 'init' )`.

## Data flow

```
WooCommerce product meta
  _thumbnail_id ───────────────┐
  _product_image_gallery ──┐    │
                           ▼    ▼
        EPI_Images_Provider::build_image_url( id )  →  https://epim.online/webproduct/assetimage/{id}.jpg
                           │    │
   single product page ────┘    └──── shop / cart / related / search
   EPI_Widget gallery               EPI_Featured_Image (woocommerce_product_get_image)
   (main = featured, thumbs follow) (returns <img> from _thumbnail_id)
```

## Error handling

- **No network calls** during rendering; URLs are built from IDs only.
- Each rendered `<img>` carries an `onerror` that swaps `src` to the bundled
  placeholder SVG, so a 404 from ePim shows a placeholder rather than a broken
  image. The handler clears itself to avoid loops if the placeholder also fails.
- Missing/blank `_thumbnail_id` and `_product_image_gallery` → placeholders
  (gallery) or untouched WooCommerce output (featured image).
- All URLs escaped on output (`esc_url` / `esc_url_raw`).

## Scope

**In scope**
- Gallery widget images sourced from ePim IDs.
- WooCommerce featured image across shop/category, related/upsell, cart,
  checkout, and search, sourced from ePim IDs.
- Shared URL builder + new feature toggle.

**Out of scope (possible follow-ups)**
- SEO/OpenGraph `og:image` and JSON-LD structured-data images (these still
  reference the non-existent attachment ID).
- Any settings UI for the base URL / file extension (constant + filter only).
- Image resizing / responsive `srcset` (ePim serves a single asset; CSS sizes
  it for display).
- Variations beyond WooCommerce's standard parent-gallery fallback.

## Versioning & deployment

- Ships as **1.2.0**. At implementation: bump the `Version` header and
  `EPI_VERSION` constant in `external-product-images.php`, the `Stable tag` and a
  changelog entry in `readme.txt`, then repackage
  `Documents/GitHub/blueworx_client_forum.zip` per the documented zip process
  (exclude `.git`/`node_modules`/`*.zip`, forward-slash entries, verify before
  handover).

## Testing / verification

This repo has no PHP test harness (no Composer/PHPUnit), so verification is:

- **Automated:** `php -l` on every changed/new PHP file.
- **Manual checklist on staging:**
  1. Product with `_thumbnail_id` + `_product_image_gallery` → single product
     page shows the ePim featured image as the main image with the gallery IDs
     as thumbnails; shop grid / related / cart show the ePim featured image.
  2. Product with no image IDs → bundled placeholders everywhere.
  3. Product with a deliberately invalid ID → `onerror` placeholder shown, no
     broken image icon.
  4. Toggling `epim-featured-image` off in BlueWorx Lab → site reverts to
     WooCommerce's default featured-image behaviour; the gallery widget is
     unaffected.
