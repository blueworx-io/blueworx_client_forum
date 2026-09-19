# Forum Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Long-form Forum Lighting support pages as a WordPress post type, where every word, image and link is editable in the shared BlueWorx page editor and rendered on the front end by one shortcode.

**Architecture:** A `forum_page` post type supplies the records. One screen registered with the vendored BlueWorx page editor library declares a panel per page section, each panel switchable off. A `[forum_page]` shortcode, dropped into an Elementor single template for the post type, reads the record being viewed and renders the visible sections from PHP partials. Section numbering and the "on this page" bar are derived at render time, never stored.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, the shared `blueworx-page-editor` library (vendored), the shared `blueworx-admin-design` system, WooCommerce (for the product section only), Playwright against the local WordPress harness.

**Spec:** `docs/superpowers/specs/2026-09-09-forum-pages-design.md`

## Global Constraints

- PHP 7.4 minimum, WordPress 6.0 minimum. No new Composer or npm dependencies — `approved-deps.json` is enforced by CI.
- Text domain is `blueworx_client_forum` on every translatable string.
- Class files live in `includes/`, named `class-epi-*.php`, classes prefixed `EPI_`. Constants prefixed `EPI_`. Front-end asset handles prefixed `epi-`.
- **Never edit `blueworx-page-editor/` or `assets/blueworx-page-editor.js` in this repo.** CI compares both against `bluegroup_core_foundation` and fails on any difference. The fix for a difference is always to re-pull.
- Field ids must be unique across the whole editor screen — the library rejects duplicates. Every field id in this plan is already section-prefixed for that reason.
- The library stores each field as post meta under `<post_type>_<field_id>`, so `hero_heading` is read back as `get_post_meta( $id, 'forum_page_hero_heading', true )`.
- A panel with `'hideable' => true` gets a toggle the library adds itself, with the id `<panel_id>__shown`. Never declare a field ending in `__shown`.
- Playwright runs against the local harness (`npm run wp:up`), never a hosted site. A skipped test is not a passing test — CI fails a run that executes zero tests.
- Lint runs once, at the end, as a check: `composer lint`. Do not loop lint → fix → lint. Present findings to Luke.
- Version bump and changelog happen once on the branch, in Task 8.

## Figma reference

File key `ocfLyNE9GtyOYMxqq7Hwt7`. Call `get_design_context` on the node for a section before writing its markup and CSS — the copy inventory is in the spec, but spacing, colour and type come from the design.

| Section | Node |
|---|---|
| Hero | `1:61` |
| On this page | `1:98` |
| What they are | `1:113` |
| How they work (step 1, and the section shell) | `1:155` |
| How they work, steps 2–4 | `1:611`, `1:646`, `1:682` |
| Advantages | `1:190` |
| Where they work | `1:256` |
| Comparison | `1:349` |
| The range | `1:384` |
| Specifying | `1:473` |
| FAQs | `1:533` |
| Closing CTA | `1:560` |

Header chrome (`1:6`) and footer (`1:585`) are the live site's own. Do not build them.

## File structure

**Created**

- `blueworx-page-editor/` — vendored library, copied from the foundation, never edited
- `assets/blueworx-page-editor.js` — the library's browser file, copied, never edited
- `assets/blueworx-admin-design.php` — the design system's enqueue helper, copied, never edited
- `includes/class-epi-forum-page-type.php` — registers the post type and the link from its list into the editor
- `includes/class-epi-forum-page-editor.php` — the editor screen schema, nothing else
- `includes/class-epi-forum-page-renderer.php` — the shortcode, the section order, and value reading
- `includes/forum-page/section-*.php` — one partial per section
- `assets/css/epi-forum-page.css`, `assets/js/epi-forum-page.js`
- `tests/forum-page-editor.spec.js`, `tests/forum-page-front.spec.js`
- `docs/elementor-template.md`

**Modified**

- `external-product-images.php` — require the library and the new classes
- `includes/class-epi-feature-registry.php` — a `content` group and the `forum-pages` feature
- `bin/build-zip.sh` — `blueworx-page-editor` added to the allowlist
- `CHANGELOG.md`, `readme.txt`, `package.json` — version and changelog, Task 8

---

### Task 0: The `record` repeater cell (separate repo)

This task is in `bluegroup_core_foundation`, not this plugin. It can run in parallel with Tasks 1–7. Task 9 is the only task that depends on it.

**Files:**
- Modify: `.claude/skills/blueworx-admin-design/editor/php/v1/Schema.php` (`REPEATER_KINDS`)
- Modify: `.claude/skills/blueworx-admin-design/editor/blueworx-page-editor.js` (`Repeater()`)
- Test: the foundation's own test that pairs `REPEATER_KINDS` with `Repeater()`

**Interfaces:**
- Consumes: nothing
- Produces: a repeater cell `[ 'id' => 'product', 'kind' => 'record', 'label' => 'Product', 'post_type' => 'product' ]`, storing a post id as an integer

- [ ] **Step 1: Read the existing pairing test**

Find the test that enforces "a kind may only be in `REPEATER_KINDS` once `Repeater()` has a case for it". Read it before changing either file — it defines what done means here.

- [ ] **Step 2: Add `record` to the test's expectations and run it**

Expected: FAIL, because `Repeater()` has no `record` case.

- [ ] **Step 3: Add the `record` case to `Repeater()` in the browser file**

Draw the same control the top-level `record` field already draws, bound to the row's cell rather than to a field id. Reuse the existing `record` rendering — do not write a second picker.

- [ ] **Step 4: Add `'record'` to `Schema::REPEATER_KINDS`**

- [ ] **Step 5: Run the test suite**

Expected: PASS.

- [ ] **Step 6: Commit, open a pull request, and release**

The plugin cannot use this until the foundation cuts a release and this plugin re-pulls its copies.

---

### Task 1: Vendor the library and open an editor on a real record

**Files:**
- Create: `blueworx-page-editor/` (copy of `../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/editor/php/`)
- Create: `assets/blueworx-page-editor.js` (copy of `.../editor/blueworx-page-editor.js`)
- Create: `assets/blueworx-admin-design.php` (copy of `../bluegroup_core_foundation/.wp-test/example-plugin/assets/blueworx-admin-design.php`)
- Create: `includes/class-epi-forum-page-type.php`
- Create: `includes/class-epi-forum-page-editor.php`
- Modify: `external-product-images.php`
- Modify: `bin/build-zip.sh:40-50`
- Test: `tests/forum-page-editor.spec.js`

**Interfaces:**
- Consumes: nothing
- Produces: post type `forum_page`; editor screen slug `forum-page`; `EPI_Forum_Page_Type::init()`; `EPI_Forum_Page_Editor::init()`; field ids `post_title`, `hero_eyebrow`, `hero_heading`

- [ ] **Step 1: Write the failing test**

Create `tests/forum-page-editor.spec.js`:

```js
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The editor edits a record that already exists. Everything here starts by
// making one, because a test that assumes a record is a test that passes on
// one machine.

async function createForumPage(page, title) {
  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', title);
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = new URL(page.url()).searchParams.get('post');
  return Number(id);
}

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('Forum Pages has its own menu section', async ({ page }) => {
  await page.goto('/wp-admin/');
  await expect(page.locator('#adminmenu')).toContainText('Forum Pages');
});

test('the editor opens on a real record and saves the hero heading', async ({ page }) => {
  const id = await createForumPage(page, 'Kinetic wireless switches');

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);

  await expect(page.locator('#bw-page-editor')).toBeVisible();
  await expect(page.locator('.bw-savebar')).toBeVisible();

  await page.fill('#hero_heading', 'Getting started with kinetic wireless switches');
  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  await page.reload();
  await expect(page.locator('#hero_heading')).toHaveValue(
    'Getting started with kinetic wireless switches'
  );
});

test('the editor says so when there is no record to edit', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=forum-page');

  await expect(page.locator('#wpbody-content')).toContainText('could not be found');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm run wp:up` then `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-editor.spec.js --workers=1`

Expected: FAIL — there is no Forum Pages menu and no `forum-page` screen.

- [ ] **Step 3: Copy the library and the design system enqueue helper**

```bash
cp -R ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/editor/php blueworx-page-editor
cp ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/editor/blueworx-page-editor.js assets/blueworx-page-editor.js
cp ../bluegroup_core_foundation/.wp-test/example-plugin/assets/blueworx-admin-design.php assets/blueworx-admin-design.php
```

Copy verbatim. Do not reformat, do not fix a lint complaint inside these files — CI compares them byte for byte against the foundation.

- [ ] **Step 4: Register the post type**

Create `includes/class-epi-forum-page-type.php`:

