# Larger ePim Pictures Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The product page's main picture and zoom ask ePim for a 1200px picture; everything else keeps the plain 300px link.

**Architecture:** The single ePim URL builder takes an optional size. The gallery asks for the large size for the main picture and for `data-epi-full` (which the lightbox and arrows read), and the plain size for thumbnail `<img>`s. Shop cards, cart and checkout call the builder with no size, so they are unchanged.

**Tech Stack:** PHP (WordPress plugin), Playwright against the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-11-16-epim-larger-pictures-design.md`

## Global Constraints

- Large size is 1200: `?w=1200&h=1200`. Both `w` and `h` are required by ePim.
- Never store or proxy ePim pictures on the site.
- Version: minor bump to 1.20.0 in `package.json`, plugin header and `EPI_VERSION`; changelog entry in plain words.
- No new dependencies. Lint once at the end; report findings, don't loop.

## Review Focus

1. Base address that already has a query string (the test stand-in: `/?epi_test_image=`) — sized link must join with `&`, and the picture must still load. Test in Task 1, step 6.
2. Opening the zoom shows the large picture, and the arrows inside it stay on large pictures. Test in Task 1, step 1.
3. Media library source (`source="woocommerce"`) — must be unchanged; existing gallery tests cover it.
4. Shop cards, cart and checkout — must keep the plain link. No WooCommerce in the harness, so not testable here; check on the live site after release.
5. The `epi_epim_image_url` filter still receives and can change the sized link.

---

### Task 1: Sized ePim links in the product gallery

**Files:**
- Modify: `external-product-images.php` (next to `EPI_EPIM_IMAGE_BASE`, ~line 30)
- Modify: `includes/class-epi-images-provider.php` (`get_images`, `build_image_url`)
- Modify: `includes/class-epi-gallery.php` (`render`, ~lines 94-170)
- Test: `tests/product-gallery.spec.js`, `tests/epim-image-cache.spec.js`

**Interfaces:**
- Produces: constant `EPI_EPIM_LARGE_PX` = `1200` (guarded by `defined()`).
- Produces: `EPI_Images_Provider::build_image_url( $id, $size = '' ): string` — `'large'` appends `w` and `h` set to `EPI_EPIM_LARGE_PX` (filterable via `epi_epim_large_px`) using `add_query_arg`, so an existing `?` is respected; `''` returns the plain link as today. The existing `epi_epim_image_url` filter runs after sizing, with a third argument `$size`.
- Produces: `EPI_Images_Provider::get_images( $product_id, $source = 'epim', $size = '' ): string[]` — passes `$size` to `build_image_url`; media library and placeholders ignore it. Pass `$size` as a fourth argument to `epi_product_images`.

- [ ] **Step 1: Update the failing tests in `tests/product-gallery.spec.js`**

In "ePim source builds the same order…" and "a product marked as using ePim pictures…":
- Before `page.goto`, route `https://epim.online/**` to fulfil with the `PIXEL` PNG (so no `onerror` swaps a `src`).
- `const large = (media) => \`${epim(media)}?w=1200&h=1200\`;`
- Thumbs: `data-epi-full` is `large(featured|first|second)`; each thumb's `img` `src` is `epim(...)` (plain).
- `.epi-gallery__main-image` `src` is `large(featured)`.

Add to the ePim-source test: click `[data-epi-enlarge]`, expect `[data-epi-lightbox-image]` `src` to be `large(featured)`; click `[data-epi-lightbox-next]`, expect `large(first)`.

- [ ] **Step 2: Run to see them fail**

Run: `npx playwright test tests/product-gallery.spec.js --workers=1` (harness up, env vars per CLAUDE.md)
Expected: the two ePim tests FAIL on `data-epi-full` (plain link received).

- [ ] **Step 3: Add the constant and the sized builder**

`EPI_EPIM_LARGE_PX` in the main plugin file; `build_image_url` and `get_images` as in Interfaces.

- [ ] **Step 4: Use the sizes in the gallery**

In `EPI_Gallery::render`: `$images = get_images( …, 'large' )` and `$thumbs_small = get_images( …, '' )` (same order, same count). Main `<img>`, lightbox `<img>` and `data-epi-full` use `$images`; thumbnail `<img src>` uses `$thumbs_small[ $index ]`. No JS change: the script already reads `data-epi-full`.

- [ ] **Step 5: Run to see them pass**

Run: `npx playwright test tests/product-gallery.spec.js --workers=1`
Expected: all PASS, including the media-library tests unchanged.

- [ ] **Step 6: Stand-in base keeps working (Review Focus 1)**

In `tests/epim-image-cache.spec.js`, add test "a large gallery picture loads from a base that already has a query string": a page with `[forum_product_gallery source="epim"]` (reuse the spec's existing page helper), then expect main image `src` to end `.jpg&w=1200&h=1200` and `naturalWidth > 0` after load.
Run: `npx playwright test tests/epim-image-cache.spec.js --workers=1` — Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add external-product-images.php includes/class-epi-images-provider.php includes/class-epi-gallery.php tests/product-gallery.spec.js tests/epim-image-cache.spec.js
git commit -m "Show larger ePim pictures on the product page"
```

### Task 2: Version, changelog, checks, pull request

**Files:**
- Modify: `package.json`, `external-product-images.php` (header + `EPI_VERSION`), `CHANGELOG.md`

- [ ] **Step 1: Bump to 1.20.0 and add the changelog entry**

Under `## [1.20.0]` / `### Changed`: "Product pages now show sharper ePim pictures, up to 1200 pixels, on the main picture and when enlarged. Shop listings, the cart and checkout keep the smaller pictures so they stay fast. A picture whose original is smaller than that shows at its own size."

- [ ] **Step 2: Full test run and one lint pass**

Run: `npx playwright test --workers=1` — Expected: all PASS, none skipped. Then `composer lint` once; report findings to Luke, don't fix without approval.

- [ ] **Step 3: Commit, push, open the PR**

Commit "Bump to 1.20.0", push `epim-larger-pictures`, open a PR: what it does in two lines, plus "Not tested: shop cards, cart and checkout (no WooCommerce in the harness)". No tag — Luke releases after merge.

---

## Tonight's import (Luke, not code)

1. Snapshot first: export the products in the categories being switched (pictures and descriptions).
2. Pull one category at a time from the import screen, test mode first where offered.
3. After each: open a product page, a category listing and the cart on the live site; compare against the snapshot.
4. If a category goes wrong, restore its snapshot first, then work out why.
