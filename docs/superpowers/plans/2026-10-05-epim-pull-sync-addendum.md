# ePim Pull: Test Mode and Category Pulls Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a test mode that records what a pull would change without changing anything, and a "pull one category" action, to the ePim pull built by the main plan.

**Architecture:** Two run flags (`is_test`, `category_id`) carried on the run row and threaded through the runner; the writer and category syncer gain a dry-run argument; the admin page gains a test-mode switch and notice, a category card, and markers on the runs table and detail view. Everything else stays as built.

**Tech Stack:** as the main plan.

**Spec:** `docs/superpowers/specs/2026-10-05-epim-pull-sync-design.md` (the Addendum section is binding for this plan). Main plan: `docs/superpowers/plans/2026-10-05-epim-pull-sync.md`.

## Global Constraints

- Branch `epim-pull-sync`, HEAD d1d74f5 at the time of writing. Version stays `1.14.0` (unreleased); the changelog entry is extended, not duplicated.
- All conventions of the main plan: tabs, text domain `blueworx_client_forum`, prefixes `EPI_`/`epi_`, admin markup only from `bw-*` classes, no new dependency, lint once at the end and present findings (Luke has approved fixing the four from the first run and whatever new alignment warnings the page file has).
- Test mode default is ON for a fresh install or update: `EPI_Pull_Settings::get()` returns `test => true` when the option has no `test` key.
- Test runs and category runs never become the baseline: `EPI_Pull_Store::last_successful_run()` returns only runs with `status = 'done' AND is_test = 0 AND category_id = 0`.
- In test mode nothing is written: no product save, no `_epim_*` stamp, no category created or renamed, no `epi_pull_category_map` change beyond what existing terms already match.
- A category pull is always a full fetch (`is_full` 1, empty `since_utc`), applies only records whose `ProductCategoryIds` intersect the chosen category and its descendants, and skips the deleted stage.
- Commit messages end with `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`.

## Review Focus

1. **Test mode on, product does not exist yet.** Must report `added` with the full change list and create nothing. Pinned in Task 10.
2. **Category pull for a parent category.** Must include products in child categories. Pinned in Task 11.
3. **Category pull, then a normal pull.** The normal pull must still be the first full import (baseline untouched). Pinned in Task 11.
4. **Test mode with the pictures switch on.** Must not write pictures either. Covered by the "changes nothing" assertion in Task 10 (product never created).
5. **Switching test mode off after a test run.** The next pull must be a real full import. Pinned in Task 10.

---

### Task 10: Test mode

**Files:**
- Modify: `includes/pull/class-epi-pull.php` (`DB_VERSION` to `'1.1'`)
- Modify: `includes/pull/class-epi-pull-store.php` (schema, `create_run()`, `last_successful_run()`)
- Modify: `includes/pull/class-epi-pull-settings.php` (`test` setting)
- Modify: `includes/pull/class-epi-pull-categories.php` (`sync()` dry run)
- Modify: `includes/pull/class-epi-pull-writer.php` (`apply()`, `hide_deleted()` dry run)
- Modify: `includes/pull/class-epi-pull-runner.php` (`start()` options, `step()`, `apply()`)
- Modify: `includes/class-epi-product-import-page.php` (settings switch, notice, table and detail markers)
- Modify: `tests/support/epi-test-epim.php` (`/pull/reset`, `/pull/settings`, `/pull/settings-default`, `/pull/start` and `/pull/pull` accept `category_id` for Task 11 too)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Produces: `EPI_Pull_Settings::test_mode(): bool`; `EPI_Pull_Store::create_run(string $trigger, string $since_utc, bool $is_full, array $options = array())` with `is_test`, `category_id`, `category_name`; `EPI_Pull_Categories::sync(array $categories, bool $dry_run = false)`; `EPI_Pull_Writer::apply(array $product, array $map, bool $images, bool $dry_run = false)` and `hide_deleted(array $entry, bool $dry_run = false)`; `EPI_Pull_Runner::start(string $trigger, bool $full = false, array $options = array())` where `$options['category_id']` is used by Task 11.

