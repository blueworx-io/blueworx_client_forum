# Product Change Log Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each product keeps its last two updates from ePim or from staff, field by field with before and after, and nothing else gets in.

**Architecture:** A stored copy of each product's tracked data is the "current version". Writes to a product mark it as touched; at the end of the request the live data is read fresh, diffed against the copy, recorded as one update if the request came from ePim or staff, and the copy is replaced. Field list, request source and storage each live in a small class of their own under `includes/change-log/`; `EPI_Product_Change_Log` wires the hooks and draws the screen.

**Tech Stack:** PHP (WordPress plugin, WooCommerce), `$wpdb` custom tables, Playwright against the foundation's local WordPress harness (PHP + SQLite, no WooCommerce).

**Spec:** `docs/superpowers/specs/2026-10-01-product-change-log-design.md`

## Global Constraints

- Text domain `blueworx_client_forum`; every user-facing string translatable.
- ePim's label is exactly `ePim External API`. The logging-off flag reads exactly `May include changes made while logging was off`.
- Two updates kept per product (`KEEP_UPDATES = 2`).
- Admin markup only from the blueworx-admin-design system (`bw-*` classes). Invoke the `blueworx-admin-design` skill before touching render code.
- No new dependencies.
- SQL must run on MySQL and on the harness's SQLite translation: no `REPLACE INTO`, no `TRUNCATE`, no subquery `LIMIT`.
- Version moves 1.12.3 → 1.13.0 in the plugin header, `EPI_VERSION`, `package.json` and `readme.txt` stable tag, with matching `CHANGELOG.md` and `readme.txt` entries.
- Run the linter (`vendor/bin/phpcs`) once at the end only. Report findings, don't loop on them.
- The local harness often times out on this machine. If it does, push and let CI run the suite; CI is the judge.

## Review Focus

- A save that changes nothing tracked (staff press Update untouched) must record nothing. Test in Task 1 (`other sources…` spec).
- A field going from missing to empty (`''`) is not a change. Test in Task 1 (`other sources…` spec).
- Text with non-ASCII characters (`°`, `Ø`) must come back exactly. Test in Task 1 (`ePim push…` spec).
- Structured fields (`_product_attributes`) must show as data, not PHP-serialised text. Test in Task 1 (`structured fields…` spec).
- Permanently deleting a product must remove its stored copy and its log. Test in Task 1 (`permanently deleting…` spec).

---

### Task 1: Track ePim and staff updates, two per product

**Files:**
- Create: `includes/change-log/class-epi-change-fields.php`
- Create: `includes/change-log/class-epi-change-source.php`
- Create: `includes/change-log/class-epi-change-store.php`
- Replace: `includes/class-epi-product-change-log.php` (whole file)
- Modify: `external-product-images.php:146-151` (call `register_always()`)
- Create: `tests/support/epi-test-change-log.php`
- Create: `tests/product-change-log.spec.js`

**Interfaces:**
- Produces:
  - `EPI_Change_Fields::product_id( int $object_id ): int`, `read( int $object_id ): array` (field id `type:name` → value), `diff( array $before, array $after ): array` (field id → `[before, after]`), `encode( mixed $value ): string`, `label( string $field_type, string $field_name ): string`, `is_tracked_meta( string ): bool`, `is_tracked_taxonomy( string ): bool`, `stock_fields(): string[]`
  - `EPI_Change_Source::current( int $object_id ): string` (`'epim'|'staff'|'ignored'`), `actor( string $source ): string`
  - `EPI_Change_Store::install()`, `clear_changes()`, `get_snapshot( int ): ?array{data:array,taken_at:int}`, `put_snapshot( int $object_id, int $product_id, array $data )`, `insert_update( int $product_id, array $rows, string $request_id, string $source, string $actor, int $user_id, bool $gap )`, `prune( int $product_id )`, `delete_object( int )`, `get_updates( int $product_id ): array`, `now_ms(): int`
  - `EPI_Product_Change_Log::install()`, `maybe_upgrade()`, `register_always()`, `init()`, `get_updates( int $product_id ): array`, `render_full_log( int $product_id )`
  - Update shape from `get_updates`: `[ 'request_id', 'changed_at' (UTC mysql), 'actor', 'source', 'gap' (bool), 'fields' => [ [ 'object_id', 'object_type', 'field_type', 'field_name', 'label', 'before', 'after' ] ] ]`, newest first.

- [ ] **Step 1: Write the test-only mu-plugin**

Create `tests/support/epi-test-change-log.php`:

```php
<?php
/**
 * Plugin Name: Forum test support — product change log
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-change-log.spec.js.
 *              Stands in for WooCommerce's product type, boots the change log, lets a request act
 *              as ePim, and reports what the log recorded.
 *              Never shipped: tests/ is outside the release allowlist.
 */

if ( ! defined( 'EPI_TEST_EPIM_KEY' ) ) {
	define( 'EPI_TEST_EPIM_KEY', 'epim-test-key' );
}

// The harness has no WooCommerce. The change log only needs posts of type
// "product", so a plain post type stands in, with the fields the specs write
// opened to REST. No "editor" support keeps the classic edit screen, where the
// change-log box is drawn.
add_action(
	'init',
	static function () {
		if ( post_type_exists( 'product' ) ) {
			return;
		}

		register_post_type(
			'product',
			array(
				'label'        => 'Products',
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'rest_base'    => 'epi-test-products',
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);

		foreach ( array( '_sku', '_regular_price', 'surerank_seo_checks_last_updated' ) as $key ) {
			register_post_meta(
				'product',
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
);

// ePim authenticates with an API key, not a browser session. A request carrying
// the test key is signed in as the site's first administrator, the way
// WooCommerce's key check signs in the key's owner.
add_filter(
	'determine_current_user',
	static function ( $user_id ) {
		if ( empty( $_SERVER['HTTP_X_EPI_TEST_KEY'] ) || EPI_TEST_EPIM_KEY !== $_SERVER['HTTP_X_EPI_TEST_KEY'] ) {
			return $user_id;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : $user_id;
	},
	30
);

// Without WooCommerce the plugin never loads its product classes, so boot the
// change log here exactly as the plugin does when WooCommerce is active.
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) || ! defined( 'EPI_PLUGIN_DIR' ) || ! class_exists( 'EPI_Feature_Registry' ) ) {
			return;
		}

		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-change-log.php';
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-meta.php';

		EPI_Product_Change_Log::maybe_upgrade();
		EPI_Product_Change_Log::register_always();

		if ( EPI_Feature_Registry::is_enabled( 'change-log' ) ) {
			EPI_Product_Change_Log::init();
		}
	},
	20
);

add_action(
	'rest_api_init',
	static function () {
		$admin_only = static function () {
			return current_user_can( 'manage_options' );
		};

		// What the log holds for a product.
		register_rest_route(
			'epi-test/v1',
			'/updates/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return EPI_Product_Change_Log::get_updates( (int) $request['id'] );
				},
			)
		);

		// Writes one field as whoever calls it: staff when called with a session
		// and nonce, nobody when called bare. Also the way to write an array,
		// which the REST meta schema above does not allow.
		register_rest_route(
			'epi-test/v1',
			'/write-meta',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					update_post_meta( (int) $request['id'], (string) $request['key'], $request['value'] );
					return array( 'ok' => true );
				},
			)
		);

		// Switch logging on or off, as the Lab screen's save does.
		register_rest_route(
			'epi-test/v1',
			'/logging',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$flags               = get_option( EPI_Feature_Registry::OPTION, array() );
					$flags               = is_array( $flags ) ? $flags : array();
					$flags['change-log'] = (bool) $request['on'];
					update_option( EPI_Feature_Registry::OPTION, $flags, false );
					return $flags;
				},
			)
		);
	}
);
```

