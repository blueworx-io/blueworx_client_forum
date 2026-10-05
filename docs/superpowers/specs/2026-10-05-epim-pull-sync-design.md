# ePim product pull sync and Product import page

**Date:** 2026-10-05
**Status:** Approved design, pending plan review
**Target version:** 1.14.0 (minor: new feature)
**Issue:** "Add ePim product pull sync and Product Import page" (label enhancement, branch `epim-pull-sync`)

## Goal

Stop depending on ePim pushing products into WooCommerce. The site pulls from ePim's
read-only API on its own schedule, in small background batches, and keeps a readable
record of every pull so the client can see exactly what ePim sent and trace data errors
back to the ePim team.

The push keeps running for a transition period. Both write the same ePim data, so a product
ends up the same either way; the pull is switched to own pictures only once the push is off.

## What was learned from the live API and site (2026-10-05)

- An ePim **Product** is a group (about 620). An ePim **Variation** is the sellable SKU
  (about 930 live, about 1180 with archived and unapproved). A WooCommerce product on the
  site is one ePim Variation, matched by SKU. All site products are simple, none variable.
- `GET Variations?changedSinceUTC=&start=&limit=&showArchived=true&showUnApproved=true`
  returns `{Start, Limit, TotalResults, Results}`. Each result carries everything needed:
  `Id`, `ProductId`, `IsArchived`, `IsApprovedForPublishing`, `SKU`, `Name`, `Price`,
  `Short_Description`, `SKU_Text`, `ProductCategoryIds`, `PictureIds`, `PictureIdsGrouped`
  (`Image`, `Logo`, `Datasheet Image`), `AttributeValues` (`AttributeHeaderName`, `Value`).
  So the Products, VariationsForProduct, Pictures and Branches endpoints are not needed.
- `GET Categories` is a flat list of about 68: `Id`, `Name`, `ParentId`, `UpdatedOnUTC`.
- `GET DeletedEntities?since=&start=&limit=`: `EntityType` is `Product` (a group) or
  `SKU_Product_Mapping` (a variation), with `EntityId`.
- A picture's web address is `https://epim.online/webproduct/assetimage/{id}.jpg`, which is
  exactly what the plugin's existing image builder makes from an ID, so picture IDs can be
  stored straight into the WooCommerce image fields and nothing is downloaded.
- A wrong key answers 401 with a JSON message.
- The live site today: title is `Name`, long description is `SKU_Text`, short description is
  empty, regular price is `Price`, attributes are custom (non-taxonomy) product attributes
  named after `AttributeHeaderName` (including `Bullet 1..n`, `Barcode`, `ECD special
  prices`), stock is not managed. The push currently uploads pictures into the media library.

## Decisions (from Luke, 2026-10-05)

1. **Stock is left out.** If a product is on the site, it is in stock. No branch or stock
   endpoint is called. (The issue listed stock; this overrides it.)
2. **All products are simple**, one per ePim Variation. No WooCommerce variations are made.
3. **Pictures:** only the `Image` group goes on the site; the first is the main image, the
   rest are the gallery. Logo and datasheet pictures are left off.
4. **Descriptions:** long description from `SKU_Text`; short description is not written.
5. **Transition:** the pull runs alongside the push. A switch on the Product import page,
   "Set product pictures from ePim", is **off by default**; while off, the pull never touches
   `_thumbnail_id` or `_product_image_gallery`, so the push's media-library pictures stay put.
   Once the push is switched off, the client turns the switch on and the next pull points
   every product's pictures at ePim.

## Rules

- **ePim always wins** for the fields it owns (below). Nothing else on the product is touched.
- **Match by SKU, no duplicates.** A product is found first by its stored ePim variation ID,
  then by SKU. Found: updated. Not found and live in ePim: created and published. Not found
  and archived or unapproved: skipped, not created.
- **Hidden** means WooCommerce status **Draft**. Archived, unapproved and deleted items are
  set to Draft; an item that comes back live is published again.