```php
<?php
/**
 * The Forum Pages record type.
 *
 * The page editor library edits records; it never creates them. So the post
 * type is registered with show_ui, WordPress draws its own list and Add New,
 * and the row action below is the way from that list into the editor.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the forum_page post type and the link into its editor.
 *
 * @since 1.8.0
 */
final class EPI_Forum_Page_Type {

	const POST_TYPE = 'forum_page';
	const SCREEN    = 'forum-page';

	/**
	 * Hook the post type and its list-table row action.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	/**
	 * Register the post type.
	 *
	 * Public, because a Forum Page is a page on the site with its own address.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Forum Pages', 'blueworx_client_forum' ),
					'singular_name' => __( 'Forum Page', 'blueworx_client_forum' ),
					'add_new_item'  => __( 'Add Forum Page', 'blueworx_client_forum' ),
					'edit_item'     => __( 'Edit Forum Page', 'blueworx_client_forum' ),
				),
				'public'       => true,
				'show_ui'      => true,
				'menu_icon'    => 'dashicons-media-document',
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'forum-pages' ),
				'supports'     => array( 'title', 'revisions', 'author' ),
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Point the list table's Edit action at the BlueWorx editor.
	 *
	 * Without this the list edits the record in Gutenberg, which knows nothing
	 * about any of these fields.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    The row's post.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$actions['edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::editor_url( $post->ID ) ),
			esc_html__( 'Edit', 'blueworx_client_forum' )
		);

		return $actions;
	}

	/**
	 * The editor address for one record.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	public static function editor_url( $id ) {
		return admin_url( 'admin.php?page=' . self::SCREEN . '&id=' . (int) $id );
	}
}
```

- [ ] **Step 5: Register the editor screen with the Hero panel only**

Create `includes/class-epi-forum-page-editor.php`:

```php
<?php
/**
 * The Forum Page editor screen.
 *
 * This file declares a schema and nothing else. The shell — page header, tabs,
 * panels, the one save bar — belongs to the shared page editor library.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declares the Forum Page editor screen.
 *
 * @since 1.8.0
 */
final class EPI_Forum_Page_Editor {

	/**
	 * Register the screen once every plugin is loaded.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Hand the screen to the library.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( '\Blueworx\PageEditor\v1\Editor' ) ) {
			return;
		}

		\Blueworx\PageEditor\v1\Editor::register(
			array(
				'slug'       => EPI_Forum_Page_Type::SCREEN,
				'title'      => __( 'Edit Forum Page', 'blueworx_client_forum' ),
				'eyebrow'    => __( 'Forum Pages', 'blueworx_client_forum' ),
				'lede'       => __( 'Everything on the live page. Nothing changes on the site until you save.', 'blueworx_client_forum' ),
				'post_type'  => EPI_Forum_Page_Type::POST_TYPE,
				'parent'     => 'edit.php?post_type=' . EPI_Forum_Page_Type::POST_TYPE,
				'capability' => 'edit_posts',
				'tabs'       => array( self::tab_top() ),
			)
		);
	}

	/**
	 * The Page top tab.
	 *
	 * @return array
	 */
	private static function tab_top() {
		return array(
			'id'     => 'top',
			'label'  => __( 'Page top', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'      => 'hero',
					'eyebrow' => __( 'Forum page · Hero', 'blueworx_client_forum' ),
					'title'   => __( 'Hero', 'blueworx_client_forum' ),
					'note'    => __( 'The top of the page. Every Forum Page has one, so it cannot be switched off.', 'blueworx_client_forum' ),
					'fields'  => array(
						array(
							'id'       => 'post_title',
							'kind'     => 'title',
							'label'    => __( 'Page name', 'blueworx_client_forum' ),
							'required' => true,
							'help'     => __( 'How this page is listed in wp-admin. Not shown on the page itself.', 'blueworx_client_forum' ),
						),
						array( 'id' => 'hero_eyebrow', 'kind' => 'text', 'label' => __( 'Eyebrow', 'blueworx_client_forum' ), 'help' => __( 'The small word above the heading, e.g. KINETIC.', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
					),
				),
			),
		);
	}
}
```

- [ ] **Step 6: Load it all from the plugin bootstrap**

In `external-product-images.php`, immediately after the `EPI_EPIM_IMAGE_BASE` define and before the update-checker block, add:

```php
/*
 * The shared admin design system, then the page editor library that enqueues
 * against it. This order matters: the design system's registrar decides which
 * copy on the site wins, and that has to be settled before anything enqueues.
 */
require_once EPI_PLUGIN_DIR . 'assets/blueworx-admin-design.php';
require_once EPI_PLUGIN_DIR . 'blueworx-page-editor/blueworx-page-editor.php';
```

Then inside `EPI_Plugin::init()`, directly above the `EPI_Lab_Page::init();` line:

```php
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-type.php';
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-editor.php';

		EPI_Forum_Page_Type::init();
		EPI_Forum_Page_Editor::init();
```

- [ ] **Step 7: Add the library to the zip allowlist**

In `bin/build-zip.sh`, inside `INCLUDE=(`, after `"plugin-update-checker"`:

```bash
	# The vendored page editor library. The main plugin file requires it
	# unguarded, so a zip without it fatals on activate.
	"blueworx-page-editor"
```