- [ ] **Step 2: Write the failing specs**

Create `tests/product-change-log.spec.js`:

```js
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The product change log keeps each product's last two updates from ePim or
// from staff, field by field. The harness has no WooCommerce, so a test-only
// mu-plugin stands a "product" post type in for it, lets a request act as ePim
// (an API key, no browser session), and reports what the log recorded.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-change-log.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const PRODUCTS = '/wp-json/wp/v2/epi-test-products';
const EPIM = { 'X-EPI-Test-Key': 'epim-test-key' };

let nonce;

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  await setLogging(page, true);
});

async function ok(res, what) {
  expect(res.ok(), `${what}: ${res.status()} ${await res.text()}`).toBeTruthy();
  return res.json();
}

// Staff: the logged-in browser session, which carries a nonce.
async function createProduct(page, title) {
  const product = await ok(
    await page.request.post(PRODUCTS, {
      headers: { 'X-WP-Nonce': nonce },
      data: { title, status: 'publish', meta: { _sku: 'START' } },
    }),
    'create product'
  );
  return product.id;
}

async function staffSave(page, id, meta) {
  return ok(
    await page.request.post(`${PRODUCTS}/${id}`, { headers: { 'X-WP-Nonce': nonce }, data: { meta } }),
    'staff save'
  );
}

async function staffWriteMeta(page, id, key, value) {
  return ok(
    await page.request.post('/wp-json/epi-test/v1/write-meta', {
      headers: { 'X-WP-Nonce': nonce },
      data: { id, key, value },
    }),
    'staff write'
  );
}

// ePim: an API key and no browser session. `request` shares no cookies with `page`.
async function epimSave(request, id, meta) {
  return ok(await request.post(`${PRODUCTS}/${id}`, { headers: EPIM, data: { meta } }), 'ePim save');
}

// Anyone else: no session and no key — a guest, a scheduled task, another plugin.
async function outsideWriteMeta(request, id, key, value) {
  return ok(
    await request.post('/wp-json/epi-test/v1/write-meta', { data: { id, key, value } }),
    'outside write'
  );
}

async function updates(page, id) {
  return ok(
    await page.request.get(`/wp-json/epi-test/v1/updates/${id}`, { headers: { 'X-WP-Nonce': nonce } }),
    'read updates'
  );
}

async function setLogging(page, on) {
  return ok(
    await page.request.post('/wp-json/epi-test/v1/logging', {
      headers: { 'X-WP-Nonce': nonce },
      data: { on },
    }),
    'set logging'
  );
}

test('a staff edit is recorded under their name, with before and after', async ({ page }) => {
  const id = await createProduct(page, 'Staff edit product');
  await staffSave(page, id, { _sku: 'STAFF-1' });

  const me = await ok(
    await page.request.get('/wp-json/wp/v2/users/me', { headers: { 'X-WP-Nonce': nonce } }),
    'current user'
  );
  const [latest] = await updates(page, id);

  expect(latest.source).toBe('staff');
  expect(latest.actor).toBe(me.name);
  expect(latest.gap).toBe(false);
  expect(latest.fields).toEqual([
    expect.objectContaining({
      field_type: 'meta',
      field_name: '_sku',
      label: 'SKU',
      before: 'START',
      after: 'STAFF-1',
    }),
  ]);
});

test('an ePim push is recorded as "ePim External API", never as the key owner', async ({
  page,
  request,
}) => {
  const id = await createProduct(page, 'ePim product');
  await epimSave(request, id, { _sku: 'Ø-90° EPIM' });

  const [latest] = await updates(page, id);

  expect(latest.source).toBe('epim');
  expect(latest.actor).toBe('ePim External API');
  expect(latest.fields).toEqual([
    expect.objectContaining({ field_name: '_sku', before: 'START', after: 'Ø-90° EPIM' }),
  ]);
});

test('other sources and SEO fields are not recorded, but the next "before" is still right', async ({
  page,
  request,
}) => {
  const id = await createProduct(page, 'Quiet product');

  await staffSave(page, id, { surerank_seo_checks_last_updated: '1790846768' });
  await staffSave(page, id, { _sku: 'START' }); // a save that changes nothing tracked
  await staffWriteMeta(page, id, '_regular_price', ''); // missing to empty is not a change
  await outsideWriteMeta(request, id, '_sku', 'OUTSIDE');

  expect(await updates(page, id)).toHaveLength(1); // only the creation

  await epimSave(request, id, { _sku: 'EPIM-2' });

  const list = await updates(page, id);
  expect(list).toHaveLength(2);
  expect(list[0].fields).toEqual([
    expect.objectContaining({ field_name: '_sku', before: 'OUTSIDE', after: 'EPIM-2' }),
  ]);
  expect(JSON.stringify(list)).not.toContain('surerank');
});

test('only the last two updates are kept, newest first', async ({ page }) => {
  const id = await createProduct(page, 'Busy product');
  await staffSave(page, id, { _sku: 'A' });
  await staffSave(page, id, { _sku: 'B' });

  const list = await updates(page, id);
  expect(list).toHaveLength(2);
  expect(list[0].fields).toEqual([expect.objectContaining({ before: 'A', after: 'B' })]);
  expect(list[1].fields).toEqual([expect.objectContaining({ before: 'START', after: 'A' })]);
});

test('structured fields are kept as data, not as stored text', async ({ page }) => {
  const id = await createProduct(page, 'Attributes product');
  const attributes = {
    colour: { name: 'Colour', value: 'White', position: 0, is_visible: 1, is_variation: 0, is_taxonomy: 0 },
  };
  await staffWriteMeta(page, id, '_product_attributes', attributes);

  const [latest] = await updates(page, id);
  expect(latest.fields).toEqual([
    expect.objectContaining({
      field_name: '_product_attributes',
      label: 'Attributes',
      before: null,
      after: attributes,
    }),
  ]);
});

test('an update after logging was switched off and on is flagged', async ({ page, request }) => {
  const id = await createProduct(page, 'Gap product');

  await setLogging(page, false);
  await staffSave(page, id, { _sku: 'WHILE-OFF' });
  await setLogging(page, true);
  await epimSave(request, id, { _sku: 'AFTER' });

  const [latest] = await updates(page, id);
  expect(latest.gap).toBe(true);
  expect(latest.fields).toEqual([expect.objectContaining({ before: 'START', after: 'AFTER' })]);
});

test('permanently deleting a product removes its log', async ({ page }) => {
  const id = await createProduct(page, 'Doomed product');
  expect(await updates(page, id)).toHaveLength(1);

  await ok(
    await page.request.delete(`${PRODUCTS}/${id}?force=true`, { headers: { 'X-WP-Nonce': nonce } }),
    'delete product'
  );

  expect(await updates(page, id)).toEqual([]);
});
```

