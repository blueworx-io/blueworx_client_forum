# Forum Banner widget

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An Elementor widget that shows the home-page banner slider from the Figma design, with the three designed slides already in it and every word, button, photo and colour editable in Elementor.

**Architecture:** One renderer (`EPI_Banner`) takes a plain settings array and prints the slider; the Elementor widget and a `[forum_banner]` shortcode both feed it. The three Figma slides are the widget's control defaults and the shortcode's data, so an empty widget already shows them. The slider's own small script handles arrows, dots, swipe, keyboard and autoplay — no library.

**Tech Stack:** WordPress plugin PHP (PHPCS WordPress ruleset), Elementor `Widget_Base` + `Repeater`, vanilla JS, Playwright.

**Spec:** Figma `ocfLyNE9GtyOYMxqq7Hwt7` frames `14:240` (Kinetic), `14:265` (BHS Decorative), `14:291` (General); `14:317` is the home page for context only. Approved in chat 2026-09-12: autoplay on by default, arrows still work, autoplay time editable.

## Global Constraints

- Version **1.11.0** in the plugin header, `EPI_VERSION`, `package.json`, `readme.txt`; changelog entry.
- Feature switch `banner` in the Content group, **no dependency on Elementor** — the shortcode and assets boot on every site; the widget registers only when `elementor/widgets/register` fires. The test harness has no Elementor, so the shortcode is what the tests hit.
- Design values (1440 canvas): banner 620px tall, text panel and photo 50/50; text starts 110px in; eyebrow 13px bold; heading 46px/55px bold; body 16px/24px; button 54px pill, red `#a71f31`, white text 16px; footnote 12px uppercase `#7c8794`; badge 126×34 red pill, 12px bold white, 48px from top and 80px from right; arrows 14×28 strokes 42px from each edge, vertically centred; dots 10px, 12px apart, 60px from right and 47px from bottom, active red, inactive `#7c8794` (dark) / `#c9cfd6` (light). Dark panel `#051a2d` with white heading and `#c9cfd6` body; light panel `#ffffff` with `#051a2d` heading and `#1f1f1f` body. Arc decoration: two circles (764 and 948 wide) at x −388, y 172 of the panel, 1.4px stroke at 16% of the text colour.
- Photos ship at `assets/img/banner/{kinetic,bhs,general}.jpg` (already downloaded and resized).
- Below 900px the photo sits above the text and the banner is as tall as its content.

---

### Task 1: Renderer, defaults and shortcode

**Files:**
- Create: `includes/class-epi-banner.php`
- Create: `assets/css/epi-banner.css`, `assets/js/epi-banner.js`
- Modify: `includes/class-epi-feature-registry.php` (definition + `boot_banner()`)
- Test: `tests/banner.spec.js`

**Interfaces:**
- `EPI_Banner::init()` — hooks shortcode, `wp_enqueue_scripts` (register assets), and `elementor/frontend/after_register_styles|scripts` (register for Elementor too).
- `EPI_Banner::defaults(): array` — the settings array for the three slides.
- `EPI_Banner::render( array $settings ): string` — the slider HTML. Settings keys: `slides` (list of `eyebrow, heading, body, button_label, button_url, footnote, badge, image_url, image_alt, theme ('dark'|'light'), eyebrow_color ('grey'|'red')`), `autoplay ('yes'|'')`, `interval` (seconds, int), `arrows ('yes'|'')`, `dots ('yes'|'')`.
- Shortcode `[forum_banner]` renders `render( defaults() )`.

- [ ] **Step 1: Failing test** `tests/banner.spec.js`:

```js
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

async function hostPage(page, content) {
  await loginAsAdmin(page);
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text())
  );
  const created = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce },
    data: { title: `Banner host ${Date.now()}`, content, status: 'publish' },
  });
  return (await created.json()).id;
}

test('the banner shows the three designed slides and moves on the arrows', async ({ page }) => {
  const id = await hostPage(page, '[forum_banner]');
  await page.goto(`/?page_id=${id}`);

  const banner = page.locator('.epi-banner');
  await expect(banner.locator('.epi-banner__slide')).toHaveCount(3);
  await expect(banner.locator('.epi-banner__slide[aria-hidden="false"] h2')).toHaveText('Wireless switches that power themselves.');
  await expect(banner.locator('.epi-banner__dot')).toHaveCount(3);
  await expect(banner).toHaveAttribute('data-autoplay', '6');

  await banner.locator('.epi-banner__arrow--next').click();
  await expect(banner.locator('.epi-banner__slide[aria-hidden="false"] h2')).toHaveText('The BHS Decorative Range has landed.');
  await expect(banner.locator('.epi-banner__slide[aria-hidden="false"]')).toHaveClass(/epi-banner__slide--light/);
  await expect(banner.locator('.epi-banner__slide[aria-hidden="false"] .epi-banner__badge')).toHaveText('NEW IN');

  await banner.locator('.epi-banner__arrow--prev').click();
  await expect(banner.locator('.epi-banner__slide[aria-hidden="false"] h2')).toHaveText('Wireless switches that power themselves.');
});

test('the banner is offered as a feature on the Lab screen', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/wp-admin/options-general.php?page=bwlab');
  await expect(page.locator('#epi-lab-form')).toContainText('Banner slider');
});
```

- [ ] **Step 2: Run** `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8891 WP_ADMIN_USER=admin WP_ADMIN_PASS=wptest-admin-pw npx playwright test tests/banner.spec.js --workers=1` — FAIL (no `.epi-banner`).

- [ ] **Step 3: Feature entry** in `definitions()` after `forum-pages`:

```php
			'banner'                      => array(
				'title'          => __( 'Banner slider', 'blueworx_client_forum' ),
				'description'    => __( 'The home-page banner: an Elementor widget with the three designed slides built in, also placed with [forum_banner].', 'blueworx_client_forum' ),
				'group'          => 'content',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array(),
				'boot'           => array( __CLASS__, 'boot_banner' ),
			),
```

and:

```php
	public static function boot_banner() {
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-banner.php';
		EPI_Banner::init();

		add_action(
			'elementor/widgets/register',
			static function ( $widgets_manager ) {
				require_once EPI_PLUGIN_DIR . 'includes/class-epi-banner-widget.php';
				$widgets_manager->register( new \EPI_Banner_Widget() );
			}
		);
	}
```

- [ ] **Step 4: `includes/class-epi-banner.php`** — `init()`, `defaults()` (three slides with the copy from the frames, `image_url` = `EPI_PLUGIN_URL . 'assets/img/banner/kinetic.jpg'` etc., `theme` dark/light/dark, `eyebrow_color` grey/red/grey, badge `NEW IN` on slide 2 only, buttons `Explore Kinetic controls → /product-category/kinetic/`, `Shop the BHS range → /product-category/decorative/`, `See All Products → /shop/`, footnotes `IN-HOUSE TECHNICAL SPECIALISTS ON HAND`, `EXPERTISE. EFFICIENCY. EXCELLENCE.`, none), `register_assets()` (version by filemtime like the forum page), `shortcode()`, `render( $settings )`.

  Markup per slide (`aria-hidden` true for all but the first; the script drives it):

```html
<div class="epi-banner" data-autoplay="6" role="region" aria-roledescription="carousel" aria-label="Featured">
  <div class="epi-banner__slides">
    <article class="epi-banner__slide epi-banner__slide--dark" aria-hidden="false" role="group" aria-roledescription="slide" aria-label="1 of 3">
      <div class="epi-banner__panel">
        <svg class="epi-banner__arc" …two circles… />
        <p class="epi-banner__eyebrow epi-banner__eyebrow--grey">…</p>
        <h2 class="epi-banner__heading">…</h2>
        <p class="epi-banner__body">…</p>
        <a class="epi-banner__button" href="…">…</a>
        <p class="epi-banner__footnote">…</p>
      </div>
      <div class="epi-banner__media"><img src="…" alt="…"><span class="epi-banner__badge">NEW IN</span></div>
    </article>
  </div>
  <button class="epi-banner__arrow epi-banner__arrow--prev" aria-label="Previous slide">…svg…</button>
  <button class="epi-banner__arrow epi-banner__arrow--next" aria-label="Next slide">…</button>
  <div class="epi-banner__dots" role="tablist"><button class="epi-banner__dot" role="tab" aria-selected="true" aria-label="Slide 1"></button>…</div>
</div>
```

  Only print the arrows/dots when their setting is `yes`. Only print the badge when set. Escape everything.

- [ ] **Step 5: `assets/css/epi-banner.css`** to the Global Constraints values; slides stacked with `grid-area: 1 / 1`, `[aria-hidden="true"] { opacity: 0; visibility: hidden }`, a 400ms opacity fade; `--light` swaps panel and text colours, arrows and dots follow the *current* slide's theme via a class the script puts on `.epi-banner` (`epi-banner--light`).

- [ ] **Step 6: `assets/js/epi-banner.js`** — for each `.epi-banner`: `show(i)` sets `aria-hidden`, dot `aria-selected`, and the `epi-banner--light` class; arrows, dots, ArrowLeft/ArrowRight when focus is inside, touch swipe (40px), autoplay from `data-autoplay` seconds (0 = off) paused on hover/focus and after any manual move restarted; honours `prefers-reduced-motion` by not autoplaying.

- [ ] **Step 7: Run** the test — PASS. Commit `Add the Forum Banner slider and its shortcode`.

---

### Task 2: Elementor widget

**Files:**
- Create: `includes/class-epi-banner-widget.php`

- [ ] **Step 1:** `EPI_Banner_Widget extends \Elementor\Widget_Base`: name `epi-forum-banner`, title `Forum: Banner slider`, icon `eicon-slider-push`, categories `general`, style/script depends `epi-banner`.
- [ ] **Step 2: Controls.** Section *Slides*: a `Repeater` with `eyebrow` (TEXT), `eyebrow_color` (SELECT grey|red), `heading` (TEXTAREA), `body` (TEXTAREA), `button_label` (TEXT), `button_url` (URL), `footnote` (TEXT), `badge` (TEXT), `image` (MEDIA), `theme` (SELECT dark|light); `title_field` `{{{ heading }}}`; `default` built from `EPI_Banner::defaults()` (map `image_url` to `array( 'url' => … )`, `button_url` to `array( 'url' => … )`). Section *Slider*: `autoplay` (SWITCHER, default yes), `interval` (NUMBER, seconds, min 2, max 30, default 6, condition autoplay), `arrows` (SWITCHER yes), `dots` (SWITCHER yes).
- [ ] **Step 3: `render()`** maps `get_settings_for_display()` back to the renderer's shape (`image['url']`, `button_url['url']`, `is_external`/`nofollow` ignored beyond `target`) and echoes `EPI_Banner::render()`.
- [ ] **Step 4:** PHPCS on the file. No Playwright possible without Elementor — say so in the handover. Commit `Offer the banner as an Elementor widget`.

---

### Task 3: Version, changelog, zip

- [ ] 1.10.0 → 1.11.0 in the four places; changelog `[1.11.0] Added`: "Banner slider: an Elementor widget (and `[forum_banner]`) with the three designed slides built in — every word, button, photo and colour editable; autoplay with an adjustable time, arrows and dots."
- [ ] Run the Forum Pages suites + banner spec; PHPCS once; commit `Release 1.11.0 - Banner slider`; push; `bash bin/build-zip.sh`.