- [ ] **Step 8: Run the tests**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-editor.spec.js --workers=1`

Expected: PASS, all three.

- [ ] **Step 9: Commit**

```bash
git add blueworx-page-editor assets/blueworx-page-editor.js assets/blueworx-admin-design.php includes/class-epi-forum-page-type.php includes/class-epi-forum-page-editor.php external-product-images.php bin/build-zip.sh tests/forum-page-editor.spec.js
git commit -m "Add the Forum Pages record type and its editor"
```

---

### Task 2: The rest of the editor panels

**Files:**
- Modify: `includes/class-epi-forum-page-editor.php`
- Test: `tests/forum-page-editor.spec.js`

**Interfaces:**
- Consumes: `EPI_Forum_Page_Editor::register()` from Task 1
- Produces: every field id the renderer reads. Panels `hero`, `sectionbar`, `what`, `how`, `advantages`, `where`, `comparison`, `specifying`, `faqs`, `cta`. Tabs `top`, `explainer`, `evidence`, `products`, `close`.

- [ ] **Step 1: Write the failing test**

Append to `tests/forum-page-editor.spec.js`:

```js
test('every tab is offered, and a section can be switched off', async ({ page }) => {
  const id = await createForumPage(page, 'Tabs and switches');
  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);

  for (const label of ['Page top', 'Explainer', 'Evidence', 'Products', 'Close']) {
    await expect(page.locator('.bw-tabs')).toContainText(label);
  }

  await page.locator('.bw-tabs').getByText('Evidence').click();
  await expect(page.locator('#comparison_heading')).toBeVisible();

  // The switch the library adds to a hideable panel.
  await page.locator('#comparison__shown').click();
  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  await page.reload();
  await page.locator('.bw-tabs').getByText('Evidence').click();
  await expect(page.locator('#comparison__shown')).not.toBeChecked();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-editor.spec.js --workers=1 -g "every tab"`

Expected: FAIL — only the Page top tab exists.

- [ ] **Step 3: Add the remaining Hero fields**

Extend the `hero` panel's `fields` array, after `hero_heading`:

```php
						array( 'id' => 'hero_breadcrumb', 'kind' => 'text', 'label' => __( 'Breadcrumb', 'blueworx_client_forum' ), 'help' => __( 'The last crumb, e.g. Kinetic wireless switches.', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_intro', 'kind' => 'textarea', 'label' => __( 'Introduction', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_image', 'kind' => 'media', 'label' => __( 'Hero image', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_cta1_label', 'kind' => 'text', 'label' => __( 'First button', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_cta1_url', 'kind' => 'text', 'label' => __( 'First button address', 'blueworx_client_forum' ), 'format' => 'url' ),
						array( 'id' => 'hero_cta2_label', 'kind' => 'text', 'label' => __( 'Second button', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_cta2_url', 'kind' => 'text', 'label' => __( 'Second button address', 'blueworx_client_forum' ), 'format' => 'url' ),
						array( 'id' => 'hero_meta_category', 'kind' => 'text', 'label' => __( 'Category chip', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_meta_read', 'kind' => 'text', 'label' => __( 'Read time chip', 'blueworx_client_forum' ) ),
						array( 'id' => 'hero_meta_updated', 'kind' => 'text', 'label' => __( 'Updated chip', 'blueworx_client_forum' ) ),
```

- [ ] **Step 4: Add the On this page panel to the Page top tab**

After the `hero` panel, inside the same `panels` array:

```php
				array(
					'id'       => 'sectionbar',
					'eyebrow'  => __( 'Forum page · On this page', 'blueworx_client_forum' ),
					'title'    => __( 'On this page', 'blueworx_client_forum' ),
					'note'     => __( 'The links are built from the sections that are switched on, using each section\'s own short name.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'sectionbar_label', 'kind' => 'text', 'label' => __( 'Bar label', 'blueworx_client_forum' ), 'default' => 'ON THIS PAGE' ),
						array( 'id' => 'sectionbar_phone', 'kind' => 'text', 'label' => __( 'Phone number', 'blueworx_client_forum' ) ),
					),
				),
```

- [ ] **Step 5: Add the Explainer tab**

Add a `tab_explainer()` method and include it in the `tabs` array:

```php
	/**
	 * The Explainer tab.
	 *
	 * @return array
	 */
	private static function tab_explainer() {
		return array(
			'id'     => 'explainer',
			'label'  => __( 'Explainer', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'what',
					'eyebrow'  => __( 'Forum page · What they are', 'blueworx_client_forum' ),
					'title'    => __( 'What they are', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'what_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ), 'help' => __( 'Used in the on-this-page bar.', 'blueworx_client_forum' ) ),
						array( 'id' => 'what_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'what_body', 'kind' => 'richtext', 'label' => __( 'Body', 'blueworx_client_forum' ) ),
						array( 'id' => 'what_pullquote', 'kind' => 'textarea', 'label' => __( 'Pull quote', 'blueworx_client_forum' ) ),
						array( 'id' => 'what_parts', 'kind' => 'repeater', 'label' => __( 'Parts', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'title', 'kind' => 'text', 'label' => __( 'Name', 'blueworx_client_forum' ) ),
							array( 'id' => 'desc', 'kind' => 'text', 'label' => __( 'What it does', 'blueworx_client_forum' ) ),
						) ),
					),
				),
				array(
					'id'       => 'how',
					'eyebrow'  => __( 'Forum page · How they work', 'blueworx_client_forum' ),
					'title'    => __( 'How they work', 'blueworx_client_forum' ),
					'note'     => __( 'One row per step. The page shows them one at a time.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'how_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'how_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'how_intro', 'kind' => 'textarea', 'label' => __( 'Introduction', 'blueworx_client_forum' ) ),
						array( 'id' => 'how_steps', 'kind' => 'repeater', 'label' => __( 'Steps', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'title', 'kind' => 'text', 'label' => __( 'Step', 'blueworx_client_forum' ) ),
							array( 'id' => 'body', 'kind' => 'textarea', 'label' => __( 'What happens', 'blueworx_client_forum' ) ),
							array( 'id' => 'image', 'kind' => 'media', 'label' => __( 'Diagram', 'blueworx_client_forum' ) ),
						) ),
					),
				),
			),
		);
	}
```

- [ ] **Step 6: Add the Evidence tab**

```php
	/**
	 * The Evidence tab.
	 *
	 * @return array
	 */
	private static function tab_evidence() {
		return array(
			'id'     => 'evidence',
			'label'  => __( 'Evidence', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'advantages',
					'eyebrow'  => __( 'Forum page · Advantages', 'blueworx_client_forum' ),
					'title'    => __( 'Advantages', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'advantages_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'advantages_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'advantages_wins_label', 'kind' => 'text', 'label' => __( 'First list heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'advantages_wins', 'kind' => 'repeater', 'label' => __( 'First list', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'item', 'kind' => 'textarea', 'label' => __( 'Point', 'blueworx_client_forum' ) ),
						) ),
						array( 'id' => 'advantages_allow_label', 'kind' => 'text', 'label' => __( 'Second list heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'advantages_allow', 'kind' => 'repeater', 'label' => __( 'Second list', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'item', 'kind' => 'textarea', 'label' => __( 'Point', 'blueworx_client_forum' ) ),
						) ),
					),
				),
				array(
					'id'       => 'where',
					'eyebrow'  => __( 'Forum page · Where they work', 'blueworx_client_forum' ),
					'title'    => __( 'Where they work', 'blueworx_client_forum' ),
					'note'     => __( 'The photo cards. Any number of them.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'where_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'where_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'where_intro', 'kind' => 'textarea', 'label' => __( 'Introduction', 'blueworx_client_forum' ) ),
						array( 'id' => 'where_cards', 'kind' => 'repeater', 'label' => __( 'Cards', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'image', 'kind' => 'media', 'label' => __( 'Photograph', 'blueworx_client_forum' ) ),
							array( 'id' => 'title', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
							array( 'id' => 'body', 'kind' => 'textarea', 'label' => __( 'Description', 'blueworx_client_forum' ) ),
						) ),
					),
				),
				array(
					'id'       => 'comparison',
					'eyebrow'  => __( 'Forum page · Comparison', 'blueworx_client_forum' ),
					'title'    => __( 'Comparison', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'comparison_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'comparison_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'comparison_col1', 'kind' => 'text', 'label' => __( 'First column', 'blueworx_client_forum' ) ),
						array( 'id' => 'comparison_col2', 'kind' => 'text', 'label' => __( 'Second column', 'blueworx_client_forum' ) ),
						array( 'id' => 'comparison_col3', 'kind' => 'text', 'label' => __( 'Third column', 'blueworx_client_forum' ) ),
						array( 'id' => 'comparison_rows', 'kind' => 'repeater', 'label' => __( 'Rows', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'label', 'kind' => 'text', 'label' => __( 'Row', 'blueworx_client_forum' ) ),
							array( 'id' => 'col1', 'kind' => 'text', 'label' => __( 'First column', 'blueworx_client_forum' ) ),
							array( 'id' => 'col2', 'kind' => 'text', 'label' => __( 'Second column', 'blueworx_client_forum' ) ),
							array( 'id' => 'col3', 'kind' => 'text', 'label' => __( 'Third column', 'blueworx_client_forum' ) ),
						) ),
					),
				),
			),
		);
	}
```

- [ ] **Step 7: Add the Products tab, without the range panel**

The range panel waits for Task 0's release, so this tab carries Specifying only for now:

```php
	/**
	 * The Products tab.
	 *
	 * @return array
	 */
	private static function tab_products() {
		return array(
			'id'     => 'products',
			'label'  => __( 'Products', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'specifying',
					'eyebrow'  => __( 'Forum page · Specifying', 'blueworx_client_forum' ),
					'title'    => __( 'Specifying', 'blueworx_client_forum' ),
					'note'     => __( 'The points are numbered on the page, in this order.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'specifying_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'specifying_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'specifying_points', 'kind' => 'repeater', 'label' => __( 'Points', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'title', 'kind' => 'text', 'label' => __( 'Point', 'blueworx_client_forum' ) ),
							array( 'id' => 'body', 'kind' => 'textarea', 'label' => __( 'Detail', 'blueworx_client_forum' ) ),
						) ),
						array( 'id' => 'specifying_trouble_label', 'kind' => 'text', 'label' => __( 'Troubleshooting heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'specifying_trouble', 'kind' => 'repeater', 'label' => __( 'Troubleshooting', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'title', 'kind' => 'text', 'label' => __( 'Symptom', 'blueworx_client_forum' ) ),
							array( 'id' => 'body', 'kind' => 'textarea', 'label' => __( 'What to do', 'blueworx_client_forum' ) ),
						) ),
					),
				),
			),
		);
	}
```

- [ ] **Step 8: Add the Close tab**

```php
	/**
	 * The Close tab.
	 *
	 * @return array
	 */
	private static function tab_close() {
		return array(
			'id'     => 'close',
			'label'  => __( 'Close', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'faqs',
					'eyebrow'  => __( 'Forum page · FAQs', 'blueworx_client_forum' ),
					'title'    => __( 'FAQs', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'faqs_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'faqs_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'faqs_items', 'kind' => 'repeater', 'label' => __( 'Questions', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'question', 'kind' => 'text', 'label' => __( 'Question', 'blueworx_client_forum' ) ),
							array( 'id' => 'answer', 'kind' => 'textarea', 'label' => __( 'Answer', 'blueworx_client_forum' ) ),
						) ),
					),
				),
				array(
					'id'       => 'cta',
					'eyebrow'  => __( 'Forum page · Closing call to action', 'blueworx_client_forum' ),
					'title'    => __( 'Closing call to action', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'cta_eyebrow', 'kind' => 'text', 'label' => __( 'Eyebrow', 'blueworx_client_forum' ) ),
						array( 'id' => 'cta_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'cta_body', 'kind' => 'textarea', 'label' => __( 'Body', 'blueworx_client_forum' ) ),
						array( 'id' => 'cta_cta1_label', 'kind' => 'text', 'label' => __( 'First button', 'blueworx_client_forum' ) ),
						array( 'id' => 'cta_cta1_url', 'kind' => 'text', 'label' => __( 'First button address', 'blueworx_client_forum' ), 'format' => 'url' ),
						array( 'id' => 'cta_cta2_label', 'kind' => 'text', 'label' => __( 'Second button', 'blueworx_client_forum' ) ),
						array( 'id' => 'cta_cta2_url', 'kind' => 'text', 'label' => __( 'Second button address', 'blueworx_client_forum' ), 'format' => 'url' ),
						array( 'id' => 'cta_stats', 'kind' => 'repeater', 'label' => __( 'Trust figures', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'value', 'kind' => 'text', 'label' => __( 'Figure', 'blueworx_client_forum' ) ),
							array( 'id' => 'label', 'kind' => 'text', 'label' => __( 'Caption', 'blueworx_client_forum' ) ),
						) ),
					),
				),
			),
		);
	}
```

- [ ] **Step 9: List every tab on the screen**

Change the `'tabs'` value in `register()` to:

```php
				'tabs'       => array(
					self::tab_top(),
					self::tab_explainer(),
					self::tab_evidence(),
					self::tab_products(),
					self::tab_close(),
				),
```

- [ ] **Step 10: Run the tests**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-editor.spec.js --workers=1`

Expected: PASS, all four.

- [ ] **Step 11: Commit**

```bash
git add includes/class-epi-forum-page-editor.php tests/forum-page-editor.spec.js
git commit -m "Add every Forum Page section to the editor"
```

---

### Task 3: The shortcode, and the hero on the front end