- [ ] **Step 3: Run the specs to see them fail**

Run:
```bash
node ../bluegroup_core_foundation/scripts/wp-test-env.mjs up --plugin .
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test tests/product-change-log.spec.js --workers=1 --retries=0
```
Expected: FAIL. `read updates` returns 500 (`get_updates` does not exist on the old class). If the harness times out on `wp-login.php`, note it and carry on; CI runs these in Step 10.

- [ ] **Step 4: Write the field list**

Create `includes/change-log/class-epi-change-fields.php`:

```php
<?php
/**
 * Which product data the change log tracks, and how it reads it.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The fixed list of product data, read straight from the database.
 *
 * Reads bypass the object cache: WooCommerce writes stock with plain SQL, and
 * a cached read would miss it.
 */
final class EPI_Change_Fields {

	/**
	 * Tracked post columns.
	 *
	 * @return array Column => label.
	 */
	public static function post_fields() {
		return array(
			'post_title'   => __( 'Product name', 'blueworx_client_forum' ),
			'post_content' => __( 'Description', 'blueworx_client_forum' ),
			'post_excerpt' => __( 'Short description', 'blueworx_client_forum' ),
			'post_status'  => __( 'Status', 'blueworx_client_forum' ),
			'post_name'    => __( 'Slug', 'blueworx_client_forum' ),
			'menu_order'   => __( 'Menu order', 'blueworx_client_forum' ),
		);
	}

	/**
	 * Tracked WooCommerce fields.
	 *
	 * `_price` is left out: WooCommerce works it out from the regular and sale
	 * price, so it would only repeat them.
	 *
	 * @return array Meta key => label.
	 */
	public static function meta_fields() {
		$fields = array(
			'_sku'                   => __( 'SKU', 'blueworx_client_forum' ),
			'_global_unique_id'      => __( 'GTIN', 'blueworx_client_forum' ),
			'_regular_price'         => __( 'Regular price', 'blueworx_client_forum' ),
			'_sale_price'            => __( 'Sale price', 'blueworx_client_forum' ),
			'_sale_price_dates_from' => __( 'Sale starts', 'blueworx_client_forum' ),
			'_sale_price_dates_to'   => __( 'Sale ends', 'blueworx_client_forum' ),
			'_tax_status'            => __( 'Tax status', 'blueworx_client_forum' ),
			'_tax_class'             => __( 'Tax class', 'blueworx_client_forum' ),
			'_manage_stock'          => __( 'Manage stock', 'blueworx_client_forum' ),
			'_stock'                 => __( 'Stock quantity', 'blueworx_client_forum' ),
			'_stock_status'          => __( 'Stock status', 'blueworx_client_forum' ),
			'_backorders'            => __( 'Backorders', 'blueworx_client_forum' ),
			'_low_stock_amount'      => __( 'Low stock amount', 'blueworx_client_forum' ),
			'_sold_individually'     => __( 'Sold individually', 'blueworx_client_forum' ),
			'_weight'                => __( 'Weight', 'blueworx_client_forum' ),
			'_length'                => __( 'Length', 'blueworx_client_forum' ),
			'_width'                 => __( 'Width', 'blueworx_client_forum' ),
			'_height'                => __( 'Height', 'blueworx_client_forum' ),
			'_virtual'               => __( 'Virtual', 'blueworx_client_forum' ),
			'_downloadable'          => __( 'Downloadable', 'blueworx_client_forum' ),
			'_downloadable_files'    => __( 'Downloadable files', 'blueworx_client_forum' ),
			'_download_limit'        => __( 'Download limit', 'blueworx_client_forum' ),
			'_download_expiry'       => __( 'Download expiry', 'blueworx_client_forum' ),
			'_purchase_note'         => __( 'Purchase note', 'blueworx_client_forum' ),
			'_product_attributes'    => __( 'Attributes', 'blueworx_client_forum' ),
			'_default_attributes'    => __( 'Default attributes', 'blueworx_client_forum' ),
			'_thumbnail_id'          => __( 'Featured image', 'blueworx_client_forum' ),
			'_product_image_gallery' => __( 'Gallery', 'blueworx_client_forum' ),
			'_upsell_ids'            => __( 'Upsells', 'blueworx_client_forum' ),
			'_crosssell_ids'         => __( 'Cross-sells', 'blueworx_client_forum' ),
			'_product_url'           => __( 'Product URL', 'blueworx_client_forum' ),
			'_button_text'           => __( 'Button text', 'blueworx_client_forum' ),
			'_children'              => __( 'Grouped products', 'blueworx_client_forum' ),
			'_variation_description' => __( 'Variation description', 'blueworx_client_forum' ),
		);

		/**
		 * Filter the product fields the change log tracks.
		 *
		 * @param array $fields Meta key => readable label.
		 */
		return (array) apply_filters( 'epi_change_log_meta_fields', $fields );
	}

	/**
	 * Whether a meta key is tracked. Variation attribute values are stored
	 * under `attribute_<name>`, one key per attribute.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_tracked_meta( $meta_key ) {
		$meta_key = (string) $meta_key;

		return array_key_exists( $meta_key, self::meta_fields() ) || 0 === strpos( $meta_key, 'attribute_' );
	}

	/**
	 * Whether a taxonomy is tracked.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function is_tracked_taxonomy( $taxonomy ) {
		return in_array(
			$taxonomy,
			array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' ),
			true
		) || 0 === strpos( (string) $taxonomy, 'pa_' );
	}

	/**
	 * Fields an order changes. `product_visibility` carries the out-of-stock
	 * term, which WooCommerce sets when stock runs out.
	 *
	 * @return string[] Field ids.
	 */
	public static function stock_fields() {
		return array( 'meta:_stock', 'meta:_stock_status', 'taxonomy:product_visibility' );
	}

	/**
	 * The product a product or variation belongs to.
	 *
	 * @param int $object_id Post ID.
	 * @return int Product ID, or 0 if it is neither.
	 */
	public static function product_id( $object_id ) {
		$post_type = get_post_type( $object_id );

		if ( 'product' === $post_type ) {
			return absint( $object_id );
		}

		if ( 'product_variation' === $post_type ) {
			return absint( wp_get_post_parent_id( $object_id ) );
		}

		return 0;
	}

	/**
	 * Read an object's tracked data, fresh from the database.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return array Field id (`post:post_title`, `meta:_sku`, `taxonomy:product_cat`) => value.
	 *               Empty if the post no longer exists.
	 */
	public static function read( $object_id ) {
		global $wpdb;

		$object_id = absint( $object_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached: the log must see what is in the database now.
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_title, post_content, post_excerpt, post_status, post_name, menu_order FROM {$wpdb->posts} WHERE ID = %d",
				$object_id
			),
			ARRAY_A
		);

		if ( ! $post ) {
			return array();
		}

		$data = array();

		foreach ( array_keys( self::post_fields() ) as $column ) {
			$data[ 'post:' . $column ] = (string) $post[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached, as above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
				$object_id
			),
			ARRAY_A
		);

		$meta = array();

		foreach ( $rows as $row ) {
			if ( self::is_tracked_meta( $row['meta_key'] ) ) {
				$meta[ $row['meta_key'] ][] = maybe_unserialize( $row['meta_value'] );
			}
		}

		foreach ( $meta as $key => $values ) {
			$data[ 'meta:' . $key ] = 1 === count( $values ) ? $values[0] : $values;
		}

		foreach ( get_object_taxonomies( (string) get_post_type( $object_id ) ) as $taxonomy ) {
			if ( ! self::is_tracked_taxonomy( $taxonomy ) ) {
				continue;
			}

			$names = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'names' ) );

			if ( is_wp_error( $names ) || empty( $names ) ) {
				continue;
			}

			natcasesort( $names );
			$data[ 'taxonomy:' . $taxonomy ] = array_values( $names );
		}

		ksort( $data );

		return $data;
	}

	/**
	 * Fields whose value differs.
	 *
	 * @param array $before Data before.
	 * @param array $after  Data after.
	 * @return array Field id => array( before, after ).
	 */
	public static function diff( array $before, array $after ) {
		$changes = array();

		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $field_id ) {
			$old = array_key_exists( $field_id, $before ) ? $before[ $field_id ] : null;
			$new = array_key_exists( $field_id, $after ) ? $after[ $field_id ] : null;

			if ( self::encode( $old ) !== self::encode( $new ) ) {
				$changes[ $field_id ] = array( $old, $new );
			}
		}

		ksort( $changes );

		return $changes;
	}

	/**
	 * Encode a value for storage and comparison. Missing, `''` and an empty
	 * array all mean "empty", so a field going from one to another is not a
	 * change.
	 *
	 * @param mixed $value Value.
	 * @return string JSON.
	 */
	public static function encode( $value ) {
		if ( '' === $value || array() === $value ) {
			$value = null;
		}

		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		return false === $json ? (string) wp_json_encode( is_scalar( $value ) ? (string) $value : null ) : $json;
	}

	/**
	 * A readable name for a field.
	 *
	 * @param string $field_type `post`, `meta` or `taxonomy`.
	 * @param string $field_name Column, meta key or taxonomy.
	 * @return string
	 */
	public static function label( $field_type, $field_name ) {
		if ( 'post' === $field_type ) {
			$fields = self::post_fields();
			return isset( $fields[ $field_name ] ) ? $fields[ $field_name ] : $field_name;
		}

		if ( 'taxonomy' === $field_type ) {
			$taxonomy = get_taxonomy( $field_name );
			return $taxonomy ? $taxonomy->labels->singular_name : $field_name;
		}

		$fields = self::meta_fields();

		if ( isset( $fields[ $field_name ] ) ) {
			return $fields[ $field_name ];
		}

		if ( 0 === strpos( $field_name, 'attribute_' ) ) {
			/* translators: %s: attribute name. */
			return sprintf( __( 'Variation attribute: %s', 'blueworx_client_forum' ), substr( $field_name, 10 ) );
		}

		return $field_name;
	}
}
```

