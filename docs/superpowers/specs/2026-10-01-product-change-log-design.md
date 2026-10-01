# Product change log — design

Date: 2026-10-01
Status: approved in conversation, spec awaiting review

## Goal

Open any product and see exactly what its data was before and after each of its last two
updates, where an update is either an ePim push or an edit by internal staff. Nothing from
those two sources is missed, and nothing else gets in.

## Decisions taken

- **Field by field, not whole-product snapshots.** Each update lists the fields that changed,
  with the value before and after.
- **Two updates per product, plus the current data.** Older updates are deleted as new ones
  arrive, so the log cannot grow without limit.
- **Only two sources are recorded:**
  - **ePim**: an API request authenticated by key rather than a browser session (a REST or
    WooCommerce API request carrying no WordPress nonce). Shown as "ePim External API", never
    as the user who owns the key.
  - **Internal staff**: a logged-in user who can edit products, working in wp-admin (including
    quick edit, bulk edit and the WooCommerce importer) or in a browser REST call with a nonce.
    Shown by their display name.
- **Everything else is not recorded**, but still updates the stored copy so the next real
  update shows the right "before". This covers customer orders (including stock going down),
  orders created by staff, scheduled tasks, the command line, and other plugins.
- **Only product data is tracked.** Fixed list below. SEO plugins (SureRank, Yoast and so on),
  sales totals, review counts, edit locks and any other plugin's fields are ignored.
- **The on/off switch stays** in the plugin settings. If changes happen while it is off, the
  next recorded update says "Includes changes made while logging was off".
- **Existing history is cleared** when this ships. It is mostly SureRank noise and the old
  method could miss changes, so it cannot be trusted.

## What counts as product data

For products and their variations. A variation's changes show under its parent product,
marked with the variation.

- Post fields: name, description, short description, status, slug, menu order.
- Fields: SKU, GTIN, regular price, sale price, sale dates, tax status, tax class, manage
  stock, stock quantity, stock status, backorders, low stock amount, sold individually,
  weight, length, width, height, virtual, downloadable, downloadable files, download limit,
  download expiry, purchase note, attributes, default attributes, featured image, gallery,
  upsells, cross-sells, product URL, button text, grouped children, variation description,
  variation attribute values.
- Taxonomies: categories, tags, product type, visibility, shipping class, global attributes.
- `_price` is left out: WooCommerce works it out from regular and sale price, so it would
  only repeat them.

A filter lets a developer add a field to the list if ePim starts sending something new.

## How it works

1. **Stored copy.** Each product and variation has one stored copy of its tracked data: its
   current version.
2. **Noticing a change.** Any write to a tracked field, post field or taxonomy on a product or
   variation marks it as touched for this request. So do WooCommerce's stock hooks, because
   WooCommerce writes stock straight to the database without going through those writes.
3. **Before value when there is no copy yet.** The first time a product is touched with no
   stored copy, its data is read at that moment, before the write lands, and used as the
   "before".
4. **At the end of the request**, for each touched product, the live data is read fresh,
   ignoring caches, and compared with the stored copy.
   - If the request is ePim or internal staff and fields differ, one update is written: date,
     who, source, and each changed field with before and after. Then the copy is replaced.
   - Otherwise the copy is replaced and nothing is recorded.
5. **Order stock changes.** Stock changes made while WooCommerce processes an order (reduce,
   restore or manual adjustment from an order) refresh the copy only, even when staff are
   the ones creating the order.
6. **Pruning.** After writing an update, anything older than the product's two most recent
   updates is deleted.
7. **Logging off.** When logging is switched back on, the time is saved. A stored copy taken
   before that time may be out of date, so the next update recorded against it is flagged.
8. **Deleted products.** Moving to the bin is a status change and is recorded. Permanently
   deleting a product removes its stored copy and its log.

## Storage

- `{prefix}epi_product_snapshots`: one row per product or variation, holding its id, parent
  product id, the tracked data as JSON, and when the copy was taken.
- `{prefix}epi_product_changes`: the existing table is kept and emptied on upgrade. It gains a
  column for the "logging was off" flag. An update is the set of rows sharing a `request_id`.

## What staff see

The existing "Product Change Log" box on the product edit screen and the section on the full
product data page both show the same thing: the last two updates, newest first. Each update
has a header (date, who, source, and the "logging was off" notice if it applies) and a table
of field, before and after. Fields use readable names ("Regular price", not `_regular_price`).
If there are no updates yet, the box says so. Built from the blueworx-admin-design system.

## Errors

If writing an update or a stored copy fails, the database error goes to the PHP error log
with the product id, so a failure is never silent.

## Testing

The CI test site has no WooCommerce, so tests use a test-only mu-plugin that registers a
stand-in `product` post type and loads the change log. Specs cover:

- an internal staff edit is recorded with the user's name, with correct before and after
- a key-authenticated API change is recorded as "ePim External API"
- a SureRank field change and a change from another source are not recorded, and the next
  real update still shows the correct "before"
- only the last two updates are kept
- an update after logging was switched off and on carries the notice

Order stock handling needs real WooCommerce and is checked by hand on a staging copy.

## Out of scope

- Catching ePim pushes that fail before they change anything (the separate sync log).
- Restoring a previous version.
- A site-wide view across all products.