**Files:**
- Create: `includes/class-epi-forum-page-renderer.php`
- Create: `includes/forum-page/section-hero.php`
- Create: `assets/css/epi-forum-page.css`
- Modify: `external-product-images.php`
- Test: `tests/forum-page-front.spec.js`

**Interfaces:**
- Consumes: field ids from Task 2; `EPI_Forum_Page_Type::POST_TYPE`
- Produces: `EPI_Forum_Page_Renderer::init()`, `::value( $id, $key, $default = '' )`, `::rows( $id, $key )`, `::shown( $id, $panel )`, `::sections()`, `::visible_sections( $id )`; shortcode `[forum_page]`; asset handle `epi-forum-page`

- [ ] **Step 1: Write the failing test**

Create `tests/forum-page-front.spec.js`:

```js
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The front end reads a record the test makes itself. Nothing here assumes
// content already on the site.

async function seedForumPage(page, { title, heading }) {
  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', title);
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = Number(new URL(page.url()).searchParams.get('post'));

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.fill('#hero_heading', heading);
  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  return id;
}

test('the page renders its hero heading', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await seedForumPage(page, {
    title: 'Kinetic wireless switches',
    heading: 'Getting started with kinetic wireless switches',
  });

  await page.goto(`/?p=${id}&post_type=forum_page`);

  await expect(page.locator('.epi-forum-page__hero h1')).toHaveText(
    'Getting started with kinetic wireless switches'
  );
});
```

The single template does not exist yet, so this task also renders the shortcode through a `the_content` fallback — see Step 4.

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-front.spec.js --workers=1`

Expected: FAIL — nothing renders the hero.

- [ ] **Step 3: Write the renderer**

Create `includes/class-epi-forum-page-renderer.php`:

```php
<?php
/**
 * Renders a Forum Page on the front end.
 *
 * The section order is fixed and lives here. Which sections a page shows is a
 * per-record switch; what order they come in is part of the product.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * The [forum_page] shortcode and the section order behind it.
 *
 * @since 1.8.0
 */
final class EPI_Forum_Page_Renderer {

	/**
	 * Hook the shortcode and its assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'forum_page', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Every section, in the order the page shows them.
	 *
	 * 'numbered' means the section carries a number on the page and a link in
	 * the on-this-page bar. Both are worked out from the sections actually
	 * shown, so switching one off closes the numbering up behind it.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'hero'       => array( 'numbered' => false ),
			'sectionbar' => array( 'numbered' => false ),
			'what'       => array( 'numbered' => true ),
			'how'        => array( 'numbered' => true ),
			'advantages' => array( 'numbered' => true ),
			'where'      => array( 'numbered' => true ),
			'comparison' => array( 'numbered' => true ),
			'range'      => array( 'numbered' => true ),
			'specifying' => array( 'numbered' => true ),
			'faqs'       => array( 'numbered' => true ),
			'cta'        => array( 'numbered' => false ),
		);
	}

	/**
	 * Whether a panel is switched on for this record.
	 *
	 * The hero is not hideable, and a record saved before a section existed
	 * has no value for its switch — both default to shown.
	 *
	 * @param int    $id    Post id.
	 * @param string $panel Panel id.
	 * @return bool
	 */
	public static function shown( $id, $panel ) {
		if ( 'hero' === $panel ) {
			return true;
		}

		$key = EPI_Forum_Page_Type::POST_TYPE . '_' . $panel . '__shown';

		if ( ! metadata_exists( 'post', $id, $key ) ) {
			return true;
		}

		return (bool) get_post_meta( $id, $key, true );
	}

	/**
	 * One field's value.
	 *
	 * @param int    $id      Post id.
	 * @param string $key     Field id.
	 * @param string $default Fallback.
	 * @return string
	 */
	public static function value( $id, $key, $default = '' ) {
		$value = get_post_meta( $id, EPI_Forum_Page_Type::POST_TYPE . '_' . $key, true );

		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * One repeater's rows.
	 *
	 * @param int    $id  Post id.
	 * @param string $key Field id.
	 * @return array
	 */
	public static function rows( $id, $key ) {
		$rows = get_post_meta( $id, EPI_Forum_Page_Type::POST_TYPE . '_' . $key, true );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The numbered sections this record actually shows, in order.
	 *
	 * Feeds both the numbering and the on-this-page bar, so the two can never
	 * disagree.
	 *
	 * @param int $id Post id.
	 * @return array List of panel ids.
	 */
	public static function visible_sections( $id ) {
		$out = array();

		foreach ( self::sections() as $panel => $section ) {
			if ( $section['numbered'] && self::shown( $id, $panel ) ) {
				$out[] = $panel;
			}
		}

		return $out;
	}

	/**
	 * Register the front-end assets. Enqueued only when the shortcode runs.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'epi-forum-page',
			EPI_PLUGIN_URL . 'assets/css/epi-forum-page.css',
			array(),
			EPI_VERSION
		);
	}

	/**
	 * Render the Forum Page being viewed.
	 *
	 * @return string
	 */
	public static function shortcode() {
		$id = get_the_ID();

		if ( ! $id || EPI_Forum_Page_Type::POST_TYPE !== get_post_type( $id ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p>' . esc_html__( 'The [forum_page] shortcode only renders on a Forum Page.', 'blueworx_client_forum' ) . '</p>';
			}

			return '';
		}

		wp_enqueue_style( 'epi-forum-page' );

		$numbers = array_flip( self::visible_sections( $id ) );

		ob_start();
		echo '<div class="epi-forum-page">';

		foreach ( self::sections() as $panel => $section ) {
			if ( ! self::shown( $id, $panel ) ) {
				continue;
			}

			$partial = EPI_PLUGIN_DIR . 'includes/forum-page/section-' . $panel . '.php';

			if ( ! file_exists( $partial ) ) {
				continue;
			}

			// Available to the partial: the record, and its number on the page.
			$post_id = $id;
			$number  = isset( $numbers[ $panel ] ) ? sprintf( '%02d', $numbers[ $panel ] + 1 ) : '';

			include $partial;
		}

		echo '</div>';

		return ob_get_clean();
	}
}
```

- [ ] **Step 4: Render the shortcode on a Forum Page even before the Elementor template exists**

Add to `EPI_Forum_Page_Renderer::init()`:

```php
		add_filter( 'the_content', array( __CLASS__, 'fallback_content' ) );
```

And the method:

```php
	/**
	 * Render the sections on a Forum Page that no template has claimed.
	 *
	 * The site's Elementor single template is the intended home for the
	 * shortcode. Until it exists — and on any site that never builds one — a
	 * Forum Page would otherwise be a blank screen.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function fallback_content( $content ) {
		if ( ! is_singular( EPI_Forum_Page_Type::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( false !== strpos( (string) $content, '[forum_page]' ) ) {
			return $content;
		}

		return $content . self::shortcode();
	}
```

- [ ] **Step 5: Write the hero partial**

Create `includes/forum-page/section-hero.php`:

```php
<?php
/**
 * The hero at the top of a Forum Page.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  Unused here — the hero is not numbered.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'hero_heading' );
$epi_eyebrow = EPI_Forum_Page_Renderer::value( $post_id, 'hero_eyebrow' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'hero_intro' );
$epi_image   = (int) EPI_Forum_Page_Renderer::value( $post_id, 'hero_image', 0 );
$epi_chips   = array_filter(
	array(
		EPI_Forum_Page_Renderer::value( $post_id, 'hero_meta_category' ),
		EPI_Forum_Page_Renderer::value( $post_id, 'hero_meta_read' ),
		EPI_Forum_Page_Renderer::value( $post_id, 'hero_meta_updated' ),
	)
);
?>
<section class="epi-forum-page__hero">
	<?php if ( $epi_eyebrow ) : ?>
		<p class="epi-forum-page__eyebrow"><?php echo esc_html( $epi_eyebrow ); ?></p>
	<?php endif; ?>

	<h1><?php echo esc_html( $epi_heading ); ?></h1>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php
	foreach ( array( 1, 2 ) as $epi_n ) :
		$epi_label = EPI_Forum_Page_Renderer::value( $post_id, 'hero_cta' . $epi_n . '_label' );
		$epi_url   = EPI_Forum_Page_Renderer::value( $post_id, 'hero_cta' . $epi_n . '_url' );

		if ( ! $epi_label || ! $epi_url ) {
			continue;
		}
		?>
		<a class="epi-forum-page__button" href="<?php echo esc_url( $epi_url ); ?>"><?php echo esc_html( $epi_label ); ?></a>
		<?php
	endforeach;
	?>

	<?php if ( $epi_chips ) : ?>
		<ul class="epi-forum-page__chips">
			<?php foreach ( $epi_chips as $epi_chip ) : ?>
				<li><?php echo esc_html( $epi_chip ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $epi_image ) : ?>
		<?php echo wp_get_attachment_image( $epi_image, 'large', false, array( 'class' => 'epi-forum-page__heroimage' ) ); ?>
	<?php endif; ?>
</section>
```

- [ ] **Step 6: Style the hero from the design**

Call `get_design_context` on node `1:61` and write `assets/css/epi-forum-page.css` for the hero only: colours, type scale, spacing and the two button styles as the design has them. Class names stay `epi-forum-page__*`. Do not add a CSS framework.

- [ ] **Step 7: Load the renderer**

In `EPI_Plugin::init()`, beside the other two Forum Page requires:

```php
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-renderer.php';

		EPI_Forum_Page_Renderer::init();
```

- [ ] **Step 8: Run the tests**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`

Expected: PASS, editor and front-end specs both.

- [ ] **Step 9: Commit**

```bash
git add includes/class-epi-forum-page-renderer.php includes/forum-page assets/css/epi-forum-page.css external-product-images.php tests/forum-page-front.spec.js
git commit -m "Render a Forum Page hero from the record"
```

---

### Task 4: The remaining sections, the numbering and the on-this-page bar

**Files:**
- Create: `includes/forum-page/section-sectionbar.php`, `section-what.php`, `section-how.php`, `section-advantages.php`, `section-where.php`, `section-comparison.php`, `section-specifying.php`, `section-faqs.php`, `section-cta.php`
- Modify: `assets/css/epi-forum-page.css`
- Test: `tests/forum-page-front.spec.js`

**Interfaces:**
- Consumes: `EPI_Forum_Page_Renderer::value()`, `::rows()`, `::visible_sections()`, and `$post_id` / `$number` inside each partial
- Produces: the rendered sections, each `<section>` carrying `id="<panel>"` so the bar can link to it

- [ ] **Step 1: Write the failing test**

Append to `tests/forum-page-front.spec.js`:

```js
test('the on-this-page bar lists the sections shown, and numbering closes up', async ({ page }) => {
  await loginAsAdmin(page);

  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', 'Numbering');
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = Number(new URL(page.url()).searchParams.get('post'));

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.fill('#hero_heading', 'Numbering');
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await page.fill('#what_nav_label', 'What they are');
  await page.fill('#what_heading', 'A switch with no supply');
  await page.fill('#how_nav_label', 'How they work');
  await page.fill('#how_heading', 'Press, generate, transmit, switch');
  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  await page.goto(`/?p=${id}&post_type=forum_page`);

  // Both sections shown: they number 01 and 02 and both appear in the bar.
  await expect(page.locator('#what .epi-forum-page__number')).toHaveText('01');
  await expect(page.locator('#how .epi-forum-page__number')).toHaveText('02');
  await expect(page.locator('.epi-forum-page__bar')).toContainText('What they are');
  await expect(page.locator('.epi-forum-page__bar')).toContainText('How they work');

  // Switch the first one off.
  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await page.locator('#what__shown').click();
  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  await page.goto(`/?p=${id}&post_type=forum_page`);

  await expect(page.locator('#what')).toHaveCount(0);
  await expect(page.locator('.epi-forum-page__bar')).not.toContainText('What they are');
  // The section behind it takes the number that was freed.
  await expect(page.locator('#how .epi-forum-page__number')).toHaveText('01');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-front.spec.js --workers=1 -g "on-this-page"`

Expected: FAIL — no bar, no numbers.

- [ ] **Step 3: Write the on-this-page bar partial**

Create `includes/forum-page/section-sectionbar.php`:

```php
<?php
/**
 * The on-this-page bar.
 *
 * The links are built from the sections this record actually shows, so a
 * switched-off section cannot leave a link pointing at nothing.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  Unused here — the bar is not numbered.
 */

defined( 'ABSPATH' ) || exit;

$epi_label = EPI_Forum_Page_Renderer::value( $post_id, 'sectionbar_label', 'ON THIS PAGE' );
$epi_phone = EPI_Forum_Page_Renderer::value( $post_id, 'sectionbar_phone' );
$epi_links = array();

foreach ( EPI_Forum_Page_Renderer::visible_sections( $post_id ) as $epi_panel ) {
	$epi_text = EPI_Forum_Page_Renderer::value( $post_id, $epi_panel . '_nav_label' );

	if ( $epi_text ) {
		$epi_links[ $epi_panel ] = $epi_text;
	}
}

if ( ! $epi_links ) {
	return;
}
?>
<nav class="epi-forum-page__bar" aria-label="<?php echo esc_attr( $epi_label ); ?>">
	<p class="epi-forum-page__barlabel"><?php echo esc_html( $epi_label ); ?></p>
	<ul>
		<?php foreach ( $epi_links as $epi_panel => $epi_text ) : ?>
			<li><a href="#<?php echo esc_attr( $epi_panel ); ?>"><?php echo esc_html( $epi_text ); ?></a></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $epi_phone ) : ?>
		<a class="epi-forum-page__phone" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $epi_phone ) ); ?>"><?php echo esc_html( $epi_phone ); ?></a>
	<?php endif; ?>
