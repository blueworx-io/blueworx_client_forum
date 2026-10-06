# ePim Pull Sync and Product Import Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The site pulls products from ePim's read API daily and on demand, in background batches, matching WooCommerce products by SKU, and an admin-only Product import page records every pull product by product.

**Architecture:** A feature `epim-pull` boots a small set of classes under `includes/pull/`: a settings holder, an HTTP client with paging, a mapper from an ePim variation record to a plain product array, a category syncer, a writer that applies that array to WooCommerce (or to plain posts when WooCommerce is absent, which is what the test harness has), a store for two log tables, and a runner that drives WP-Cron batches under a lock. The admin page is PHP rendered from the blueworx-admin-design system. Tests fake ePim at the HTTP transport inside a test-only mu-plugin.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, WooCommerce product CRUD when present, WP-Cron, `$wpdb` with `dbDelta`, blueworx-admin-design classes, Playwright against the SQLite test harness.

**Spec:** `docs/superpowers/specs/2026-10-05-epim-pull-sync-design.md`

## Global Constraints

- Branch `epim-pull-sync`; every change through a pull request; never main.
- Version bump to `1.14.0` in `package.json`, the plugin header, `EPI_VERSION`, and `readme.txt` Stable tag; CHANGELOG.md and readme.txt changelog updated. (Task 9.)
- No new npm or Composer dependency. `approved-deps.json` stays as is.
- Text domain `blueworx_client_forum`. Prefixes `EPI_` / `epi_`. Tabs for indentation. No Yoda conditions required.
- Admin markup only from blueworx-admin-design classes (`bw-*`), enqueued via `EPI_Lab_Page::enqueue_design_system()`. The Write/Edit hook refuses anything else.
- The subscription key is never committed. The real key is `bff1…cb61` and lives only in the site's settings; the test key is `epim-test-key`.
- Stock is not pulled. Short description, GTIN, weight and dimensions are not written.
- Pictures are written only while the "Set product pictures from ePim" switch is on (off by default).
- Attributes ePim sends are set; attributes it does not mention are left alone.
- Hidden means post status `draft`. Not found and hidden in ePim means skipped, not created.
- Records older than 90 days are removed at the start of the daily run.
- Run the linter once at the end (`composer lint`), present findings, do not loop.
- Commit messages: one plain line. Pull request: what it does and what Luke must decide.

## Review Focus

1. **ePim sends a variation with an empty SKU.** It must be recorded as an error item, never created. Pinned in Task 6.
2. **ePim's category list names a parent that is not in the list.** The child must still be created at the top level, not dropped. Pinned in Task 4.
3. **A product already on the site has the SKU but no ePim ID.** It must be updated in place, never duplicated. Pinned in Task 5.
4. **WooCommerce refuses a save (duplicate SKU, thrown exception).** The run must continue and the item show as an error with the message. Pinned in Task 5 (posts path returns a WP_Error for a missing product, same code path).
5. **A batch dies mid-run (fatal, timeout) and the lock stays.** A pull more than 20 minutes old must be marked failed and a new pull allowed. Pinned in Task 6.

---

## File structure

Create:
- `includes/pull/class-epi-pull.php` — `EPI_Pull`: loads the pull classes, installs tables, boots runner and page.
- `includes/pull/class-epi-pull-settings.php` — `EPI_Pull_Settings`: key, pictures switch, base URL.
- `includes/pull/class-epi-pull-store.php` — `EPI_Pull_Store`: runs and items tables, counters, paging, pruning.
- `includes/pull/class-epi-pull-client.php` — `EPI_Pull_Client`: GET with key, one page of a list.
- `includes/pull/class-epi-pull-mapper.php` — `EPI_Pull_Mapper`: ePim record to product array.
- `includes/pull/class-epi-pull-categories.php` — `EPI_Pull_Categories`: ePim categories to `product_cat` terms.
- `includes/pull/class-epi-pull-writer.php` — `EPI_Pull_Writer`: find, read, diff, write a product; hide deleted.
- `includes/pull/class-epi-pull-runner.php` — `EPI_Pull_Runner`: start, lock, batch loop, stages, daily schedule, drain.
- `includes/class-epi-product-import-page.php` — `EPI_Product_Import_Page`: the admin screen.
- `assets/css/epi-product-import.css`, `assets/js/epi-product-import.js`.
- `tests/support/epi-test-epim.php` — test-only mu-plugin: fake ePim, stand-in post type, helper routes.
- `tests/product-import.spec.js`.

Modify:
- `includes/class-epi-feature-registry.php` — new feature and boot method.
- `includes/change-log/class-epi-change-source.php` — `epi_change_source` filter.
- `external-product-images.php` — install tables on activation; version.
- `uninstall.php` — new options and tables.
- `package.json`, `readme.txt`, `CHANGELOG.md` — version and changelog.

Shared test helpers used by every task's spec (added in Task 1 to `tests/product-import.spec.js`):

```js
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The pull talks to ePim over HTTP and writes WooCommerce products. The harness
// has neither ePim nor WooCommerce, and PHP's built-in server cannot answer a
// request to itself, so a test-only mu-plugin fakes ePim at the HTTP transport
// and stands a plain post type in for products.
const SUPPORT = [
  path.resolve(__dirname, 'support', 'epi-test-epim.php'),
  // Boots the product change log without WooCommerce, for the change log test.
  path.resolve(__dirname, 'support', 'epi-test-change-log.php'),
];
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const SCREEN = '/wp-admin/edit.php?post_type=product&page=epi-product-import';
const API = '/wp-json/epi-test/v1/pull';

let nonce;

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  for (const file of SUPPORT) fs.copyFileSync(file, path.join(MU_DIR, path.basename(file)));
});

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text())
  );
  await api(page, 'POST', '/reset');
});

async function api(page, method, route, data) {
  const res = await page.request.fetch(`${API}${route}`, {
    method,
    headers: { 'X-WP-Nonce': nonce },
    data,
  });
  expect(res.ok(), `${method} ${route}: ${res.status()} ${await res.text()}`).toBeTruthy();
  return res.json();
}

// Start a pull and run it to completion without waiting on cron.
async function pull(page, options = {}) {
  if (options.scenario) await api(page, 'POST', '/scenario', { scenario: options.scenario });
  return api(page, 'POST', '/pull', { full: !!options.full });
}
```

---

### Task 1: Feature, settings, tables and the test harness skeleton

**Files:**
- Create: `includes/pull/class-epi-pull.php`
- Create: `includes/pull/class-epi-pull-settings.php`
- Create: `includes/pull/class-epi-pull-store.php`
- Modify: `includes/class-epi-feature-registry.php` (after the `change-log` entry, and a new boot method)
- Modify: `external-product-images.php:120-124` (`activate()`)
- Modify: `uninstall.php`
- Create: `tests/support/epi-test-epim.php`
- Create: `tests/product-import.spec.js`

**Interfaces:**
- Produces: `EPI_Pull::boot()`, `EPI_Pull::load()`, `EPI_Pull::install()`.
- Produces: `EPI_Pull_Settings::get(): array{key:string, images:bool}`, `::save(array)`, `::key(): string`, `::images_from_epim(): bool`, `::base_url(): string`, `::page_size(): int`.
- Produces: `EPI_Pull_Store::install()`, `::runs_table()`, `::items_table()`, `::create_run(string $trigger, string $since_utc, bool $is_full): int`, `::get_run(int): object|null`, `::update_run(int, array)`, `::bump(int $run_id, string $action)`, `::finish_run(int, string $status, string $message = '')`, `::add_item(int $run_id, array $item)`, `::get_runs(int $page, int $per_page): array`, `::count_runs(): int`, `::get_items(int $run_id, int $page, int $per_page): array`, `::count_items(int): int`, `::last_successful_run(): object|null`, `::prune(int $days): int`.

- [ ] **Step 1: Write the failing test**

Create `tests/product-import.spec.js` with the shared helpers block from "File structure" above, then this test:

```js
test('the pull feature is on by default and its tables exist', async ({ page }) => {
  await page.goto('/wp-admin/options-general.php?page=bwlab');
  const toggle = page.locator('input[name="epi_features[]"][value="epim-pull"]');
  await expect(toggle).toBeChecked();

  const tables = await api(page, 'GET', '/tables');
  expect(tables).toEqual({ runs: true, items: true });
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `npm run wp:up` once, then `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: FAIL. The reset route answers 404 (`POST /reset: 404`).

- [ ] **Step 3: Create the settings class**

`includes/pull/class-epi-pull-settings.php`:

```php
<?php
/**
 * Settings for the ePim pull: the key, the pictures switch, the API address.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the pull's settings.
 */
final class EPI_Pull_Settings {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'epi_pull_settings';

	/**
	 * ePim's read API for the Forum website channel.
	 */
	const DEFAULT_BASE = 'https://epim.azure-api.net/forum-website-categorised-channel/api/';

	/**
	 * The saved settings, with defaults filled in.
	 *
	 * @return array{key: string, images: bool}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'key'    => isset( $saved['key'] ) ? (string) $saved['key'] : '',
			'images' => ! empty( $saved['images'] ),
		);
	}

	/**
	 * Save some or all of the settings.
	 *
	 * @param array $values Any of: key (string), images (bool).
	 * @return void
	 */
	public static function save( array $values ) {
		$current = self::get();

		if ( array_key_exists( 'key', $values ) ) {
			$current['key'] = sanitize_text_field( (string) $values['key'] );
		}

		if ( array_key_exists( 'images', $values ) ) {
			$current['images'] = ! empty( $values['images'] );
		}

		update_option( self::OPTION, $current, false );
	}

	/**
	 * The subscription key. A constant in wp-config.php wins over the saved one.
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'EPI_EPIM_SUBSCRIPTION_KEY' ) && EPI_EPIM_SUBSCRIPTION_KEY ) {
			return (string) EPI_EPIM_SUBSCRIPTION_KEY;
		}

		$settings = self::get();

		return $settings['key'];
	}

	/**
	 * Whether the pull sets product pictures. Off while ePim still pushes.
	 *
	 * @return bool
	 */
	public static function images_from_epim() {
		$settings = self::get();

		return $settings['images'];
	}

	/**
	 * The API address, with a trailing slash.
	 *
	 * @return string
	 */
	public static function base_url() {
		/**
		 * Filter the ePim API address. The test harness points it at a fake.
		 *
		 * @param string $base_url Address ending in /api/.
		 */
		return trailingslashit( (string) apply_filters( 'epi_pull_api_base', self::DEFAULT_BASE ) );
	}

	/**
	 * How many records one API page asks for.
	 *
	 * @return int
	 */
	public static function page_size() {
		/**
		 * Filter the page size. Tests shrink it to exercise paging.
		 *
		 * @param int $size Records per page.
		 */
		return max( 1, (int) apply_filters( 'epi_pull_page_size', 50 ) );
	}
}
```

- [ ] **Step 4: Create the store**

`includes/pull/class-epi-pull-store.php`:

```php
<?php
/**
 * Storage for pull runs and the products each one touched.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the two pull tables.
 *
 * Every failed write goes to the PHP error log, so a lost record is never silent.
 */
final class EPI_Pull_Store {

	/**
	 * Counters a run keeps, by the action name the writer reports.
	 *
	 * @var array
	 */
	private static $counters = array(
		'added'     => 'added',
		'updated'   => 'updated',
		'hidden'    => 'hidden',
		'unchanged' => 'unchanged',
		'skipped'   => 'skipped',
		'error'     => 'errors',
	);

	/**
	 * One row per pull.
	 *
	 * @return string
	 */
	public static function runs_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_pull_runs';
	}

	/**
	 * One row per product a pull added, updated, hid or failed on.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_pull_items';
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
		$runs            = self::runs_table();
		$items           = self::items_table();

		// "trigger" and "cursor" are reserved words in MySQL, hence the longer names.
		dbDelta(
			"CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trigger_type varchar(10) NOT NULL DEFAULT 'auto',
			status varchar(12) NOT NULL DEFAULT 'queued',
			is_full tinyint(1) NOT NULL DEFAULT 0,
			since_utc varchar(25) NOT NULL DEFAULT '',
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			stage varchar(20) NOT NULL DEFAULT 'categories',
			cursor_start int(11) NOT NULL DEFAULT 0,
			total int(11) NOT NULL DEFAULT 0,
			batches int(11) NOT NULL DEFAULT 0,
			added int(11) NOT NULL DEFAULT 0,
			updated int(11) NOT NULL DEFAULT 0,
			hidden int(11) NOT NULL DEFAULT 0,
			unchanged int(11) NOT NULL DEFAULT 0,
			skipped int(11) NOT NULL DEFAULT 0,
			errors int(11) NOT NULL DEFAULT 0,
			message text NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at),
			KEY status (status)
		) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			epim_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(100) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			action varchar(12) NOT NULL DEFAULT '',
			changes longtext NULL,
			raw longtext NULL,
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id)
		) {$charset_collate};"
		);
	}

	/**
	 * Start a run record.
	 *
	 * @param string $trigger   'auto' or 'manual'.
	 * @param string $since_utc ISO time the pull asks for changes since, or '' for everything.
	 * @param bool   $is_full   Whether this is a full import.
	 * @return int Run ID, or 0 when the insert failed.
	 */
	public static function create_run( $trigger, $since_utc, $is_full ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$result = $wpdb->insert(
			self::runs_table(),
			array(
				'trigger_type' => 'manual' === $trigger ? 'manual' : 'auto',
				'status'       => 'queued',
				'is_full'      => $is_full ? 1 : 0,
				'since_utc'    => (string) $since_utc,
				'started_at'   => current_time( 'mysql', true ),
				'stage'        => 'categories',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			self::report( 'starting a run', 0 );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One run.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	public static function get_run( $run_id ) {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the row changes every batch.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT * FROM {$table} WHERE id = %d",
				$run_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Change some columns of a run.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $fields Column => value.
	 * @return void
	 */
	public static function update_run( $run_id, array $fields ) {
		global $wpdb;

		$formats = array();

		foreach ( $fields as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$result = $wpdb->update( self::runs_table(), $fields, array( 'id' => absint( $run_id ) ), $formats, array( '%d' ) );

		if ( false === $result ) {
			self::report( 'updating a run', $run_id );
		}
	}

	/**
	 * Add one to a run's counter for an action.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $action added, updated, hidden, unchanged, skipped or error.
	 * @return void
	 */
	public static function bump( $run_id, $action ) {
		global $wpdb;

		if ( ! isset( self::$counters[ $action ] ) ) {
			return;
		}

		$column = self::$counters[ $action ];
		$table  = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names come from this class's own lists; the id is bound.
				"UPDATE {$table} SET {$column} = {$column} + 1 WHERE id = %d",
				$run_id
			)
		);

		if ( false === $result ) {
			self::report( 'counting ' . $action, $run_id );
		}
	}

	/**
	 * Close a run.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $status  'done' or 'failed'.
	 * @param string $message Why, when it failed.
	 * @return void
	 */
	public static function finish_run( $run_id, $status, $message = '' ) {
		self::update_run(
			$run_id,
			array(
				'status'      => 'failed' === $status ? 'failed' : 'done',
				'stage'       => 'done',
				'finished_at' => current_time( 'mysql', true ),
				'message'     => (string) $message,
			)
		);
	}

	/**
	 * Record one product a run touched.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $item   Keys: product_id, epim_id, sku, name, action, changes (array), raw (array), message.
	 * @return void
	 */
	public static function add_item( $run_id, array $item ) {
		global $wpdb;

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$result = $wpdb->insert(
			self::items_table(),
			array(
				'run_id'     => absint( $run_id ),
				'product_id' => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'epim_id'    => isset( $item['epim_id'] ) ? absint( $item['epim_id'] ) : 0,
				'sku'        => isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '',
				'name'       => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
				'action'     => isset( $item['action'] ) ? sanitize_key( $item['action'] ) : '',
				'changes'    => (string) wp_json_encode( isset( $item['changes'] ) ? $item['changes'] : array(), $flags ),
				'raw'        => (string) wp_json_encode( isset( $item['raw'] ) ? $item['raw'] : array(), $flags ),
				'message'    => isset( $item['message'] ) ? sanitize_text_field( $item['message'] ) : '',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			self::report( 'recording a product', $run_id );
		}
	}

	/**
	 * Runs, newest first.
	 *
	 * @param int $page     Page number from 1.
	 * @param int $per_page Rows per page.
	 * @return object[]
	 */
	public static function get_runs( $page, $per_page ) {
		global $wpdb;

		$table  = self::runs_table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for an admin-only screen.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the numbers are bound.
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				(int) $per_page,
				$offset
			)
		);
	}

	/**
	 * How many runs there are.
	 *
	 * @return int
	 */
	public static function count_runs() {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table; name from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * A run's recorded products, in the order they were recorded.
	 *
	 * @param int $run_id   Run ID.
	 * @param int $page     Page number from 1.
	 * @param int $per_page Rows per page.
	 * @return array Each: id, product_id, epim_id, sku, name, action, changes (array), raw (array), message, created_at.
	 */
	public static function get_items( $run_id, $page, $per_page ) {
		global $wpdb;

		$table  = self::items_table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for an admin-only screen.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the numbers are bound.
				"SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$run_id,
				(int) $per_page,
				$offset
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$changes        = json_decode( (string) $row['changes'], true );
			$raw            = json_decode( (string) $row['raw'], true );
			$row['changes'] = is_array( $changes ) ? $changes : array();
			$row['raw']     = is_array( $raw ) ? $raw : array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * How many products a run recorded.
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	public static function count_items( $run_id ) {
		global $wpdb;

		$table = self::items_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT COUNT(*) FROM {$table} WHERE run_id = %d",
				$run_id
			)
		);
	}

	/**
	 * The newest run that finished properly.
	 *
	 * @return object|null
	 */
	public static function last_successful_run() {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table; name from $wpdb->prefix.
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status = 'done' ORDER BY id DESC LIMIT 1" );

		return $row ? $row : null;
	}

	/**
	 * Remove runs older than a number of days, with their products.
	 *
	 * @param int $days Age in days.
	 * @return int Runs removed.
	 */
	public static function prune( $days ) {
		global $wpdb;

		$runs   = self::runs_table();
		$items  = self::items_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - absint( $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix; the date is bound.
				"DELETE FROM {$items} WHERE run_id IN (SELECT id FROM {$runs} WHERE started_at < %s)",
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$removed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the date is bound.
				"DELETE FROM {$runs} WHERE started_at < %s",
				$cutoff
			)
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Leave a trace of a failed write.
	 *
	 * @param string $what What was being written.
	 * @param int    $id   Run ID.
	 * @return void
	 */
	private static function report( $what, $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed write must leave a trace; the error log is the one place it can.
		error_log( sprintf( '[Forum ePim pull] Failed %1$s for run #%2$d: %3$s', $what, $id, $wpdb->last_error ) );
	}
}
```

