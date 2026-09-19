# Forum Pages: seeded Kinetic page and per-page shortcode

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A fresh install already has the "Getting started with kinetic wireless switches" page, filled in and editable; any Forum Page can be dropped into any Elementor page with `[forum_page id="N"]`.

**Architecture:** A seeder runs once per site after the post type is registered, stamps an option, and writes the record through the library's own `PostStore` so the values are exactly what the editor would have saved. Two photos ship with the plugin under `assets/seed/` and are sideloaded into the Media Library at seed time. The shortcode grows an `id` attribute; the list table shows each page's shortcode.

**Tech Stack:** WordPress plugin PHP (PHPCS WordPress ruleset), `Blueworx\PageEditor\v1\PostStore`, Playwright.

**Spec:** `docs/superpowers/specs/2026-09-09-forum-pages-design.md`, plus the decisions in this plan's header. Copy source: Figma `ocfLyNE9GtyOYMxqq7Hwt7`, frame `1:5` and the three "How they work" state frames (`1:611`, `1:646`, `1:682`).

## Global Constraints

- Version to **1.9.0** in the plugin header, `EPI_VERSION`, `package.json`, `readme.txt` Stable tag; changelog entry under `[1.9.0]`.
- Never edit `blueworx-page-editor/` or `.claude/skills/blueworx-admin-design/` — CI hash-checks them.
- Seeding runs once (option `epi_forum_page_seeded` = `'1'`) and never touches an existing record.
- The `range` section stays absent (no partial, no seed).
- Gallery card images are not seeded — the design uses placeholders.
- FAQ answers 2–6 are drafted from the page's own copy, not from the design; flag for Luke.
- Future layouts: out of scope; when they arrive, add a `layout` field on the record and a partial set per layout — do not fork the post type.

---

### Task 1: Shortcode takes an `id`

**Files:**
- Modify: `includes/class-epi-forum-page-renderer.php:198-207`
- Test: `tests/forum-page-front.spec.js`

**Interfaces:**
- Produces: `[forum_page id="123"]` renders record 123 wherever it is placed; `[forum_page]` unchanged.

- [ ] **Step 1: Failing test** — append to `tests/forum-page-front.spec.js`:

```js
test('[forum_page id] renders a chosen page inside an ordinary page', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await seedForumPage(page, {
    title: 'Placed by id',
    heading: 'Heading placed by id',
  });

  await page.goto('/wp-admin/post-new.php?post_type=page');
  // Classic editor is what the harness has; type into the content box.
  await page.fill('#title', `Host page for ${id}`);
  await page.locator('#content').fill(`[forum_page id="${id}"]`);
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const hostId = Number(new URL(page.url()).searchParams.get('post'));

  await page.goto(`/?page_id=${hostId}`);
  await expect(page.locator('.epi-forum-page__hero h1')).toHaveText('Heading placed by id');
});
```

- [ ] **Step 2: Run** `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8891 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/forum-page-front.spec.js -g "renders a chosen page" --workers=1` — expect FAIL (the shortcode prints "only renders on a Forum Page").

- [ ] **Step 3: Implement** — replace the top of `shortcode()`:

```php
	public static function shortcode( $atts = array() ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'forum_page' );
		$id   = (int) $atts['id'] ? (int) $atts['id'] : get_the_ID();

		if ( ! $id || EPI_Forum_Page_Type::POST_TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
```

Keep the existing editor-only message but reword: `'The [forum_page] shortcode needs a published Forum Page — use it in a Forum Pages template, or give it an id.'`. Update the docblock: the shortcode shows the current Forum Page, or the one named by `id`.

- [ ] **Step 4: Run** the same test — expect PASS. Run the whole front spec — all pass.
- [ ] **Step 5: Commit** `Let [forum_page id="N"] place a Forum Page anywhere`.

---

### Task 2: Shortcode column in the Forum Pages list

**Files:**
- Modify: `includes/class-epi-forum-page-type.php` (`init()`, two new methods)
- Test: `tests/forum-page-editor.spec.js`

- [ ] **Step 1: Failing test**:

```js
test('the list shows each page its shortcode', async ({ page }) => {
  const id = await createForumPage(page, 'Has a shortcode');
  await page.goto('/wp-admin/edit.php?post_type=forum_page');
  await expect(page.locator(`#post-${id} .column-epi_shortcode`)).toContainText(`[forum_page id="${id}"]`);
});
```

- [ ] **Step 2: Run** `... npx playwright test tests/forum-page-editor.spec.js -g "its shortcode"` — expect FAIL.
- [ ] **Step 3: Implement** in `EPI_Forum_Page_Type::init()`:

```php
		add_filter( 'manage_edit-forum_page_columns', array( __CLASS__, 'list_columns' ) );
		add_action( 'manage_forum_page_posts_custom_column', array( __CLASS__, 'list_column' ), 10, 2 );
```

and:

```php
	/**
	 * A Shortcode column after Title, so each page's tag is there to copy.
	 *
	 * @param array $columns List columns.
	 * @return array
	 */
	public static function list_columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['epi_shortcode'] = __( 'Shortcode', 'blueworx_client_forum' );
			}
		}
		return $out;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Row post id.
	 * @return void
	 */
	public static function list_column( $column, $post_id ) {
		if ( 'epi_shortcode' === $column ) {
			echo '<code>' . esc_html( self::shortcode_for( $post_id ) ) . '</code>';
		}
	}

	/**
	 * @param int $id Post id.
	 * @return string
	 */
	public static function shortcode_for( $id ) {
		return sprintf( '[forum_page id="%d"]', (int) $id );
	}
```

- [ ] **Step 4: Run** the test — PASS. **Step 5: Commit** `Show each Forum Page its shortcode in the list`.

---

### Task 3: Seed the Kinetic page once

**Files:**
- Create: `includes/class-epi-forum-page-seed.php`
- Create: `includes/seed/kinetic-wireless-switches.php` (returns the values array)
- Create: `assets/seed/kinetic-hero.jpg` (from Figma raw image 5, 1320×1297), `assets/seed/kinetic-switch.png` (raw image 3, 512×512)
- Modify: `includes/class-epi-feature-registry.php:325-334` (require + init)
- Test: `tests/forum-page-seed.spec.js`

**Interfaces:**
- `EPI_Forum_Page_Seed::init()` hooks `init` at priority 20 (after `register` at 10).
- `EPI_Forum_Page_Seed::maybe_seed()` — runs once, guarded by option `epi_forum_page_seeded`.
- `EPI_Forum_Page_Seed::seed()` — returns the new post id or 0. Public so a test can call it.
- `includes/seed/kinetic-wireless-switches.php` returns `array( 'title' => ..., 'slug' => ..., 'values' => array( field_id => value ), 'images' => array( field_id_or_repeater.cell => asset filename ) )`.

- [ ] **Step 1: Failing test** `tests/forum-page-seed.spec.js`:

```js
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The plugin ships the Kinetic page. The harness activates the plugin before
// any test runs, so by now the seed has had its one chance.

test('the Kinetic page is there on a fresh site, filled in', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/wp-admin/edit.php?post_type=forum_page');
  const row = page.locator('.wp-list-table tr', { hasText: 'Getting started with kinetic wireless switches' }).first();
  await expect(row).toBeVisible();

  await row.hover();
  await row.locator('.row-actions a', { hasText: 'Edit' }).first().click();
  await page.waitForURL(/page=forum-page/);

  await expect(page.locator('#hero_heading')).toHaveValue('Getting started with kinetic wireless switches');
  await expect(page.locator('#hero_meta_read')).toHaveValue('6 MIN READ');
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await expect(page.locator('#how_heading')).toHaveValue('Press, generate, transmit, switch');
});

