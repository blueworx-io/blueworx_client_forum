# ePim External Product Images Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Source the gallery widget's images and the site-wide WooCommerce featured image from external ePim asset URLs built from the product's `_thumbnail_id` / `_product_image_gallery` IDs, instead of bundled placeholders.

**Architecture:** A single shared URL builder on `EPI_Images_Provider` turns an ePim ID into `https://epim.online/webproduct/assetimage/{ID}.jpg`. The existing gallery provider uses it to build `[featured, …gallery]`. A new `EPI_Featured_Image` class hooks `woocommerce_product_get_image` to swap the featured image everywhere else, gated behind its own BlueWorx Lab feature toggle. Placeholders remain the fallback.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce, Elementor. No build step, no Composer.

## Global Constraints

- **No automated test harness exists** in this repo (no Composer/PHPUnit), and we are NOT introducing one — it conflicts with the zip-based deploy workflow. Per-task verification is `php -l` (syntax) plus the manual checklist in the spec. Do **not** scaffold pytest/phpunit.
- **PHP floor:** 7.4. **WordPress:** 6.0+. Text domain: `external-product-images`. Internal prefix: `EPI_`.
- **ePim URL pattern (verbatim):** `https://epim.online/webproduct/assetimage/{ID}.jpg`.
- **All output escaped:** `esc_url` / `esc_url_raw` for URLs, `esc_attr` for attributes.
- **No remote HTTP calls during render** (no `wp_remote_head` existence checks).
- **All features ship ON** by default (registry treats a missing flag as on).
- **Coding style:** match surrounding files — tabs for indentation, Yoda conditions, full DocBlocks, `// Exit if accessed directly.` guard at top of every PHP file.
- Spec: `docs/superpowers/specs/2026-06-23-epim-product-images-design.md`.

## Starting state

Branch `epim-product-images` already exists with the spec committed. The working tree carries an **uncommitted, unrelated fix** (product bullets rendering, version already bumped to 1.1.1 in `external-product-images.php` and `readme.txt`). Task 1 commits that fix first so feature work starts from a clean tree.

## File structure

- `external-product-images.php` — add `EPI_EPIM_IMAGE_BASE` constant; `require_once` the provider + new featured-image class in the WooCommerce branch of `init()`. (Final version bump to 1.2.0 in Task 4.)
- `includes/class-epi-images-provider.php` — add `build_image_url()` + `fallback_image_url()`; rewrite `get_images()` STEP 1 to read ePim IDs; delete dead `META_KEY` + `parse_meta_value()`.
- `includes/class-epi-widget.php` — add `onerror` fallback to the rendered `<img>`s; update the editor notice copy.
- `includes/class-epi-featured-image.php` — **new** — `EPI_Featured_Image::init()` + `woocommerce_product_get_image` filter callback.
- `includes/class-epi-feature-registry.php` — register the `epim-featured-image` feature.
- `readme.txt` — stable tag + changelog (Task 4).

---

### Task 1: Baseline commit + shared ePim URL builder

**Files:**
- Commit (no edit): `external-product-images.php`, `includes/snippets/product-bullets.php`, `readme.txt` (pre-existing bullets fix)
- Modify: `external-product-images.php` (add constant + provider require)
- Modify: `includes/class-epi-images-provider.php` (add `build_image_url`, `fallback_image_url`)

**Interfaces:**
- Produces: `EPI_Images_Provider::build_image_url( int|string $id ) : string` — returns an escaped ePim URL, or `''` for a zero/empty ID.
- Produces: `EPI_Images_Provider::fallback_image_url() : string` — first bundled placeholder URL, or `''`.
- Produces: constant `EPI_EPIM_IMAGE_BASE` — `'https://epim.online/webproduct/assetimage/'`.

- [ ] **Step 1: Commit the pre-existing bullets fix**

```bash
git add external-product-images.php includes/snippets/product-bullets.php readme.txt
git commit -m "Fix product bullets rendering for custom (local) attributes (1.1.1)"
```

- [ ] **Step 2: Add the ePim base-URL constant**

In `external-product-images.php`, add this new line directly beneath the existing `define( 'EPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );` (currently line 28), so it sits with the other core plugin constants:

```php
define( 'EPI_EPIM_IMAGE_BASE', 'https://epim.online/webproduct/assetimage/' );
```

- [ ] **Step 3: Require the provider in the WooCommerce branch of `init()`**

In `external-product-images.php`, in `init()`, inside `if ( class_exists( 'WooCommerce' ) ) {`, add the provider require alongside the existing requires (so the shared URL builder is available even when the gallery feature is off):