</nav>
```

- [ ] **Step 4: Write the What they are partial**

Create `includes/forum-page/section-what.php`:

```php
<?php
/**
 * "What they are".
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "01".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading   = EPI_Forum_Page_Renderer::value( $post_id, 'what_heading' );
$epi_body      = EPI_Forum_Page_Renderer::value( $post_id, 'what_body' );
$epi_pullquote = EPI_Forum_Page_Renderer::value( $post_id, 'what_pullquote' );
$epi_parts     = EPI_Forum_Page_Renderer::rows( $post_id, 'what_parts' );
?>
<section class="epi-forum-page__section epi-forum-page__what" id="what">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_parts ) : ?>
		<ul class="epi-forum-page__parts">
			<?php foreach ( $epi_parts as $epi_part ) : ?>
				<li>
					<h3><?php echo esc_html( isset( $epi_part['title'] ) ? $epi_part['title'] : '' ); ?></h3>
					<p><?php echo esc_html( isset( $epi_part['desc'] ) ? $epi_part['desc'] : '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<div class="epi-forum-page__body"><?php echo wp_kses_post( $epi_body ); ?></div>

	<?php if ( $epi_pullquote ) : ?>
		<blockquote class="epi-forum-page__pullquote"><?php echo esc_html( $epi_pullquote ); ?></blockquote>
	<?php endif; ?>
</section>
```

- [ ] **Step 5: Write the How they work partial**

Create `includes/forum-page/section-how.php`. The steps render as a tab list: every step is in the markup, and with no JavaScript they all show, stacked.

```php
<?php
/**
 * "How they work" — the four-step sequence.
 *
 * Every step is in the markup. The script turns them into one-at-a-time; with
 * no script they stack, which reads fine and loses nothing.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'how_heading' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'how_intro' );
$epi_steps   = EPI_Forum_Page_Renderer::rows( $post_id, 'how_steps' );
$epi_total   = count( $epi_steps );
?>
<section class="epi-forum-page__section epi-forum-page__how" id="how" data-epi-steps>
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_steps ) : ?>
		<ul class="epi-forum-page__steptabs" role="tablist">
			<?php foreach ( $epi_steps as $epi_i => $epi_step ) : ?>
				<li role="presentation">
					<button
						type="button"
						role="tab"
						id="how-tab-<?php echo esc_attr( $epi_i ); ?>"
						aria-controls="how-step-<?php echo esc_attr( $epi_i ); ?>"
						aria-selected="<?php echo 0 === $epi_i ? 'true' : 'false'; ?>"
					>
						<span class="epi-forum-page__stepnumber"><?php echo esc_html( sprintf( '%02d', $epi_i + 1 ) ); ?></span>
						<?php echo esc_html( isset( $epi_step['title'] ) ? $epi_step['title'] : '' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php foreach ( $epi_steps as $epi_i => $epi_step ) : ?>
			<div
				class="epi-forum-page__step"
				role="tabpanel"
				id="how-step-<?php echo esc_attr( $epi_i ); ?>"
				aria-labelledby="how-tab-<?php echo esc_attr( $epi_i ); ?>"
			>
				<p class="epi-forum-page__stepof">
					<?php
					printf(
						/* translators: 1: this step's number, 2: how many steps there are. */
						esc_html__( 'STEP %1$s OF %2$s', 'blueworx_client_forum' ),
						esc_html( sprintf( '%02d', $epi_i + 1 ) ),
						esc_html( sprintf( '%02d', $epi_total ) )
					);
					?>
				</p>
				<h3><?php echo esc_html( isset( $epi_step['title'] ) ? $epi_step['title'] : '' ); ?></h3>
				<p><?php echo esc_html( isset( $epi_step['body'] ) ? $epi_step['body'] : '' ); ?></p>
				<?php if ( ! empty( $epi_step['image'] ) ) : ?>
					<?php echo wp_get_attachment_image( (int) $epi_step['image'], 'large' ); ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