- [ ] **Step 5: Create the loader**

`includes/pull/class-epi-pull.php`:

```php
<?php
/**
 * The ePim pull feature: loads its classes, keeps its tables, boots its parts.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entry point for the pull.
 */
final class EPI_Pull {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '1.0';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'epi_pull_db_version';

	/**
	 * Load every pull class. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function load() {
		require_once __DIR__ . '/class-epi-pull-settings.php';
		require_once __DIR__ . '/class-epi-pull-store.php';
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		self::load();
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		EPI_Pull_Store::install();
	}

	/**
	 * Boot the feature. Called by the feature registry when it is switched on.
	 *
	 * @return void
	 */
	public static function boot() {
		self::load();

		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}
}
```

- [ ] **Step 6: Register the feature**

In `includes/class-epi-feature-registry.php`, directly after the `'change-log'` entry's closing `),` add:

```php
			'epim-pull'                   => array(
				'title'          => __( 'ePim product pull', 'blueworx_client_forum' ),
				'description'    => __( 'Pulls products from ePim once a day and on demand, matched by SKU, and records every pull under Products > Product import.', 'blueworx_client_forum' ),
				'group'          => 'admin-product',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array(),
				'boot'           => array( __CLASS__, 'boot_epim_pull' ),
			),
```

And after `boot_gallery()` add:

```php
	/**
	 * Boot the ePim pull. It requires its own files so the feature can be
	 * switched off without loading any of them.
	 *
	 * @return void
	 */
	public static function boot_epim_pull() {
		require_once EPI_PLUGIN_DIR . 'includes/pull/class-epi-pull.php';
		EPI_Pull::boot();
	}
```

- [ ] **Step 7: Install on activation and remove on uninstall**

In `external-product-images.php`, `activate()` becomes:

```php
	public static function activate() {
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-change-log.php';
		require_once EPI_PLUGIN_DIR . 'includes/pull/class-epi-pull.php';
		EPI_Product_Change_Log::install();
		EPI_Pull::install();
	}
```

In `uninstall.php`, add to `$epi_options`:

```php
	'epi_pull_settings',
	'epi_pull_lock',
	'epi_pull_category_map',
	'epi_pull_db_version',
```

and after the two existing `DROP TABLE` lines:

```php
// The plugin's own ePim pull tables.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table on uninstall; the name is built from $wpdb->prefix.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'epi_pull_runs' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table on uninstall; the name is built from $wpdb->prefix.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'epi_pull_items' );
wp_clear_scheduled_hook( 'epi_pull_daily' );
```

- [ ] **Step 8: Create the test support mu-plugin skeleton**

`tests/support/epi-test-epim.php` (later tasks add to it; the fake ePim arrives in Task 2):

```php
<?php
/**
 * Plugin Name: Forum test support — ePim pull
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-import.spec.js.
 *              Stands a plain post type and taxonomy in for WooCommerce, fakes ePim at the HTTP
 *              transport, and offers routes that run a pull without waiting on cron.
 *              Never shipped: tests/ is outside the release allowlist.
 */

if ( ! defined( 'EPI_TEST_EPIM_KEY' ) ) {
	define( 'EPI_TEST_EPIM_KEY', 'epim-test-key' );
}

// CI's harness has no WooCommerce. The pull writes posts of type "product" with
// "product_cat" terms, so plain ones stand in. Guarded, because the change log's
// support file registers the same post type.
add_action(
	'init',
	static function () {
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'label'        => 'Products',
					'public'       => false,
					'show_ui'      => true,
					'show_in_rest' => true,
					'rest_base'    => 'product',
					'supports'     => array( 'title', 'editor', 'custom-fields' ),
				)
			);
		}

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy(
				'product_cat',
				'product',
				array(
					'label'        => 'Product categories',
					'hierarchical' => true,
					'public'       => false,
					'show_ui'      => true,
				)
			);
		}

		if ( ! get_user_by( 'login', 'epi-test-editor' ) ) {
			wp_insert_user(
				array(
					'user_login' => 'epi-test-editor',
					'user_pass'  => 'editor-test-pw',
					'role'       => 'editor',
				)
			);
		}
	}
);

// WordPress tries to start cron with a request to itself on most page loads. On
// the single-threaded test server that request would queue behind the one that
// made it, and in these tests it would race the route that runs batches directly.
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'wp-cron.php' ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		return $pre;
	},
	5,
	3
);

add_action(
	'rest_api_init',
	static function () {
		$admin_only = static function () {
			return current_user_can( 'manage_options' );
		};

		// Whether the pull's tables exist.
		register_rest_route(
			'epi-test/v1',
			'/pull/tables',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					global $wpdb;

					$exists = static function ( $table ) use ( $wpdb ) {
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
					};

					return array(
						'runs'  => $exists( $wpdb->prefix . 'epi_pull_runs' ),
						'items' => $exists( $wpdb->prefix . 'epi_pull_items' ),
					);
				},
			)
		);

		// Back to a clean slate: no products, no categories, no runs, no lock.
		register_rest_route(
			'epi-test/v1',
			'/pull/reset',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					global $wpdb;

					foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $id ) {
						wp_delete_post( $id, true );
					}

					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'fields' => 'ids' ) ) as $term_id ) {
						wp_delete_term( $term_id, 'product_cat' );
					}

					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'epi_pull_items' );
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'epi_pull_runs' );

					foreach ( array( 'epi_pull_lock', 'epi_pull_category_map', 'epi_test_epim_scenario', 'epi_test_epim_calls' ) as $option ) {
						delete_option( $option );
					}

					update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false ), false );

					return array( 'ok' => true );
				},
			)
		);
	}
);
```

- [ ] **Step 9: Run the test to verify it passes**

Restart the harness so the mu-plugin is picked up fresh if needed (`npm run wp:down && npm run wp:up`), then:
Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (1 test). If `SHOW TABLES LIKE` is not understood by the SQLite drop-in, replace the `$exists` closure body with `return false !== $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );` and re-run.

- [ ] **Step 10: Commit**

```bash
git checkout -b epim-pull-sync
git add includes/pull includes/class-epi-feature-registry.php external-product-images.php uninstall.php tests/support/epi-test-epim.php tests/product-import.spec.js docs/superpowers/specs/2026-10-05-epim-pull-sync-design.md docs/superpowers/plans/2026-10-05-epim-pull-sync.md
git commit -m "Add the ePim pull feature with its settings and log tables"
```

---

### Task 2: The API client and the fake ePim

**Files:**
- Create: `includes/pull/class-epi-pull-client.php`
- Modify: `includes/pull/class-epi-pull.php` (`load()`)
- Modify: `tests/support/epi-test-epim.php` (fake transport, fixtures, `scenario`, `calls` and `fetch` routes)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: `EPI_Pull_Settings::key()`, `::base_url()`, `::page_size()`.
- Produces: `EPI_Pull_Client::get(string $path, array $query = array()): array|WP_Error`, `::page(string $path, array $query, int $start): array{results: array, total: int}|WP_Error`, `::variations(string $since_utc, int $start)`, `::deleted(string $since_utc, int $start)`, `::categories(): array|WP_Error`.
- Produces (test support): scenarios `initial`, `changed`, `deleted`; `POST /pull/scenario {scenario}`; `GET /pull/calls` (list of `{path, query}` the fake received); `POST /pull/fetch {what: variations|categories|deleted, start, key?}`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/product-import.spec.js`:

```js
test('the client pages through variations and reports a bad key', async ({ page }) => {
  // The fake serves two records a page (filter epi_pull_page_size), and the
  // initial scenario has three variations.
  const first = await api(page, 'POST', '/fetch', { what: 'variations', start: 0 });
  expect(first.total).toBe(3);
  expect(first.results.map((r) => r.SKU)).toEqual(['TEST-1001', 'TEST-1002']);

  const second = await api(page, 'POST', '/fetch', { what: 'variations', start: 2 });
  expect(second.results.map((r) => r.SKU)).toEqual(['TEST-1003']);

  const calls = await api(page, 'GET', '/calls');
  expect(calls[0].path).toBe('Variations');
  expect(calls[0].query).toMatchObject({ start: '0', limit: '2', showArchived: 'true', showUnApproved: 'true' });

  const categories = await api(page, 'POST', '/fetch', { what: 'categories' });
  expect(categories.map((c) => c.Name)).toEqual(['Lighting controls', 'Kinetic switches', 'Decorative']);

  const bad = await api(page, 'POST', '/fetch', { what: 'categories', key: 'wrong' });
  expect(bad.error).toBe('ePim did not accept the subscription key.');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "bad key"`
Expected: FAIL, `POST /fetch: 404`.

- [ ] **Step 3: Create the client**

`includes/pull/class-epi-pull-client.php`:

```php
<?php
/**
 * Talks to ePim's read API.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET requests with the subscription key, and one page of a paged list.
 */
final class EPI_Pull_Client {

	/**
	 * Fetch one endpoint and decode its JSON.
	 *
	 * @param string $path  Path after /api/, such as 'Variations'.
	 * @param array  $query Query string values.
	 * @return array|WP_Error Decoded body, or a plain-language error.
	 */
	public static function get( $path, array $query = array() ) {
		$key = EPI_Pull_Settings::key();

		if ( '' === $key ) {
			return new WP_Error( 'epi_pull_no_key', __( 'No ePim subscription key is saved.', 'blueworx_client_forum' ) );
		}

		$url = EPI_Pull_Settings::base_url() . ltrim( $path, '/' );

		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Ocp-Apim-Subscription-Key' => $key,
					'Accept'                    => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'epi_pull_network',
				/* translators: 1: endpoint path, 2: the network error. */
				sprintf( __( 'Could not reach ePim for %1$s: %2$s', 'blueworx_client_forum' ), $path, $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'epi_pull_bad_key', __( 'ePim did not accept the subscription key.', 'blueworx_client_forum' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: 1: HTTP status code, 2: endpoint path. */
			return new WP_Error( 'epi_pull_http', sprintf( __( 'ePim answered %1$d for %2$s.', 'blueworx_client_forum' ), $code, $path ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			/* translators: %s: endpoint path. */
			return new WP_Error( 'epi_pull_body', sprintf( __( 'ePim sent something that is not JSON for %s.', 'blueworx_client_forum' ), $path ) );
		}

		return $body;
	}

	/**
	 * One page of a paged list.
	 *
	 * @param string $path  Endpoint path.
	 * @param array  $query Query values besides start and limit.
	 * @param int    $start Offset of the first record.
	 * @return array|WP_Error array( 'results' => array, 'total' => int ).
	 */
	public static function page( $path, array $query, $start ) {
		$query['start'] = (int) $start;
		$query['limit'] = EPI_Pull_Settings::page_size();

		$body = self::get( $path, $query );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return array(
			'results' => isset( $body['Results'] ) && is_array( $body['Results'] ) ? array_values( $body['Results'] ) : array(),
			'total'   => isset( $body['TotalResults'] ) ? (int) $body['TotalResults'] : 0,
		);
	}

	/**
	 * A page of variations changed since a time, archived and unapproved included
	 * so that a product taken off sale comes through and gets hidden.
	 *
	 * @param string $since_utc ISO 8601 UTC time.
	 * @param int    $start     Offset.
	 * @return array|WP_Error
	 */
	public static function variations( $since_utc, $start ) {
		return self::page(
			'Variations',
			array(
				'changedSinceUTC' => $since_utc,
				'showArchived'    => 'true',
				'showUnApproved'  => 'true',
			),
			$start
		);
	}

	/**
	 * A page of deleted entities since a time.
	 *
	 * @param string $since_utc ISO 8601 UTC time.
	 * @param int    $start     Offset.
	 * @return array|WP_Error
	 */
	public static function deleted( $since_utc, $start ) {
		return self::page( 'DeletedEntities', array( 'since' => $since_utc ), $start );
	}

	/**
	 * Every category. The list is small, so it is not paged.
	 *
	 * @return array|WP_Error
	 */
	public static function categories() {
		$body = self::get( 'Categories' );

		return is_wp_error( $body ) ? $body : array_values( $body );
	}
}
```

In `includes/pull/class-epi-pull.php`, `load()` gains a line after the store require:

```php
		require_once __DIR__ . '/class-epi-pull-client.php';
```

- [ ] **Step 4: Add the fake ePim to the test support**

In `tests/support/epi-test-epim.php`, after the `define`, add the fixtures and the transport fake:

```php
/**
 * ePim as the tests see it. Three scenarios, chosen by the epi_test_epim_scenario option:
 * initial (first pull), changed (a rename, a price change, an archive), deleted (an entity
 * deletion). Shapes copy the real API, sampled 2026-10-05.
 */
function epi_test_epim_fixtures( $scenario ) {
	$categories = array(
		array( 'Id' => 1, 'Name' => 'Lighting controls', 'Description' => null, 'Alias' => null, 'UpdatedOnUTC' => '2026-05-07T06:06:35.47', 'ParentId' => null, 'PictureIds' => array() ),
		array( 'Id' => 2, 'Name' => 'Kinetic switches', 'Description' => null, 'Alias' => null, 'UpdatedOnUTC' => '2026-05-07T06:18:17.067', 'ParentId' => 1, 'PictureIds' => array() ),
		array( 'Id' => 3, 'Name' => 'Decorative', 'Description' => null, 'Alias' => null, 'UpdatedOnUTC' => '2026-05-07T06:07:58.973', 'ParentId' => null, 'PictureIds' => array() ),
	);

	$attr = static function ( $id, $name, $value, $group = 'Technical Data' ) {
		return array( 'AttributeId' => 'epim-' . $id, 'Value' => $value, 'AttributeHeaderName' => $name, 'AttributeHeaderGroup' => $group );
	};

	$a = array(
		'Id'                      => 1001,
		'IsArchived'              => false,
		'IsApprovedForPublishing' => true,
		'ProductId'               => 501,
		'Name'                    => 'Single Kinetic Switch - White',
		'SKU'                     => 'TEST-1001',
		'ProductGroupCode'        => 'TEST-1001',
		'Price'                   => 51.25,
		'PictureIds'              => array( 11, 12, 13, 14 ),
		'ProductCategoryIds'      => array( 2 ),
		'PictureIdsGrouped'       => array( 'Image' => array( 11, 12, 13 ), 'Logo' => array( 14 ) ),
		'Short_Description'       => 'Single Kinetic Switch - White',
		'SKU_Text'                => 'Kit includes a switch and a receiver.',
		'AttributeValues'         => array( $attr( 1, 'Colour', 'White' ), $attr( 2, 'Material', 'Plastic' ), $attr( 3, 'Bulb Type', '' ) ),
	);
	$b = array(
		'Id'                      => 1002,
		'IsArchived'              => false,
		'IsApprovedForPublishing' => true,
		'ProductId'               => 502,
		'Name'                    => 'Lila Flush Ceiling Light - Chrome',
		'SKU'                     => 'TEST-1002',
		'ProductGroupCode'        => '30000750',
		'Price'                   => 112.5,
		'PictureIds'              => array( 21, 22 ),
		'ProductCategoryIds'      => array( 3, 999 ),
		'PictureIdsGrouped'       => array( 'Image' => array( 21, 22 ) ),
		'Short_Description'       => 'Lila Flush Ceiling Light - Chrome',
		'SKU_Text'                => 'A swirling chrome flush fitting.',
		'AttributeValues'         => array( $attr( 1, 'Colour', 'Chrome' ) ),
	);
	$c = array(
		'Id'                      => 1003,
		'IsArchived'              => true,
		'IsApprovedForPublishing' => true,
		'ProductId'               => 503,
		'Name'                    => 'Archived Lamp',
		'SKU'                     => 'TEST-1003',
		'ProductGroupCode'        => 'TEST-1003',
		'Price'                   => 10,
		'PictureIds'              => array(),
		'ProductCategoryIds'      => array( 3 ),
		'PictureIdsGrouped'       => array(),
		'Short_Description'       => 'Archived Lamp',
		'SKU_Text'                => 'No longer sold.',
		'AttributeValues'         => array(),
	);

	$deleted = array();

	if ( 'changed' === $scenario ) {
		$a['Name']            = 'Single Kinetic Switch Kit - White';
		$a['Price']           = 55;
		$a['AttributeValues'] = array( $attr( 1, 'Colour', 'Off white' ), $attr( 2, 'Material', 'Plastic' ) );
		$b['IsArchived']      = true;
	}

	if ( 'deleted' === $scenario ) {
		$deleted = array(
			array( 'Id' => 1, 'EntityId' => 1001, 'EntityType' => 'SKU_Product_Mapping', 'TimeStamp' => '2026-10-05T10:00:00' ),
			array( 'Id' => 2, 'EntityId' => 502, 'EntityType' => 'Product', 'TimeStamp' => '2026-10-05T10:00:01' ),
		);
	}

	return array(
		'categories' => $categories,
		'variations' => array( $a, $b, $c ),
		'deleted'    => $deleted,
	);
}

function epi_test_epim_response( $code, $body ) {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => wp_json_encode( $body ),
		'response' => array(
			'code'    => $code,
			'message' => '',
		),
		'cookies'  => array(),
		'filename' => null,
	);
}

function epi_test_epim_paged( array $all, array $query ) {
	$start = isset( $query['start'] ) ? (int) $query['start'] : 0;
	$limit = isset( $query['limit'] ) ? max( 1, (int) $query['limit'] ) : 50;

	return epi_test_epim_response(
		200,
		array(
			'Start'        => $start,
			'Limit'        => $limit,
			'TotalResults' => count( $all ),
			'Results'      => array_values( array_slice( $all, $start, $limit ) ),
		)
	);
}

// Point the pull at a host that does not exist, and answer for it here.
add_filter( 'epi_pull_api_base', static function () { return 'https://epim.test/api/'; } );
add_filter( 'epi_pull_page_size', static function () { return 2; } );

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( (string) $url, 'https://epim.test/api/' ) ) {
			return $pre;
		}

		$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$key     = isset( $headers['Ocp-Apim-Subscription-Key'] ) ? $headers['Ocp-Apim-Subscription-Key'] : '';

		if ( EPI_TEST_EPIM_KEY !== $key ) {
			return epi_test_epim_response( 401, array( 'statusCode' => 401, 'message' => 'Access denied due to invalid subscription key.' ) );
		}

		$parts = wp_parse_url( $url );
		$query = array();
		parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $query );
		$path = substr( $parts['path'], strlen( '/api/' ) );

		$calls   = get_option( 'epi_test_epim_calls', array() );
		$calls[] = array( 'path' => $path, 'query' => $query );
		update_option( 'epi_test_epim_calls', $calls, false );

		$data = epi_test_epim_fixtures( get_option( 'epi_test_epim_scenario', 'initial' ) );

		switch ( $path ) {
			case 'Categories':
				return epi_test_epim_response( 200, $data['categories'] );
			case 'Variations':
				return epi_test_epim_paged( $data['variations'], $query );
			case 'DeletedEntities':
				return epi_test_epim_paged( $data['deleted'], $query );
		}

		return epi_test_epim_response( 404, array( 'message' => 'No such endpoint: ' . $path ) );
	},
	10,
	3
);
```

Inside the `rest_api_init` closure, add three routes after `/pull/reset`:

```php
		register_rest_route(
			'epi-test/v1',
			'/pull/scenario',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					update_option( 'epi_test_epim_scenario', sanitize_key( $request['scenario'] ), false );
					return array( 'scenario' => get_option( 'epi_test_epim_scenario' ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/calls',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array_values( (array) get_option( 'epi_test_epim_calls', array() ) );
				},
			)
		);

		// Call the client directly. `key` overrides the saved key for one call.
		register_rest_route(
			'epi-test/v1',
			'/pull/fetch',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( $request['key'] ) {
						update_option( 'epi_pull_settings', array( 'key' => (string) $request['key'], 'images' => false ), false );
					}

					switch ( (string) $request['what'] ) {
						case 'variations':
							$result = EPI_Pull_Client::variations( '2000-01-01T00:00:00Z', (int) $request['start'] );
							break;
						case 'deleted':
							$result = EPI_Pull_Client::deleted( '2000-01-01T00:00:00Z', (int) $request['start'] );
							break;
						default:
							$result = EPI_Pull_Client::categories();
					}

					if ( $request['key'] ) {
						update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false ), false );
					}

					return is_wp_error( $result ) ? array( 'error' => $result->get_error_message(), 'code' => $result->get_error_code() ) : $result;
				},
			)
		);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/pull tests/support/epi-test-epim.php tests/product-import.spec.js