- [ ] **Step 1: Write the failing tests**

Append to `tests/product-import.spec.js`:

```js
test('test mode is on by default after install', async ({ page }) => {
  const defaults = await api(page, 'GET', '/settings-default');
  expect(defaults.test).toBe(true);
  expect(defaults.images).toBe(false);
});

test('a test pull records what it would add and creates nothing', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true, images: true });
  const run = await pull(page);
  expect(run).toMatchObject({ status: 'done', is_test: '1', added: '2', skipped: '1' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBe(0);
  expect((await api(page, 'GET', '/product/TEST-1002')).id).toBe(0);

  const items = await api(page, 'GET', `/runs/${run.id}/items`);
  expect(items.map((i) => i.action)).toEqual(['added', 'added']);
  expect(items[0].changes.map((c) => c.field)).toEqual(expect.arrayContaining(['name', 'price', 'image']));

  // The test run is not a baseline: switching test mode off, the next pull is the real full import.
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: false });
  const real = await pull(page);
  expect(real).toMatchObject({ status: 'done', is_test: '0', is_full: '1', since_utc: '', added: '2' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBeGreaterThan(0);
});

test('a test pull against existing products reports updates without applying them', async ({ page }) => {
  await pull(page);
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true });
  const run = await pull(page, { scenario: 'changed' });
  expect(run).toMatchObject({ status: 'done', is_test: '1', updated: '1', hidden: '1' });

  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product.title).toBe('Single Kinetic Switch - White');
  expect(product.price).toBe('51.25');
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('publish');

  const deleted = await pull(page, { scenario: 'deleted' });
  expect(deleted).toMatchObject({ is_test: '1', hidden: '2' });
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('publish');
});

test('the page shows test mode and marks test runs', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true });
  const run = await pull(page);

  await page.goto(SCREEN);
  await expect(page.locator('.bw-notice--info')).toContainText('Test mode is on');
  await expect(page.locator('input[name="epi_pull_test"]')).toBeChecked();
  await expect(page.locator('.bw-table tbody tr').first()).toContainText('Test');

  await page.goto(`${SCREEN}&run=${run.id}`);
  await expect(page.locator('.bw-notice--info')).toContainText('nothing on the site was changed');
  await expect(page.locator('.bw-dl:not(.bw-dl--stack)')).toContainText('Test');

  await page.goto(SCREEN);
  await page.locator('input[name="epi_pull_test"]').uncheck();
  await page.getByRole('button', { name: 'Save settings' }).click();
  await expect(page.locator('.bw-notice--info')).toHaveCount(0);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test tests/product-import.spec.js --workers=1 --timeout=240000 -g "test mode|test pull"`
Expected: FAIL (`GET /settings-default: 404` first).

- [ ] **Step 3: Settings and schema**

`class-epi-pull-settings.php`: in `get()` add `'test' => ! array_key_exists( 'test', $saved ) || ! empty( $saved['test'] ),` (absent means on); in `save()` add `if ( array_key_exists( 'test', $values ) ) { $current['test'] = ! empty( $values['test'] ); }`; add:

```php
	/**
	 * Whether pulls only report what they would change. On until switched off.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		$settings = self::get();

		return $settings['test'];
	}
```

`class-epi-pull.php`: `const DB_VERSION = '1.1';`

`class-epi-pull-store.php`: in the runs `CREATE TABLE`, after `since_utc`, add

```
			is_test tinyint(1) NOT NULL DEFAULT 0,
			category_id bigint(20) unsigned NOT NULL DEFAULT 0,
			category_name varchar(191) NOT NULL DEFAULT '',
```

`create_run()` becomes:

```php
	public static function create_run( $trigger, $since_utc, $is_full, array $options = array() ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$result = $wpdb->insert(
			self::runs_table(),
			array(
				'trigger_type'  => 'manual' === $trigger ? 'manual' : 'auto',
				'status'        => 'queued',
				'is_full'       => $is_full ? 1 : 0,
				'since_utc'     => (string) $since_utc,
				'is_test'       => empty( $options['is_test'] ) ? 0 : 1,
				'category_id'   => isset( $options['category_id'] ) ? absint( $options['category_id'] ) : 0,
				'category_name' => isset( $options['category_name'] ) ? sanitize_text_field( $options['category_name'] ) : '',
				'started_at'    => current_time( 'mysql', true ),
				'stage'         => 'categories',
			),
			array( '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		…unchanged…
```

`last_successful_run()` query: `"SELECT * FROM {$table} WHERE status = 'done' AND is_test = 0 AND category_id = 0 ORDER BY id DESC LIMIT 1"` with the docblock saying test runs and category pulls are not a baseline.

- [ ] **Step 4: Dry run in the categories syncer and the writer**

`class-epi-pull-categories.php`: `sync( array $categories, $dry_run = false )` passes `$dry_run` to `ensure_term( $name, $parent, $epim_id, $dry_run )`. In `ensure_term()`: in the found-by-meta branch, skip the `wp_update_term()` when `$dry_run`; after `term_exists()`, when `$dry_run` return `$existing ? (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ) : 0` before any insert or meta write. Docblock: "In a dry run only terms that already exist are matched; nothing is created, renamed or tagged."

`class-epi-pull-writer.php`: `apply( array $product, array $category_map, $images, $dry_run = false )`. Both `self::stamp( $id, $product )` calls become `if ( ! $dry_run ) { self::stamp( $id, $product ); }`. Replace the save block with:

```php
		$action = ! $id ? 'added' : ( $product['hidden'] ? 'hidden' : 'updated' );

		// A test pull reports what it would do and stops here.
		if ( $dry_run ) {
			return self::result( $action, (int) $id, $changes, '' );
		}

		$saved = self::write( $id, $wanted, $product );

		if ( is_wp_error( $saved ) ) {
			return self::result( 'error', (int) $id, $changes, $saved->get_error_message() );
		}

		return self::result( $action, (int) $saved, $changes, '' );
```

`hide_deleted( array $entry, $dry_run = false )`: inside the loop, when `$dry_run`, build `$result = self::result( 'hidden', $id, $changes, '' )` without calling `write()` or stamping.

- [ ] **Step 5: The runner**

`start( $trigger, $full = false, array $options = array() )`. After the lock is taken:

```php
		$category_id = isset( $options['category_id'] ) ? absint( $options['category_id'] ) : 0;
		$is_test     = EPI_Pull_Settings::test_mode();

		// A category pull always reads the whole list, then keeps only its category.
		if ( $category_id ) {
			$full = true;
		}

		$last  = $full ? null : EPI_Pull_Store::last_successful_run();
		$since = $last ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $last->started_at . ' UTC' ) - 5 * MINUTE_IN_SECONDS ) : '';

		$run_id = EPI_Pull_Store::create_run(
			$trigger,
			$since,
			! $last,
			array(
				'is_test'       => $is_test,
				'category_id'   => $category_id,
				'category_name' => $category_id ? self::category_name( $category_id ) : '',
			)
		);
```

Add:

```php
	/**
	 * The site's name for an ePim category, for the runs table.
	 *
	 * @param int $epim_id ePim category ID.
	 * @return string
	 */
	public static function category_name( $epim_id ) {
		$term = EPI_Pull_Categories::term_for( $epim_id );

		return $term instanceof WP_Term ? $term->name : '#' . absint( $epim_id );
	}
```

and in `class-epi-pull-categories.php`:

```php
	/**
	 * The term for an ePim category ID, or null.
	 *
	 * @param int $epim_id ePim category ID.
	 * @return WP_Term|null
	 */
	public static function term_for( $epim_id ) {
		$found = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				'orderby'    => 'name',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One term per ePim ID.
				'meta_value' => (string) absint( $epim_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( is_wp_error( $found ) || ! $found ) {
			return null;
		}

		return $found[0] instanceof WP_Term ? $found[0] : null;
	}
```