```php
require_once EPI_PLUGIN_DIR . 'includes/class-epi-images-provider.php';
require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-change-log.php';
require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-meta.php';
EPI_Product_Change_Log::maybe_upgrade();
```

- [ ] **Step 4: Add the URL builder + fallback helper to the provider**

In `includes/class-epi-images-provider.php`, add these two methods inside the `EPI_Images_Provider` class (e.g. directly above `get_placeholder_images()`):

```php
	/**
	 * Build the external ePim asset URL for a single image ID.
	 *
	 * @param int|string $id ePim asset ID (stored in product meta).
	 * @return string Escaped URL, or '' when the ID is empty/invalid.
	 */
	public static function build_image_url( $id ) {
		$id = absint( $id );

		if ( ! $id ) {
			return '';
		}

		$url = EPI_EPIM_IMAGE_BASE . $id . '.jpg';

		/**
		 * Filter the constructed ePim image URL (e.g. to change host or extension).
		 *
		 * @param string $url Constructed URL.
		 * @param int    $id  ePim asset ID.
		 */
		$url = apply_filters( 'epi_epim_image_url', $url, $id );

		return esc_url_raw( $url );
	}

	/**
	 * First bundled placeholder, used as the <img> onerror fallback.
	 *
	 * @return string
	 */
	public static function fallback_image_url() {
		$images = self::get_placeholder_images();

		return ! empty( $images ) ? $images[0] : '';
	}
```

- [ ] **Step 5: Lint the changed files**

Run: `php -l external-product-images.php && php -l includes/class-epi-images-provider.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 6: Commit**

```bash
git add external-product-images.php includes/class-epi-images-provider.php
git commit -m "Add shared ePim image URL builder and base-URL constant"
```

---

### Task 2: Gallery sources images from ePim IDs

**Files:**
- Modify: `includes/class-epi-images-provider.php` (`get_images()` STEP 1; delete `META_KEY` + `parse_meta_value()`)
- Modify: `includes/class-epi-widget.php` (`onerror` on `<img>`s; editor notice copy)

**Interfaces:**
- Consumes: `EPI_Images_Provider::build_image_url()`, `EPI_Images_Provider::fallback_image_url()` (Task 1).
- Produces: `EPI_Images_Provider::get_images( int $product_id ) : string[]` now returns `[featured, …gallery]` ePim URLs (placeholders when no IDs). Signature unchanged.

- [ ] **Step 1: Replace STEP 1 of `get_images()` with ePim-ID sourcing**

In `includes/class-epi-images-provider.php`, replace the commented-out STEP 1 block (the `/* … */` block that reads `self::META_KEY`) with this live code (keep STEP 2 fallback and the `epi_product_images` filter that follow it):

```php
		/**
		 * ------------------------------------------------------------------
		 *  STEP 1 — BUILD URLS FROM ePim IMAGE IDS
		 * ------------------------------------------------------------------
		 *
		 * Featured image (`_thumbnail_id`) first, then the gallery IDs
		 * (`_product_image_gallery`, comma-separated). IDs are ePim asset IDs,
		 * not WordPress attachments — we construct external URLs, never import.
		 */
		if ( $product_id ) {
			$ids = array();

			$thumbnail_id = get_post_meta( $product_id, '_thumbnail_id', true );
			if ( $thumbnail_id ) {
				$ids[] = $thumbnail_id;
			}

			$gallery = get_post_meta( $product_id, '_product_image_gallery', true );
			if ( ! empty( $gallery ) ) {
				$ids = array_merge( $ids, explode( ',', (string) $gallery ) );
			}

			foreach ( $ids as $id ) {
				$url = self::build_image_url( $id );

				if ( '' !== $url ) {
					$urls[] = $url;
				}
			}

			$urls = array_values( array_unique( $urls ) );
		}
```

- [ ] **Step 2: Delete the dead external-URL code**

In the same file, delete the now-unused `META_KEY` constant (the `const META_KEY = '_epi_external_image_urls';` line and its DocBlock) and the entire `parse_meta_value()` method and its DocBlock. Update the class/file top DocBlock wording that says it "returns placeholder images" to reflect that it now builds ePim URLs with a placeholder fallback.

- [ ] **Step 3: Add `onerror` fallback + update notice in the widget**

In `includes/class-epi-widget.php`, in `render()`, after `$alt_base` is set, add:

```php
		$fallback = EPI_Images_Provider::fallback_image_url();
		$on_error = $fallback ? "this.onerror=null;this.src='" . $fallback . "';" : '';