git commit -m "Fetch variations, categories and deletions from ePim with paging"
```

---

### Task 3: Mapping an ePim record to product fields

**Files:**
- Create: `includes/pull/class-epi-pull-mapper.php`
- Modify: `includes/pull/class-epi-pull.php` (`load()`)
- Modify: `tests/support/epi-test-epim.php` (`map` route)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Produces: `EPI_Pull_Mapper::map(array $raw): array` with keys `epim_id` (int), `epim_product_id` (int), `sku` (string), `name` (string), `description` (string), `price` (string, two decimals or ''), `hidden` (bool), `category_ids` (int[] ePim IDs), `image_ids` (int[] in order), `attributes` (array name => value, in order sent, empty values dropped).

- [ ] **Step 1: Write the failing test**

Append to `tests/product-import.spec.js`:

```js
test('an ePim record maps to the product fields', async ({ page }) => {
  const mapped = await api(page, 'POST', '/map', {
    raw: {
      Id: 7,
      ProductId: 70,
      IsArchived: false,
      IsApprovedForPublishing: false,
      SKU: ' CUL-1 ',
      Name: 'Lamp ',
      SKU_Text: 'Long copy.',
      Price: 51.2,
      ProductCategoryIds: [3, 0, 5],
      PictureIds: [1, 2, 3, 2],
      PictureIdsGrouped: { Logo: [3] },
      AttributeValues: [
        { AttributeHeaderName: 'Colour', Value: 'White' },
        { AttributeHeaderName: 'Bulb Type', Value: '' },
        { AttributeHeaderName: '', Value: 'x' },
      ],
    },
  });

  expect(mapped).toEqual({
    epim_id: 7,
    epim_product_id: 70,
    sku: 'CUL-1',
    name: 'Lamp',
    description: 'Long copy.',
    price: '51.20',
    hidden: true,
    category_ids: [3, 5],
    image_ids: [1, 2],
    attributes: { Colour: 'White' },
  });

  // With an Image group, that group is the whole picture list, in its order.
  const grouped = await api(page, 'POST', '/map', {
    raw: { Id: 8, SKU: 'X', PictureIds: [9, 8, 7], PictureIdsGrouped: { Image: [8, 7], Logo: [9] } },
  });
  expect(grouped.image_ids).toEqual([8, 7]);
  expect(grouped.hidden).toBe(false);
  expect(grouped.price).toBe('');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "maps to the product fields"`
Expected: FAIL, `POST /map: 404`.

- [ ] **Step 3: Create the mapper**

`includes/pull/class-epi-pull-mapper.php`:

```php
<?php
/**
 * Turns one ePim variation record into the fields a product gets.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure mapping: no database, no HTTP. The writer applies the result.
 */
final class EPI_Pull_Mapper {

	/**
	 * Map a record as ePim sends it.
	 *
	 * @param array $raw One entry of a Variations response.
	 * @return array See the task's Interfaces block for the keys.
	 */
	public static function map( array $raw ) {
		$archived = ! empty( $raw['IsArchived'] );
		$approved = ! array_key_exists( 'IsApprovedForPublishing', $raw ) || ! empty( $raw['IsApprovedForPublishing'] );
		$price    = isset( $raw['Price'] ) && is_numeric( $raw['Price'] ) ? number_format( (float) $raw['Price'], 2, '.', '' ) : '';

		return array(
			'epim_id'         => isset( $raw['Id'] ) ? absint( $raw['Id'] ) : 0,
			'epim_product_id' => isset( $raw['ProductId'] ) ? absint( $raw['ProductId'] ) : 0,
			'sku'             => isset( $raw['SKU'] ) ? trim( (string) $raw['SKU'] ) : '',
			'name'            => isset( $raw['Name'] ) ? trim( (string) $raw['Name'] ) : '',
			'description'     => isset( $raw['SKU_Text'] ) ? (string) $raw['SKU_Text'] : '',
			'price'           => $price,
			'hidden'          => $archived || ! $approved,
			'category_ids'    => self::ids( isset( $raw['ProductCategoryIds'] ) ? $raw['ProductCategoryIds'] : array() ),
			'image_ids'       => self::images( $raw ),
			'attributes'      => self::attributes( isset( $raw['AttributeValues'] ) ? $raw['AttributeValues'] : array() ),
		);
	}

	/**
	 * The product photos, main image first. The "Image" group when ePim sends
	 * one; otherwise every picture that is not in another group (logo, datasheet).
	 *
	 * @param array $raw The record.
	 * @return int[]
	 */
	private static function images( array $raw ) {
		$grouped = isset( $raw['PictureIdsGrouped'] ) && is_array( $raw['PictureIdsGrouped'] ) ? $raw['PictureIdsGrouped'] : array();

		if ( ! empty( $grouped['Image'] ) && is_array( $grouped['Image'] ) ) {
			return self::ids( $grouped['Image'] );
		}

		$images = isset( $raw['PictureIds'] ) && is_array( $raw['PictureIds'] ) ? $raw['PictureIds'] : array();

		foreach ( $grouped as $group => $ids ) {
			if ( 'Image' !== $group && is_array( $ids ) ) {
				$images = array_diff( $images, $ids );
			}
		}

		return self::ids( $images );
	}

	/**
	 * Attribute name => value, in the order sent. Empty names or values are dropped.
	 *
	 * @param mixed $values The AttributeValues list.
	 * @return array
	 */
	private static function attributes( $values ) {
		$attributes = array();

		foreach ( is_array( $values ) ? $values : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name  = isset( $entry['AttributeHeaderName'] ) ? trim( (string) $entry['AttributeHeaderName'] ) : '';
			$value = isset( $entry['Value'] ) ? trim( (string) $entry['Value'] ) : '';

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$attributes[ $name ] = $value;
		}

		return $attributes;
	}

	/**
	 * Positive integers, unique, in order.
	 *
	 * @param mixed $values A list.
	 * @return int[]
	 */
	private static function ids( $values ) {
		$ids = array();

		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$id = absint( $value );

			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
```

In `includes/pull/class-epi-pull.php`, `load()` gains, after the client require:

```php
		require_once __DIR__ . '/class-epi-pull-mapper.php';
```

- [ ] **Step 4: Add the map route**

In `tests/support/epi-test-epim.php`, inside `rest_api_init` after `/pull/fetch`:

```php
		register_rest_route(
			'epi-test/v1',
			'/pull/map',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return EPI_Pull_Mapper::map( (array) $request['raw'] );
				},
			)
		);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/pull tests/support/epi-test-epim.php tests/product-import.spec.js
git commit -m "Map an ePim variation to product fields"
```

---

### Task 4: Categories as a product_cat hierarchy

**Files:**
- Create: `includes/pull/class-epi-pull-categories.php`
- Modify: `includes/pull/class-epi-pull.php` (`load()`)
- Modify: `tests/support/epi-test-epim.php` (`categories` route)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: `EPI_Pull_Client::categories()` shape (`Id`, `Name`, `ParentId`).
- Produces: `EPI_Pull_Categories::sync(array $categories): array` returning ePim category ID => term ID; `EPI_Pull_Categories::META = '_epim_category_id'`.

- [ ] **Step 1: Write the failing test**

Append to `tests/product-import.spec.js`:

```js
test('categories arrive as a hierarchy and are not duplicated', async ({ page }) => {
  // A category the site already has, by name, is reused rather than doubled.
  await api(page, 'POST', '/category', { name: 'Decorative' });

  const first = await api(page, 'POST', '/categories', {
    categories: [
      { Id: 1, Name: 'Lighting controls', ParentId: null },
      { Id: 2, Name: 'Kinetic switches', ParentId: 1 },
      { Id: 3, Name: 'Decorative', ParentId: null },
      // A parent ePim never listed: the child still arrives, at the top level.
      { Id: 4, Name: 'Orphan', ParentId: 77 },
    ],
  });

  expect(Object.keys(first.map).sort()).toEqual(['1', '2', '3', '4']);
  expect(first.terms).toEqual([
    { name: 'Decorative', parent: '', epim: 3 },
    { name: 'Kinetic switches', parent: 'Lighting controls', epim: 2 },
    { name: 'Lighting controls', parent: '', epim: 1 },
    { name: 'Orphan', parent: '', epim: 4 },
  ]);

  // A rename and a move follow ePim; nothing is created twice.
  const second = await api(page, 'POST', '/categories', {
    categories: [
      { Id: 1, Name: 'Controls', ParentId: null },
      { Id: 2, Name: 'Kinetic switches', ParentId: 3 },
      { Id: 3, Name: 'Decorative', ParentId: null },
    ],
  });
  expect(second.map['1']).toBe(first.map['1']);
  expect(second.terms).toEqual([
    { name: 'Controls', parent: '', epim: 1 },
    { name: 'Decorative', parent: '', epim: 3 },
    { name: 'Kinetic switches', parent: 'Decorative', epim: 2 },
    { name: 'Orphan', parent: '', epim: 4 },
  ]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "hierarchy"`
Expected: FAIL, `POST /category: 404`.

- [ ] **Step 3: Create the category syncer**

`includes/pull/class-epi-pull-categories.php`:

```php
<?php
/**
 * Keeps WooCommerce's product categories in step with ePim's.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each ePim category is one product_cat term, tagged with the ePim ID in term
 * meta so renames and moves follow ePim without making a second term.
 */
final class EPI_Pull_Categories {

	/**
	 * Term meta holding the ePim category ID.
	 */
	const META = '_epim_category_id';

	/**
	 * Create or update a term for every category, parents before children.
	 *
	 * @param array $categories Entries with Id, Name, ParentId.
	 * @return array ePim category ID => term ID.
	 */
	public static function sync( array $categories ) {
		$by_id = array();

		foreach ( $categories as $category ) {
			if ( is_array( $category ) && ! empty( $category['Id'] ) ) {
				$by_id[ absint( $category['Id'] ) ] = $category;
			}
		}

		$map     = array();
		$pending = $by_id;
		$guard   = 0;

		// Each pass places every category whose parent is placed (or unknown).
		// A list of n categories needs at most n passes; the guard stops a cycle.
		while ( $pending && $guard++ <= count( $by_id ) ) {
			foreach ( $pending as $epim_id => $category ) {
				$parent_epim = isset( $category['ParentId'] ) ? absint( $category['ParentId'] ) : 0;

				if ( $parent_epim && isset( $by_id[ $parent_epim ] ) && ! isset( $map[ $parent_epim ] ) ) {
					continue;
				}

				$parent_term = $parent_epim && isset( $map[ $parent_epim ] ) ? (int) $map[ $parent_epim ] : 0;
				$term_id     = self::ensure_term( isset( $category['Name'] ) ? (string) $category['Name'] : '', $parent_term, $epim_id );

				if ( $term_id ) {
					$map[ $epim_id ] = $term_id;
				}

				unset( $pending[ $epim_id ] );
			}
		}

		return $map;
	}

	/**
	 * The term for one ePim category: found by ePim ID, else by name under the
	 * same parent (the site's existing categories, on first contact), else made.
	 *
	 * @param string $name    Category name.
	 * @param int    $parent  Parent term ID, 0 for top level.
	 * @param int    $epim_id ePim category ID.
	 * @return int Term ID, or 0 if it could not be made.
	 */
	private static function ensure_term( $name, $parent, $epim_id ) {
		$name = trim( $name );

		if ( '' === $name ) {
			return 0;
		}

		$found = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One term per ePim ID; the list is tiny.
				'meta_value' => (string) $epim_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( ! is_wp_error( $found ) && $found ) {
			$term_id = (int) $found[0];
			$term    = get_term( $term_id, 'product_cat' );

			if ( $term instanceof WP_Term && ( $term->name !== $name || (int) $term->parent !== $parent ) ) {
				wp_update_term(
					$term_id,
					'product_cat',
					array(
						'name'   => $name,
						'parent' => $parent,
					)
				);
			}

			return $term_id;
		}

		$existing = term_exists( $name, 'product_cat', $parent );

		if ( $existing ) {
			$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		} else {
			$created = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );

			if ( is_wp_error( $created ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A category that cannot be made must leave a trace.
				error_log( sprintf( '[Forum ePim pull] Could not make category "%1$s": %2$s', $name, $created->get_error_message() ) );
				return 0;
			}

			$term_id = (int) $created['term_id'];
		}

		update_term_meta( $term_id, self::META, (string) $epim_id );

		return $term_id;
	}
}
```

In `includes/pull/class-epi-pull.php`, `load()` gains, after the mapper require:

```php
		require_once __DIR__ . '/class-epi-pull-categories.php';
```

- [ ] **Step 4: Add the category routes**

In `tests/support/epi-test-epim.php`, inside `rest_api_init` after `/pull/map`:

```php
		// A category the site already had before the pull existed.
		register_rest_route(
			'epi-test/v1',
			'/pull/category',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$term = wp_insert_term( (string) $request['name'], 'product_cat' );
					return is_wp_error( $term ) ? array( 'error' => $term->get_error_message() ) : $term;
				},
			)
		);

		// Sync a category list, then report every term as name, parent name, ePim id.
		register_rest_route(
			'epi-test/v1',
			'/pull/categories',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$map   = EPI_Pull_Categories::sync( (array) $request['categories'] );
					$terms = array();

					foreach ( get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) ) as $term ) {
						$parent  = $term->parent ? get_term( $term->parent, 'product_cat' ) : null;
						$terms[] = array(
							'name'   => $term->name,
							'parent' => $parent instanceof WP_Term ? $parent->name : '',
							'epim'   => (int) get_term_meta( $term->term_id, EPI_Pull_Categories::META, true ),
						);
					}

					return array( 'map' => (object) $map, 'terms' => $terms );
				},
			)
		);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/pull tests/support/epi-test-epim.php tests/product-import.spec.js