- **No ePim images are stored.** Picture IDs go into the image meta; URLs are built on display
  by the existing gallery and featured-image features.

## Field mapping (ePim Variation to WooCommerce simple product)

| ePim | WooCommerce | Notes |
|---|---|---|
| `Id` | meta `_epim_variation_id` | Identity for matching after a SKU change |
| `ProductId` | meta `_epim_product_id` | Lets a deleted group hide its products |
| `SKU` | `_sku` | Match key |
| `Name` | post title | |
| `SKU_Text` | post content | Plain text as sent |
| `Price` | `_regular_price` | Two decimals; `_price` follows via WooCommerce save |
| `IsArchived`, `IsApprovedForPublishing` | post status | Live: publish. Otherwise: draft |
| `ProductCategoryIds` | `product_cat` terms | Replaces the product's categories. IDs not in the Categories list are ignored. If none map, categories are left alone |
| `AttributeValues` | `_product_attributes` (custom, visible) | Key `sanitize_title(AttributeHeaderName)`, name as sent, one value, position in order sent; empty values skipped. Attributes ePim does not mention are left alone, so the push's extra ones do not flip-flop during the transition |
| `PictureIdsGrouped.Image` | `_thumbnail_id` (first), `_product_image_gallery` (rest) | Only while "Set product pictures from ePim" is on. Falls back to `PictureIds` minus Logo and Datasheet IDs when the group is missing |
| pull time | meta `_epim_synced_at` | UTC |

Not written: short description, GTIN, weight and dimensions, stock, slug, SEO, Elementor data.

Categories: each ePim category becomes a `product_cat` term carrying term meta
`_epim_category_id`, placed under its parent. On first contact an existing term with the same
name under the same parent is reused and tagged, so the site's current categories are not
duplicated. Names and parents follow ePim on every pull.

## Sync behaviour

- **Automatic pull** once a day at 02:00 site time via WP-Cron (`epi_pull_daily`). It first
  removes records older than 90 days, then starts a pull.
- **Manual pull:** "Pull now" on the Product import page. "Re-import everything" does a full
  pull (same as the first run) after a browser confirmation.
- **What a pull fetches:** the Categories list (always, it is tiny), then Variations changed
  since the last successful pull's start time minus five minutes (the first run and a full
  re-import use `2000-01-01`), always with archived and unapproved included so a product that
  was archived gets hidden, then DeletedEntities since the same time.
- **Batches:** work runs under a WP-Cron single event, `epi_pull_batch`, in a loop with a
  time budget of 20 seconds (filter `epi_pull_batch_seconds`); one unit of work is one API
  page of 50 variations, or the categories list, or one page of deleted entities. When the
  budget is used up the runner schedules the next batch (distinct arguments each time, so
  WordPress does not drop it as a duplicate) and calls `spawn_cron()`.
- **No overlap:** an option lock holds the running run's ID and time. A new pull is refused
  while it is held. A lock older than 20 minutes is treated as a crashed run: that run is
  marked failed with "Timed out" and the lock is released.
- **Errors:** an API failure (network, non-2xx, bad key, non-JSON) fails the run with a plain
  message; products already written stay written. A product that cannot be saved (for
  example a duplicate SKU WooCommerce refuses) is recorded as an error item and the run
  continues.
- **Change log:** writes made during a pull are classified as ePim in the existing product
  change log (new filter `epi_change_source`), so each product's history shows the pull as
  "ePim External API" just as the push does.
- **Hosting note:** WP-Cron needs page traffic or a system cron hitting `wp-cron.php`. If the
  host sets `DISABLE_WP_CRON`, a system cron must call `wp-cron.php` for pulls to run.

## Storage

Two tables, created on activation and on first boot after update (option
`epi_pull_db_version`), dropped on uninstall.