```

- [ ] **Step 6: Write the Advantages partial**

Create `includes/forum-page/section-advantages.php`:

```php
<?php
/**
 * "Advantages" — what you gain, and what to allow for.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'advantages_heading' );
$epi_lists   = array(
	array(
		'label' => EPI_Forum_Page_Renderer::value( $post_id, 'advantages_wins_label' ),
		'items' => EPI_Forum_Page_Renderer::rows( $post_id, 'advantages_wins' ),
	),
	array(
		'label' => EPI_Forum_Page_Renderer::value( $post_id, 'advantages_allow_label' ),
		'items' => EPI_Forum_Page_Renderer::rows( $post_id, 'advantages_allow' ),
	),
);
?>
<section class="epi-forum-page__section epi-forum-page__advantages" id="advantages">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php foreach ( $epi_lists as $epi_list ) : ?>
		<?php if ( ! $epi_list['items'] ) : ?>
			<?php continue; ?>
		<?php endif; ?>
		<div class="epi-forum-page__advantagelist">
			<h3><?php echo esc_html( $epi_list['label'] ); ?></h3>
			<ul>
				<?php foreach ( $epi_list['items'] as $epi_item ) : ?>
					<li><?php echo esc_html( isset( $epi_item['item'] ) ? $epi_item['item'] : '' ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
</section>
```

- [ ] **Step 7: Write the Where they work partial**

Create `includes/forum-page/section-where.php`:

```php
<?php
/**
 * "Where they work" — the photo cards.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'where_heading' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'where_intro' );
$epi_cards   = EPI_Forum_Page_Renderer::rows( $post_id, 'where_cards' );
?>
<section class="epi-forum-page__section epi-forum-page__where" id="where">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_cards ) : ?>
		<ul class="epi-forum-page__cards">
			<?php foreach ( $epi_cards as $epi_card ) : ?>
				<li class="epi-forum-page__card">
					<?php if ( ! empty( $epi_card['image'] ) ) : ?>
						<?php echo wp_get_attachment_image( (int) $epi_card['image'], 'medium_large' ); ?>
					<?php endif; ?>
					<h3><?php echo esc_html( isset( $epi_card['title'] ) ? $epi_card['title'] : '' ); ?></h3>
					<p><?php echo esc_html( isset( $epi_card['body'] ) ? $epi_card['body'] : '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
```

- [ ] **Step 8: Write the Comparison partial**

Create `includes/forum-page/section-comparison.php`:

```php
<?php
/**
 * "Comparison" — kinetic against the alternatives.
 *
 * A real table, because it is one: row headers down the side, column headers
 * across the top, so a screen reader announces each cell with both.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'comparison_heading' );
$epi_cols    = array(
	EPI_Forum_Page_Renderer::value( $post_id, 'comparison_col1' ),
	EPI_Forum_Page_Renderer::value( $post_id, 'comparison_col2' ),
	EPI_Forum_Page_Renderer::value( $post_id, 'comparison_col3' ),
);
$epi_rows    = EPI_Forum_Page_Renderer::rows( $post_id, 'comparison_rows' );
?>
<section class="epi-forum-page__section epi-forum-page__comparison" id="comparison">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_rows ) : ?>
		<div class="epi-forum-page__tablewrap">
			<table>
				<thead>
					<tr>
						<td></td>
						<?php foreach ( $epi_cols as $epi_col ) : ?>
							<th scope="col"><?php echo esc_html( $epi_col ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $epi_rows as $epi_row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( isset( $epi_row['label'] ) ? $epi_row['label'] : '' ); ?></th>
							<?php foreach ( array( 'col1', 'col2', 'col3' ) as $epi_cell ) : ?>
								<td><?php echo esc_html( isset( $epi_row[ $epi_cell ] ) ? $epi_row[ $epi_cell ] : '' ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
```

- [ ] **Step 9: Write the Specifying partial**

Create `includes/forum-page/section-specifying.php`:

```php
<?php
/**
 * "Specifying" — the numbered points, and what to do when something is wrong.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'specifying_heading' );
$epi_points  = EPI_Forum_Page_Renderer::rows( $post_id, 'specifying_points' );
$epi_tlabel  = EPI_Forum_Page_Renderer::value( $post_id, 'specifying_trouble_label' );
$epi_trouble = EPI_Forum_Page_Renderer::rows( $post_id, 'specifying_trouble' );
?>
<section class="epi-forum-page__section epi-forum-page__specifying" id="specifying">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_points ) : ?>
		<ol class="epi-forum-page__points">
			<?php foreach ( $epi_points as $epi_point ) : ?>
				<li>
					<h3><?php echo esc_html( isset( $epi_point['title'] ) ? $epi_point['title'] : '' ); ?></h3>
					<p><?php echo esc_html( isset( $epi_point['body'] ) ? $epi_point['body'] : '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php if ( $epi_trouble ) : ?>
		<div class="epi-forum-page__trouble">
			<h3><?php echo esc_html( $epi_tlabel ); ?></h3>
			<?php foreach ( $epi_trouble as $epi_item ) : ?>
				<h4><?php echo esc_html( isset( $epi_item['title'] ) ? $epi_item['title'] : '' ); ?></h4>
				<p><?php echo esc_html( isset( $epi_item['body'] ) ? $epi_item['body'] : '' ); ?></p>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
```

- [ ] **Step 10: Write the FAQs partial**

Create `includes/forum-page/section-faqs.php`. Answers are in the markup and open by default; the script collapses them.

```php
<?php
/**
 * "FAQs".
 *
 * Every answer is in the markup and open. The script collapses them into an
 * accordion; without it the page is a plain question-and-answer list.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'faqs_heading' );
$epi_items   = EPI_Forum_Page_Renderer::rows( $post_id, 'faqs_items' );
?>
<section class="epi-forum-page__section epi-forum-page__faqs" id="faqs" data-epi-faqs>
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_items ) : ?>
		<ul class="epi-forum-page__faqlist">
			<?php foreach ( $epi_items as $epi_i => $epi_item ) : ?>
				<li>
					<h3>
						<button type="button" aria-expanded="true" aria-controls="faq-answer-<?php echo esc_attr( $epi_i ); ?>">
							<?php echo esc_html( isset( $epi_item['question'] ) ? $epi_item['question'] : '' ); ?>
						</button>
					</h3>
					<div class="epi-forum-page__answer" id="faq-answer-<?php echo esc_attr( $epi_i ); ?>">
						<p><?php echo esc_html( isset( $epi_item['answer'] ) ? $epi_item['answer'] : '' ); ?></p>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
```

- [ ] **Step 11: Write the Closing CTA partial**

Create `includes/forum-page/section-cta.php`:

```php
<?php
/**
 * The closing call to action.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  Unused here — the closing block is not numbered.
 */

defined( 'ABSPATH' ) || exit;