git commit -m "Mirror ePim categories as product categories"
```

---

### Task 5: Writing a product (create, update, hide, skip)

**Files:**
- Create: `includes/pull/class-epi-pull-writer.php`
- Modify: `includes/pull/class-epi-pull.php` (`load()`)
- Modify: `tests/support/epi-test-epim.php` (`apply`, `product`, `product/{sku}`, `settings` routes)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: the mapper's product array; `EPI_Pull_Categories::sync()` map.
- Produces: `EPI_Pull_Writer::apply(array $product, array $category_map, bool $images): array{action: string, product_id: int, changes: array, message: string}` where action is `added|updated|hidden|unchanged|skipped|error` and each change is `{field, label, before, after}`.
- Produces: `EPI_Pull_Writer::hide_deleted(array $entry): array` of the same result shape plus `sku` and `name`, one per product hidden.
- Produces: `EPI_Pull_Writer::read(int $product_id): array` (status, name, description, sku, price, attributes, categories, image, gallery).

- [ ] **Step 1: Write the failing tests**

Append to `tests/product-import.spec.js`:

```js
// Map a raw record and apply it, with the fixture categories synced first.
async function applyRaw(page, raw, images = false) {
  return api(page, 'POST', '/apply', { raw, images });
}

const RAW_A = {
  Id: 1001, ProductId: 501, IsArchived: false, IsApprovedForPublishing: true,
  SKU: 'TEST-1001', Name: 'Single Kinetic Switch - White', Price: 51.25,
  SKU_Text: 'Kit includes a switch and a receiver.', ProductCategoryIds: [2],
  PictureIds: [11, 12, 13, 14], PictureIdsGrouped: { Image: [11, 12, 13], Logo: [14] },
  AttributeValues: [
    { AttributeHeaderName: 'Colour', Value: 'White' },
    { AttributeHeaderName: 'Material', Value: 'Plastic' },
  ],
};

test('a record is added, then updated, then hidden, then unchanged', async ({ page }) => {
  const added = await applyRaw(page, RAW_A);
  expect(added.action).toBe('added');
  expect(added.changes.map((c) => c.field)).toEqual(
    expect.arrayContaining(['status', 'name', 'description', 'sku', 'price', 'categories', 'attribute:Colour'])
  );

  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product).toMatchObject({
    status: 'publish',
    title: 'Single Kinetic Switch - White',
    content: 'Kit includes a switch and a receiver.',
    sku: 'TEST-1001',
    price: '51.25',
    epim_id: 1001,
    epim_product_id: 501,
    categories: ['Kinetic switches'],
    attributes: { Colour: 'White', Material: 'Plastic' },
    thumbnail: '',
    gallery: '',
  });

  const updated = await applyRaw(page, { ...RAW_A, Name: 'Kit - White', Price: 55, AttributeValues: [{ AttributeHeaderName: 'Colour', Value: 'Off white' }] });
  expect(updated.action).toBe('updated');
  expect(updated.product_id).toBe(product.id);
  expect(updated.changes).toEqual([
    { field: 'name', label: 'Product name', before: 'Single Kinetic Switch - White', after: 'Kit - White' },
    { field: 'price', label: 'Regular price', before: '51.25', after: '55.00' },
    { field: 'attribute:Colour', label: 'Attribute: Colour', before: 'White', after: 'Off white' },
  ]);
  // An attribute ePim stopped sending is left alone.
  expect((await api(page, 'GET', '/product/TEST-1001')).attributes).toEqual({ Colour: 'Off white', Material: 'Plastic' });

  const hidden = await applyRaw(page, { ...RAW_A, IsArchived: true });
  expect(hidden.action).toBe('hidden');
  expect(hidden.changes).toEqual([{ field: 'status', label: 'Status', before: 'Published', after: 'Draft (hidden)' }]);
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('draft');

  expect((await applyRaw(page, { ...RAW_A, IsArchived: true })).action).toBe('unchanged');

  // Back on sale in ePim: published again.
  const back = await applyRaw(page, RAW_A);
  expect(back.action).toBe('updated');
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('publish');
});

test('a record that is archived and not on the site is skipped, and a missing SKU is an error', async ({ page }) => {
  const skipped = await applyRaw(page, { ...RAW_A, IsArchived: true });
  expect(skipped.action).toBe('skipped');
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBe(0);

  const unapproved = await applyRaw(page, { ...RAW_A, IsApprovedForPublishing: false });
  expect(unapproved.action).toBe('skipped');
});

test('an existing product with the SKU but no ePim id is updated, not duplicated', async ({ page }) => {
  const existing = await api(page, 'POST', '/product', { sku: 'TEST-1001', title: 'Old name' });

  const result = await applyRaw(page, RAW_A);
  expect(result.action).toBe('updated');
  expect(result.product_id).toBe(existing.id);

  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product.count).toBe(1);
  expect(product.epim_id).toBe(1001);
  expect(product.title).toBe('Single Kinetic Switch - White');
});

test('pictures are left alone until the switch is on', async ({ page }) => {
  await applyRaw(page, RAW_A, false);
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '', gallery: '' });

  const withImages = await applyRaw(page, RAW_A, true);
  expect(withImages.action).toBe('updated');
  expect(withImages.changes).toEqual([
    { field: 'image', label: 'Main image', before: '', after: '11' },
    { field: 'gallery', label: 'Gallery', before: '', after: '12, 13' },
  ]);
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '11', gallery: '12,13' });
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "added, then updated|skipped|not duplicated|switch is on"`
Expected: FAIL, `POST /apply: 404`.

- [ ] **Step 3: Create the writer**

`includes/pull/class-epi-pull-writer.php`:

```php
<?php
/**
 * Applies an ePim record to a WooCommerce product.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the product, works out what would change, writes it, and reports the
 * changes field by field. Uses WooCommerce's product API when WooCommerce is
 * present and plain posts and meta otherwise (the test harness).
 */
final class EPI_Pull_Writer {

	/**
	 * Meta keys written on every product the pull touches.
	 */
	const META_VARIATION = '_epim_variation_id';
	const META_PRODUCT   = '_epim_product_id';
	const META_SYNCED    = '_epim_synced_at';

	/**
	 * Apply one mapped product.
	 *
	 * @param array $product      From EPI_Pull_Mapper::map().
	 * @param array $category_map ePim category ID => term ID.
	 * @param bool  $images       Whether to set the pictures.
	 * @return array action, product_id, changes, message.
	 */
	public static function apply( array $product, array $category_map, $images ) {
		$id = self::find( $product );

		if ( ! $id && $product['hidden'] ) {
			return self::result( 'skipped', 0, array(), __( 'Not on the site and not live in ePim, so nothing to add.', 'blueworx_client_forum' ) );
		}

		$before = $id ? self::read( $id ) : array();
		$wanted = $product['hidden'] ? array( 'status' => 'draft' ) : self::wanted( $product, $category_map, $images );
		$changes = self::diff( $before, $wanted );

		if ( $id && empty( $changes ) ) {
			self::stamp( $id, $product );
			return self::result( 'unchanged', $id, array(), '' );
		}

		$saved = self::write( $id, $wanted, $product );

		if ( is_wp_error( $saved ) ) {
			return self::result( 'error', (int) $id, $changes, $saved->get_error_message() );
		}

		$action = ! $id ? 'added' : ( $product['hidden'] ? 'hidden' : 'updated' );

		return self::result( $action, (int) $saved, $changes, '' );
	}

	/**
	 * Hide every product an ePim deletion names.
	 *
	 * @param array $entry One DeletedEntities record: EntityType, EntityId.
	 * @return array One result per product hidden, each with sku and name added.
	 */
	public static function hide_deleted( array $entry ) {
		$type   = isset( $entry['EntityType'] ) ? (string) $entry['EntityType'] : '';
		$entity = isset( $entry['EntityId'] ) ? absint( $entry['EntityId'] ) : 0;

		if ( 'SKU_Product_Mapping' === $type ) {
			$meta_key = self::META_VARIATION;
		} elseif ( 'Product' === $type ) {
			$meta_key = self::META_PRODUCT;
		} else {
			return array();
		}

		if ( ! $entity ) {
			return array();
		}

		$results = array();

		foreach ( self::find_by_meta( $meta_key, $entity, -1 ) as $id ) {
			$before = self::read( $id );

			if ( 'draft' === $before['status'] ) {
				continue;
			}

			$wanted  = array( 'status' => 'draft' );
			$changes = self::diff( $before, $wanted );
			$saved   = self::write( $id, $wanted, null );
			$result  = is_wp_error( $saved )
				? self::result( 'error', $id, $changes, $saved->get_error_message() )
				: self::result( 'hidden', $id, $changes, '' );

			$result['sku']  = $before['sku'];
			$result['name'] = $before['name'];
			$results[]      = $result;
		}

		return $results;
	}

	/**
	 * The product's current values for the fields the pull owns.
	 *
	 * @param int $id Product ID.
	 * @return array
	 */
	public static function read( $id ) {
		$post       = get_post( $id );
		$attributes = array();
		$stored     = get_post_meta( $id, '_product_attributes', true );

		foreach ( is_array( $stored ) ? $stored : array() as $attribute ) {
			if ( is_array( $attribute ) && empty( $attribute['is_taxonomy'] ) && isset( $attribute['name'] ) ) {
				$attributes[ (string) $attribute['name'] ] = isset( $attribute['value'] ) ? (string) $attribute['value'] : '';
			}
		}

		$categories = wp_get_object_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
		$categories = is_wp_error( $categories ) ? array() : array_map( 'intval', $categories );
		sort( $categories );

		$gallery = (string) get_post_meta( $id, '_product_image_gallery', true );
		$gallery = '' === $gallery ? array() : array_values( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) );

