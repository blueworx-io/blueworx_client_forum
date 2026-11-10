# ePim image browser cache — design

Date: 2026-11-02
Status: approved in conversation ("can we do both?" / "proceed"), spec awaiting review

## Goal

A visitor's browser downloads each ePim product image once, then reuses it on every later page,
without anything being stored on Forum's server.

## Why

Product images are shown straight from ePim (`https://epim.online/webproduct/assetimage/{id}.jpg`),
which Forum and ePim's developer have agreed is the approach. But ePim answers every image with
`Cache-Control: private`, no lifetime, no `ETag`/`Last-Modified`, and three cookies. Cloudflare
passes every request through (`cf-cache-status: BYPASS`) and browsers have nothing to cache on, so
the same image is fetched again on every page view.

Two fixes run side by side:

1. **ePim's side (not code):** Luke has emailed asking ePim to send `Cache-Control: public,
   max-age=...`, drop the cookies, and add `Access-Control-Allow-Origin` for the Forum site.
2. **Our side (this design):** a service worker that keeps ePim images in the visitor's browser.

When ePim fixes their headers, our side keeps working and stops being needed for speed; it is not
removed.

## Decisions taken

- **Nothing stored on Forum's server.** Luke ruled out a server-side copy or proxy: keeping images
  off Forum's server is the point. Images stay at ePim; only the visitor's browser keeps a copy.
- **Only ePim product images are touched.** The worker answers requests whose URL starts with the
  ePim image base (`EPI_EPIM_IMAGE_BASE`). Every other request (pages, cart, checkout, admin,
  WooCommerce AJAX, other images) is never intercepted. It does not call `respondWith`, so the
  browser handles it exactly as today.
- **Saved images are thrown away after 7 days** (Claude's call, matches the weekly ePim pull). The
  whole saved set is cleared once it is 7 days old and refills as pages are viewed. A replaced
  image therefore shows within a week at worst.
- **Broken images are forgotten straight away.** ePim answers a missing image with a 500 HTML
  page. Without CORS the worker cannot see the status, so it saves whatever comes back. The page
  tells the worker to drop any ePim image that fails to load, so a broken copy is never reused.
- **No CORS attempt for now** (Claude's call, revised after review). Trying CORS first doubled
  requests to ePim on every uncached image, because workers restart every few seconds of idle.
  When ePim adds CORS, a release can switch the worker to CORS mode and save only real images.
- **A cap on how many images are kept** (Claude's call): 300. Oldest go first. Chrome counts each
  CORS-less saved image as several MB against storage quota whatever its real size; a cap keeps
  that in bounds. A failed save (quota) is ignored and the image still shows from the network.
- **On/off switch in BlueWorx Lab** (Claude's call), under Product display, titled "Keep ePim
  images in the browser". On by default, like every other feature (the registry treats a missing
  flag as on).
- **Turning it off removes it from visitors' browsers.** The worker script stays reachable when the
  feature is off, and then serves a version that clears its saved images and unregisters itself.
  Browsers may keep the worker script for 6 hours (see below), so it disappears on the first visit
  after that.
- **Warm the connection to ePim early** (Claude's call): a `preconnect` hint for the ePim host on
  front-end pages while the feature is on.

## How it works

### The worker script

Served through `admin-ajax.php?action=epi_image_cache_sw` (`wp_ajax_` and `wp_ajax_nopriv_`), with
`Service-Worker-Allowed: /` so its scope can be the whole site (`/`) although the script lives under
`/wp-admin/`. admin-ajax is never page-cached, so the off switch and new releases always reach
browsers (Claude's call; the live site has an nginx page cache). Response headers:
`Content-Type: application/javascript; charset=utf-8`, `Cache-Control: max-age=21600`,
`Service-Worker-Allowed: /`. The handler is registered whether or not the feature is on.

Browsers re-check the worker script on page loads. Without any caching that is a full WordPress
request on every page a returning visitor opens, so the script may be kept for 6 hours (Claude's
call, after review). The cost is that switching off or a new release takes up to 6 hours to reach a
browser.

The script is generated from a static JS file in the plugin, with the ePim image base, cache name,
lifetime and cap passed in (so the worker never hard-codes a host the PHP does not). The plugin
version goes into a comment at the top so each release changes the bytes and browsers pick up the
new worker.

- Feature **on**: the caching worker below.
- Feature **off**: a worker that deletes its caches and calls `self.registration.unregister()` on
  activate.

Caching worker behaviour:

- `install`: `skipWaiting()`, and where the browser supports it (`event.addRoutes`, Chrome 123+)
  declare that page loads go straight to the network, so the worker is not even started for a page
  load. Other browsers start the worker briefly before each page; it still does not answer. `activate`: `clients.claim()`, and delete any cache whose name starts
  with the plugin prefix but is not the current one.
- `fetch`: only for GET requests whose URL starts with the ePim base. Otherwise return without
  `respondWith`.
  - Look in the cache. If the saved set is older than 7 days, clear it first. A stamp entry in the
    cache records when the set was started.
  - Hit: return it.
  - Miss: fetch, return the response to the page, and save a copy
    in the background (`event.waitUntil`). Trim to the cap after saving. Never let a failed save
    break the response.
  - If fetching fails outright, let the error reach the page as it would today (the existing
    `onerror` shows the placeholder).
- `message` `{ type: 'epi-forget', url }`: delete that URL from the cache.

### On the page

A small front-end script (not in wp-admin), enqueued on every front-end page while the feature is
on:

- Registers the worker with `{ scope: '/', updateViaCache: 'all' }`, only when
  `'serviceWorker' in navigator`. It is a progressive enhancement: no worker support means today's
  behaviour.
- Listens for image `error` events on the document in the capture phase, so it sees the failing
  URL before the existing inline `onerror` swaps in the placeholder. For an ePim URL it posts
  `epi-forget` to the active worker.

Plus a `preconnect` resource hint to `https://epim.online` via `wp_resource_hints`.