```

Then add `onerror="<?php echo esc_attr( $on_error ); ?>"` to BOTH the main `<img class="epi-gallery__main-image" …>` and the thumbnail `<img class="epi-gallery__thumb-image" …>` tags.

Replace the editor-notice `raw` text (the `epi_editor_notice` control) with:

```php
					'raw'             => esc_html__( 'Images are pulled live from ePim using the product image IDs. Products with no image IDs fall back to placeholders.', 'external-product-images' ),
```

- [ ] **Step 4: Lint**

Run: `php -l includes/class-epi-images-provider.php && php -l includes/class-epi-widget.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Manual verification (staging — record result)**

On a product with `_thumbnail_id` + `_product_image_gallery` set: the single-product gallery shows the featured image as the main image, with gallery IDs as the thumbnails after it. On a product with neither: the bundled placeholders show. Confirm gallery image `src`s are `https://epim.online/webproduct/assetimage/<id>.jpg`.

- [ ] **Step 6: Commit**

```bash
git add includes/class-epi-images-provider.php includes/class-epi-widget.php
git commit -m "Source gallery images from ePim IDs with placeholder fallback"
```

---

### Task 3: Site-wide WooCommerce featured image from ePim

**Files:**
- Create: `includes/class-epi-featured-image.php`
- Modify: `external-product-images.php` (require the new class in the WooCommerce branch of `init()`)
- Modify: `includes/class-epi-feature-registry.php` (register `epim-featured-image`)

**Interfaces:**
- Consumes: `EPI_Images_Provider::build_image_url()`, `EPI_Images_Provider::fallback_image_url()` (Task 1).
- Produces: `EPI_Featured_Image::init() : void` (registers the filter) and `EPI_Featured_Image::filter_product_image( string $html, WC_Product $product, mixed $size, array $attr ) : string`.
- Produces: registry feature id `epim-featured-image`, boot `array( 'EPI_Featured_Image', 'init' )`.

- [ ] **Step 1: Create the featured-image class**

Create `includes/class-epi-featured-image.php`:

```php
<?php
/**
 * Site-wide WooCommerce featured image, sourced from ePim.
 *
 * Swaps the product featured image used on shop/category grids, related &
 * upsell products, cart, checkout and search results for the external ePim
 * asset URL built from the product's image ID (`_thumbnail_id`).
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the WooCommerce product image HTML.
 *
 * @since 1.2.0
 */
final class EPI_Featured_Image {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'woocommerce_product_get_image', array( __CLASS__, 'filter_product_image' ), 10, 4 );
	}

	/**
	 * Replace the product image HTML with an ePim-sourced <img>.
	 *
	 * @param string     $html    Original image HTML.
	 * @param WC_Product $product Product object.
	 * @param mixed      $size    Requested image size (string or array).
	 * @param array      $attr    Image attributes.
	 * @return string
	 */
	public static function filter_product_image( $html, $product, $size, $attr ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}

		$image_id = $product->get_image_id();

		if ( ! $image_id ) {
			return $html; // No featured image: leave WooCommerce's placeholder.
		}

		$url = EPI_Images_Provider::build_image_url( $image_id );

		if ( '' === $url ) {
			return $html;
		}

		$size_name = is_string( $size ) ? $size : 'woocommerce_thumbnail';
		$classes   = array( 'wp-post-image', 'attachment-' . $size_name, 'size-' . $size_name, 'epi-epim-image' );

		if ( ! empty( $attr['class'] ) ) {
			$classes[] = $attr['class'];
		}

		$fallback = EPI_Images_Provider::fallback_image_url();
		$on_error = $fallback ? "this.onerror=null;this.src='" . $fallback . "';" : '';

		return sprintf(
			'<img src="%1$s" alt="%2$s" class="%3$s" loading="lazy" decoding="async" onerror="%4$s" />',
			esc_url( $url ),
			esc_attr( $product->get_title() ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( $on_error )
		);
	}
}
```

- [ ] **Step 2: Require the class in `init()`**

In `external-product-images.php`, in the WooCommerce branch of `init()`, add the require next to the others added in Task 1:

```php
require_once EPI_PLUGIN_DIR . 'includes/class-epi-featured-image.php';
```

- [ ] **Step 3: Register the feature toggle**

In `includes/class-epi-feature-registry.php`, in `definitions()`, add this entry inside the `// --- Product display ---` group (e.g. directly after the `gallery` entry, before `hide-internal-attributes`):