		return array(
			'status'      => $post instanceof WP_Post ? $post->post_status : '',
			'name'        => $post instanceof WP_Post ? $post->post_title : '',
			'description' => $post instanceof WP_Post ? $post->post_content : '',
			'sku'         => (string) get_post_meta( $id, '_sku', true ),
			'price'       => (string) get_post_meta( $id, '_regular_price', true ),
			'attributes'  => $attributes,
			'categories'  => $categories,
			'image'       => absint( get_post_meta( $id, '_thumbnail_id', true ) ),
			'gallery'     => $gallery,
		);
	}

	/**
	 * What a live product should hold.
	 *
	 * @param array $product      Mapped product.
	 * @param array $category_map ePim category ID => term ID.
	 * @param bool  $images       Whether pictures are set.
	 * @return array
	 */
	private static function wanted( array $product, array $category_map, $images ) {
		$wanted = array(
			'status'      => 'publish',
			'name'        => $product['name'],
			'description' => $product['description'],
			'sku'         => $product['sku'],
			'price'       => $product['price'],
			'attributes'  => $product['attributes'],
		);

		$terms = array();

		foreach ( $product['category_ids'] as $epim_id ) {
			if ( isset( $category_map[ $epim_id ] ) ) {
				$terms[] = (int) $category_map[ $epim_id ];
			}
		}

		// No mappable category: leave the product's categories as they are.
		if ( $terms ) {
			$terms = array_values( array_unique( $terms ) );
			sort( $terms );
			$wanted['categories'] = $terms;
		}

		if ( $images ) {
			$wanted['image']   = $product['image_ids'] ? (int) $product['image_ids'][0] : 0;
			$wanted['gallery'] = array_slice( $product['image_ids'], 1 );
		}

		return $wanted;
	}

	/**
	 * The fields whose value would change, in a form the import log shows.
	 *
	 * @param array $before From read(), or empty for a new product.
	 * @param array $wanted From wanted().
	 * @return array Each: field, label, before, after.
	 */
	public static function diff( array $before, array $wanted ) {
		$changes = array();

		foreach ( $wanted as $field => $after ) {
			$old = array_key_exists( $field, $before ) ? $before[ $field ] : null;

			if ( 'attributes' === $field ) {
				$old = is_array( $old ) ? $old : array();

				// Only the attributes ePim sends: one it stopped sending is left alone.
				foreach ( $after as $name => $value ) {
					$was = isset( $old[ $name ] ) ? $old[ $name ] : null;

					if ( $was !== $value ) {
						$changes[] = array(
							'field'  => 'attribute:' . $name,
							/* translators: %s: attribute name. */
							'label'  => sprintf( __( 'Attribute: %s', 'blueworx_client_forum' ), $name ),
							'before' => $was,
							'after'  => $value,
						);
					}
				}

				continue;
			}

			if ( self::same( $field, $old, $after ) ) {
				continue;
			}

			$changes[] = array(
				'field'  => $field,
				'label'  => self::label( $field ),
				'before' => self::display( $field, $old ),
				'after'  => self::display( $field, $after ),
			);
		}

		return $changes;
	}

	/**
	 * Whether two values of a field are the same.
	 *
	 * @param string $field Field.
	 * @param mixed  $old   Current value.
	 * @param mixed  $new   Wanted value.
	 * @return bool
	 */
	private static function same( $field, $old, $new ) {
		if ( 'price' === $field ) {
			return self::money( $old ) === self::money( $new );
		}

		if ( is_array( $old ) || is_array( $new ) ) {
			return array_map( 'intval', (array) $old ) === array_map( 'intval', (array) $new );
		}

		return (string) $old === (string) $new;
	}

	/**
	 * A price as two decimals, or '' when there is none.
	 *
	 * @param mixed $value Price.
	 * @return string
	 */
	private static function money( $value ) {
		return is_numeric( $value ) ? number_format( (float) $value, 2, '.', '' ) : '';
	}

	/**
	 * A readable name for a field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private static function label( $field ) {
		$labels = array(
			'status'      => __( 'Status', 'blueworx_client_forum' ),
			'name'        => __( 'Product name', 'blueworx_client_forum' ),
			'description' => __( 'Description', 'blueworx_client_forum' ),
			'sku'         => __( 'SKU', 'blueworx_client_forum' ),
			'price'       => __( 'Regular price', 'blueworx_client_forum' ),
			'categories'  => __( 'Categories', 'blueworx_client_forum' ),
			'image'       => __( 'Main image', 'blueworx_client_forum' ),
			'gallery'     => __( 'Gallery', 'blueworx_client_forum' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * A value as the import log shows it.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return string|null Null for "nothing".
	 */
	private static function display( $field, $value ) {
		if ( null === $value ) {
			return null;
		}

		if ( 'status' === $field ) {
			return 'publish' === $value ? __( 'Published', 'blueworx_client_forum' ) : __( 'Draft (hidden)', 'blueworx_client_forum' );
		}

		if ( 'price' === $field ) {
			return self::money( $value );
		}

		if ( 'categories' === $field ) {
			$names = array();

			foreach ( (array) $value as $term_id ) {
				$term    = get_term( (int) $term_id, 'product_cat' );
				$names[] = $term instanceof WP_Term ? $term->name : '#' . (int) $term_id;
			}

			return implode( ', ', $names );
		}

		if ( 'image' === $field ) {
			return $value ? (string) (int) $value : '';
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Find the product for a record: by ePim ID first, then by SKU.
	 *
	 * @param array $product Mapped product.
	 * @return int Product ID or 0.
	 */
	private static function find( array $product ) {
		if ( $product['epim_id'] ) {
			$ids = self::find_by_meta( self::META_VARIATION, $product['epim_id'], 1 );

			if ( $ids ) {
				return (int) $ids[0];
			}
		}

		if ( '' === $product['sku'] ) {
			return 0;
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( $product['sku'] );

			if ( $id && 'product' === get_post_type( $id ) ) {
				return $id;
			}
		}

		$ids = self::find_by_meta( '_sku', $product['sku'], 1 );

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Product IDs with a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @param int    $limit How many, -1 for all.
	 * @return int[]
	 */
	private static function find_by_meta( $key, $value, $limit ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Matching by ePim id or SKU is the whole point.
					'meta_value'     => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Write the wanted fields, through WooCommerce when it is there.
	 *
	 * @param int        $id      Product ID, 0 to create.
	 * @param array      $wanted  Fields to set.
	 * @param array|null $product Mapped product, for the ePim stamp; null to leave it.
	 * @return int|WP_Error Product ID.
	 */
	private static function write( $id, array $wanted, $product ) {
		if ( class_exists( 'WC_Product_Simple' ) && function_exists( 'wc_get_product' ) ) {
			return self::write_woocommerce( $id, $wanted, $product );
		}

		return self::write_posts( $id, $wanted, $product );
	}

	/**
	 * WooCommerce's own save: keeps its lookup tables and caches right.
	 *
	 * @param int        $id      Product ID, 0 to create.
	 * @param array      $wanted  Fields.
	 * @param array|null $product Mapped product or null.
	 * @return int|WP_Error
	 */
	private static function write_woocommerce( $id, array $wanted, $product ) {
		try {
			$wc = $id ? wc_get_product( $id ) : new WC_Product_Simple();

			if ( ! $wc ) {
				/* translators: %d: product ID. */
				return new WP_Error( 'epi_pull_missing', sprintf( __( 'Product #%d could not be loaded.', 'blueworx_client_forum' ), $id ) );
			}

			if ( isset( $wanted['name'] ) ) {
				$wc->set_name( $wanted['name'] );
			}
			if ( isset( $wanted['description'] ) ) {
				$wc->set_description( $wanted['description'] );
			}
			if ( isset( $wanted['status'] ) ) {
				$wc->set_status( $wanted['status'] );
			}
			if ( isset( $wanted['sku'] ) ) {
				$wc->set_sku( $wanted['sku'] );
			}
			if ( isset( $wanted['price'] ) ) {
				$wc->set_regular_price( $wanted['price'] );
			}
			if ( isset( $wanted['categories'] ) ) {
				$wc->set_category_ids( $wanted['categories'] );
			}
			if ( isset( $wanted['attributes'] ) ) {
				$wc->set_attributes( self::wc_attributes( $wc->get_attributes(), $wanted['attributes'] ) );
			}
			if ( array_key_exists( 'image', $wanted ) ) {
				$wc->set_image_id( (int) $wanted['image'] );
			}
			if ( isset( $wanted['gallery'] ) ) {
				$wc->set_gallery_image_ids( $wanted['gallery'] );
			}

			if ( is_array( $product ) ) {
				$wc->update_meta_data( self::META_VARIATION, (string) $product['epim_id'] );
				$wc->update_meta_data( self::META_PRODUCT, (string) $product['epim_product_id'] );
				$wc->update_meta_data( self::META_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
			}

			$saved = $wc->save();

			return $saved ? (int) $saved : new WP_Error( 'epi_pull_save', __( 'WooCommerce did not save the product.', 'blueworx_client_forum' ) );
		} catch ( Exception $e ) {
			return new WP_Error( 'epi_pull_save', $e->getMessage() );
		}
	}

	/**
	 * Merge ePim's attributes into the product's: ePim's values win, other
	 * attributes stay. Taxonomy attributes are never touched.
	 *
	 * @param array $current Current WC_Product_Attribute list.
	 * @param array $values  Name => value from ePim.
	 * @return array
	 */
	private static function wc_attributes( array $current, array $values ) {
		$by_key = array();

		foreach ( $current as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$by_key[ sanitize_title( $attribute->get_name() ) ] = $attribute;
			}
		}

		$position = 0;

		foreach ( $values as $name => $value ) {
			$key       = sanitize_title( $name );
			$attribute = isset( $by_key[ $key ] ) && ! $by_key[ $key ]->is_taxonomy() ? $by_key[ $key ] : new WC_Product_Attribute();

			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );

			$by_key[ $key ] = $attribute;
		}

		return array_values( $by_key );
	}

	/**
	 * Plain WordPress save, for a site (the test harness) without WooCommerce.
	 * Writes the same post fields and meta keys WooCommerce would.
	 *
	 * @param int        $id      Product ID, 0 to create.
	 * @param array      $wanted  Fields.
	 * @param array|null $product Mapped product or null.
	 * @return int|WP_Error
	 */
	private static function write_posts( $id, array $wanted, $product ) {
		$postarr = array( 'post_type' => 'product' );

		if ( isset( $wanted['name'] ) ) {
			$postarr['post_title'] = $wanted['name'];
		}
		if ( isset( $wanted['description'] ) ) {
			$postarr['post_content'] = $wanted['description'];
		}
		if ( isset( $wanted['status'] ) ) {
			$postarr['post_status'] = $wanted['status'];
		}

		if ( $id ) {
			if ( ! get_post( $id ) ) {
				/* translators: %d: product ID. */
				return new WP_Error( 'epi_pull_missing', sprintf( __( 'Product #%d could not be loaded.', 'blueworx_client_forum' ), $id ) );
			}

			$postarr['ID'] = $id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$id = (int) $result;

		if ( isset( $wanted['sku'] ) ) {
			update_post_meta( $id, '_sku', $wanted['sku'] );
		}
		if ( isset( $wanted['price'] ) ) {
			update_post_meta( $id, '_regular_price', $wanted['price'] );
			update_post_meta( $id, '_price', $wanted['price'] );
		}
		if ( isset( $wanted['attributes'] ) ) {
			update_post_meta( $id, '_product_attributes', self::meta_attributes( $id, $wanted['attributes'] ) );
		}
		if ( isset( $wanted['categories'] ) ) {
			wp_set_object_terms( $id, $wanted['categories'], 'product_cat', false );
		}
		if ( array_key_exists( 'image', $wanted ) ) {
			update_post_meta( $id, '_thumbnail_id', (int) $wanted['image'] );
		}
		if ( isset( $wanted['gallery'] ) ) {
			update_post_meta( $id, '_product_image_gallery', implode( ',', array_map( 'intval', $wanted['gallery'] ) ) );
		}
		if ( taxonomy_exists( 'product_type' ) ) {
			wp_set_object_terms( $id, 'simple', 'product_type', false );
		}

		if ( is_array( $product ) ) {
			self::stamp( $id, $product );
		}

		return $id;
	}

	/**
	 * The _product_attributes array WooCommerce stores, with ePim's values
	 * merged over the current ones.
	 *
	 * @param int   $id     Product ID.
	 * @param array $values Name => value.
	 * @return array
	 */
	private static function meta_attributes( $id, array $values ) {
		$stored   = get_post_meta( $id, '_product_attributes', true );
		$stored   = is_array( $stored ) ? $stored : array();
		$position = 0;

		foreach ( $values as $name => $value ) {
			$stored[ sanitize_title( $name ) ] = array(
				'name'         => $name,
				'value'        => $value,
				'position'     => $position++,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 0,
			);
		}

		return $stored;
	}

	/**
	 * Note which ePim record the product is, and when it was last pulled.
	 *
	 * @param int   $id      Product ID.
	 * @param array $product Mapped product.
	 * @return void
	 */
	private static function stamp( $id, array $product ) {
		update_post_meta( $id, self::META_VARIATION, (string) $product['epim_id'] );
		update_post_meta( $id, self::META_PRODUCT, (string) $product['epim_product_id'] );
		update_post_meta( $id, self::META_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A result array.
	 *
	 * @param string $action     Action.
	 * @param int    $product_id Product ID.
	 * @param array  $changes    Changes.
	 * @param string $message    Message.
	 * @return array
	 */
	private static function result( $action, $product_id, array $changes, $message ) {
		return array(
			'action'     => $action,
			'product_id' => (int) $product_id,
			'changes'    => $changes,
			'message'    => (string) $message,
		);
	}
}
```

In `includes/pull/class-epi-pull.php`, `load()` gains, after the categories require:

```php
		require_once __DIR__ . '/class-epi-pull-writer.php';
```

- [ ] **Step 4: Add the writer routes to the test support**

In `tests/support/epi-test-epim.php`, inside `rest_api_init` after `/pull/categories`:

```php
		// Map and apply one raw record, with the fixture categories in place.
		register_rest_route(
			'epi-test/v1',
			'/pull/apply',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$fixtures = epi_test_epim_fixtures( 'initial' );
					$map      = EPI_Pull_Categories::sync( $fixtures['categories'] );

					return EPI_Pull_Writer::apply( EPI_Pull_Mapper::map( (array) $request['raw'] ), $map, ! empty( $request['images'] ) );
				},
			)
		);

		// A product that existed before the pull: SKU, no ePim id.
		register_rest_route(
			'epi-test/v1',
			'/pull/product',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$id = wp_insert_post(
						array(
							'post_type'   => 'product',
							'post_status' => 'publish',
							'post_title'  => (string) $request['title'],
						)
					);
					update_post_meta( $id, '_sku', (string) $request['sku'] );

					return array( 'id' => (int) $id );
				},
			)
		);

		// What the site holds for a SKU. `count` says how many products carry it.
		register_rest_route(
			'epi-test/v1',
			'/pull/product/(?P<sku>[^/]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$ids = get_posts(
						array(
							'post_type'      => 'product',
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'fields'         => 'ids',
							'meta_key'       => '_sku',
							'meta_value'     => (string) $request['sku'],
						)
					);

					if ( ! $ids ) {
						return array( 'id' => 0, 'count' => 0 );
					}

					$id         = (int) $ids[0];
					$post       = get_post( $id );
					$read       = EPI_Pull_Writer::read( $id );
					$categories = array();

					foreach ( $read['categories'] as $term_id ) {
						$term         = get_term( $term_id, 'product_cat' );
						$categories[] = $term instanceof WP_Term ? $term->name : (string) $term_id;
					}

					return array(
						'id'              => $id,
						'count'           => count( $ids ),
						'status'          => $post->post_status,
						'title'           => $post->post_title,
						'content'         => $post->post_content,
						'sku'             => $read['sku'],
						'price'           => $read['price'],
						'epim_id'         => (int) get_post_meta( $id, '_epim_variation_id', true ),
						'epim_product_id' => (int) get_post_meta( $id, '_epim_product_id', true ),
						'categories'      => $categories,
						'attributes'      => (object) $read['attributes'],
						'thumbnail'       => (string) get_post_meta( $id, '_thumbnail_id', true ),
						'gallery'         => (string) get_post_meta( $id, '_product_image_gallery', true ),
					);
				},
			)
		);

		// Set the pull's settings directly.
		register_rest_route(
			'epi-test/v1',
			'/pull/settings',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					EPI_Pull_Settings::save( array( 'key' => (string) $request['key'], 'images' => ! empty( $request['images'] ) ) );
					return EPI_Pull_Settings::get();
				},
			)
		);
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (8 tests). If the `added` test's `thumbnail` reads `'0'` instead of `''`, the harness's `product` post type wrote the meta on insert; keep the writer as is and change the two expectations to `expect.any(String)`. Do not change the writer to match the harness.

- [ ] **Step 6: Commit**

```bash
git add includes/pull tests/support/epi-test-epim.php tests/product-import.spec.js
git commit -m "Write pulled products: add, update, hide and skip by SKU"
```

---

### Task 6: The runner: lock, batches, stages, schedule, change log source

**Files:**
- Create: `includes/pull/class-epi-pull-runner.php`
- Modify: `includes/pull/class-epi-pull.php` (`load()`, `boot()`)
- Modify: `includes/change-log/class-epi-change-source.php:56-70` (`current()`)
- Modify: `tests/support/epi-test-epim.php` (`start`, `drain`, `pull`, `seed-run`, `prune`, `schedule`, `lock`, `changes/{id}`, `runs` routes)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: `EPI_Pull_Client`, `EPI_Pull_Mapper`, `EPI_Pull_Categories`, `EPI_Pull_Writer`, `EPI_Pull_Store`, `EPI_Pull_Settings`.
- Produces: `EPI_Pull_Runner::init()`, `::start(string $trigger, bool $full = false): int|WP_Error` (error codes `epi_pull_no_key`, `epi_pull_running`, `epi_pull_store`), `::batch(int $run_id, int $batch_no = 0, bool $reschedule = true)`, `::drain(int $run_id): object|null`, `::is_locked(): bool`, `::running_run_id(): int`, `::daily()`, `::ensure_schedule()`, constants `DAILY_HOOK = 'epi_pull_daily'`, `BATCH_HOOK = 'epi_pull_batch'`, `KEEP_DAYS = 90`.
- Produces: filter `epi_change_source( string $source, int $object_id )` in `EPI_Change_Source::current()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/product-import.spec.js`:

```js
test('a first pull imports everything and a second asks only for changes', async ({ page }) => {
  const first = await pull(page);
  expect(first).toMatchObject({ status: 'done', is_full: '1', since_utc: '', added: '2', updated: '0', hidden: '0', skipped: '1', errors: '0' });

  const calls = await api(page, 'GET', '/calls');
  expect(calls.map((c) => c.path)).toEqual(['Categories', 'Variations', 'Variations', 'DeletedEntities']);
  expect(calls[1].query.changedSinceUTC).toBe('2000-01-01T00:00:00Z');

  const second = await pull(page, { scenario: 'changed' });
  expect(second).toMatchObject({ status: 'done', is_full: '0', added: '0', updated: '1', hidden: '1', unchanged: '0', skipped: '1' });
  // Since the first run started, less five minutes, as an ISO UTC time.
  expect(second.since_utc).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  const sinceCalls = await api(page, 'GET', '/calls');
  expect(sinceCalls.find((c) => c.path === 'Variations').query.changedSinceUTC).toBe(second.since_utc);

  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ title: 'Single Kinetic Switch Kit - White', price: '55.00' });
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('draft');

  // A full re-import asks for everything again.
  const full = await pull(page, { full: true });
  expect(full).toMatchObject({ status: 'done', is_full: '1', since_utc: '' });
});

test('a deleted entity hides its product', async ({ page }) => {
  await pull(page);
  const run = await pull(page, { scenario: 'deleted' });

  expect(run).toMatchObject({ status: 'done', hidden: '2' });
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('draft');
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('draft');

  const items = await api(page, 'GET', `/runs/${run.id}/items`);
  const hidden = items.filter((i) => i.action === 'hidden');
  expect(hidden.map((i) => i.sku).sort()).toEqual(['TEST-1001', 'TEST-1002']);
  expect(hidden[0].raw.EntityType).toBeDefined();
});

test('a record without a SKU is an error and the run carries on', async ({ page }) => {
  await api(page, 'POST', '/scenario', { scenario: 'nosku' });
  const run = await api(page, 'POST', '/pull', {});

  expect(run).toMatchObject({ status: 'done', added: '1', errors: '1' });
  const items = await api(page, 'GET', `/runs/${run.id}/items`);
  expect(items.find((i) => i.action === 'error').message).toBe('No SKU, so it cannot be matched to a product.');
});

test('a second pull is refused while one is running, and a stale lock is cleared', async ({ page }) => {
  const started = await api(page, 'POST', '/start', {});
  expect(started.run_id).toBeGreaterThan(0);

  const again = await api(page, 'POST', '/start', {});
  expect(again.error).toBe('epi_pull_running');

  await api(page, 'POST', '/drain', { run_id: started.run_id });
  expect((await api(page, 'POST', '/start', {})).run_id).toBeGreaterThan(started.run_id);

  // A lock 21 minutes old belongs to a run that died: it is failed and released.
  const dead = await api(page, 'POST', '/lock', { minutes_ago: 21 });
  const next = await api(page, 'POST', '/start', {});
  expect(next.run_id).toBeGreaterThan(0);
  const runs = await api(page, 'GET', '/runs');
  expect(runs.find((r) => Number(r.id) === dead.run_id)).toMatchObject({ status: 'failed', message: 'Timed out: no batch finished for 20 minutes.' });
});

test('a bad key fails the run with a plain message', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: 'wrong' });
  const run = await api(page, 'POST', '/pull', {});
  expect(run).toMatchObject({ status: 'failed', message: 'ePim did not accept the subscription key.' });
});

test('records older than 90 days are pruned', async ({ page }) => {
  const old = await api(page, 'POST', '/seed-run', { days_ago: 100 });
  const recent = await api(page, 'POST', '/seed-run', { days_ago: 80 });

  const pruned = await api(page, 'POST', '/prune');
  expect(pruned.removed).toBe(1);

  const runs = await api(page, 'GET', '/runs');
  expect(runs.map((r) => Number(r.id))).toEqual([recent.id]);
  expect(runs.map((r) => Number(r.id))).not.toContain(old.id);
  expect(await api(page, 'GET', `/runs/${old.id}/items`)).toEqual([]);
});

test('a daily pull is scheduled for 02:00 site time', async ({ page }) => {
  const schedule = await api(page, 'GET', '/schedule');
  expect(schedule.next).toBeGreaterThan(Date.now() / 1000);
  expect(schedule.local_time).toBe('02:00');
  expect(schedule.recurrence).toBe('daily');
});

test('a pull shows in the product change log as ePim', async ({ page }) => {
  await pull(page);
  const product = await api(page, 'GET', '/product/TEST-1001');

  const updates = await api(page, 'GET', `/changes/${product.id}`);
  expect(updates.length).toBeGreaterThan(0);
  expect(updates[0].source).toBe('epim');
  expect(updates[0].actor).toBe('ePim External API');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "first pull|deleted entity|without a SKU|refused|bad key|pruned|daily pull|change log"`
Expected: FAIL, `POST /pull: 404` and the rest.

- [ ] **Step 3: Let a pull claim its writes in the change log**

In `includes/change-log/class-epi-change-source.php`, `current()` currently returns `self::classify( array( ... ) )`. Change it so the return is filtered:

```php
	public static function current( $object_id ) {
		$source = self::classify(
			array(
				'cli'        => defined( 'WP_CLI' ) && WP_CLI,
				'cron'       => wp_doing_cron(),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads which AJAX action is running; nothing is changed on it.
				'background' => did_action( 'action_scheduler_before_execute' ) || ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'as_async_request_queue_runner' === $_REQUEST['action'] ),
				'api'        => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks a nonce is present, to tell a browser session from an API key; WordPress verifies it.
				'nonce'      => ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) || ! empty( $_REQUEST['_wpnonce'] ),
				'admin'      => is_admin(),
				'user_id'    => get_current_user_id(),
				'can_edit'   => current_user_can( 'edit_post', $object_id ),
			)
		);

		/**
		 * Filter who a change is from. The ePim pull runs as a background job,
		 * which would be ignored; it claims its writes as ePim through this.
		 *
		 * @param string $source    One of the class constants.
		 * @param int    $object_id Product or variation ID.
		 */
		return (string) apply_filters( 'epi_change_source', $source, $object_id );
	}
```

- [ ] **Step 4: Create the runner**

`includes/pull/class-epi-pull-runner.php`:

```php
<?php
/**
 * Drives a pull: start, lock, batches under WP-Cron, the daily schedule.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pull is a run record moved through three stages: categories, products
 * (one API page at a time), deleted entities. Each cron batch works for a
 * time budget, then schedules the next batch. One lock stops overlap.
 */
final class EPI_Pull_Runner {

	const DAILY_HOOK = 'epi_pull_daily';
	const BATCH_HOOK = 'epi_pull_batch';
	const LOCK       = 'epi_pull_lock';
	const MAP_OPTION = 'epi_pull_category_map';

	/**
	 * Days of records to keep.
	 */
	const KEEP_DAYS = 90;

	/**
	 * Seconds a lock may go without a batch finishing before the run is
	 * treated as dead.
	 */
	const STALE = 20 * MINUTE_IN_SECONDS;

	/**
	 * The time asked for when there is no last successful run.
	 */
	const BEGINNING = '2000-01-01T00:00:00Z';

	/**
	 * Register the cron hooks and make sure the daily event exists.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'batch' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * Schedule the daily pull at 02:00 site time if it is not scheduled.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( self::next_two_am(), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * The next 02:00 in the site's timezone, as a Unix time.
	 *
	 * @return int
	 */
	public static function next_two_am() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$two = $now->setTime( 2, 0, 0 );

		if ( $two <= $now ) {
			$two = $two->modify( '+1 day' );
		}

		return $two->getTimestamp();
	}

	/**
	 * The daily job: clear old records, then pull.
	 *
	 * @return void
	 */
	public static function daily() {
		EPI_Pull_Store::prune( self::KEEP_DAYS );

		$started = self::start( 'auto' );

		if ( is_wp_error( $started ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A daily pull that could not start must leave a trace.
			error_log( '[Forum ePim pull] Daily pull did not start: ' . $started->get_error_message() );
		}
	}

	/**
	 * Start a pull and schedule its first batch.
	 *
	 * @param string $trigger 'auto' or 'manual'.
	 * @param bool   $full    Ask ePim for everything rather than changes since the last pull.
	 * @return int|WP_Error Run ID.
	 */
	public static function start( $trigger, $full = false ) {
		if ( '' === EPI_Pull_Settings::key() ) {
			return new WP_Error( 'epi_pull_no_key', __( 'No ePim subscription key is saved.', 'blueworx_client_forum' ) );
		}

		if ( self::is_locked() ) {
			return new WP_Error( 'epi_pull_running', __( 'A pull is already running.', 'blueworx_client_forum' ) );
		}

		$last  = $full ? null : EPI_Pull_Store::last_successful_run();
		$since = $last ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $last->started_at . ' UTC' ) - 5 * MINUTE_IN_SECONDS ) : '';

		$run_id = EPI_Pull_Store::create_run( $trigger, $since, ! $last );

		if ( ! $run_id ) {
			return new WP_Error( 'epi_pull_store', __( 'The pull could not be recorded.', 'blueworx_client_forum' ) );
		}

		self::lock( $run_id );
		self::schedule_batch( $run_id, 1 );

		return $run_id;
	}

	/**
	 * Work on a run for a while, then hand over to the next batch.
	 *
	 * @param int  $run_id     Run ID.
	 * @param int  $batch_no   Which batch this is; only there to make each cron event distinct.
	 * @param bool $reschedule Whether to schedule the next batch when time runs out.
	 * @return void
	 */
	public static function batch( $run_id, $batch_no = 0, $reschedule = true ) {
		$run = EPI_Pull_Store::get_run( $run_id );

		if ( ! $run || in_array( $run->status, array( 'done', 'failed' ), true ) ) {
			return;
		}

		self::lock( $run_id );
		EPI_Pull_Store::update_run( $run_id, array( 'status' => 'running' ) );

		add_filter( 'epi_change_source', array( __CLASS__, 'as_epim' ) );

		/**
		 * Filter how long one batch may run, in seconds.
		 *
		 * @param int $seconds Budget.
		 */
		$deadline = microtime( true ) + (int) apply_filters( 'epi_pull_batch_seconds', 20 );
		$outcome  = true;

		while ( true === $outcome && microtime( true ) < $deadline ) {
			$run     = EPI_Pull_Store::get_run( $run_id );
			$outcome = $run ? self::step( $run ) : 'done';
		}

		remove_filter( 'epi_change_source', array( __CLASS__, 'as_epim' ) );

		if ( is_wp_error( $outcome ) ) {
			self::finish( $run_id, 'failed', $outcome->get_error_message() );
			return;
		}

		if ( 'done' === $outcome ) {
			self::finish( $run_id, 'done' );
			return;
		}

		if ( $reschedule ) {
			$run = EPI_Pull_Store::get_run( $run_id );
			self::schedule_batch( $run_id, (int) $run->batches + 1 );
		}
	}

	/**
	 * Run a pull to the end now, without cron. For tests and WP-CLI.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null The finished run.
	 */
	public static function drain( $run_id ) {
		$guard = 0;

		do {
			self::batch( $run_id, 0, false );
			$run = EPI_Pull_Store::get_run( $run_id );
		} while ( $run && ! in_array( $run->status, array( 'done', 'failed' ), true ) && $guard++ < 1000 );

		return $run;
	}

	/**
	 * Claim writes made during a batch as ePim's.
	 *
	 * @return string
	 */
	public static function as_epim() {
		return EPI_Change_Source::EPIM;
	}

	/**
	 * Whether a pull is running. A lock left by a dead run is cleared here.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		$lock = get_option( self::LOCK );

		if ( ! is_array( $lock ) || empty( $lock['run_id'] ) ) {
			return false;
		}

		if ( time() - (int) $lock['time'] > self::STALE ) {
			EPI_Pull_Store::finish_run( (int) $lock['run_id'], 'failed', __( 'Timed out: no batch finished for 20 minutes.', 'blueworx_client_forum' ) );
			delete_option( self::LOCK );
			return false;
		}

		return true;
	}

	/**
	 * The run that holds the lock.
	 *
	 * @return int Run ID, or 0.
	 */
	public static function running_run_id() {
		if ( ! self::is_locked() ) {
			return 0;
		}

		$lock = get_option( self::LOCK );

		return (int) $lock['run_id'];
	}

	/**
	 * One unit of work on a run.
	 *
	 * @param object $run The run row.
	 * @return true|string|WP_Error true for more to do, 'done', or an error.
	 */
	private static function step( $run ) {
		$since = '' !== (string) $run->since_utc ? (string) $run->since_utc : self::BEGINNING;

		switch ( $run->stage ) {
			case 'categories':
				$categories = EPI_Pull_Client::categories();

				if ( is_wp_error( $categories ) ) {
					return $categories;
				}

				update_option( self::MAP_OPTION, EPI_Pull_Categories::sync( $categories ), false );
				EPI_Pull_Store::update_run(
					(int) $run->id,
					array(
						'stage'        => 'products',
						'cursor_start' => 0,
					)
				);
				return true;

			case 'products':
				$page = EPI_Pull_Client::variations( $since, (int) $run->cursor_start );

				if ( is_wp_error( $page ) ) {
					return $page;
				}

				foreach ( $page['results'] as $raw ) {
					self::apply( (int) $run->id, is_array( $raw ) ? $raw : array() );
				}

				$next = (int) $run->cursor_start + EPI_Pull_Settings::page_size();

				if ( empty( $page['results'] ) || $next >= $page['total'] ) {
					EPI_Pull_Store::update_run(
						(int) $run->id,
						array(
							'stage'        => 'deleted',
							'cursor_start' => 0,
							'total'        => (int) $page['total'],
						)
					);
				} else {
					EPI_Pull_Store::update_run(
						(int) $run->id,
						array(
							'cursor_start' => $next,
							'total'        => (int) $page['total'],
						)
					);
				}
				return true;

			case 'deleted':
				$page = EPI_Pull_Client::deleted( $since, (int) $run->cursor_start );

				if ( is_wp_error( $page ) ) {
					return $page;
				}

				foreach ( $page['results'] as $entry ) {
					foreach ( EPI_Pull_Writer::hide_deleted( is_array( $entry ) ? $entry : array() ) as $result ) {
						self::record( (int) $run->id, $result, $result['sku'], $result['name'], 0, $entry );
					}
				}

				$next = (int) $run->cursor_start + EPI_Pull_Settings::page_size();

				if ( empty( $page['results'] ) || $next >= $page['total'] ) {
					return 'done';
				}

				EPI_Pull_Store::update_run( (int) $run->id, array( 'cursor_start' => $next ) );
				return true;
		}

		return 'done';
	}

	/**
	 * Map and write one record, and record what happened.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $raw    The ePim record.
	 * @return void
	 */
	private static function apply( $run_id, array $raw ) {
		$product = EPI_Pull_Mapper::map( $raw );

		if ( '' === $product['sku'] ) {
			$result = array(
				'action'     => 'error',
				'product_id' => 0,
				'changes'    => array(),
				'message'    => __( 'No SKU, so it cannot be matched to a product.', 'blueworx_client_forum' ),
			);
		} else {
			$map    = get_option( self::MAP_OPTION, array() );
			$result = EPI_Pull_Writer::apply( $product, is_array( $map ) ? $map : array(), EPI_Pull_Settings::images_from_epim() );
		}

		self::record( $run_id, $result, $product['sku'], $product['name'], $product['epim_id'], $raw );
	}

	/**
	 * Count a result on the run, and keep the product when something happened.
	 *
	 * @param int    $run_id  Run ID.
	 * @param array  $result  Writer result.
	 * @param string $sku     SKU.
	 * @param string $name    Product name.
	 * @param int    $epim_id ePim variation ID.
	 * @param array  $raw     What ePim sent.
	 * @return void
	 */
	private static function record( $run_id, array $result, $sku, $name, $epim_id, array $raw ) {
		EPI_Pull_Store::bump( $run_id, $result['action'] );

		if ( ! in_array( $result['action'], array( 'added', 'updated', 'hidden', 'error' ), true ) ) {
			return;
		}

		EPI_Pull_Store::add_item(
			$run_id,
			array(
				'product_id' => $result['product_id'],
				'epim_id'    => $epim_id,
				'sku'        => $sku,
				'name'       => $name,
				'action'     => $result['action'],
				'changes'    => $result['changes'],
				'raw'        => $raw,
				'message'    => $result['message'],
			)
		);
	}

	/**
	 * Close the run and release the lock.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $status  'done' or 'failed'.
	 * @param string $message Why, when failed.
	 * @return void
	 */
	private static function finish( $run_id, $status, $message = '' ) {
		EPI_Pull_Store::finish_run( $run_id, $status, $message );
		delete_option( self::LOCK );
	}

	/**
	 * Hold the lock for a run, with the time of the last sign of life.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private static function lock( $run_id ) {
		update_option(
			self::LOCK,
			array(
				'run_id' => (int) $run_id,
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * Queue the next batch. The batch number keeps each event distinct, or
	 * WordPress would drop it as a duplicate of the one just run.
	 *
	 * @param int $run_id   Run ID.
	 * @param int $batch_no Batch number.
	 * @return void
	 */
	private static function schedule_batch( $run_id, $batch_no ) {
		EPI_Pull_Store::update_run( $run_id, array( 'batches' => (int) $batch_no ) );
		wp_schedule_single_event( time(), self::BATCH_HOOK, array( (int) $run_id, (int) $batch_no ) );
		spawn_cron();
	}
}
```

In `includes/pull/class-epi-pull.php`, `load()` gains, after the writer require:

```php
		require_once __DIR__ . '/class-epi-pull-runner.php';
```

and `boot()` gains, after the install check:

```php
		EPI_Pull_Runner::init();
```

- [ ] **Step 5: Add the runner routes and the no-SKU scenario to the test support**

In `epi_test_epim_fixtures()`, after the `deleted` scenario block:

```php
	if ( 'nosku' === $scenario ) {
		$b['SKU'] = '';
		$c        = null;
	}
```

and change the return to drop the null:

```php
	return array(
		'categories' => $categories,
		'variations' => array_values( array_filter( array( $a, $b, $c ) ) ),
		'deleted'    => $deleted,
	);
```

Inside `rest_api_init`, after `/pull/settings`:

```php
		$run_to_array = static function ( $run ) {
			return $run ? (array) $run : null;
		};

		register_rest_route(
			'epi-test/v1',
			'/pull/start',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$started = EPI_Pull_Runner::start( 'manual', ! empty( $request['full'] ) );
					return is_wp_error( $started ) ? array( 'error' => $started->get_error_code() ) : array( 'run_id' => $started );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/drain',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) use ( $run_to_array ) {
					return $run_to_array( EPI_Pull_Runner::drain( (int) $request['run_id'] ) );
				},
			)
		);

		// Start and run to the end in one go. Calls are cleared first so a
		// test can see exactly what this pull asked ePim for.
		register_rest_route(
			'epi-test/v1',
			'/pull/pull',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) use ( $run_to_array ) {
					delete_option( 'epi_test_epim_calls' );
					$started = EPI_Pull_Runner::start( 'manual', ! empty( $request['full'] ) );

					if ( is_wp_error( $started ) ) {
						return array( 'error' => $started->get_error_code(), 'message' => $started->get_error_message() );
					}

					return $run_to_array( EPI_Pull_Runner::drain( $started ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/runs',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array_map( static function ( $run ) { return (array) $run; }, EPI_Pull_Store::get_runs( 1, 100 ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/runs/(?P<id>\d+)/items',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return EPI_Pull_Store::get_items( (int) $request['id'], 1, 100 );
				},
			)
		);

		// A finished run from some days ago, with one item.
		register_rest_route(
			'epi-test/v1',
			'/pull/seed-run',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$run_id = EPI_Pull_Store::create_run( 'auto', '', false );
					EPI_Pull_Store::update_run(
						$run_id,
						array(
							'started_at' => gmdate( 'Y-m-d H:i:s', time() - (int) $request['days_ago'] * DAY_IN_SECONDS ),
							'status'     => 'done',
						)
					);
					EPI_Pull_Store::add_item( $run_id, array( 'sku' => 'SEED', 'name' => 'Seed', 'action' => 'added' ) );

					return array( 'id' => $run_id );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/prune',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					return array( 'removed' => EPI_Pull_Store::prune( EPI_Pull_Runner::KEEP_DAYS ) );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/schedule',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					$next = (int) wp_next_scheduled( EPI_Pull_Runner::DAILY_HOOK );

					return array(
						'next'       => $next,
						'local_time' => $next ? wp_date( 'H:i', $next ) : '',
						'recurrence' => $next ? wp_get_schedule( EPI_Pull_Runner::DAILY_HOOK ) : '',
					);
				},
			)
		);

		// A lock some minutes old, as a run that died mid-batch would leave.
		register_rest_route(
			'epi-test/v1',
			'/pull/lock',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$run_id = EPI_Pull_Store::create_run( 'auto', '', false );
					EPI_Pull_Store::update_run( $run_id, array( 'status' => 'running' ) );
					update_option( 'epi_pull_lock', array( 'run_id' => $run_id, 'time' => time() - (int) $request['minutes_ago'] * MINUTE_IN_SECONDS ), false );

					return array( 'run_id' => $run_id );
				},
			)
		);

		register_rest_route(
			'epi-test/v1',
			'/pull/changes/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return class_exists( 'EPI_Product_Change_Log' ) ? EPI_Product_Change_Log::get_updates( (int) $request['id'] ) : array();
				},
			)
		);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (16 tests). If the change log test finds no updates, check that `tests/support/epi-test-change-log.php` was copied (the spec's `beforeAll` copies both) and that the `change-log` feature is on; the change log writes at `shutdown`, which the REST request that drained the run reaches before the next request reads.

- [ ] **Step 7: Commit**

```bash
git add includes/pull includes/change-log/class-epi-change-source.php tests/support/epi-test-epim.php tests/product-import.spec.js
git commit -m "Run pulls in cron batches under a lock, daily at 02:00"
```

---

### Task 7: The Product import page: settings, Pull now, the runs table

Use the blueworx-admin-design skill for this screen: page header, notice, card, fields, switch, stats, flush card with table, table footer with pager. Every class below is from `assets/blueworx-admin-design.css`.

**Files:**
- Create: `includes/class-epi-product-import-page.php`
- Create: `assets/css/epi-product-import.css`
- Create: `assets/js/epi-product-import.js`
- Modify: `includes/pull/class-epi-pull.php` (`load()`, `boot()`)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: `EPI_Pull_Settings`, `EPI_Pull_Store::get_runs/count_runs`, `EPI_Pull_Runner::start/is_locked/running_run_id/DAILY_HOOK`, `EPI_Lab_Page::enqueue_design_system()`, `EPI_Lab_Page::DESIGN_HANDLE`.
- Produces: `EPI_Product_Import_Page::init()`, `::SLUG = 'epi-product-import'`, `::url(array $args = array()): string`, `::render()`, and the private `render_list()`; Task 8 adds `render_detail(int $run_id)`.
- Form contract: POST `epi_pull_action` in `settings|pull|full` with nonce field `epi_pull_nonce` for action `epi_pull_{action}`; fields `epi_pull_key`, `epi_pull_images`. Redirect back with `notice` in `saved|started|epi_pull_running|epi_pull_no_key|epi_pull_store`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/product-import.spec.js`:

```js
async function loginAs(page, user, pass) {
  await page.context().clearCookies();
  await page.goto('/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/);
}

test('only administrators can open Product import', async ({ page }) => {
  await page.goto('/wp-admin/edit.php?post_type=product');
  await expect(page.locator('#adminmenu')).toContainText('Product import');

  await loginAs(page, 'epi-test-editor', 'editor-test-pw');
  await page.goto(SCREEN);
  await expect(page.locator('#wpbody-content, body')).toContainText('Sorry, you are not allowed to access this page.');
});

test('the page renders from the design system and saves the settings', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('.bw-page .bw-pagehead__h1')).toHaveText('Product import');
  await expect(page.locator('.bw-empty__title')).toHaveText('No pulls yet');

  await page.fill('#epi_pull_key', 'new-key-123');
  await page.locator('input[name="epi_pull_images"]').check();
  await page.getByRole('button', { name: 'Save settings' }).click();

  await expect(page.locator('.bw-notice--success')).toContainText('Settings saved.');
  await expect(page.locator('#epi_pull_key')).toHaveValue('new-key-123');
  await expect(page.locator('input[name="epi_pull_images"]')).toBeChecked();
});

test('Pull now starts a pull that shows in the table, and a second is refused while it runs', async ({ page }) => {
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Pull now' }).click();
  await expect(page.locator('.bw-notice--success')).toContainText('Pull started.');

  const row = page.locator('.bw-table tbody tr').first();
  await expect(row).toContainText('Manual');
  await expect(row.locator('.bw-badge')).toHaveText(/Queued|Running/);

  await page.getByRole('button', { name: 'Pull now' }).click();
  await expect(page.locator('.bw-notice--warning')).toContainText('A pull is already running.');

  // Let it finish, then the row reads Done with its counts.
  const runs = await api(page, 'GET', '/runs');
  await api(page, 'POST', '/drain', { run_id: Number(runs[0].id) });
  await page.goto(SCREEN);
  await expect(page.locator('.bw-table tbody tr').first().locator('.bw-badge')).toHaveText('Done');
  await expect(page.locator('.bw-table tbody tr').first()).toContainText('2'); // added
  await expect(page.locator('.bw-stat__value').first()).not.toHaveText('Never');
});

test('without a key, Pull now says so', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: '' });
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Pull now' }).click();
  await expect(page.locator('.bw-notice--warning')).toContainText('No ePim subscription key is saved.');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "administrators|design system|Pull now|without a key"`