test('the Kinetic page renders every section with its photo', async ({ page }) => {
  await page.goto('/forum-pages/getting-started-with-kinetic-wireless-switches/');
  await expect(page.locator('.epi-forum-page__hero h1')).toContainText('kinetic wireless switches');
  await expect(page.locator('.epi-forum-page__hero img')).toHaveAttribute('src', /kinetic-hero/);
  await expect(page.locator('.epi-forum-page')).toContainText('Six things to settle on site');
  await expect(page.locator('.epi-forum-page')).toContainText('Frequently asked');
});
```

- [ ] **Step 2: Run** it — FAIL (no such row; 404).

- [ ] **Step 3: Copy the two images** from the scratchpad into `assets/seed/` (`raw5.jpeg` → `kinetic-hero.jpg`, `raw3.png` → `kinetic-switch.png`).

- [ ] **Step 4: Write `includes/seed/kinetic-wireless-switches.php`** — every value below, verbatim. Repeater rows are `array( cell => value )`. Media cells hold the asset filename here; the seeder swaps them for attachment ids.

```php
<?php
/**
 * The Kinetic page as shipped. Copy from the Figma design; FAQ answers 2–6
 * are drafted from the page's own sections.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

return array(
	'title'  => 'Getting started with kinetic wireless switches',
	'slug'   => 'getting-started-with-kinetic-wireless-switches',
	'values' => array(
		'hero_eyebrow'        => 'KINETIC',
		'hero_heading'        => 'Getting started with kinetic wireless switches',
		'hero_breadcrumb'     => 'Kinetic wireless switches',
		'hero_intro'          => 'A switch that powers itself from the press, sends the command by radio and needs no cable back to the circuit. Here is what that means on site, where it earns its place, and what to specify.',
		'hero_image'          => 'kinetic-hero.jpg',
		'hero_cta1_label'     => 'View the Kinetic range',
		'hero_cta1_url'       => '/product-category/kinetic/',
		'hero_cta2_label'     => 'Talk to a Technical Specialist',
		'hero_cta2_url'       => '/contact/',
		'hero_meta_category'  => 'LIGHTING CONTROLS',
		'hero_meta_read'      => '6 MIN READ',
		'hero_meta_updated'   => 'UPDATED JULY 2026',

		'sectionbar_label'    => 'ON THIS PAGE',
		'sectionbar_phone'    => '0161 359 4949',

		'what_nav_label'      => 'What they are',
		'what_heading'        => 'A switch with no supply and no battery',
		'what_body'           => '<p>A kinetic switch generates its own power. Pressing the rocker moves a small generator inside the plate, and that movement produces enough energy to send a single radio command — nothing else is needed behind it.</p><p>Because there is no supply at the switch, there is no cable to run and no back box to cut in. The switching itself happens at a paired receiver on the lighting circuit, at the fitting or in the ceiling void.</p>',
		'what_pullquote'      => 'The switch position is no longer decided by the wiring. It is decided by where the client wants to reach.',
		'what_parts'          => array(
			array( 'title' => 'Rocker', 'desc' => 'Press supplies the energy' ),
			array( 'title' => 'Generator', 'desc' => 'Induction coil, no battery' ),
			array( 'title' => 'Transmitter', 'desc' => 'Sends the paired command' ),
		),

		'how_nav_label'       => 'How they work',
		'how_heading'         => 'Press, generate, transmit, switch',
		'how_intro'           => 'Four things happen in the time it takes to click the rocker. Every kinetic installation is a version of this sequence.',
		'how_steps'           => array(
			array( 'title' => 'Press', 'body' => 'The rocker moves a lever inside the plate. That movement is the only energy the switch ever needs.', 'image' => 'kinetic-switch.png' ),
			array( 'title' => 'Generate', 'body' => 'The movement drives a miniature generator, producing a brief pulse of power — no battery, no supply.', 'image' => 'kinetic-switch.png' ),
			array( 'title' => 'Transmit', 'body' => 'The pulse powers a short radio signal carrying the command and the switch identity.', 'image' => 'kinetic-switch.png' ),
			array( 'title' => 'Switch', 'body' => 'The paired receiver on the lighting circuit reads the command and switches or dims the load.', 'image' => 'kinetic-switch.png' ),
		),

		'advantages_nav_label'   => 'Advantages',
		'advantages_heading'     => 'What you gain, and what to allow for',
		'advantages_wins_label'  => 'WHERE KINETIC WINS',
		'advantages_wins'        => array(
			array( 'item' => 'No cable back to the switch position — nothing to chase into a finished wall' ),
			array( 'item' => 'No batteries to replace over the life of the fitting' ),
			array( 'item' => 'Switch plates can be sited on glass, tile, brick or plasterboard' ),
			array( 'item' => 'Add two-way and three-way control after first fix without rewiring' ),
			array( 'item' => 'One switch can be paired to several receivers, or several to one' ),
		),
		'advantages_allow_label' => 'WHAT TO ALLOW FOR',
		'advantages_allow'       => array(
			array( 'item' => 'Every switched circuit needs a paired receiver at the fitting or in the ceiling void' ),
			array( 'item' => 'Radio range is affected by the building fabric — check dense walls and metal on site' ),
			array( 'item' => 'Existing wired switches are not reused; they are replaced or made off' ),
			array( 'item' => 'Pairing is a commissioning step and should be recorded for the handover' ),
		),

		'where_nav_label'     => 'Where they work',
		'where_heading'       => 'Jobs where the cable is the problem',
		'where_intro'         => 'Kinetic switching pays for itself wherever a new switch drop would mean damage, delay or a second visit.',
		'where_cards'         => array(
			array( 'image' => 0, 'title' => 'Retrofit and refurbishment', 'body' => 'Finished plaster, tiled walls and listed interiors where chasing a new switch drop is not an option.' ),
			array( 'image' => 0, 'title' => 'Kitchens and utility', 'body' => 'Switching at the worktop, island or pantry after the units are set out.' ),
			array( 'image' => 0, 'title' => 'Bathrooms', 'body' => 'Plate sited outside the zones, receiver at the fitting.' ),
			array( 'image' => 0, 'title' => 'Glass and partitions', 'body' => 'Bonded to glass balustrades, stud partitions and demountable office walls.' ),
			array( 'image' => 0, 'title' => 'Outbuildings and garden rooms', 'body' => 'Local switching without a second cable run from the house.' ),
			array( 'image' => 0, 'title' => 'Landlord and HMO work', 'body' => 'Adding two-way control to stairs and landings between tenancies.' ),
		),

		'comparison_nav_label' => 'Compare',
		'comparison_heading'   => 'Kinetic, battery RF and wired switching',
		'comparison_col1'      => 'KINETIC',
		'comparison_col2'      => 'BATTERY RF',
		'comparison_col3'      => 'WIRED',
		'comparison_rows'      => array(
			array( 'label' => 'Power at the switch', 'col1' => 'Harvested from the press', 'col2' => 'Battery cell', 'col3' => 'Mains cable' ),
			array( 'label' => 'Cable to switch position', 'col1' => 'None', 'col2' => 'None', 'col3' => 'Required' ),
			array( 'label' => 'Consumables', 'col1' => 'None', 'col2' => 'Battery replacement', 'col3' => 'None' ),
			array( 'label' => 'Receiver required', 'col1' => 'Yes', 'col2' => 'Yes', 'col3' => 'No' ),
			array( 'label' => 'Best suited to', 'col1' => 'Retrofit and late changes', 'col2' => 'Low-use positions', 'col3' => 'New build first fix' ),
		),

		'specifying_nav_label'     => 'Specifying',
		'specifying_heading'       => 'Six things to settle on site',
		'specifying_points'        => array(
			array( 'title' => 'Count the circuits, not the switches', 'body' => 'A receiver is needed per switched load. Two plates onto one circuit still need only one receiver.' ),
			array( 'title' => 'Site the receiver where you can reach it', 'body' => 'Ceiling void, luminaire back box or above an access panel — pairing may need a second visit.' ),
			array( 'title' => 'Walk the range on site', 'body' => 'Test from the intended plate position before you fix it, with doors closed and the building as built.' ),
			array( 'title' => 'Check the load is compatible', 'body' => 'Confirm dimmable drivers and lamp types against the receiver before you order.' ),
			array( 'title' => 'Record the pairing', 'body' => 'Note plate against circuit on the as-built so the next visit is not a guessing game.' ),
			array( 'title' => 'Leave the client a spare plate position', 'body' => 'Kinetic makes later additions easy; agree where a second control might go.' ),
		),
		'specifying_trouble_label' => 'IF SOMETHING IS NOT WORKING',
		'specifying_trouble'       => array(
			array( 'title' => 'The receiver does not respond', 'body' => 'Re-run the pairing sequence with the plate held at the fitting, then re-test from the wall position. Working close-to but not at the wall is a range problem, not a fault.' ),
			array( 'title' => 'One of two gangs works', 'body' => 'Each gang pairs separately. Confirm the second gang has been paired to its own receiver.' ),
			array( 'title' => 'Dimming is uneven or flickers', 'body' => 'Check the driver or lamp is dimmable and matched to the receiver output. Mixed loads on one circuit are the usual cause.' ),
		),

		'faqs_nav_label' => 'FAQs',
		'faqs_heading'   => 'Frequently asked',
		'faqs_items'     => array(
			array( 'question' => 'Do kinetic switches need a battery?', 'answer' => 'No. The energy comes from the press itself, so there is nothing to charge and nothing to replace.' ),
			array( 'question' => 'Can I use a kinetic switch with existing wiring?', 'answer' => 'Yes. The receiver fits at the fitting or in the ceiling void on the existing lighting circuit. The old wired switch is replaced or made off; it is not reused.' ),
			array( 'question' => 'How many switches can control one light?', 'answer' => 'Several plates can be paired to one receiver, and one plate to several receivers, so two-way and three-way control is a pairing step rather than a wiring job.' ),
			array( 'question' => 'Will it work through a wall?', 'answer' => 'Usually. Range depends on the building fabric — dense masonry and metal reduce it — so test from the intended plate position before fixing.' ),
			array( 'question' => 'Are they suitable for bathrooms?', 'answer' => 'Yes, with the plate sited outside the zones and the receiver at the fitting.' ),
			array( 'question' => 'Can they be surface fixed to glass or tile?', 'answer' => 'Yes. With no back box to cut in, plates can be bonded to glass, tile, brick or plasterboard.' ),
		),

		'cta_eyebrow'    => 'NEXT STEP',
		'cta_heading'    => 'Send us the drawing and we will mark up the controls',
		'cta_body'       => 'Our in-house Technical Specialists will confirm receivers against the circuits, check load compatibility and quote the plates. Orders placed before 2pm go out on DPD next day delivery.',
		'cta_cta1_label' => 'Talk to a Technical Specialist',
		'cta_cta1_url'   => '/contact/',
		'cta_cta2_label' => 'Download the catalogue',
		'cta_cta2_url'   => '/catalogues/',
		'cta_stats'      => array(
			array( 'value' => '60+', 'label' => 'YEARS TRADING' ),
			array( 'value' => 'Next day', 'label' => 'DPD BEFORE 2PM' ),
			array( 'value' => 'ISO 9001', 'label' => '' ),
		),
	),
);
```

- [ ] **Step 5: Write `includes/class-epi-forum-page-seed.php`**:

```php
<?php
/**
 * Ships the first Forum Page.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the Kinetic page the first time the feature runs on a site.
 *
 * @since 1.9.0
 */