(and make the existing found-by-meta lookup in `ensure_term()` use `term_for()` so the query lives once.)

In `step()`: categories stage uses `EPI_Pull_Categories::sync( $categories, (bool) $run->is_test )`; products stage calls `self::apply( (int) $run->id, $raw, (bool) $run->is_test )`; deleted stage calls `EPI_Pull_Writer::hide_deleted( $entry, (bool) $run->is_test )`. `apply( $run_id, array $raw, $dry_run )` passes `$dry_run` as the writer's fourth argument. (Task 11 adds the category filter to the products stage.)

- [ ] **Step 6: The page**

`handle_actions()` settings branch adds `'test' => ! empty( $_POST['epi_pull_test'] ),`.

In `render_list()`, directly after `self::render_notice( $notice );`:

```php
						<?php if ( $settings['test'] ) : ?>
							<div class="bw-notice bw-notice--info" role="status">
								<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="info" aria-hidden="true"></i>
								<div class="bw-notice__body">
									<p class="bw-notice__title"><?php esc_html_e( 'Test mode is on', 'blueworx_client_forum' ); ?></p>
									<p class="bw-notice__text"><?php esc_html_e( 'Pulls record what they would add, update or hide, and change nothing. Switch it off in Settings when you are happy with what you see.', 'blueworx_client_forum' ); ?></p>
								</div>
							</div>
						<?php endif; ?>
```

In the Settings card, before the pictures switch `<div>`, add the same switch markup with `name="epi_pull_test"`, `checked( $settings['test'] )`, label "Test mode" and small text "Pulls report what they would change and change nothing. Start here, then switch off once a test pull looks right."

Runs table Status cell: after the status badge, `<?php if ( $run->is_test ) : ?> <span class="bw-badge bw-badge--neutral"><?php esc_html_e( 'Test', 'blueworx_client_forum' ); ?></span><?php endif; ?>`.

Detail view: before the Summary card, when `$run->is_test`, the same info notice with title "Test pull" and text "This was a test pull: nothing on the site was changed. Each row shows what a real pull would do." In the summary `dl`, after Status add `<dt>Mode</dt><dd>` "Test (nothing changed)" or "Live" `</dd>`.

- [ ] **Step 7: Test support**

`/pull/reset`: the settings line becomes `update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false, 'test' => false ), false );` (existing tests are real pulls). `/pull/settings`: pass `'test' => ! empty( $request['test'] )` only when the request has a `test` key (`$request->has_param( 'test' )`), same for `images`, so a call that sets only the key keeps the rest. New route:

```php
		// What a fresh install gets: the option deleted, then read back.
		register_rest_route(
			'epi-test/v1',
			'/pull/settings-default',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function () {
					delete_option( 'epi_pull_settings' );
					$settings = EPI_Pull_Settings::get();
					update_option( 'epi_pull_settings', array( 'key' => EPI_TEST_EPIM_KEY, 'images' => false, 'test' => false ), false );
					return $settings;
				},
			)
		);
```

`/pull/start` and `/pull/pull`: pass `array( 'category_id' => (int) $request['category_id'] )` as the third argument to `EPI_Pull_Runner::start()`.

- [ ] **Step 8: Run the whole spec**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test tests/product-import.spec.js --workers=1 --timeout=240000`
Expected: PASS (33 tests). The schema upgrade runs on the first request after the version constant changes (dbDelta adds the three columns).

- [ ] **Step 9: Commit**

```bash
git add includes tests/support/epi-test-epim.php tests/product-import.spec.js
git commit -m "Add a test mode that reports what a pull would change" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 11: Pull one category

**Files:**
- Modify: `includes/pull/class-epi-pull-categories.php` (`choices()`, `scope()`)
- Modify: `includes/pull/class-epi-pull-runner.php` (`refresh_categories()`, products-stage filter, skip deleted stage)
- Modify: `includes/class-epi-product-import-page.php` (card, two actions, notices, table sub-line, detail row)
- Modify: `tests/product-import.spec.js`