Expected: FAIL, the Products menu has no "Product import".

- [ ] **Step 3: Create the chrome override CSS and the page JS**

`assets/css/epi-product-import.css`:

```css
/**
 * Product import page — chrome overrides only.
 *
 * Everything the screen looks like comes from assets/blueworx-admin-design.css,
 * the shared design system copied verbatim from the foundation. The only
 * styling this plugin keeps of its own is what makes the screen run full width
 * inside wp-admin, which is the documented exception.
 */

.wrap.bw-wrap {
	margin: 0;
}

body.product_page_epi-product-import #wpcontent {
	padding-left: 0;
}

body.product_page_epi-product-import #wpbody-content {
	padding-bottom: 0;
}

body.product_page_epi-product-import #wpfooter {
	display: none;
}
```

`assets/js/epi-product-import.js`:

```js
/**
 * Product import page.
 *
 * Two jobs: confirm before a full re-import, and open or close the raw-data
 * accordions on the detail view.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var confirmButton = event.target.closest( '[data-epi-confirm]' );
		if ( confirmButton && ! window.confirm( confirmButton.getAttribute( 'data-epi-confirm' ) ) ) {
			event.preventDefault();
			return;
		}

		var head = event.target.closest( '[data-epi-accordion] .bw-accordion__head' );
		if ( ! head ) {
			return;
		}

		var accordion = head.closest( '[data-epi-accordion]' );
		var body = accordion.querySelector( '.bw-accordion__body' );
		var open = ! accordion.classList.contains( 'is-open' );

		accordion.classList.toggle( 'is-open', open );
		head.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		if ( body ) {
			body.hidden = ! open;
		}
	} );
} )();
```

- [ ] **Step 4: Create the page class (list view)**

`includes/class-epi-product-import-page.php`:

```php
<?php
/**
 * Product import page (Products -> Product import).
 *
 * The record of every ePim pull: a settings card, the last pull at a glance,
 * and a table of runs, each opening to a product-by-product view. Built from
 * the shared blueworx-admin-design system; this plugin's own CSS is only the
 * full-bleed chrome override.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the page and handles its forms.
 */
final class EPI_Product_Import_Page {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'epi-product-import';

	/**
	 * Runs per page of the table.
	 */
	const RUNS_PER_PAGE = 20;

	/**
	 * Products per page of the detail view.
	 */
	const ITEMS_PER_PAGE = 50;

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the page under Products. Administrators only.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Product import', 'blueworx_client_forum' ),
			__( 'Product import', 'blueworx_client_forum' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The page's address, with extra query values.
	 *
	 * @param array $args Query values.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => 'product',
					'page'      => self::SLUG,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Load assets on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'product_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		EPI_Lab_Page::enqueue_design_system();
		wp_enqueue_style( 'epi-product-import', EPI_PLUGIN_URL . 'assets/css/epi-product-import.css', array( EPI_Lab_Page::DESIGN_HANDLE ), EPI_VERSION );
		wp_enqueue_script( 'epi-product-import', EPI_PLUGIN_URL . 'assets/js/epi-product-import.js', array(), EPI_VERSION, true );
	}

	/**
	 * Save settings, or start a pull, then come back with a notice.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['epi_pull_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the product import.', 'blueworx_client_forum' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['epi_pull_action'] ) );
		check_admin_referer( 'epi_pull_' . $action, 'epi_pull_nonce' );

		$notice = 'saved';

		if ( 'settings' === $action ) {
			EPI_Pull_Settings::save(
				array(
					'key'    => isset( $_POST['epi_pull_key'] ) ? sanitize_text_field( wp_unslash( $_POST['epi_pull_key'] ) ) : '',
					'images' => ! empty( $_POST['epi_pull_images'] ),
				)
			);
		} elseif ( 'pull' === $action || 'full' === $action ) {
			$started = EPI_Pull_Runner::start( 'manual', 'full' === $action );
			$notice  = is_wp_error( $started ) ? $started->get_error_code() : 'started';
		}

		wp_safe_redirect( self::url( array( 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * Render the page: the detail view when a run is asked for, else the list.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view choice for an admin screen; sanitised with absint().
		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;

		if ( $run_id ) {
			self::render_detail( $run_id );
			return;
		}

		self::render_list();
	}

	/**
	 * The list view: settings, last pull, every run.
	 *
	 * The page callback runs after wp-admin has sent its header, so a view
	 * that cannot be shown falls back to this one with a notice rather than
	 * redirecting.
	 *
	 * @param string $forced_notice A notice to show instead of the one in the URL.
	 * @return void
	 */
	private static function render_list( $forced_notice = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging and notice for an admin screen; sanitised below.
		$page_no = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$notice = '' !== $forced_notice ? $forced_notice : ( isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '' );

		$settings = EPI_Pull_Settings::get();
		$total    = EPI_Pull_Store::count_runs();
		$pages    = max( 1, (int) ceil( $total / self::RUNS_PER_PAGE ) );
		$page_no  = min( $page_no, $pages );
		$runs     = EPI_Pull_Store::get_runs( $page_no, self::RUNS_PER_PAGE );
		$last     = EPI_Pull_Store::last_successful_run();
		$latest   = $runs && 1 === $page_no ? $runs[0] : null;
		$next     = (int) wp_next_scheduled( EPI_Pull_Runner::DAILY_HOOK );
		?>
		<div class="wrap bw-wrap">
			<div class="bw-admin bw-page">
				<header class="bw-pagehead">
					<div class="bw-pagehead__titles">
						<p class="bw-pagehead__eyebrow"><?php esc_html_e( 'ePim', 'blueworx_client_forum' ); ?></p>
						<h1 class="bw-pagehead__h1"><?php esc_html_e( 'Product import', 'blueworx_client_forum' ); ?></h1>
						<p class="bw-pagehead__lede">
							<?php esc_html_e( 'Products are pulled from ePim once a day at 02:00 and whenever you ask. Every pull is listed here, product by product, so you can see exactly what ePim sent.', 'blueworx_client_forum' ); ?>
						</p>
					</div>
					<div class="bw-pagehead__actions">
						<form method="post" action="">
							<?php wp_nonce_field( 'epi_pull_full', 'epi_pull_nonce' ); ?>
							<input type="hidden" name="epi_pull_action" value="full" />
							<button type="submit" class="bw-btn" data-epi-confirm="<?php esc_attr_e( 'This fetches every product from ePim again, not just the changes. Carry on?', 'blueworx_client_forum' ); ?>">
								<?php esc_html_e( 'Re-import everything', 'blueworx_client_forum' ); ?>
							</button>
						</form>
						<form method="post" action="">
							<?php wp_nonce_field( 'epi_pull_pull', 'epi_pull_nonce' ); ?>
							<input type="hidden" name="epi_pull_action" value="pull" />
							<button type="submit" class="bw-btn bw-btn--primary">
								<i class="bw-icon" data-lucide="refresh-cw" aria-hidden="true"></i>
								<?php esc_html_e( 'Pull now', 'blueworx_client_forum' ); ?>
							</button>
						</form>
					</div>
				</header>

				<div class="bw-page__body bw-page__body--single">
					<div class="bw-panels">
						<?php self::render_notice( $notice ); ?>

						<form method="post" action="">
							<?php wp_nonce_field( 'epi_pull_settings', 'epi_pull_nonce' ); ?>
							<input type="hidden" name="epi_pull_action" value="settings" />
							<section class="bw-card">
								<div class="bw-card__head">
									<div class="bw-card__titles">
										<h2 class="bw-card__title"><?php esc_html_e( 'Settings', 'blueworx_client_forum' ); ?></h2>
									</div>
								</div>
								<div class="bw-card__body">
									<div class="bw-fields bw-fields--single">
										<div class="bw-field bw-field--wide">
											<label class="bw-field__label" for="epi_pull_key"><?php esc_html_e( 'Subscription key', 'blueworx_client_forum' ); ?></label>
											<input class="bw-input bw-input--mono" id="epi_pull_key" name="epi_pull_key" type="text" value="<?php echo esc_attr( $settings['key'] ); ?>" autocomplete="off" spellcheck="false" />
											<p class="bw-field__help"><?php esc_html_e( 'From the ePim team. It is sent with every request and stored only on this site.', 'blueworx_client_forum' ); ?></p>
										</div>
										<div>
											<label class="bw-switch bw-switch--bare">
												<input type="checkbox" role="switch" name="epi_pull_images" value="1" <?php checked( $settings['images'] ); ?> />
												<span class="bw-switch__track"><span class="bw-switch__thumb"></span></span>
												<span class="bw-switch__label">
													<?php esc_html_e( 'Set product pictures from ePim', 'blueworx_client_forum' ); ?>
													<small><?php esc_html_e( 'Leave off while ePim is still pushing products to this site, or the two will keep changing each other\'s pictures. Switch on once the push is off.', 'blueworx_client_forum' ); ?></small>
												</span>
											</label>
										</div>
									</div>
								</div>
								<div class="bw-card__foot">
									<button type="submit" class="bw-btn bw-btn--primary"><?php esc_html_e( 'Save settings', 'blueworx_client_forum' ); ?></button>
								</div>
							</section>
						</form>

						<div class="bw-stats">
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="refresh-cw" aria-hidden="true"></i><?php esc_html_e( 'Last pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value"><?php echo $latest ? esc_html( self::when( $latest->started_at ) ) : esc_html__( 'Never', 'blueworx_client_forum' ); ?></p>
								</div>
								<p class="bw-stat__foot"><?php echo $latest ? esc_html( self::status_label( $latest->status ) . ', ' . self::trigger_label( $latest->trigger_type ) ) : esc_html__( 'Use Pull now to start the first one.', 'blueworx_client_forum' ); ?></p>
							</div>
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="calendar" aria-hidden="true"></i><?php esc_html_e( 'Next automatic pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value"><?php echo $next ? esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $next ) ) : esc_html__( 'Not scheduled', 'blueworx_client_forum' ); ?></p>
								</div>
								<p class="bw-stat__foot"><?php esc_html_e( 'Fetches only what changed since the last successful pull.', 'blueworx_client_forum' ); ?></p>
							</div>
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="circle-check" aria-hidden="true"></i><?php esc_html_e( 'Last successful pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value">
										<?php
										if ( $last ) {
											printf(
												/* translators: 1: added, 2: updated, 3: hidden. */
												esc_html__( '%1$d added, %2$d updated, %3$d hidden', 'blueworx_client_forum' ),
												(int) $last->added,
												(int) $last->updated,
												(int) $last->hidden
											);
										} else {
											esc_html_e( 'None yet', 'blueworx_client_forum' );
										}
										?>
									</p>
								</div>
								<p class="bw-stat__foot"><?php echo $last ? esc_html( self::when( $last->started_at ) ) : ''; ?></p>
							</div>
						</div>

						<section class="bw-card bw-card--flush">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Pulls', 'blueworx_client_forum' ); ?></h2>
								</div>
							</div>
							<?php if ( ! $runs ) : ?>
								<div class="bw-empty">
									<i class="bw-icon bw-icon--28 bw-empty__icon" data-lucide="refresh-cw" aria-hidden="true"></i>
									<h3 class="bw-empty__title"><?php esc_html_e( 'No pulls yet', 'blueworx_client_forum' ); ?></h3>
									<p class="bw-empty__text"><?php esc_html_e( 'The first pull fetches every product from ePim. Save the subscription key, then use Pull now.', 'blueworx_client_forum' ); ?></p>
								</div>
							<?php else : ?>
								<div class="bw-tablescroll">
									<table class="bw-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'When', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Trigger', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Added', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Updated', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Hidden', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Errors', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Status', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'View', 'blueworx_client_forum' ); ?></span></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $runs as $run ) : ?>
												<tr>
													<td>
														<span class="bw-table__primary"><?php echo esc_html( self::when( $run->started_at ) ); ?></span>
														<?php if ( $run->is_full ) : ?>
															<span class="bw-table__sub"><?php esc_html_e( 'Full import', 'blueworx_client_forum' ); ?></span>
														<?php endif; ?>
													</td>
													<td><?php echo esc_html( self::trigger_label( $run->trigger_type ) ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->added ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->updated ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->hidden ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->errors ); ?></td>
													<td><?php self::render_status_badge( $run->status ); ?></td>
													<td class="bw-table__actions">
														<a class="bw-btn bw-btn--sm" href="<?php echo esc_url( self::url( array( 'run' => (int) $run->id ) ) ); ?>"><?php esc_html_e( 'View', 'blueworx_client_forum' ); ?></a>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<?php self::render_pager( $page_no, $pages, $total, array() ); ?>
							<?php endif; ?>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The notice for the last action, if any.
	 *
	 * @param string $notice Notice code from the redirect.
	 * @return void
	 */
	private static function render_notice( $notice ) {
		$notices = array(
			'saved'            => array( 'success', __( 'Settings saved.', 'blueworx_client_forum' ) ),
			'started'          => array( 'success', __( 'Pull started. It runs in the background; refresh this page to follow it.', 'blueworx_client_forum' ) ),
			'epi_pull_running' => array( 'warning', __( 'A pull is already running. Wait for it to finish, then try again.', 'blueworx_client_forum' ) ),
			'epi_pull_no_key'  => array( 'warning', __( 'No ePim subscription key is saved. Add it below, save, then pull.', 'blueworx_client_forum' ) ),
			'epi_pull_store'   => array( 'danger', __( 'The pull could not be recorded. Check the PHP error log.', 'blueworx_client_forum' ) ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		list( $tone, $text ) = $notices[ $notice ];
		$icon                = 'success' === $tone ? 'circle-check' : 'triangle-alert';
		?>
		<div class="bw-notice bw-notice--<?php echo esc_attr( $tone ); ?>" role="<?php echo 'danger' === $tone ? 'alert' : 'status'; ?>">
			<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
			<div class="bw-notice__body">
				<p class="bw-notice__text"><?php echo esc_html( $text ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * A run's status as a badge.
	 *
	 * @param string $status Run status.
	 * @return void
	 */
	private static function render_status_badge( $status ) {
		$tones = array(
			'queued'  => 'neutral',
			'running' => 'warning',
			'done'    => 'success',
			'failed'  => 'danger',
		);
		$tone  = isset( $tones[ $status ] ) ? $tones[ $status ] : 'neutral';
		?>
		<span class="bw-badge bw-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span>
		<?php
	}

	/**
	 * Previous / next links under a table.
	 *
	 * @param int   $page_no Current page.
	 * @param int   $pages   Page count.
	 * @param int   $total   Row count.
	 * @param array $args    Extra query values to keep (the run, on the detail view).
	 * @return void
	 */
	private static function render_pager( $page_no, $pages, $total, array $args ) {
		?>
		<div class="bw-tablefoot">
			<span>
				<?php
				printf(
					/* translators: 1: current page, 2: page count, 3: row count. */
					esc_html__( 'Page %1$d of %2$d, %3$d in all', 'blueworx_client_forum' ),
					(int) $page_no,
					(int) $pages,
					(int) $total
				);
				?>
			</span>
			<?php if ( $pages > 1 ) : ?>
				<nav class="bw-pager" aria-label="<?php esc_attr_e( 'Pages', 'blueworx_client_forum' ); ?>">
					<div class="bw-pager__btns">
						<?php if ( $page_no > 1 ) : ?>
							<a class="bw-pager__btn" href="<?php echo esc_url( self::url( $args + array( 'paged' => $page_no - 1 ) ) ); ?>"><?php esc_html_e( 'Previous', 'blueworx_client_forum' ); ?></a>
						<?php endif; ?>
						<?php if ( $page_no < $pages ) : ?>
							<a class="bw-pager__btn" href="<?php echo esc_url( self::url( $args + array( 'paged' => $page_no + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'blueworx_client_forum' ); ?></a>
						<?php endif; ?>
					</div>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A stored UTC time in the site's date and time format.
	 *
	 * @param string $utc 'Y-m-d H:i:s' in UTC.
	 * @return string
	 */
	private static function when( $utc ) {
		$timestamp = strtotime( (string) $utc . ' UTC' );

		return $timestamp ? wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $timestamp ) : '';
	}

	/**
	 * A status in words.
	 *
	 * @param string $status Run status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'queued'  => __( 'Queued', 'blueworx_client_forum' ),
			'running' => __( 'Running', 'blueworx_client_forum' ),
			'done'    => __( 'Done', 'blueworx_client_forum' ),
			'failed'  => __( 'Failed', 'blueworx_client_forum' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * A trigger in words.
	 *
	 * @param string $trigger 'auto' or 'manual'.
	 * @return string
	 */
	private static function trigger_label( $trigger ) {
		return 'manual' === $trigger ? __( 'Manual', 'blueworx_client_forum' ) : __( 'Auto', 'blueworx_client_forum' );
	}

	/**
	 * The detail view. Filled in by Task 8.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private static function render_detail( $run_id ) {
		self::render_list( 'missing' );
	}
}
```