$epi_eyebrow = EPI_Forum_Page_Renderer::value( $post_id, 'cta_eyebrow' );
$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'cta_heading' );
$epi_body    = EPI_Forum_Page_Renderer::value( $post_id, 'cta_body' );
$epi_stats   = EPI_Forum_Page_Renderer::rows( $post_id, 'cta_stats' );
?>
<section class="epi-forum-page__section epi-forum-page__cta" id="cta">
	<?php if ( $epi_eyebrow ) : ?>
		<p class="epi-forum-page__eyebrow"><?php echo esc_html( $epi_eyebrow ); ?></p>
	<?php endif; ?>

	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_body ) : ?>
		<p><?php echo esc_html( $epi_body ); ?></p>
	<?php endif; ?>

	<?php
	foreach ( array( 1, 2 ) as $epi_n ) :
		$epi_label = EPI_Forum_Page_Renderer::value( $post_id, 'cta_cta' . $epi_n . '_label' );
		$epi_url   = EPI_Forum_Page_Renderer::value( $post_id, 'cta_cta' . $epi_n . '_url' );

		if ( ! $epi_label || ! $epi_url ) {
			continue;
		}
		?>
		<a class="epi-forum-page__button" href="<?php echo esc_url( $epi_url ); ?>"><?php echo esc_html( $epi_label ); ?></a>
		<?php
	endforeach;
	?>

	<?php if ( $epi_stats ) : ?>
		<ul class="epi-forum-page__stats">
			<?php foreach ( $epi_stats as $epi_stat ) : ?>
				<li>
					<strong><?php echo esc_html( isset( $epi_stat['value'] ) ? $epi_stat['value'] : '' ); ?></strong>
					<span><?php echo esc_html( isset( $epi_stat['label'] ) ? $epi_stat['label'] : '' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
```

- [ ] **Step 12: Style every section from the design**

Call `get_design_context` on each node in the Figma table above, one section at a time, and extend `assets/css/epi-forum-page.css` to match. Keep one block of rules per section, in page order, so the file reads in the same order as the page.

- [ ] **Step 13: Run the tests**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`

Expected: PASS.

- [ ] **Step 14: Commit**

```bash
git add includes/forum-page assets/css/epi-forum-page.css tests/forum-page-front.spec.js
git commit -m "Render every Forum Page section, numbered from what is shown"
```

---

### Task 5: The stepper and the FAQ accordion

**Files:**
- Create: `assets/js/epi-forum-page.js`
- Modify: `includes/class-epi-forum-page-renderer.php`
- Test: `tests/forum-page-front.spec.js`

**Interfaces:**
- Consumes: `[data-epi-steps]` and `[data-epi-faqs]` from Task 4's partials
- Produces: asset handle `epi-forum-page` (script), enqueued with the stylesheet

- [ ] **Step 1: Write the failing test**

Append to `tests/forum-page-front.spec.js`:

```js
test('the stepper shows one step at a time and the FAQs collapse', async ({ page }) => {
  await loginAsAdmin(page);

  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', 'Steps and questions');
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = Number(new URL(page.url()).searchParams.get('post'));

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.fill('#hero_heading', 'Steps and questions');
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await page.fill('#how_heading', 'Press, generate, transmit, switch');

  // Two steps is enough to prove one-at-a-time.
  const addStep = page.locator('#how_steps').getByRole('button', { name: /add/i });
  await addStep.click();
  await page.locator('#how_steps input[type="text"]').nth(0).fill('Press');
  await addStep.click();
  await page.locator('#how_steps input[type="text"]').nth(1).fill('Generate');

  await page.locator('.bw-tabs').getByText('Close').click();
  await page.fill('#faqs_heading', 'Frequently asked');
  const addFaq = page.locator('#faqs_items').getByRole('button', { name: /add/i });
  await addFaq.click();
  await page.locator('#faqs_items input[type="text"]').nth(0).fill('Do they need a battery?');

  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  await page.goto(`/?p=${id}&post_type=forum_page`);

  // One step panel visible, and choosing the second swaps which.
  await expect(page.locator('#how .epi-forum-page__step:visible')).toHaveCount(1);
  await page.locator('#how-tab-1').click();
  await expect(page.locator('#how-step-1')).toBeVisible();
  await expect(page.locator('#how-step-0')).toBeHidden();

  // The answer starts collapsed and the question opens it.
  const question = page.locator('#faqs button[aria-expanded]').first();
  await expect(question).toHaveAttribute('aria-expanded', 'false');
  await question.click();
  await expect(question).toHaveAttribute('aria-expanded', 'true');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-front.spec.js --workers=1 -g "stepper"`

Expected: FAIL — every step and answer is showing.

- [ ] **Step 3: Write the script**

Create `assets/js/epi-forum-page.js`:

```js
/**
 * Forum Page behaviour: the four-step sequence, and the FAQ accordion.
 *
 * Both start from markup that already works. This file takes a stacked list
 * and makes it one-at-a-time — so a page whose script fails to load is still
 * a page somebody can read.
 */
(function () {
	'use strict';

	function steps(section) {
		var tabs = section.querySelectorAll('[role="tab"]');
		var panels = section.querySelectorAll('[role="tabpanel"]');

		if (!tabs.length) {
			return;
		}

		function show(index) {
			tabs.forEach(function (tab, i) {
				tab.setAttribute('aria-selected', i === index ? 'true' : 'false');
				tab.tabIndex = i === index ? 0 : -1;
			});
			panels.forEach(function (panel, i) {
				panel.hidden = i !== index;
			});
		}

		tabs.forEach(function (tab, i) {
			tab.addEventListener('click', function () {
				show(i);
			});
			tab.addEventListener('keydown', function (event) {
				var next = event.key === 'ArrowRight' ? i + 1 : event.key === 'ArrowLeft' ? i - 1 : null;

				if (null === next) {
					return;
				}

				event.preventDefault();
				var target = (next + tabs.length) % tabs.length;
				tabs[target].focus();
				show(target);
			});
		});

		show(0);
	}

	function faqs(section) {
		section.querySelectorAll('button[aria-expanded]').forEach(function (button) {
			var answer = document.getElementById(button.getAttribute('aria-controls'));

			if (!answer) {
				return;
			}

			button.setAttribute('aria-expanded', 'false');
			answer.hidden = true;

			button.addEventListener('click', function () {
				var open = button.getAttribute('aria-expanded') === 'true';
				button.setAttribute('aria-expanded', open ? 'false' : 'true');
				answer.hidden = open;
			});
		});
	}

	document.querySelectorAll('[data-epi-steps]').forEach(steps);
	document.querySelectorAll('[data-epi-faqs]').forEach(faqs);
})();
```

- [ ] **Step 4: Register and enqueue the script**

In `EPI_Forum_Page_Renderer::register_assets()`, after the style:

```php
		wp_register_script(
			'epi-forum-page',
			EPI_PLUGIN_URL . 'assets/js/epi-forum-page.js',
			array(),
			EPI_VERSION,
			true
		);
```

And in `shortcode()`, beside `wp_enqueue_style`:

```php
		wp_enqueue_script( 'epi-forum-page' );
```

- [ ] **Step 5: Run the tests**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add assets/js/epi-forum-page.js includes/class-epi-forum-page-renderer.php tests/forum-page-front.spec.js
git commit -m "Show the steps one at a time and collapse the FAQs"
```

---

### Task 6: The feature switch

**Files:**
- Modify: `includes/class-epi-feature-registry.php`
- Modify: `external-product-images.php`
- Test: `tests/forum-page-editor.spec.js`

**Interfaces:**
- Consumes: `EPI_Forum_Page_Type::init()`, `EPI_Forum_Page_Editor::init()`, `EPI_Forum_Page_Renderer::init()`
- Produces: feature id `forum-pages` in a new `content` group

- [ ] **Step 1: Write the failing test**

Append to `tests/forum-page-editor.spec.js`:

```js
test('Forum Pages is offered as a feature on the Lab screen', async ({ page }) => {
  await page.goto('/wp-admin/options-general.php?page=bwlab');

  await expect(page.locator('#epi-lab-form')).toContainText('Forum Pages');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-editor.spec.js --workers=1 -g "offered as a feature"`

Expected: FAIL — no such feature.

- [ ] **Step 3: Add the group**

In `EPI_Feature_Registry::groups()`, add as the first entry:

```php
			'content'         => __( 'Content', 'blueworx_client_forum' ),
```

- [ ] **Step 4: Add the feature definition**

In `EPI_Feature_Registry::definitions()`, add:

```php
			'forum-pages'                 => array(
				'title'          => __( 'Forum Pages', 'blueworx_client_forum' ),
				'description'    => __( 'Long-form support pages, edited under Forum Pages and placed with [forum_page].', 'blueworx_client_forum' ),
				'group'          => 'content',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array(),
				'boot'           => array( __CLASS__, 'boot_forum_pages' ),
			),
```

- [ ] **Step 5: Add its boot method**

In the same class, beside `boot_gallery()`:

```php
	/**
	 * Boot the Forum Pages record type, its editor and its front end.
	 *
	 * @return void
	 */
	public static function boot_forum_pages() {
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-type.php';
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-editor.php';
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-renderer.php';

		EPI_Forum_Page_Type::init();
		EPI_Forum_Page_Editor::init();
		EPI_Forum_Page_Renderer::init();
	}
```

- [ ] **Step 6: Take the direct wiring back out of the bootstrap**

Delete the three requires and three `::init()` calls added to `EPI_Plugin::init()` in Tasks 1 and 3. The registry boots them now, and booting twice would register the post type twice.

Leave the two `require_once` lines for the design system and the editor library at the top of the file exactly where they are: the library has to register its copy on every request, switch or no switch.

- [ ] **Step 7: Run the whole suite**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`

Expected: PASS — the feature defaults to on, so every earlier test still passes.

- [ ] **Step 8: Commit**

```bash
git add includes/class-epi-feature-registry.php external-product-images.php tests/forum-page-editor.spec.js
git commit -m "Put Forum Pages behind a feature switch"
```

---

### Task 7: Write down what the Elementor template needs

**Files:**
- Create: `docs/elementor-template.md`

**Interfaces:**
- Consumes: the `[forum_page]` shortcode from Task 3
- Produces: nothing in code

- [ ] **Step 1: Write the note**

Create `docs/elementor-template.md`:

```markdown
# The Forum Pages template

Forum Pages are edited under **Forum Pages** in wp-admin, not in Elementor. Elementor
draws the page around them.

Build this once, in Elementor > Templates > Theme Builder:

1. Add a **Single** template and set its condition to **Forum Pages**.
2. Put the site header at the top and the site footer at the bottom, the same ones every
   other page uses.
3. Between them, add one **Shortcode** widget containing `[forum_page]`, full width, with no
   padding of its own.

That is the whole template. Every Forum Page — this one and the next — uses it, so the
sections only ever get placed once.

Until the template exists, a Forum Page still renders its sections on its own. Once it
exists, the template is what draws them.
```

- [ ] **Step 2: Commit**

```bash
git add docs/elementor-template.md
git commit -m "Say what the Elementor template for Forum Pages needs"
```

---

### Task 8: Version, changelog and lint

**Files:**
- Modify: `external-product-images.php` (header `Version:` and `EPI_VERSION`)
- Modify: `package.json`
- Modify: `CHANGELOG.md`
- Modify: `readme.txt`

**Interfaces:**
- Consumes: every task above
- Produces: version 1.8.0

- [ ] **Step 1: Bump the version in all three places**

New features, so a minor bump: `1.7.1` → `1.8.0`. Change the `Version:` header, the `EPI_VERSION` define, and `package.json`'s `version`. CI fails the pull request if any two disagree. Leave `package-lock.json`'s own version field alone — that is deliberate.

- [ ] **Step 2: Add the changelog entry**

Under `## [Unreleased]`, add a `## [1.8.0]` section written for the person using it:

```markdown
## [1.8.0]

### Added
- Forum Pages: long-form support pages with every heading, paragraph, photo and button
  editable in one place, under Forum Pages in the menu.
- Each section of a Forum Page can be switched off, and the page numbers its sections and
  builds its "on this page" links from whichever ones are left.
```

- [ ] **Step 3: Match `readme.txt`**

Add the same entry to the changelog section of `readme.txt`, in that file's own format.

- [ ] **Step 4: Run the whole suite once more**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`

Expected: PASS, with a real test count — not zero.

- [ ] **Step 5: Run the linter once**

Run: `composer lint`

Do not fix anything in a loop. Collect the findings and present them to Luke at the end of the session; fix only what he approves. Findings inside `blueworx-page-editor/` are never fixed here — that folder is compared against the foundation.

- [ ] **Step 6: Commit and open the pull request**

```bash
git add external-product-images.php package.json CHANGELOG.md readme.txt
git commit -m "Release 1.8.0 — Forum Pages"
git push -u origin forum-pages
```

Open the pull request against `main`. The description says what it does and anything Luke has to decide — not a walkthrough.

---

### Task 9: The range section (blocked on Task 0)

Do not start this until the foundation release from Task 0 is out and this plugin's copies have been re-pulled.

**Files:**
- Modify: `blueworx-page-editor/`, `assets/blueworx-page-editor.js`, `assets/blueworx-admin-design.css`, `assets/blueworx-admin-icons.js` (re-pulled, not edited)
- Modify: `includes/class-epi-forum-page-editor.php`
- Create: `includes/forum-page/section-range.php`
- Modify: `assets/css/epi-forum-page.css`
- Test: `tests/forum-page-front.spec.js`

**Interfaces:**
- Consumes: the `record` repeater cell from Task 0
- Produces: panel `range`; field ids `range_nav_label`, `range_heading`, `range_intro`, `range_products`, `range_more_label`, `range_more_url`

- [ ] **Step 1: Re-pull the design system and the library**

```bash
cp -R ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/editor/php/. blueworx-page-editor/
cp ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/editor/blueworx-page-editor.js assets/blueworx-page-editor.js
cp ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/styles.css assets/blueworx-admin-design.css
cp ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/assets/icons/lucide-icons.js assets/blueworx-admin-icons.js
cp -R ../bluegroup_core_foundation/.claude/skills/blueworx-admin-design/. .claude/skills/blueworx-admin-design/
```

- [ ] **Step 2: Write the failing test**

Append to `tests/forum-page-front.spec.js`:

```js
test('the range section shows a picked product', async ({ page }) => {
  await loginAsAdmin(page);

  // A product to pick. WooCommerce is active on the harness.
  await page.goto('/wp-admin/post-new.php?post_type=product');
  await page.fill('#title', 'Kinetic 1 Gang Wireless Wall Switch');
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);

  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', 'The range');
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = Number(new URL(page.url()).searchParams.get('post'));

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.fill('#hero_heading', 'The range');
  await page.locator('.bw-tabs').getByText('Products').click();
  await page.fill('#range_heading', 'Plates, dimmers and receivers');
  await page.locator('#range_products').getByRole('button', { name: /add/i }).click();
  await page.locator('#range_products select, #range_products input[list]').first()
    .selectOption({ label: 'Kinetic 1 Gang Wireless Wall Switch' });

  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Saved');

  await page.goto(`/?p=${id}&post_type=forum_page`);

  await expect(page.locator('#range')).toContainText('Kinetic 1 Gang Wireless Wall Switch');
});
```

If the released control renders as something other than a `<select>`, fix the selector in this test to match what it actually draws — the assertion about the rendered page does not change.

- [ ] **Step 3: Run it to verify it fails**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-front.spec.js --workers=1 -g "range section"`

Expected: FAIL — there is no range panel.

- [ ] **Step 4: Add the range panel to the Products tab**

In `EPI_Forum_Page_Editor::tab_products()`, before the `specifying` panel:

```php
				array(
					'id'       => 'range',
					'eyebrow'  => __( 'Forum page · The range', 'blueworx_client_forum' ),
					'title'    => __( 'The range', 'blueworx_client_forum' ),
					'note'     => __( 'Pick the products. Their names, photographs and codes come from the shop, so they cannot go stale here.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array( 'id' => 'range_nav_label', 'kind' => 'text', 'label' => __( 'Short name', 'blueworx_client_forum' ) ),
						array( 'id' => 'range_heading', 'kind' => 'text', 'label' => __( 'Heading', 'blueworx_client_forum' ) ),
						array( 'id' => 'range_intro', 'kind' => 'textarea', 'label' => __( 'Introduction', 'blueworx_client_forum' ) ),
						array( 'id' => 'range_products', 'kind' => 'repeater', 'label' => __( 'Products', 'blueworx_client_forum' ), 'fields' => array(
							array( 'id' => 'product', 'kind' => 'record', 'label' => __( 'Product', 'blueworx_client_forum' ), 'post_type' => 'product' ),
							array( 'id' => 'blurb', 'kind' => 'text', 'label' => __( 'One-line description', 'blueworx_client_forum' ) ),
						) ),
						array( 'id' => 'range_more_label', 'kind' => 'text', 'label' => __( 'Link below the cards', 'blueworx_client_forum' ) ),
						array( 'id' => 'range_more_url', 'kind' => 'text', 'label' => __( 'Its address', 'blueworx_client_forum' ), 'format' => 'url' ),
					),
				),
```

- [ ] **Step 5: Write the range partial**

Create `includes/forum-page/section-range.php`:

```php
<?php
/**
 * "The range" — product cards.
 *
 * Name, photograph, code and link all come from the product itself. Only the
 * one-line description is written here, because it is editorial and the shop
 * has nowhere to keep it.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'range_heading' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'range_intro' );
$epi_rows    = EPI_Forum_Page_Renderer::rows( $post_id, 'range_products' );
$epi_label   = EPI_Forum_Page_Renderer::value( $post_id, 'range_more_label' );
$epi_url     = EPI_Forum_Page_Renderer::value( $post_id, 'range_more_url' );
?>
<section class="epi-forum-page__section epi-forum-page__range" id="range">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_rows ) : ?>
		<ul class="epi-forum-page__products">
			<?php
			foreach ( $epi_rows as $epi_row ) :
				$epi_id      = isset( $epi_row['product'] ) ? (int) $epi_row['product'] : 0;
				$epi_product = $epi_id ? get_post( $epi_id ) : null;

				// A product deleted from the shop after it was picked. Skipping
				// it beats rendering a card with no name and a dead link.
				if ( ! $epi_product || 'product' !== $epi_product->post_type ) {
					continue;
				}

				$epi_sku = function_exists( 'wc_get_product' ) && wc_get_product( $epi_id )
					? wc_get_product( $epi_id )->get_sku()
					: '';
				?>
				<li class="epi-forum-page__product">
					<?php echo get_the_post_thumbnail( $epi_id, 'medium' ); ?>
					<h3><?php echo esc_html( get_the_title( $epi_id ) ); ?></h3>
					<?php if ( ! empty( $epi_row['blurb'] ) ) : ?>
						<p><?php echo esc_html( $epi_row['blurb'] ); ?></p>
					<?php endif; ?>
					<?php if ( $epi_sku ) : ?>
						<p class="epi-forum-page__sku"><?php echo esc_html( $epi_sku ); ?></p>
					<?php endif; ?>
					<a href="<?php echo esc_url( get_permalink( $epi_id ) ); ?>">
						<?php esc_html_e( 'Read more', 'blueworx_client_forum' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $epi_label && $epi_url ) : ?>
		<a class="epi-forum-page__more" href="<?php echo esc_url( $epi_url ); ?>"><?php echo esc_html( $epi_label ); ?></a>
	<?php endif; ?>
</section>
```

- [ ] **Step 6: Style it from the design**

Call `get_design_context` on node `1:384` and add the range rules to `assets/css/epi-forum-page.css`, in page order with the rest.

- [ ] **Step 7: Run the whole suite**

Run: `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8881 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test --workers=1`

Expected: PASS.

- [ ] **Step 8: Bump the version again and commit**

A further minor bump — `1.8.0` → `1.9.0` — in the header, the `EPI_VERSION` define and `package.json`, with a changelog entry saying the range section now picks real products.

```bash
git add -A
git commit -m "Add the range section, picking real products"
```

---

## Self-review

**Spec coverage.** Every section in the spec has a task: hero and shortcode (Task 3), the other nine sections (Task 4), the range (Task 9), the editor schema (Tasks 1–2), derived numbering and the bar (Tasks 3–4), the stepper and accordion (Task 5), the feature flag (Task 6), the Elementor template note (Task 7), version and changelog (Task 8), the foundation dependency (Task 0). Accessibility is built into the partials in Tasks 4 and 5 rather than bolted on at the end.

**Names used consistently.** `EPI_Forum_Page_Renderer::value()`, `::rows()`, `::shown()`, `::sections()`, `::visible_sections()` are defined in Task 3 and used with those exact names in Tasks 4 and 9. Partials receive `$post_id` and `$number`, set in `shortcode()` and documented in every partial's docblock. Panel ids match field prefixes throughout, and `<panel>__shown` is only ever read, never declared.

**One thing to watch.** Task 6 removes wiring that Tasks 1 and 3 added. That is deliberate — the feature switch has to be the only thing that boots this — and Step 6 of Task 6 says so explicitly, but a reviewer seeing Tasks 1 and 3 alone would think the bootstrap wiring was final.