```php
				'epim-featured-image'     => array(
					'title'          => __( 'ePim featured image', 'external-product-images' ),
					'description'    => __( 'Builds the WooCommerce featured image (shop, category, related, cart, checkout, search) from the product image ID using the external ePim asset URL.', 'external-product-images' ),
					'group'          => 'product-display',
					'dangerous'      => false,
					'danger_message' => '',
					'dependencies'   => array( 'woocommerce' ),
					'boot'           => array( 'EPI_Featured_Image', 'init' ),
				),
```

- [ ] **Step 4: Lint**

Run: `php -l includes/class-epi-featured-image.php && php -l external-product-images.php && php -l includes/class-epi-feature-registry.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Manual verification (staging — record result)**

In Settings → BlueWorx Lab, confirm "ePim featured image" appears under Product display and is on. On a shop/category grid and in related products / cart, the product thumbnail `src` is the ePim URL built from `_thumbnail_id`. Toggle the feature off → WooCommerce reverts to default behaviour and the gallery widget is unaffected. A product with a deliberately bad ID shows the placeholder via `onerror`, not a broken image.

- [ ] **Step 6: Commit**

```bash
git add includes/class-epi-featured-image.php external-product-images.php includes/class-epi-feature-registry.php
git commit -m "Add site-wide ePim featured image feature with toggle"
```

---

### Task 4: Version bump to 1.2.0 and repackage zip

**Files:**
- Modify: `external-product-images.php` (header `Version` + `EPI_VERSION`)
- Modify: `readme.txt` (`Stable tag` + changelog)
- Build: `../blueworx_client_forum.zip`

**Interfaces:** none (release packaging).

- [ ] **Step 1: Bump the plugin version**

In `external-product-images.php`, change the header `* Version:           1.1.1` to `1.2.0`, and `define( 'EPI_VERSION', '1.1.1' );` to `'1.2.0'`.

- [ ] **Step 2: Update readme stable tag + changelog**

In `readme.txt`, change `Stable tag: 1.1.1` to `Stable tag: 1.2.0`, and add above the `= 1.1.1 =` entry:

```
= 1.2.0 =
* Product gallery and the WooCommerce featured image now load product imagery
  directly from the external ePim asset service, built from the product image
  IDs (_thumbnail_id and _product_image_gallery) — no media-library imports.
* Added a "ePim featured image" feature toggle under Settings > BlueWorx Lab.
* Products with no image IDs (or a missing ePim asset) fall back to the bundled
  placeholder images.
```

- [ ] **Step 3: Lint the changed PHP**

Run: `php -l external-product-images.php`
Expected: `No syntax errors detected`.

- [ ] **Step 4: Commit the version bump**

```bash
git add external-product-images.php readme.txt
git commit -m "Bump version to 1.2.0"
```

- [ ] **Step 5: Repackage the deployment zip**

Remove the old zip first (its own command), then build with forward-slash entries into the parent dir, excluding `.git`/`node_modules`/`*.zip` (see `memory/plugin-zip-process.md`). Run sandbox-disabled. After building, verify: 0 backslashes, `blueworx_client_forum/external-product-images.php` present, `blueworx_client_forum/includes/class-epi-featured-image.php` present, no `.git` entries.

- [ ] **Step 6: Final check**

Confirm the built zip's entry count and the presence of the new class file, then hand the zip path over for upload (WP admin → upload → "Replace current with uploaded").

---

## Self-Review

**Spec coverage:**
- Shared URL builder → Task 1 (`build_image_url`, `EPI_EPIM_IMAGE_BASE`). ✓
- Gallery composition (featured first, gallery after, placeholder fallback) → Task 2. ✓
- Dead `_epi_external_image_urls` removal → Task 2 Step 2. ✓
- Site-wide featured image via `woocommerce_product_get_image` → Task 3. ✓
- Separate `epim-featured-image` toggle → Task 3 Step 3. ✓
- `onerror` placeholder fallback, no remote checks → Tasks 2 & 3. ✓
- `.jpg` hardcoded + `epi_epim_image_url` filter override → Task 1 Step 4. ✓
- Version 1.2.0 + zip → Task 4. ✓
- Out-of-scope items (SEO/OG, settings UI, srcset) → not implemented, as intended. ✓

**Placeholder scan:** No TBD/TODO; every code step shows complete code.

**Type consistency:** `build_image_url()` and `fallback_image_url()` names/signatures match across Tasks 1, 2, 3. `EPI_Featured_Image::init` matches the registry boot callback in Task 3 Step 3. Filter `woocommerce_product_get_image` registered with 4 received args (of 6 passed), callback signature matches.