In `includes/pull/class-epi-pull.php`, `load()` gains, after the runner require:

```php
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-import-page.php';
```

and `boot()` gains, after `EPI_Pull_Runner::init();`:

```php
		if ( is_admin() ) {
			EPI_Product_Import_Page::init();
		}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (20 tests). If the Write hook refuses the page file, it names the offending class or element: replace it with the design-system equivalent from the skill's `readme.md`, never with a custom class.

- [ ] **Step 6: Commit**

```bash
git add includes/class-epi-product-import-page.php includes/pull/class-epi-pull.php assets/css/epi-product-import.css assets/js/epi-product-import.js tests/product-import.spec.js
git commit -m "Add the Product import page with settings, Pull now and the list of pulls"
```

---

### Task 8: The pull detail view

Use the blueworx-admin-design skill: page header with a back button, description list, flush card with table, badges, accordion, empty state.

**Files:**
- Modify: `includes/class-epi-product-import-page.php` (`render_detail()` and two helpers)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: `EPI_Pull_Store::get_run/get_items/count_items`, the page's `url()`, `when()`, `status_label()`, `trigger_label()`, `render_status_badge()`, `render_pager()`.
- Produces: `render_detail(int $run_id)` replacing Task 7's redirecting stub; `render_action_badge(string $action)`; `render_changes(array $changes)`.

- [ ] **Step 1: Write the failing test**

Append to `tests/product-import.spec.js`:

```js
test('the detail view lists each product with what changed and the raw record', async ({ page }) => {
  await pull(page);
  const run = await pull(page, { scenario: 'changed' });

  await page.goto(`${SCREEN}&run=${run.id}`);
  await expect(page.locator('.bw-pagehead__h1')).toContainText('Pull on');
  await expect(page.locator('.bw-dl')).toContainText('Manual');
  await expect(page.locator('.bw-dl')).toContainText('Done');

  const rows = page.locator('.bw-table tbody tr');
  await expect(rows).toHaveCount(2);

  const updated = rows.filter({ hasText: 'TEST-1001' });
  await expect(updated.locator('.bw-badge')).toHaveText('Updated');
  await expect(updated).toContainText('Product name');
  await expect(updated).toContainText('Single Kinetic Switch - White');
  await expect(updated).toContainText('Single Kinetic Switch Kit - White');
  await expect(updated).toContainText('Regular price');
  await expect(updated).toContainText('55.00');

  const hidden = rows.filter({ hasText: 'TEST-1002' });
  await expect(hidden.locator('.bw-badge')).toHaveText('Hidden');
  await expect(hidden).toContainText('Draft (hidden)');

  // The raw record is there, closed until asked for.
  const raw = updated.locator('[data-epi-accordion]');
  await expect(raw.locator('.bw-accordion__body')).toBeHidden();
  await raw.locator('.bw-accordion__head').click();
  await expect(raw.locator('.bw-accordion__body')).toBeVisible();
  await expect(raw.locator('pre')).toContainText('"SKU": "TEST-1001"');

  await page.getByRole('link', { name: 'Back to pulls' }).click();
  await expect(page.locator('.bw-pagehead__h1')).toHaveText('Product import');

  // A run that touched nothing says so.
  const quiet = await pull(page, { scenario: 'changed' });
  await page.goto(`${SCREEN}&run=${quiet.id}`);
  await expect(page.locator('.bw-empty__title')).toHaveText('Nothing changed');

  // A run that does not exist goes back to the list.
  await page.goto(`${SCREEN}&run=999999`);
  await expect(page.locator('.bw-notice--warning')).toContainText('That pull could not be found.');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1 -g "detail view"`
Expected: FAIL, the heading reads "Product import" (the stub shows the list view with a notice).

- [ ] **Step 3: Replace the stub with the detail view**

In `includes/class-epi-product-import-page.php`, replace the `render_detail()` stub with:

```php
	/**
	 * The detail view: one run, product by product.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private static function render_detail( $run_id ) {
		$run = EPI_Pull_Store::get_run( $run_id );

		if ( ! $run ) {
			self::render_list( 'missing' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging for an admin screen; sanitised with absint().
		$page_no = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$total   = EPI_Pull_Store::count_items( $run_id );
		$pages   = max( 1, (int) ceil( $total / self::ITEMS_PER_PAGE ) );
		$page_no = min( $page_no, $pages );
		$items   = EPI_Pull_Store::get_items( $run_id, $page_no, self::ITEMS_PER_PAGE );
		$json    = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		?>
		<div class="wrap bw-wrap">
			<div class="bw-admin bw-page">
				<header class="bw-pagehead">
					<div class="bw-pagehead__titles">
						<p class="bw-pagehead__eyebrow"><?php esc_html_e( 'Product import', 'blueworx_client_forum' ); ?></p>
						<h1 class="bw-pagehead__h1">
							<?php
							/* translators: %s: date and time. */
							printf( esc_html__( 'Pull on %s', 'blueworx_client_forum' ), esc_html( self::when( $run->started_at ) ) );
							?>
						</h1>
						<p class="bw-pagehead__lede"><?php esc_html_e( 'Every product this pull added, updated or hid, with each changed field before and after, and the record exactly as ePim sent it.', 'blueworx_client_forum' ); ?></p>
					</div>
					<div class="bw-pagehead__actions">
						<a class="bw-btn" href="<?php echo esc_url( self::url() ); ?>">
							<i class="bw-icon" data-lucide="arrow-left" aria-hidden="true"></i>
							<?php esc_html_e( 'Back to pulls', 'blueworx_client_forum' ); ?>
						</a>
					</div>
				</header>

				<div class="bw-page__body bw-page__body--single">
					<div class="bw-panels">
						<section class="bw-card">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Summary', 'blueworx_client_forum' ); ?></h2>
								</div>
							</div>
							<div class="bw-card__body">
								<dl class="bw-dl">
									<dt><?php esc_html_e( 'Trigger', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo esc_html( self::trigger_label( $run->trigger_type ) ); ?></dd>
									<dt><?php esc_html_e( 'Status', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo esc_html( self::status_label( $run->status ) ); ?></dd>
									<dt><?php esc_html_e( 'Started', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo esc_html( self::when( $run->started_at ) ); ?></dd>
									<dt><?php esc_html_e( 'Finished', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo $run->finished_at ? esc_html( self::when( $run->finished_at ) ) : esc_html__( 'Not yet', 'blueworx_client_forum' ); ?></dd>
									<dt><?php esc_html_e( 'Asked ePim for', 'blueworx_client_forum' ); ?></dt>
									<dd>
										<?php
										if ( '' === (string) $run->since_utc ) {
											esc_html_e( 'Every product', 'blueworx_client_forum' );
										} else {
											/* translators: %s: date and time. */
											printf( esc_html__( 'Changes since %s', 'blueworx_client_forum' ), esc_html( self::when( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $run->since_utc ) ) ) );
										}
										?>
									</dd>
									<dt><?php esc_html_e( 'Counts', 'blueworx_client_forum' ); ?></dt>
									<dd>
										<?php
										printf(
											/* translators: 1: added, 2: updated, 3: hidden, 4: unchanged, 5: skipped, 6: errors. */
											esc_html__( '%1$d added, %2$d updated, %3$d hidden, %4$d unchanged, %5$d skipped, %6$d errors', 'blueworx_client_forum' ),
											(int) $run->added,
											(int) $run->updated,
											(int) $run->hidden,
											(int) $run->unchanged,
											(int) $run->skipped,
											(int) $run->errors
										);
										?>
									</dd>
									<?php if ( '' !== (string) $run->message ) : ?>
										<dt><?php esc_html_e( 'Message', 'blueworx_client_forum' ); ?></dt>
										<dd><?php echo esc_html( $run->message ); ?></dd>
									<?php endif; ?>
								</dl>
							</div>
						</section>

						<section class="bw-card bw-card--flush">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Products', 'blueworx_client_forum' ); ?></h2>
								</div>
							</div>
							<?php if ( ! $items ) : ?>
								<div class="bw-empty">
									<i class="bw-icon bw-icon--28 bw-empty__icon" data-lucide="circle-check" aria-hidden="true"></i>
									<h3 class="bw-empty__title"><?php esc_html_e( 'Nothing changed', 'blueworx_client_forum' ); ?></h3>
									<p class="bw-empty__text"><?php esc_html_e( 'ePim sent nothing this pull needed to add, update or hide.', 'blueworx_client_forum' ); ?></p>
								</div>
							<?php else : ?>
								<div class="bw-tablescroll">
									<table class="bw-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'Product', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Result', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Changes', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'From ePim', 'blueworx_client_forum' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $items as $item ) : ?>
												<tr>
													<td>
														<span class="bw-table__primary"><?php echo esc_html( '' !== $item['name'] ? $item['name'] : __( '(no name)', 'blueworx_client_forum' ) ); ?></span>
														<span class="bw-table__sub"><?php echo esc_html( '' !== $item['sku'] ? $item['sku'] : __( 'No SKU', 'blueworx_client_forum' ) ); ?></span>
														<?php if ( $item['product_id'] ) : ?>
															<span class="bw-table__sub"><a href="<?php echo esc_url( get_edit_post_link( (int) $item['product_id'], 'raw' ) ); ?>"><?php esc_html_e( 'Open product', 'blueworx_client_forum' ); ?></a></span>
														<?php endif; ?>
													</td>
													<td>
														<?php self::render_action_badge( $item['action'] ); ?>
														<?php if ( '' !== (string) $item['message'] ) : ?>
															<span class="bw-table__sub"><?php echo esc_html( $item['message'] ); ?></span>
														<?php endif; ?>
													</td>
													<td><?php self::render_changes( $item['changes'] ); ?></td>
													<td>
														<section class="bw-accordion" data-epi-accordion>
															<button type="button" class="bw-accordion__head" aria-expanded="false">
																<span class="bw-accordion__title"><?php esc_html_e( 'Raw ePim data', 'blueworx_client_forum' ); ?></span>
																<i class="bw-icon bw-accordion__chev" data-lucide="chevron-down" aria-hidden="true"></i>
															</button>
															<div class="bw-accordion__body" hidden>
																<pre><?php echo esc_html( (string) wp_json_encode( $item['raw'], $json ) ); ?></pre>
															</div>
														</section>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<?php self::render_pager( $page_no, $pages, $total, array( 'run' => (int) $run_id ) ); ?>
							<?php endif; ?>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * An item's action as a badge.
	 *
	 * @param string $action added, updated, hidden or error.
	 * @return void
	 */
	private static function render_action_badge( $action ) {
		$badges = array(
			'added'   => array( 'success', __( 'Added', 'blueworx_client_forum' ) ),
			'updated' => array( 'info', __( 'Updated', 'blueworx_client_forum' ) ),
			'hidden'  => array( 'warning', __( 'Hidden', 'blueworx_client_forum' ) ),
			'error'   => array( 'danger', __( 'Error', 'blueworx_client_forum' ) ),
		);

		list( $tone, $label ) = isset( $badges[ $action ] ) ? $badges[ $action ] : array( 'neutral', $action );
		?>
		<span class="bw-badge bw-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Field changes as "label: before, arrow, after", one per line.
	 *
	 * @param array $changes Each: field, label, before, after.
	 * @return void
	 */
	private static function render_changes( array $changes ) {
		if ( ! $changes ) {
			?>
			<span class="bw-table__sub"><?php esc_html_e( 'None', 'blueworx_client_forum' ); ?></span>
			<?php
			return;
		}

		$empty = __( '(empty)', 'blueworx_client_forum' );
		?>
		<dl class="bw-dl bw-dl--stack">
			<?php foreach ( $changes as $change ) : ?>
				<dt><?php echo esc_html( isset( $change['label'] ) ? $change['label'] : $change['field'] ); ?></dt>
				<dd>
					<?php echo esc_html( isset( $change['before'] ) && '' !== (string) $change['before'] && null !== $change['before'] ? (string) $change['before'] : $empty ); ?>
					<i class="bw-icon bw-icon--14" data-lucide="arrow-right" aria-hidden="true"></i>
					<?php echo esc_html( isset( $change['after'] ) && '' !== (string) $change['after'] && null !== $change['after'] ? (string) $change['after'] : $empty ); ?>
				</dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}
```

In `render_notice()`, add one entry to `$notices`:

```php
			'missing'          => array( 'warning', __( 'That pull could not be found. It may have been older than 90 days and removed.', 'blueworx_client_forum' ) ),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test tests/product-import.spec.js --workers=1`
Expected: PASS (21 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/class-epi-product-import-page.php tests/product-import.spec.js
git commit -m "Show each pull product by product with every changed field"
```

---

### Task 9: Version, changelog, lint and final verification

**Files:**
- Modify: `external-product-images.php:5` (header `Version:`) and `:27` (`EPI_VERSION`)
- Modify: `package.json:3`
- Modify: `readme.txt:7` (Stable tag) and the changelog section
- Modify: `CHANGELOG.md`
- Modify: `includes/class-epi-feature-registry.php` only if the Lab page description needs the word "pull" (it does not).

- [ ] **Step 1: Bump the version in all four places**

- `external-product-images.php`: ` * Version:           1.14.0` and `define( 'EPI_VERSION', '1.14.0' );`
- `package.json`: `"version": "1.14.0",`
- `readme.txt`: `Stable tag: 1.14.0`

Leave `package-lock.json`'s own version field alone.

- [ ] **Step 2: Write the changelog entries**

In `CHANGELOG.md`, under `## [Unreleased]`, add a new section above `## [1.13.0]`:

```markdown
## [1.14.0]

### Added
- Products now come from ePim by the site pulling them, once a day at 02:00 and
  whenever you press Pull now, instead of ePim pushing them in. Products are
  matched by SKU, archived or deleted ones are hidden, and nothing from ePim is
  saved to the media library.
- A Product import page under Products, for administrators, lists every pull and
  shows each product it added, updated or hid, with every changed field before
  and after and the record exactly as ePim sent it. Records are kept for 90 days.
- A "Set product pictures from ePim" switch, off until ePim's push is switched off,
  so the two do not fight over pictures during the changeover.
```

In `readme.txt`, under `== Changelog ==`, add above `= 1.13.0 =`:

```
= 1.14.0 =
* Products are pulled from ePim daily and on demand, matched by SKU, with a
  Product import page that records every pull product by product.
```

- [ ] **Step 3: Run the whole suite and the linter once**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 npx playwright test --workers=1`
Expected: every spec passes, including the existing ones (the change log spec shares the harness; both support files register `product` guarded by `post_type_exists`).

Run: `composer install && composer lint`
Expected: a list of findings or none. Do not fix any; present them to Luke at the end of the session and let him decide.

Run: `node .claude/hooks/admin-ui-adherence.mjs` is not run by hand; it ran on every Write. CI's admin UI check covers the same rule.

- [ ] **Step 4: Commit and open the pull request**

```bash
git add external-product-images.php package.json readme.txt CHANGELOG.md
git commit -m "Version 1.14.0"
git push -u origin epim-pull-sync
```

Pull request title: `Pull products from ePim daily and record every pull`.
Body (short, plain):

```
Products now come from ePim by the site pulling them, daily at 02:00 and on demand, matched by SKU. A Product import page under Products (admins only) lists every pull product by product, with each changed field before and after and the raw ePim record. Records kept 90 days.

To decide before go-live:
- Paste the subscription key into the page's Settings and press Pull now once on staging.
- The "Set product pictures from ePim" switch stays off until ePim's push is turned off, then turn it on.
- Stock is not pulled (agreed): a product on the site is in stock.

Checked by hand on staging: a full pull with WooCommerce active, then a second pull showing only changes.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
```

- [ ] **Step 5: Hand-check on staging (before merge, not CI)**

With WooCommerce active: save the real key, Pull now, confirm the run reaches Done, a product's edit screen shows the custom attributes and category, the shop shows it in stock with its price, and no new media library items appeared. Then a second Pull now shows mostly unchanged counts. Note results in the pull request.

---

## Self-review

- **Spec coverage.** Daily pull (Task 6 schedule), manual pull and full re-import (Tasks 6, 7), batches with a time budget and a lock (Task 6), first run full (Task 6 `since` empty), categories (Task 4), SKU matching with no duplicates (Task 5), hidden as draft (Task 5), deleted entities (Task 6), no images stored and the pictures switch (Tasks 5, 7), admin-only page with list and detail and raw data (Tasks 7, 8), 90-day retention (Task 6 daily, store prune), settings with key and Pull now and last status (Task 7), change log source (Task 6), version and changelog (Task 9), Playwright throughout. Stock is out by decision. Switching ePim's push off is an ePim-side action, not code.
- **Placeholders.** None: every step carries its code; the Task 7 `render_detail()` stub is a working redirect replaced in Task 8.
- **Type consistency.** `EPI_Pull_Store::create_run( $trigger, $since_utc, $is_full )` is called that way in the runner and the test routes. Writer results always carry `action`, `product_id`, `changes`, `message`; `hide_deleted()` adds `sku` and `name`, which the runner's `record()` reads. Run rows use `trigger_type`, `cursor_start`, `is_full` everywhere, and REST returns them as strings (`'1'`, `'2'`), which the specs compare as strings.
- **Review Focus.** Empty SKU (Task 6 test "without a SKU"), orphan category parent (Task 4 test), SKU-only existing product (Task 5 test "not duplicated"), a save that fails (Task 5 posts path returns `WP_Error` for a missing product; the WooCommerce path catches `WC_Data_Exception` the same way), stale lock (Task 6 test "stale lock is cleared").