- [ ] **Step 5: Write the source rules**

Create `includes/change-log/class-epi-change-source.php`:

```php
<?php
/**
 * Who a change came from.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sorts a request into ePim, staff, or neither.
 */
final class EPI_Change_Source {

	const EPIM    = 'epim';
	const STAFF   = 'staff';
	const IGNORED = 'ignored';

	/**
	 * Classify a request from plain facts about it.
	 *
	 * An API request with a signed-in user and no nonce was authenticated by a
	 * key, not a browser session: WordPress drops cookie sign-ins from REST
	 * requests that carry no nonce. That is ePim.
	 *
	 * @param array $context Keys: cli, cron, api, nonce, admin, user_id, can_edit.
	 * @return string One of the class constants.
	 */
	public static function classify( array $context ) {
		if ( ! empty( $context['cli'] ) || ! empty( $context['cron'] ) || empty( $context['user_id'] ) ) {
			return self::IGNORED;
		}

		if ( ! empty( $context['api'] ) ) {
			if ( empty( $context['nonce'] ) ) {
				return self::EPIM;
			}

			return empty( $context['can_edit'] ) ? self::IGNORED : self::STAFF;
		}

		return ! empty( $context['admin'] ) && ! empty( $context['can_edit'] ) ? self::STAFF : self::IGNORED;
	}

	/**
	 * Classify the current request for one product or variation.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return string
	 */
	public static function current( $object_id ) {
		return self::classify(
			array(
				'cli'      => defined( 'WP_CLI' ) && WP_CLI,
				'cron'     => wp_doing_cron(),
				'api'      => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks a nonce is present, to tell a browser session from an API key; WordPress verifies it.
				'nonce'    => ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) || ! empty( $_REQUEST['_wpnonce'] ),
				'admin'    => is_admin(),
				'user_id'  => get_current_user_id(),
				'can_edit' => current_user_can( 'edit_post', $object_id ),
			)
		);
	}

	/**
	 * The name shown for who made the change.
	 *
	 * @param string $source One of the class constants.
	 * @return string
	 */
	public static function actor( $source ) {
		if ( self::EPIM === $source ) {
			return __( 'ePim External API', 'blueworx_client_forum' );
		}

		$user = wp_get_current_user();

		return $user->exists() ? $user->display_name : __( 'Unknown user', 'blueworx_client_forum' );
	}
}
```

- [ ] **Step 6: Write the storage**

Create `includes/change-log/class-epi-change-store.php`:

```php
<?php
/**
 * Change log storage: stored copies and recorded updates.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's two change-log tables.
 *
 * Every failed write goes to the PHP error log, so a lost entry is never silent.
 */
final class EPI_Change_Store {

	/**
	 * Updates kept per product.
	 */
	const KEEP_UPDATES = 2;

	/**
	 * Recorded updates, one row per changed field.
	 *
	 * @return string
	 */
	public static function changes_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_product_changes';
	}

	/**
	 * One stored copy per product or variation.
	 *
	 * @return string
	 */
	public static function snapshots_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_product_snapshots';
	}

	/**
	 * Create or update both tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$changes         = self::changes_table();
		$snapshots       = self::snapshots_table();

		dbDelta(
			"CREATE TABLE {$changes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(30) NOT NULL DEFAULT 'product',
			changed_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor varchar(191) NOT NULL DEFAULT '',
			source varchar(50) NOT NULL DEFAULT '',
			field_type varchar(30) NOT NULL DEFAULT '',
			field_name varchar(191) NOT NULL DEFAULT '',
			action varchar(30) NOT NULL DEFAULT 'updated',
			old_value longtext NULL,
			new_value longtext NULL,
			request_id varchar(64) NOT NULL DEFAULT '',
			gap tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY object_id (object_id),
			KEY changed_at (changed_at),
			KEY request_id (request_id)
		) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
			object_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			data longtext NOT NULL,
			taken_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (object_id),
			KEY product_id (product_id)
		) {$charset_collate};"
		);
	}

	/**
	 * Empty the recorded updates.
	 *
	 * @return void
	 */
	public static function clear_changes() {
		global $wpdb;

		$table = self::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table; name built from $wpdb->prefix.
		if ( false === $wpdb->query( "DELETE FROM {$table}" ) ) {
			self::report( 'clearing old history', 0 );
		}
	}

	/**
	 * Milliseconds since the epoch. Fine enough that a copy taken in the same
	 * second as logging resumed still sorts before or after it.
	 *
	 * @return int
	 */
	public static function now_ms() {
		return (int) round( microtime( true ) * 1000 );
	}

	/**
	 * An object's stored copy.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return array|null array( 'data' => array, 'taken_at' => int ), or null if none.
	 */
	public static function get_snapshot( $object_id ) {
		global $wpdb;

		$table = self::snapshots_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once per touched product per request.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT data, taken_at FROM {$table} WHERE object_id = %d",
				$object_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$data = json_decode( $row->data, true );

		return array(
			'data'     => is_array( $data ) ? $data : array(),
			'taken_at' => (int) $row->taken_at,
		);
	}

	/**
	 * Replace an object's stored copy.
	 *
	 * Insert-or-update by hand: REPLACE INTO does not survive the test
	 * harness's SQLite translation.
	 *
	 * @param int   $object_id  Product or variation ID.
	 * @param int   $product_id Product it belongs to.
	 * @param array $data       Tracked data.
	 * @return void
	 */
	public static function put_snapshot( $object_id, $product_id, array $data ) {
		global $wpdb;

		$table = self::snapshots_table();
		$json  = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		$values = array(
			'product_id' => absint( $product_id ),
			'data'       => false === $json ? '{}' : $json,
			'taken_at'   => self::now_ms(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT object_id FROM {$table} WHERE object_id = %d",
				$object_id
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$result = $wpdb->update( $table, $values, array( 'object_id' => absint( $object_id ) ), array( '%d', '%s', '%d' ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
			$result = $wpdb->insert( $table, array( 'object_id' => absint( $object_id ) ) + $values, array( '%d', '%d', '%s', '%d' ) );
		}

		if ( false === $result ) {
			self::report( 'saving the stored copy', $object_id );
		}
	}

	/**
	 * Write one update: a row per changed field, sharing a request id.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $rows       Each: object_id, object_type, field_type, field_name, before, after.
	 * @param string $request_id This request's id.
	 * @param string $source     EPI_Change_Source constant.
	 * @param string $actor      Name shown for who made it.
	 * @param int    $user_id    Signed-in user.
	 * @param bool   $gap        Whether the stored copy predates logging being switched back on.
	 * @return void
	 */
	public static function insert_update( $product_id, array $rows, $request_id, $source, $actor, $user_id, $gap ) {
		global $wpdb;

		$table      = self::changes_table();
		$changed_at = current_time( 'mysql', true );

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, via insert() with a format map.
			$result = $wpdb->insert(
				$table,
				array(
					'product_id'  => absint( $product_id ),
					'object_id'   => absint( $row['object_id'] ),
					'object_type' => sanitize_key( $row['object_type'] ),
					'changed_at'  => $changed_at,
					'user_id'     => absint( $user_id ),
					'actor'       => sanitize_text_field( $actor ),
					'source'      => sanitize_key( $source ),
					'field_type'  => sanitize_key( $row['field_type'] ),
					'field_name'  => sanitize_text_field( $row['field_name'] ),
					'action'      => 'updated',
					'old_value'   => EPI_Change_Fields::encode( $row['before'] ),
					'new_value'   => EPI_Change_Fields::encode( $row['after'] ),
					'request_id'  => $request_id,
					'gap'         => $gap ? 1 : 0,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( false === $result ) {
				self::report( 'saving an update', $product_id );
			}
		}
	}

	/**
	 * Delete all but the product's newest updates.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function prune( $product_id ) {
		global $wpdb;

		$table = self::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$request_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT request_id FROM {$table} WHERE product_id = %d GROUP BY request_id ORDER BY MAX(id) DESC",
				$product_id
			)
		);

		foreach ( array_slice( $request_ids, self::KEEP_UPDATES ) as $request_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$result = $wpdb->delete(
				$table,
				array(
					'product_id' => absint( $product_id ),
					'request_id' => $request_id,
				),
				array( '%d', '%s' )
			);

			if ( false === $result ) {
				self::report( 'removing an old update', $product_id );
			}
		}
	}

	/**
	 * Forget a deleted product or variation.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	public static function delete_object( $object_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->delete( self::snapshots_table(), array( 'object_id' => absint( $object_id ) ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->delete( self::changes_table(), array( 'product_id' => absint( $object_id ) ), array( '%d' ) );
	}

	/**
	 * A product's recorded updates, newest first.
	 *
	 * @param int $product_id Product ID.
	 * @return array See EPI_Product_Change_Log::get_updates().
	 */
	public static function get_updates( $product_id ) {
		global $wpdb;

		$table = self::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for an admin-only display.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY id DESC",
				$product_id
			)
		);

		$updates = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $updates[ $row->request_id ] ) ) {
				$updates[ $row->request_id ] = array(
					'request_id' => $row->request_id,
					'changed_at' => $row->changed_at,
					'actor'      => $row->actor,
					'source'     => $row->source,
					'gap'        => (bool) (int) $row->gap,
					'fields'     => array(),
				);
			}

			$updates[ $row->request_id ]['fields'][] = array(
				'object_id'   => (int) $row->object_id,
				'object_type' => $row->object_type,
				'field_type'  => $row->field_type,
				'field_name'  => $row->field_name,
				'label'       => EPI_Change_Fields::label( $row->field_type, $row->field_name ),
				'before'      => json_decode( (string) $row->old_value, true ),
				'after'       => json_decode( (string) $row->new_value, true ),
			);
		}

		foreach ( $updates as $request_id => $update ) {
			$updates[ $request_id ]['fields'] = array_reverse( $update['fields'] );
		}

		return array_values( $updates );
	}

	/**
	 * Leave a trace of a failed write.
	 *
	 * @param string $what What was being written.
	 * @param int    $id   Product or object ID.
	 * @return void
	 */
	private static function report( $what, $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed write must leave a trace; the error log is the one place it can.
		error_log( sprintf( '[Forum change log] Failed %1$s for #%2$d: %3$s', $what, $id, $wpdb->last_error ) );
	}
}
```

