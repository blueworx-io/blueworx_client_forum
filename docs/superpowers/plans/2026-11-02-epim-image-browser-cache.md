# ePim Image Browser Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A service worker keeps ePim product images in the visitor's browser, so each image is downloaded once, with nothing stored on Forum's server.

**Architecture:** One PHP class serves the worker script through admin-ajax (always, so switching off can remove it) and, while the feature is on, enqueues a small front-end script that registers the worker and tells it to forget images that fail. The worker only answers GET requests under the ePim image base; everything else is never intercepted.

**Tech Stack:** WordPress plugin PHP 7.4+, plain browser JS (no build), Playwright on the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-11-02-epim-image-browser-cache-design.md`

## Global Constraints

- Feature id `epim-image-cache`, title "Keep ePim images in the browser", group `product-display`, no dependencies, on by default.
- Worker address: `admin_url( 'admin-ajax.php?action=epi_image_cache_sw' )`, registered for logged-in and logged-out visitors.
- Worker response headers: `Content-Type: application/javascript; charset=utf-8`, `Cache-Control: no-cache, max-age=0`, `Service-Worker-Allowed: /`.
- Registration: `navigator.serviceWorker.register( url, { scope: '/', updateViaCache: 'none' } )` (scope is the site's home path, from `home_url( '/' )`).
- Cache name prefix `epi-epim-images-`; current cache `epi-epim-images-v1`. Lifetime 7 days. Cap 300 images.
- Forget message: `{ type: 'epi-forget', url }`.
- Only URLs starting with `EPI_EPIM_IMAGE_BASE` are touched. No `respondWith` for anything else.
- No new dependencies. No admin screen changes beyond the new switch, which the Lab page renders from the registry.
- Version 1.19.0 (minor: new feature) in `package.json`, plugin header and `EPI_VERSION`; changelog entry.

## Review Focus

1. A page load (especially cart/checkout POSTs) while the worker is active: must reach the network untouched. Test: a non-ePim request on the page is not `fromServiceWorker()`.
2. ePim answers 500 for an unknown picture: the cached copy must be gone after the image errors. Test in Task 2.
3. Switching the feature off: the worker script becomes the self-removing one and pages stop registering. Tests in Tasks 1 and 2.
4. Browser without service worker support or a failed cache write (quota): images still load from the network. Covered by code shape (feature-detect, `catch` around every cache write); no test.
5. A worker update after a release: the version comment changes the bytes. Test in Task 1 asserts the version is in the script.

---

### Task 1: Worker address, stand-in ePim, on/off worker

**Files:**
- Modify: `external-product-images.php` (make `EPI_EPIM_IMAGE_BASE` overridable; require the new class and call `EPI_Image_Cache::register_worker_endpoint()` from `EPI_Plugin::init()` before `EPI_Feature_Registry::boot()`)
- Create: `includes/class-epi-image-cache.php`
- Create: `assets/js/epi-image-cache-sw.js` (caching worker template)
- Create: `tests/support/epi-test-epim-standin.php`
- Test: `tests/epim-image-cache.spec.js`

**Interfaces:**
- Produces: `EPI_Image_Cache::FEATURE = 'epim-image-cache'`, `EPI_Image_Cache::register_worker_endpoint(): void`, `EPI_Image_Cache::serve_worker(): void` (echoes and exits), `EPI_Image_Cache::worker_url(): string`, `EPI_Image_Cache::init(): void` (Task 2 fills it).
- The worker template reads its settings from a line the PHP prepends: `self.EPI_CACHE = { base, cache, maxAgeMs, maxEntries };` plus a `/* BlueWorx Lab {version} */` comment.

- [ ] **Step 1: Stand-in mu-plugin.** When `EPI_TEST_EPIM_STANDIN` runs it defines `EPI_EPIM_IMAGE_BASE` as `'http://localhost:' . $_SERVER['SERVER_PORT'] . '/epi-test-image/'`, and on `init` priority 0 answers `/epi-test-image/{id}.jpg`: id `999999` → status 500 HTML; anything else → 200 `image/png` one-pixel PNG with `Cache-Control: private` (mirrors ePim). Exit after.
- [ ] **Step 2: Write the failing tests** in `tests/epim-image-cache.spec.js`: `beforeAll` copies the stand-in into `.wp-test/wp/wp-content/mu-plugins/`, `afterAll` deletes it and restores the feature to on.
  - `the worker address serves the caching worker with a site-wide scope header`: GET worker url → 200, `content-type` contains `javascript`, `service-worker-allowed` is `/`, body contains `epi-epim-images-v1`, `localhost`, and the plugin version from `package.json`.
  - `switched off, the worker address serves a worker that removes itself`: set feature off via the Lab form, GET → 200, body contains `unregister`, not `epi-epim-images-v1`.
- [ ] **Step 3: Run to verify they fail.** `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/epim-image-cache.spec.js --workers=1` → FAIL (400 from admin-ajax).
- [ ] **Step 4: Implement** the class and template. `serve_worker()` builds the script from `EPI_Feature_Registry::is_enabled( self::FEATURE )`: on → settings line + template file contents; off → inline self-removing worker (`install`: skipWaiting; `activate`: delete caches whose key starts with the prefix, then `self.registration.unregister()`). Send headers with `header()`, `nocache_headers()` is not used (it sets `Expires` etc.; fine either way, but keep the exact Cache-Control above).
- [ ] **Step 5: Run to verify they pass.**
- [ ] **Step 6: Commit** `Serve an ePim image worker that can switch itself off`.

### Task 2: Register on pages, cache images, forget failures

**Files:**
- Modify: `includes/class-epi-feature-registry.php` (new definition, boot `array( 'EPI_Image_Cache', 'init' )`)
- Modify: `includes/class-epi-image-cache.php` (`init()`: enqueue on `wp_enqueue_scripts`, `wp_resource_hints` preconnect)
- Create: `assets/js/epi-image-cache.js` (register + capture-phase `error` listener)
- Modify: `assets/js/epi-image-cache-sw.js` (full caching behaviour per spec "Caching worker behaviour")
- Test: `tests/epim-image-cache.spec.js`

**Interfaces:**
- Consumes: Task 1's `worker_url()`, `FEATURE`, settings line.
- `epi-image-cache.js` gets `{ workerUrl, scope, base }` via `wp_localize_script` as `epiImageCache`.

- [ ] **Step 1: Write the failing tests** (a published page with `[forum_product_gallery source="epim"]` and featured image + two gallery ids, reusing the gallery spec's setup pattern; logged out for the front-end checks):
  - `a product page registers the worker and the second visit takes ePim images from it`: goto page, `await page.evaluate(() => navigator.serviceWorker.ready)`, reload; collect responses; the main image's response (url starts with `http://localhost:`) has `fromServiceWorker() === true`; `caches.open('epi-epim-images-v1')` keys include that url.
  - `page loads and other files are left to the network`: on the second visit, the document response and the gallery CSS response have `fromServiceWorker() === false`.
  - `an ePim image that fails is not kept`: page whose gallery includes id `999999`; after reload and the image's error, the cache keys do not include `/999999.jpg` (poll with `expect.poll`).
  - `switched off, pages stop registering the worker`: feature off, fresh context, goto page, `navigator.serviceWorker.getRegistrations()` length 0 after load + 1s; `epi-image-cache.js` not in the page.
  - `the page warms up the connection to ePim`: `link[rel=preconnect][href^="http://localhost:"]` present.