`{prefix}epi_pull_runs`: `id`, `trigger` (auto|manual), `status`
(queued|running|done|failed), `full` (0|1), `since_utc` (datetime, null for full),
`started_at`, `finished_at`, `stage` (categories|products|deleted|done), `cursor`, `total`,
`batches`, `added`, `updated`, `hidden`, `unchanged`, `skipped`, `errors`, `message`.

`{prefix}epi_pull_items`: `id`, `run_id`, `product_id`, `epim_id`, `sku`, `name`, `action`
(added|updated|hidden|error), `changes` (JSON list of `{field, label, before, after}`), `raw`
(the ePim record as received, JSON), `message`, `created_at`. Unchanged and skipped products
are counted on the run but not stored, so 90 days of records stays small.

Options: `epi_pull_settings` (`key`, `images`), `epi_pull_lock`, `epi_pull_category_map`
(ePim category ID to term ID, refreshed each run), `epi_pull_db_version`. A
`EPI_EPIM_SUBSCRIPTION_KEY` constant in `wp-config.php` overrides the saved key.

Retention: runs and their items older than 90 days are deleted at the start of each daily run.

## Product import page

Products menu, "Product import", `manage_options` only (administrators). Built from the
blueworx-admin-design system and enqueued like the Lab page.

**List view** (`admin.php?page=epi-product-import`):
- Page header: eyebrow "ePim", title "Product import", actions "Pull now" (primary) and
  "Re-import everything" (secondary, confirms first).
- Notice after an action: saved, started, already running, no key, or the error.
- Settings card: "Subscription key" (monospace input), "Set product pictures from ePim"
  switch with a one-line note about the push, "Save settings" in the card footer.
- Three stat tiles: last pull (date, status), next automatic pull, last pull's changes.
- Runs table (flush card): When, Trigger, Added, Updated, Hidden, Errors, Status, View.
  Twenty per page with a pager in the table footer. Empty state when there are no runs.

**Detail view** (`&run=ID`): header with the pull's date and a "Back to pulls" button; a
description list (trigger, started, finished, since, status, message, counts); items table
(Product name with SKU beneath, Result badge, Changes as "Label: before, arrow icon, after"
one per line, and a closed accordion "Raw ePim data" holding the record as `<pre>`). Fifty
per page. A run with nothing recorded shows an empty state.

The page's own CSS is the chrome override only (`assets/css/epi-product-import.css`); its JS
(`assets/js/epi-product-import.js`) confirms the full re-import and toggles the accordions.

## Feature switch

A new Lab feature `epim-pull`, "ePim product pull", in Admin product tools, on by default, no
dependency (the writer uses WooCommerce's product API when it is present and plain posts and
meta otherwise, which is what lets the test harness run the whole pipeline). Switching it off
leaves the daily event scheduled with nothing attached to it, so nothing runs.

## Testing

The harness has no WooCommerce and no outside network, and PHP's built-in server cannot
answer a request to itself. So a test-only mu-plugin (`tests/support/epi-test-epim.php`):
- registers a `product` post type and `product_cat` taxonomy when absent, and an editor user;
- fakes ePim at the HTTP transport (`pre_http_request`) from fixture arrays with three
  scenarios (initial, changed, deleted), honouring `start`, `limit` and the key header;
- exposes REST routes under `epi-test/v1/pull/` to set the scenario, run a pull to completion
  without waiting on cron, read a product by SKU, seed an old run, prune and reset.

Playwright covers: feature boot and schedule, API paging and bad key, mapping, category
hierarchy, create / update / hide / skip, SKU matching of an existing product, the pictures
switch, incremental and full runs, deleted entities, overlap refusal, pruning, the change log
source, the page's admin-only access, saving settings, Pull now, the runs table and the
detail view. WooCommerce's own save path is checked by hand on staging.

## Out of scope

Stock (decided), variable products, downloading pictures, ePim groups as WooCommerce
grouped products, related or alternative products, keywords, GTIN, switching the push off
(an ePim-side action after go-live testing).