- [ ] **Step 7: Replace the change log class**

Invoke the `blueworx-admin-design` skill first ("Using the blueworx-admin-design skill for the product change log box"). The markup below uses its table, grouped-row, badge and empty-state patterns; if the skill shows a different pattern for any of them, follow the skill.

Overwrite `includes/class-epi-product-change-log.php` with:

```php
<?php
/**
 * WooCommerce product change log.
 *
 * Keeps each product's last two updates from ePim or from staff, field by
 * field, with the value before and after.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/change-log/class-epi-change-fields.php';
require_once __DIR__ . '/change-log/class-epi-change-source.php';
require_once __DIR__ . '/change-log/class-epi-change-store.php';

/**
 * Notices product writes, diffs them against a stored copy at the end of the
 * request, and records ePim and staff updates.
 *
 * @since 1.0.6
 */
final class EPI_Product_Change_Log {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '2.0';

	/**
	 * Option holding when logging was last switched back on, in milliseconds.
	 */
	const RESUMED_OPTION = 'epi_change_log_resumed_at';

	/**
	 * Products touched in this request.
	 *
	 * @var array Object ID => array( 'before' => array, 'taken_at' => int ). taken_at 0 means no stored copy.
	 */
	private static $touched = array();

	/**
	 * One identifier shared by all changes in the current request.
	 *
	 * @var string
	 */
	private static $request_id = '';

	/**
	 * Create or update the tables. Versions before 2.0 kept every change from
	 * every source, mostly SEO noise; that history is cleared.
	 *
	 * @return void
	 */
	public static function install() {
		$installed = (string) get_option( 'epi_change_log_db_version', '' );

		EPI_Change_Store::install();

		if ( '' !== $installed && version_compare( $installed, '2.0', '<' ) ) {
			EPI_Change_Store::clear_changes();
		}

		update_option( 'epi_change_log_db_version', self::DB_VERSION, false );
	}

	/**
	 * Upgrade the tables when required.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'epi_change_log_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * Hooks that run whether logging is on or off.
	 *
	 * @return void
	 */
	public static function register_always() {
		add_action( 'deleted_post', array( __CLASS__, 'forget' ), 10, 2 );
		add_action( 'update_option_' . EPI_Feature_Registry::OPTION, array( __CLASS__, 'note_flags_saved' ), 10, 2 );
	}

	/**
	 * Register change tracking and the edit-screen box.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'add_post_metadata', array( __CLASS__, 'before_meta_write' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'before_meta_write' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'before_meta_write' ), 10, 3 );
		add_action( 'pre_post_update', array( __CLASS__, 'before_post_write' ) );
		add_action( 'wp_insert_post', array( __CLASS__, 'after_post_insert' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'after_terms_set' ), 10, 6 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'after_stock_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'after_stock_set' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * A product's recorded updates, newest first.
	 *
	 * @param int $product_id Product ID.
	 * @return array Each: request_id, changed_at, actor, source, gap, fields
	 *               (object_id, object_type, field_type, field_name, label, before, after).
	 */
	public static function get_updates( $product_id ) {
		return EPI_Change_Store::get_updates( absint( $product_id ) );
	}

	/**
	 * Mark a product touched before a tracked field is written.
	 *
	 * @param mixed  $check     Short-circuit value, returned untouched.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public static function before_meta_write( $check, $object_id, $meta_key ) {
		if ( EPI_Change_Fields::is_tracked_meta( $meta_key ) ) {
			self::touch( $object_id );
		}

		return $check;
	}

	/**
	 * Mark a product touched before its post fields are written.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function before_post_write( $post_id ) {
		self::touch( $post_id );
	}

	/**
	 * A brand-new product starts from nothing, whatever was read while it was
	 * being inserted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this was an update.
	 * @return void
	 */
	public static function after_post_insert( $post_id, $post, $update ) {
		if ( ! $update && EPI_Change_Fields::product_id( $post_id ) ) {
			self::$touched[ absint( $post_id ) ] = array(
				'before'   => array(),
				'taken_at' => 0,
			);
		}
	}

	/**
	 * Mark a product touched when its terms change. Terms have no "before"
	 * hook, so with no stored copy the old terms WordPress passes in stand in.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Submitted terms.
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public static function after_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		$object_id = absint( $object_id );

		if ( isset( self::$touched[ $object_id ] ) || ! EPI_Change_Fields::is_tracked_taxonomy( $taxonomy ) ) {
			return;
		}

		self::touch( $object_id );

		if ( ! isset( self::$touched[ $object_id ] ) || self::$touched[ $object_id ]['taken_at'] ) {
			return;
		}

		$old_terms = self::term_names( $old_tt_ids );

		if ( $old_terms ) {
			self::$touched[ $object_id ]['before'][ 'taxonomy:' . $taxonomy ] = $old_terms;
		} else {
			unset( self::$touched[ $object_id ]['before'][ 'taxonomy:' . $taxonomy ] );
		}
	}

	/**
	 * WooCommerce writes stock with plain SQL, past the meta hooks.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return void
	 */
	public static function after_stock_set( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			self::touch( $product->get_id() );
		}
	}

	/**
	 * Remove a deleted product's copy and log.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 * @return void
	 */
	public static function forget( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			EPI_Change_Store::delete_object( $post_id );
		}
	}

	/**
	 * Note when logging is switched back on, so copies taken before then are
	 * known to be possibly out of date.
	 *
	 * @param mixed $old_value Flags before.
	 * @param mixed $value     Flags after.
	 * @return void
	 */
	public static function note_flags_saved( $old_value, $value ) {
		$was_on = ! is_array( $old_value ) || ! array_key_exists( 'change-log', $old_value ) || ! empty( $old_value['change-log'] );
		$is_on  = ! is_array( $value ) || ! array_key_exists( 'change-log', $value ) || ! empty( $value['change-log'] );

		if ( ! $was_on && $is_on ) {
			update_option( self::RESUMED_OPTION, EPI_Change_Store::now_ms(), false );
		}
	}

	/**
	 * Compare every touched product with its stored copy and record what
	 * ePim or staff changed. Runs once, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$touched ) ) {
			return;
		}

		$touched       = self::$touched;
		self::$touched = array();
		$in_order      = self::in_order_request();
		$resumed_at    = (int) get_option( self::RESUMED_OPTION, 0 );
		$updates       = array();

		foreach ( $touched as $object_id => $state ) {
			$product_id = EPI_Change_Fields::product_id( $object_id );
			$after      = EPI_Change_Fields::read( $object_id );

			if ( ! $product_id || empty( $after ) ) {
				EPI_Change_Store::delete_object( $object_id );
				continue;
			}

			EPI_Change_Store::put_snapshot( $object_id, $product_id, $after );

			// "Add New" saves an empty draft first; the real save comes next.
			if ( 'auto-draft' === $after['post:post_status'] ) {
				continue;
			}

			$source = EPI_Change_Source::current( $object_id );

			if ( EPI_Change_Source::IGNORED === $source ) {
				continue;
			}

			$changes = EPI_Change_Fields::diff( $state['before'], $after );

			if ( $in_order ) {
				foreach ( EPI_Change_Fields::stock_fields() as $field_id ) {
					unset( $changes[ $field_id ] );
				}
			}

			if ( empty( $changes ) ) {
				continue;
			}

			if ( ! isset( $updates[ $product_id ] ) ) {
				$updates[ $product_id ] = array(
					'source' => $source,
					'gap'    => false,
					'rows'   => array(),
				);
			}

			if ( $state['taken_at'] && $state['taken_at'] < $resumed_at ) {
				$updates[ $product_id ]['gap'] = true;
			}

			foreach ( $changes as $field_id => $pair ) {
				list( $field_type, $field_name ) = explode( ':', $field_id, 2 );

				$updates[ $product_id ]['rows'][] = array(
					'object_id'   => $object_id,
					'object_type' => get_post_type( $object_id ),
					'field_type'  => $field_type,
					'field_name'  => $field_name,
					'before'      => $pair[0],
					'after'       => $pair[1],
				);
			}
		}

		foreach ( $updates as $product_id => $update ) {
			EPI_Change_Store::insert_update(
				$product_id,
				$update['rows'],
				self::get_request_id(),
				$update['source'],
				EPI_Change_Source::actor( $update['source'] ),
				get_current_user_id(),
				$update['gap']
			);
			EPI_Change_Store::prune( $product_id );
		}
	}

	/**
	 * Add the change log to product edit screens.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'epi-product-change-log',
			esc_html__( 'Product Change Log', 'blueworx_client_forum' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the product's last updates in the editor.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		?>
		<div class="bw-admin">
			<p class="bw-card__note">
				<?php esc_html_e( 'The last two updates to this product from ePim or from staff.', 'blueworx_client_forum' ); ?>
			</p>

			<?php self::render_updates( $post->ID ); ?>

			<div class="bw-tablefoot">
				<span class="bw-toolbar__spacer"></span>
				<a class="bw-btn bw-btn--sm" href="<?php echo esc_url( EPI_Product_Meta::get_full_page_url( $post->ID ) . '#epi-change-log' ); ?>" target="_blank" rel="noopener noreferrer">
					<i class="bw-icon bw-icon--14" data-lucide="external-link" aria-hidden="true"></i>
					<?php esc_html_e( 'View product data', 'blueworx_client_forum' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the change log section on the full product data page.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function render_full_log( $product_id ) {
		?>
		<section class="bw-card bw-card--flush" id="epi-change-log">
			<div class="bw-card__head">
				<div class="bw-card__titles">
					<p class="bw-card__eyebrow"><?php esc_html_e( 'History', 'blueworx_client_forum' ); ?></p>
					<h2 class="bw-card__title"><?php esc_html_e( 'Product change log', 'blueworx_client_forum' ); ?></h2>
				</div>
			</div>
			<?php self::render_updates( $product_id ); ?>
		</section>
		<?php
	}

	/**
	 * Render the updates table: one grouped row per update, then its fields.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private static function render_updates( $product_id ) {
		$updates = self::get_updates( $product_id );

		if ( empty( $updates ) ) {
			?>
			<div class="bw-empty">
				<i class="bw-icon bw-icon--28 bw-empty__icon" data-lucide="archive" aria-hidden="true"></i>
				<h3 class="bw-empty__title"><?php esc_html_e( 'Nothing recorded yet', 'blueworx_client_forum' ); ?></h3>
				<p class="bw-empty__text"><?php esc_html_e( 'Updates from ePim and from staff will appear here.', 'blueworx_client_forum' ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="bw-tablescroll">
			<table class="bw-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'blueworx_client_forum' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Before', 'blueworx_client_forum' ); ?></th>
						<th scope="col"><?php esc_html_e( 'After', 'blueworx_client_forum' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $updates as $update ) : ?>
						<tr class="bw-table__group">
							<td colspan="3">
								<span class="bw-table__group-title">
									<?php echo esc_html( self::format_date( $update['changed_at'] ) . ' · ' . $update['actor'] ); ?>
								</span>
								<?php if ( $update['gap'] ) : ?>
									<span class="bw-badge bw-badge--warning"><?php esc_html_e( 'May include changes made while logging was off', 'blueworx_client_forum' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php foreach ( $update['fields'] as $field ) : ?>
							<tr>
								<td>
									<?php if ( 'product_variation' === $field['object_type'] ) : ?>
										<span class="bw-badge bw-badge--info"><?php echo esc_html( sprintf( /* translators: %d: variation ID. */ __( 'Variation #%d', 'blueworx_client_forum' ), $field['object_id'] ) ); ?></span>
									<?php endif; ?>
									<span class="bw-table__primary"><?php echo esc_html( $field['label'] ); ?></span>
								</td>
								<td><?php self::render_value( $field['before'] ); ?></td>
								<td><?php self::render_value( $field['after'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render one stored value.
	 *
	 * @param mixed $value Decoded value.
	 * @return void
	 */
	private static function render_value( $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			echo '<span class="bw-badge bw-badge--neutral">' . esc_html__( 'Empty', 'blueworx_client_forum' ) . '</span>';
			return;
		}

		if ( is_array( $value ) ) {
			$display = (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} elseif ( is_bool( $value ) ) {
			$display = $value ? 'true' : 'false';
		} else {
			$display = (string) $value;
		}

		if ( strlen( $display ) > 180 || false !== strpos( $display, "\n" ) ) {
			?>
			<details>
				<summary><?php esc_html_e( 'View value', 'blueworx_client_forum' ); ?></summary>
				<pre><?php echo esc_html( $display ); ?></pre>
			</details>
			<?php
			return;
		}

		echo '<pre>' . esc_html( $display ) . '</pre>';
	}

	/**
	 * Mark an object touched, keeping the first "before" seen this request.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	private static function touch( $object_id ) {
		$object_id = absint( $object_id );

		if ( ! $object_id || isset( self::$touched[ $object_id ] ) || ! EPI_Change_Fields::product_id( $object_id ) ) {
			return;
		}

		$copy = EPI_Change_Store::get_snapshot( $object_id );

		self::$touched[ $object_id ] = $copy
			? array(
				'before'   => $copy['data'],
				'taken_at' => $copy['taken_at'],
			)
			: array(
				'before'   => EPI_Change_Fields::read( $object_id ),
				'taken_at' => 0,
			);
	}

	/**
	 * Whether WooCommerce handled an order in this request. Stock changes made
	 * then are the order's, not an update.
	 *
	 * @return bool
	 */
	private static function in_order_request() {
		$hooks = array(
			'woocommerce_reduce_order_stock',
			'woocommerce_restore_order_stock',
			'woocommerce_reduce_order_item_stock',
			'woocommerce_restore_order_item_stock',
			'woocommerce_checkout_order_processed',
			'woocommerce_new_order',
			'woocommerce_update_order',
			'woocommerce_order_status_changed',
		);

		foreach ( $hooks as $hook ) {
			if ( did_action( $hook ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert term taxonomy IDs to sorted names.
	 *
	 * @param array $tt_ids Term taxonomy IDs.
	 * @return array
	 */
	private static function term_names( $tt_ids ) {
		$names = array();

		foreach ( (array) $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', absint( $tt_id ) );

			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}

		natcasesort( $names );

		return array_values( $names );
	}

	/**
	 * Format a UTC database date in the site's timezone.
	 *
	 * @param string $date_gmt UTC date.
	 * @return string
	 */
	private static function format_date( $date_gmt ) {
		return get_date_from_gmt( $date_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/**
	 * Return one request identifier.
	 *
	 * @return string
	 */
	private static function get_request_id() {
		if ( ! self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}

		return self::$request_id;
	}
}
```