final class EPI_Forum_Page_Seed {

	const OPTION = 'epi_forum_page_seeded';

	/**
	 * @return void
	 */
	public static function init() {
		// After EPI_Forum_Page_Type::register() at 10, so the post type exists.
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 20 );
	}

	/**
	 * Seed once per site. The stamp is written whatever happens, so a site
	 * that deletes the page does not get it back on the next request.
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( get_option( self::OPTION ) ) {
			return;
		}
		update_option( self::OPTION, '1', false );
		self::seed();
	}

	/**
	 * Create the record, its images and its values.
	 *
	 * @return int New post id, or 0 when a page with that slug already exists
	 *             or the library is missing.
	 */
	public static function seed() {
		if ( ! class_exists( '\Blueworx\PageEditor\v1\Editor' ) ) {
			return 0;
		}

		$page = include EPI_PLUGIN_DIR . 'includes/seed/kinetic-wireless-switches.php';

		if ( get_page_by_path( $page['slug'], OBJECT, EPI_Forum_Page_Type::POST_TYPE ) ) {
			return 0;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => EPI_Forum_Page_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $page['title'],
				'post_name'   => $page['slug'],
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		$values = self::attach_images( $page['values'], $id );

		$screen = \Blueworx\PageEditor\v1\Editor::get( EPI_Forum_Page_Type::SCREEN );
		if ( $screen ) {
			\Blueworx\PageEditor\v1\Store::for( $screen )->write( $values, $id );
		}

		return $id;
	}

	/**
	 * Swap asset filenames for attachment ids, one attachment per file.
	 *
	 * @param array $values Field values, media cells holding filenames.
	 * @param int   $post_id Parent post.
	 * @return array
	 */
	private static function attach_images( array $values, $post_id ) {
		$ids = array();

		foreach ( $values as $field => &$value ) {
			if ( is_string( $value ) && self::is_asset( $value ) ) {
				$value = self::attachment( $value, $post_id, $ids );
			} elseif ( is_array( $value ) ) {
				foreach ( $value as &$row ) {
					foreach ( $row as &$cell ) {
						if ( is_string( $cell ) && self::is_asset( $cell ) ) {
							$cell = self::attachment( $cell, $post_id, $ids );
						}
					}
					unset( $cell );
				}
				unset( $row );
			}
		}
		unset( $value );

		return $values;
	}

	/**
	 * @param string $value A field value.
	 * @return bool
	 */
	private static function is_asset( $value ) {
		return (bool) preg_match( '/^[a-z0-9-]+\.(jpg|png)$/', $value ) && file_exists( EPI_PLUGIN_DIR . 'assets/seed/' . $value );
	}

	/**
	 * Copy one shipped image into the Media Library.
	 *
	 * @param string $file    Filename under assets/seed/.
	 * @param int    $post_id Parent post.
	 * @param array  $ids     Already-made attachments, by filename.
	 * @return int Attachment id, or 0.
	 */
	private static function attachment( $file, $post_id, array &$ids ) {
		if ( isset( $ids[ $file ] ) ) {
			return $ids[ $file ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() moves its input, so hand it a copy.
		$tmp = wp_tempnam( $file );
		copy( EPI_PLUGIN_DIR . 'assets/seed/' . $file, $tmp );

		$attachment = media_handle_sideload(
			array(
				'name'     => $file,
				'tmp_name' => $tmp,
			),
			$post_id
		);

		if ( is_wp_error( $attachment ) ) {
			return 0;
		}

		$ids[ $file ] = (int) $attachment;
		return $ids[ $file ];
	}
}
```

- [ ] **Step 6: Boot it** in `EPI_Feature_Registry::boot_forum_pages()` — add `require_once EPI_PLUGIN_DIR . 'includes/class-epi-forum-page-seed.php';` and `EPI_Forum_Page_Seed::init();` after the renderer.

- [ ] **Step 7: Check the hero partial** prints the image with its filename in `src` (it uses `wp_get_attachment_image` — the upload keeps the name `kinetic-hero`). If the partial only renders when the image id is non-zero, the test's `src` check holds.

- [ ] **Step 8: Reset the local site** so the seed can run: `php -r` against `.wp-test/wp/wp-load.php` deleting option `epi_forum_page_seeded`, then load any admin page. **Run** `tests/forum-page-seed.spec.js` — PASS. Run the whole editor + front specs — PASS.

- [ ] **Step 9: Uninstall** — add `delete_option( 'epi_forum_page_seeded' );` beside the other options in `uninstall.php`.

- [ ] **Step 10: Commit** `Ship the Kinetic page, filled in`.

---

### Task 4: Version, changelog, docs

**Files:**
- Modify: `external-product-images.php` (header + `EPI_VERSION`), `package.json`, `readme.txt`, `CHANGELOG.md`, `docs/elementor-template.md`

- [ ] **Step 1:** 1.8.0 → 1.9.0 in all four places.
- [ ] **Step 2:** Changelog `[1.9.0]` Added:
  - The Kinetic wireless switches page comes with the plugin, filled in and ready to edit under Forum Pages.
  - Every Forum Page has its own shortcode, shown in the Forum Pages list, so a page can be dropped into any Elementor page.
- [ ] **Step 3:** In `docs/elementor-template.md` add a short "Or place one page yourself" section: copy the shortcode from the list into a Shortcode widget on any page.
- [ ] **Step 4:** PHPCS once on the changed files; report findings, don't loop.
- [ ] **Step 5: Commit** `Release 1.9.0 - Kinetic page shipped, shortcode per page`, push, build the zip with `bash bin/build-zip.sh`.