- [ ] **Step 2: Run to verify they fail.**
- [ ] **Step 3: Implement** the registry entry, `init()`, the page script and the caching worker. Worker CORS logic: module-level `corsWorks = true`; on a miss, if `corsWorks` try `fetch(url, { mode: 'cors', credentials: 'omit' })`, save only if `ok` and `content-type` starts with `image/`; on throw set `corsWorks = false` and fall back to `fetch(event.request)` (opaque, saved as is). Stamp: a `Response` stored under `base + '__epi-stamp'` holding `Date.now()`; clear the cache when older than `maxAgeMs`. Trim: delete `keys()` from the front while count (excluding stamp) > `maxEntries`.
- [ ] **Step 4: Run to verify they pass**, then the whole suite.
- [ ] **Step 5: Commit** `Keep ePim images in the visitor's browser`.

### Task 3: Version, changelog, readme

**Files:** `package.json`, `external-product-images.php`, `CHANGELOG.md`, `readme.txt`

- [ ] **Step 1:** Bump to 1.19.0 in all three version places. Changelog `## [1.19.0]` / `### Added`: one plain entry. Readme changelog line if the readme keeps one.
- [ ] **Step 2:** `git grep -n "1.18.1"` shows no stale version outside history entries.
- [ ] **Step 3: Commit** `Version 1.19.0`.