**Interfaces:**
- Consumes: Task 10's `start()` options and run columns.
- Produces: `EPI_Pull_Categories::choices(): array` (ePim id => "Parent › Child" label, sorted by label); `EPI_Pull_Categories::scope(int $epim_id): int[]` (the id plus every descendant's ePim id); `EPI_Pull_Runner::refresh_categories(): int|WP_Error` (count of categories mapped); page actions `refresh` and `category` (field `epi_pull_category`), notices `categories`, `nocategory`, `error` (message in transient `epi_pull_last_error`).

- [ ] **Step 1: Write the failing tests**

Append to `tests/product-import.spec.js`:

```js
test('refreshing categories from the page creates the terms and offers them', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('#epi_pull_category option')).toHaveCount(1); // the placeholder only

  await page.getByRole('button', { name: 'Refresh categories from ePim' }).click();
  await expect(page.locator('.bw-notice--success')).toContainText('Categories refreshed from ePim.');

  const labels = await page.locator('#epi_pull_category option').allTextContents();
  expect(labels.slice(1)).toEqual(['Decorative', 'Lighting controls', 'Lighting controls › Kinetic switches']);
});

test('pulling one category touches only its products, including subcategories', async ({ page }) => {
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Refresh categories from ePim' }).click();

  // Lighting controls is the parent of Kinetic switches, which holds TEST-1001.
  await page.locator('#epi_pull_category').selectOption({ label: 'Lighting controls' });
  await page.getByRole('button', { name: 'Pull this category' }).click();
  await expect(page.locator('.bw-notice--success')).toContainText('Pull started.');

  const runs = await api(page, 'GET', '/runs');
  const run = await api(page, 'POST', '/drain', { run_id: Number(runs[0].id) });
  expect(run).toMatchObject({ status: 'done', added: '1', skipped: '0', category_name: 'Lighting controls' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBeGreaterThan(0);
  expect((await api(page, 'GET', '/product/TEST-1002')).id).toBe(0);

  await page.goto(SCREEN);
  await expect(page.locator('.bw-table tbody tr').first()).toContainText('Lighting controls');

  // A category pull is not a baseline: the next normal pull is still the first full import.
  const next = await pull(page);
  expect(next).toMatchObject({ is_full: '1', since_utc: '', added: '1', unchanged: '1' });
});

test('pulling with no category chosen says so', async ({ page }) => {
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Pull this category' }).click();
  await expect(page.locator('.bw-notice--warning')).toContainText('Choose a category first.');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `… -g "categories from the page|one category|no category chosen"`
Expected: FAIL (no "Refresh categories from ePim" button).

- [ ] **Step 3: Category helpers**

In `class-epi-pull-categories.php`:

```php
	/**
	 * Every ePim-tagged category as a choice: ePim ID => "Parent › Child".
	 *
	 * @return array Sorted by label.
	 */
	public static function choices() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The tagged categories are the whole list.
			)
		);

		$choices = array();

		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$epim_id = absint( get_term_meta( $term->term_id, self::META, true ) );

			if ( ! $epim_id ) {
				continue;
			}

			$path = array( $term->name );

			foreach ( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'product_cat' );

				if ( $ancestor instanceof WP_Term ) {
					array_unshift( $path, $ancestor->name );
				}
			}

			$choices[ $epim_id ] = implode( ' › ', $path );
		}

		natcasesort( $choices );

		return $choices;
	}

	/**
	 * An ePim category and every category under it, as ePim IDs.
	 *
	 * @param int $epim_id ePim category ID.
	 * @return int[]
	 */
	public static function scope( $epim_id ) {
		$epim_id = absint( $epim_id );
		$ids     = array( $epim_id );
		$term    = self::term_for( $epim_id );

		if ( $term instanceof WP_Term ) {
			$children = get_term_children( $term->term_id, 'product_cat' );

			foreach ( is_wp_error( $children ) ? array() : $children as $child_id ) {
				$child_epim = absint( get_term_meta( $child_id, self::META, true ) );

				if ( $child_epim ) {
					$ids[] = $child_epim;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}
```

- [ ] **Step 4: The runner**

Add:

```php
	/**
	 * Fetch ePim's categories now and bring the site's into line.
	 *
	 * @return int|WP_Error How many categories were matched or made.
	 */
	public static function refresh_categories() {
		$categories = EPI_Pull_Client::categories();

		if ( is_wp_error( $categories ) ) {
			return $categories;
		}

		$map = EPI_Pull_Categories::sync( $categories );
		update_option( self::MAP_OPTION, $map, false );

		return count( $map );
	}
```

In `step()`, products stage: before the `foreach`, `$scope = (int) $run->category_id ? EPI_Pull_Categories::scope( (int) $run->category_id ) : array();`. Inside the loop, before `self::apply(...)`:

```php
					if ( $scope ) {
						$record_categories = isset( $raw['ProductCategoryIds'] ) && is_array( $raw['ProductCategoryIds'] ) ? array_map( 'absint', $raw['ProductCategoryIds'] ) : array();

						// A category pull leaves everything outside the category untouched and uncounted.
						if ( ! array_intersect( $record_categories, $scope ) ) {
							continue;
						}
					}
```

Where the products stage moves to the deleted stage (`'stage' => 'deleted'`), first: `if ( (int) $run->category_id ) { return 'done'; }` with a comment that a category pull does not act on deletions.

- [ ] **Step 5: The page**

`handle_actions()`: two new branches.

```php
		} elseif ( 'refresh' === $action ) {
			$count = EPI_Pull_Runner::refresh_categories();

			if ( is_wp_error( $count ) ) {
				set_transient( 'epi_pull_last_error', $count->get_error_message(), MINUTE_IN_SECONDS );
				$notice = 'error';
			} else {
				$notice = 'categories';
			}
		} elseif ( 'category' === $action ) {
			$category_id = isset( $_POST['epi_pull_category'] ) ? absint( wp_unslash( $_POST['epi_pull_category'] ) ) : 0;

			if ( ! $category_id ) {
				$notice = 'nocategory';
			} else {
				$started = EPI_Pull_Runner::start( 'manual', true, array( 'category_id' => $category_id ) );
				$notice  = is_wp_error( $started ) ? $started->get_error_code() : 'started';
			}
		}
```

`render_notice()` table gains:

```php
			'categories' => array( 'success', __( 'Categories refreshed from ePim.', 'blueworx_client_forum' ) ),
			'nocategory' => array( 'warning', __( 'Choose a category first.', 'blueworx_client_forum' ) ),
			'error'      => array( 'danger', (string) get_transient( 'epi_pull_last_error' ) ),
```

(and after rendering an `error` notice, `delete_transient( 'epi_pull_last_error' )`; if the transient is empty, render nothing). Realign the array's arrows across the whole table while here (the lint warnings).

In `render_list()`, after the Settings card's closing `</form>`, add the card:

```php
						<section class="bw-card">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Pull one category', 'blueworx_client_forum' ); ?></h2>
								</div>
								<div class="bw-card__actions">
									<form method="post" action="">
										<?php wp_nonce_field( 'epi_pull_refresh', 'epi_pull_nonce' ); ?>
										<input type="hidden" name="epi_pull_action" value="refresh" />
										<button type="submit" class="bw-btn bw-btn--sm">
											<i class="bw-icon bw-icon--14" data-lucide="refresh-cw" aria-hidden="true"></i>
											<?php esc_html_e( 'Refresh categories from ePim', 'blueworx_client_forum' ); ?>
										</button>
									</form>
								</div>
							</div>
							<form method="post" action="">
								<?php wp_nonce_field( 'epi_pull_category', 'epi_pull_nonce' ); ?>
								<input type="hidden" name="epi_pull_action" value="category" />
								<div class="bw-card__body">
									<div class="bw-fields">
										<div class="bw-field">
											<label class="bw-field__label" for="epi_pull_category"><?php esc_html_e( 'Category', 'blueworx_client_forum' ); ?></label>
											<span class="bw-select">
												<select class="bw-select__el" id="epi_pull_category" name="epi_pull_category">
													<option value=""><?php esc_html_e( 'Choose a category', 'blueworx_client_forum' ); ?></option>
													<?php foreach ( EPI_Pull_Categories::choices() as $epim_id => $label ) : ?>
														<option value="<?php echo esc_attr( (string) $epim_id ); ?>"><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
												<i class="bw-icon bw-icon--14 bw-select__arrow" data-lucide="chevron-down" aria-hidden="true"></i>
											</span>
											<p class="bw-field__help"><?php esc_html_e( 'Covers the category and everything under it. Refresh first so new ePim categories appear here.', 'blueworx_client_forum' ); ?></p>
										</div>
									</div>
								</div>
								<div class="bw-card__foot">
									<button type="submit" class="bw-btn bw-btn--primary"><?php esc_html_e( 'Pull this category', 'blueworx_client_forum' ); ?></button>
								</div>
							</form>
						</section>
```

Runs table Trigger cell: `<?php echo esc_html( self::trigger_label( $run->trigger_type ) ); ?><?php if ( '' !== (string) $run->category_name ) : ?><span class="bw-table__sub"><?php echo esc_html( $run->category_name ); ?></span><?php endif; ?>`. Detail summary: when `category_name` is set, a `<dt>Category</dt><dd>…</dd>` row after Trigger.

- [ ] **Step 6: Run the whole spec, commit**

Run: the full product-import spec. Expected: PASS (36 tests).

```bash
git add includes tests/product-import.spec.js
git commit -m "Pull one ePim category at a time from the Product import page" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

### Task 12: Changelog, full suite, lint

- [ ] **Step 1: Changelog**

In `CHANGELOG.md` under `## [1.14.0]` → `### Added`, append two bullets:

```markdown
- Test mode, on to begin with: pulls record what they would add, update or hide and
  change nothing, so the feature can be checked before it touches live products.
- Pull one category: refresh the category list from ePim, pick a category, and pull
  just the products in it and under it.
```

In `readme.txt`'s `= 1.14.0 =` entry add: `* Test mode (on to begin with) and a pull of one category at a time.`

- [ ] **Step 2: Full suite once**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=admin npx playwright test --workers=1 --timeout=240000`
Expected: everything green except the three pre-existing failures (forum-page-seed, two woocommerce-notice).

- [ ] **Step 3: Lint once**

Run: `vendor/bin/phpcs --standard=phpcs.xml.dist --exclude=Generic.Files.LineEndings includes external-product-images.php uninstall.php`
Luke has approved fixing alignment and formatting findings in the files this branch touches; fix those in the same commit and record anything else verbatim for him.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md readme.txt includes
git commit -m "Describe test mode and category pulls in the changelog" -m "Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>"
```

---

## Self-review

- **Spec coverage.** Price 0: no change (Task none). Category pull with refresh, descendants, no deleted stage, not a baseline, shown in the table: Task 11. Test mode default on, no writes anywhere, marked runs, notice and detail wording, not a baseline: Task 10. Schema 1.1: Task 10. Changelog: Task 12.
- **Placeholders.** None.
- **Type consistency.** `create_run()` options keys `is_test`, `category_id`, `category_name` match the columns and `start()`. `sync()`/`apply()`/`hide_deleted()` dry-run arguments are booleans cast from the run row. `term_for()` is used by `category_name()`, `scope()` and `ensure_term()`.
- **Review Focus.** 1 and 5 in Task 10's "records what it would add" test; 2 and 3 in Task 11's "including subcategories" test; 4 by the same Task 10 test (images on, product never created).