- [ ] **Step 8: Wire the always-on hooks in the plugin**

In `external-product-images.php`, inside `if ( class_exists( 'WooCommerce' ) ) {`, change:

```php
			EPI_Product_Change_Log::maybe_upgrade();
```

to:

```php
			EPI_Product_Change_Log::maybe_upgrade();
			EPI_Product_Change_Log::register_always();
```

- [ ] **Step 9: Run the specs to see them pass**

Run: `php -l` on the four PHP files, then
```bash
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test tests/product-change-log.spec.js tests/product-meta-keys.spec.js --workers=1 --retries=0
```
Expected: 8 passed. If the harness times out, go to Step 10 and judge by CI.

- [ ] **Step 10: Commit and push**

```bash
git add includes/change-log includes/class-epi-product-change-log.php external-product-images.php tests/product-change-log.spec.js tests/support/epi-test-change-log.php
git commit -m "Record only ePim and staff product updates, last two per product"
git push -u origin product-change-log
```
Then open the PR (`gh pr create`, base `exclude-surerank-meta` until #11 merges, then `main`) and watch `gh pr checks --watch`. Expected: all checks pass.

---

### Task 2: Check the product screen

**Files:**
- Modify: `tests/product-change-log.spec.js` (append one test)

**Interfaces:**
- Consumes: `createProduct`, `epimSave` and `nonce` from Task 1's spec; meta box id `epi-product-change-log`.

- [ ] **Step 1: Append the screen spec**

```js
test('the product screen shows the last updates and who made them', async ({ page, request }) => {
  const id = await createProduct(page, 'Screen product');
  await epimSave(request, id, { _sku: 'ON-SCREEN' });

  // The edit screen pulls a lot through the harness's single-threaded server;
  // the box's markup is what matters, not the load event.
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });

  const box = page.locator('#epi-product-change-log');
  const groups = box.locator('tr.bw-table__group');

  await expect(groups).toHaveCount(2);
  await expect(groups.first()).toContainText('ePim External API');
  await expect(box).toContainText('SKU');
  await expect(box).toContainText('ON-SCREEN');
  await expect(box).not.toContainText('Fatal error');
});
```

- [ ] **Step 2: Run it**

Run: `npx playwright test tests/product-change-log.spec.js -g "product screen" --workers=1 --retries=0` (same env vars as Task 1).
Expected: PASS. If it fails, the render code in Task 1 Step 7 is wrong: fix it there, using the blueworx-admin-design skill's patterns.

- [ ] **Step 3: Commit**

```bash
git add tests/product-change-log.spec.js
git commit -m "Check the change log box on the product screen"
```

---

### Task 3: Release housekeeping

**Files:**
- Modify: `uninstall.php`
- Modify: `includes/class-epi-feature-registry.php` (change-log description)
- Modify: `external-product-images.php` (header `Version`, `EPI_VERSION`)
- Modify: `package.json`, `readme.txt`, `CHANGELOG.md`

- [ ] **Step 1: Uninstall removes the new table and option**

In `uninstall.php`, add `'epi_change_log_resumed_at',` to `$epi_options`, and after the existing `DROP TABLE` line add:

```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table on uninstall; the name is built from $wpdb->prefix.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'epi_product_snapshots' );
```

Change the comment above the first drop from `// The plugin's own change-log table.` to `// The plugin's own change-log tables.`

- [ ] **Step 2: Say what the switch does**

In `includes/class-epi-feature-registry.php`, the `change-log` description becomes:

```php
				'description'    => __( 'Keeps the last two updates to each product from ePim or from staff, showing each changed field before and after.', 'blueworx_client_forum' ),
```

- [ ] **Step 3: Version 1.13.0**

Change `1.12.3` to `1.13.0` in: `external-product-images.php` header `Version:` and `define( 'EPI_VERSION', ... )`, `package.json` `"version"`, `readme.txt` `Stable tag:`. Leave `package-lock.json` alone.

Add to `CHANGELOG.md` under `## [Unreleased]`:

```markdown
## [1.13.0]

### Changed
- The product change log now keeps each product's last two updates from ePim or
  from staff, showing every changed field before and after. ePim shows as
  "ePim External API". Orders, other plugins and scheduled tasks are no longer
  recorded, and older history is cleared when the update installs.
```

Add to `readme.txt` under `== Changelog ==`:

```
= 1.13.0 =
* The product change log keeps each product's last two updates from ePim or
  from staff, field by field. Older history is cleared on update.
```

- [ ] **Step 4: Lint once**

Run: `vendor/bin/phpcs includes/change-log includes/class-epi-product-change-log.php uninstall.php`
Expected: no errors other than Windows line-ending (`LineEndings.InvalidEOLChar`) warnings, which come from the checkout and not the committed files. Report anything else to Luke; don't fix without approval.

- [ ] **Step 5: Commit, push, check CI**

```bash
git add uninstall.php includes/class-epi-feature-registry.php external-product-images.php package.json readme.txt CHANGELOG.md
git commit -m "Release the product change log as 1.13.0"
git push
gh pr checks --watch
```
Expected: all checks pass.

- [ ] **Step 6: Hand-check on staging (needs real WooCommerce)**

On a staging copy with the build installed, confirm and report to Luke:
1. Updating to 1.13.0 empties the old log, and the product screen says "Nothing recorded yet".
2. Editing a product's price in wp-admin shows one update under your name.
3. Placing a test order for that product does not add an update, and a later price edit does not list stock as changed.
4. An ePim push shows as "ePim External API".