Existing image markup (gallery, featured image, lightbox) is unchanged.

## Non-goals

- Copying, proxying or resizing images on Forum's server.
- Changing image URLs or adding version numbers to them. ePim gives no "picture changed" signal yet;
  the 7-day clear stands in for one. Revisit when ePim answers whether a replaced image gets a new
  picture number.
- Caching anything other than ePim product images, including the datasheet and fitting-instruction
  PDFs on other ePim hosts.
- Offline support.

## Anti-goals (would be a failure even if it worked)

- Any change to how cart, checkout, account or admin pages load.
- A broken image that keeps showing after ePim fixes it, beyond a single page view.
- A worker that cannot be removed by switching the feature off.

## Known limits (Claude's calls)

- **Removing the whole plugin leaves the worker in browsers that already have it.** Once the plugin
  is gone the worker address returns an error, and browsers keep the old worker. It only ever
  handles ePim images and clears them weekly, so the harm is small. To remove it cleanly, switch the
  feature off and leave the plugin active for a couple of weeks first.
- **One worker per site.** The live site has none today (checked 2026-11-02: no registration on the
  home page, no `sw.js`). A future push-notification or PWA plugin would clash and need merging.

## Testing

Playwright on the local WordPress harness (no WooCommerce there, so use the gallery shortcode on a
page, as the gallery spec does). Tests must not depend on the real ePim. A test-only mu-plugin points
the ePim image base at a stand-in on a different origin of the same harness
(`http://localhost:8881/epi-test-image/{id}.jpg` while the site is `127.0.0.1:8881`), which returns a
small image, or a 500 for a "missing" id. So `EPI_EPIM_IMAGE_BASE` becomes overridable (defined only
if not already defined). The spec removes the mu-plugin when it finishes so other specs see the real
base. Cover:

- The worker script address returns JavaScript with `Service-Worker-Allowed: /`, and contains the
  caching worker when on and the self-removing worker when off.
- A front-end page registers the worker; after it takes control, a second load serves the ePim image
  from the worker and the image is in the cache.
- A non-ePim request on the same page is not served by the worker.
- A failing ePim image (unknown ID) is not left in the cache.
- Switched off in BlueWorx Lab: the page no longer registers the worker.

## Left to the builder

File and class names, exact JS structure, how the stamp entry is stored, and test helper layout.
