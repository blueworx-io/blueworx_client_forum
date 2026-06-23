# BlueWorx Lab | Forum Lighting — Feature Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (inline) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the Code Snippets scripts into the plugin verbatim, rebrand it as BlueWorx Lab | Forum Lighting, and add a Settings → BlueWorx Lab page that switches each feature (and the existing gallery/meta/change-log) on or off.

**Architecture:** A declarative `EPI_Feature_Registry` lists every feature (id, title, description, group, dangerous, dependencies, boot callback). On `plugins_loaded` it boots each enabled, dependency-satisfied feature — booting a moved snippet `require`s its verbatim file in `includes/snippets/`; booting an existing feature calls its init. `EPI_Lab_Page` renders the toggles under Settings and saves the `epi_feature_flags` option (missing key = enabled).

**Tech Stack:** PHP 7.4+ (verified PHP 8.3.3 locally for `php -l`), WordPress 6.0+, WooCommerce, Elementor, ACF. No build step, no test framework — verification is `php -l` per file plus the manual checks listed.

## Global Constraints

- Plugin display name: `BlueWorx Lab | Forum Lighting`. Folder slug `external-product-images`, text domain `external-product-images`, internal `EPI_` prefixes, and the change-log DB table/option all UNCHANGED.
- Version → `1.1.0` everywhere (`Version:` header, `EPI_VERSION`, readme `Stable tag` + changelog).
- Moved scripts are VERBATIM. The only edited byte is the corrupted `£` (`Â£` → `£`) in the guest-price script. Comment/console mojibake left as-is.
- All features default ON (`epi_feature_flags` missing key = enabled).
- Dangerous features (`role-based-pricing`, `hide-prices-from-guests`, `hide-cart-for-guests`, `image-purge`) get sterner off-switch confirmation text.
- Capability for the Lab page and saves: `manage_options`, with nonce.
- Preserve every existing nonce/capability check inside the moved scripts.

---

### Task 1: Extract the 16 snippets into verbatim files under `includes/snippets/`

**Files:**
- Create: `includes/snippets/role-based-pricing.php`, `hide-prices-from-guests.php`, `hide-internal-attributes.php`, `hide-na-attributes.php`, `product-bullets.php`, `fitting-instructions-button.php`, `datasheet-button.php`, `product-video-button.php`, `product-category-filter.php`, `hide-cart-for-guests.php`, `restrict-search.php`, `acf-account-details.php`, `email-footer.php`, `catalogue-ordering.php`, `image-purge.php`, `debug-log-user-role.php`
- Source: `forum-lighting-solutions.code-snippets.php` (the provided export)

**Mapping (snippet block → file):**
- Hide Specific Product Attributes → `hide-internal-attributes.php`
- Display Attributes as Bullets → `product-bullets.php`
- Display Dynamic User Pricing (price filters + EUR + helper + `[dynamic_product_price]`) → `role-based-pricing.php`
- Show ACF User Details → `acf-account-details.php`
- Hide N/A Attributes → `hide-na-attributes.php`
- Create Fitting Instruction Url → `fitting-instructions-button.php`
- Create Data Sheet Url → `datasheet-button.php`
- Generate Product Page Filters → `product-category-filter.php`
- Restrict Search to Products & Sku → `restrict-search.php`
- Hide WooCommerce Cart Buttons (both blocks) → `hide-cart-for-guests.php`
- Remove Woo App Text + Remove WooCommerce Email Footer (both blocks) → `email-footer.php`
- Hide Price From Logged Out Users → `hide-prices-from-guests.php`
- Console Log User Role → `debug-log-user-role.php`
- Allow Filter in Catalogues → `catalogue-ordering.php`
- Generate Video Preview Button (shortcode + hide-video filter) → `product-video-button.php`
- Remove Historical WooCommerce Images (`One_Time_WC_Product_Image_Purge` + `new ...()`) → `image-purge.php`

**Steps:**
- [ ] Each file starts with `<?php` + `if ( ! defined( 'ABSPATH' ) ) { exit; }` then the snippet body verbatim.
- [ ] `hide-prices-from-guests.php`: change the single `Â£` to `£`. No other edits anywhere.
- [ ] Save all files UTF-8 (no BOM). Leave comment/console mojibake untouched.
- [ ] **Verify:** `php -l` each file → "No syntax errors detected".

---

### Task 2: Feature registry

**Files:**
- Create: `includes/class-epi-feature-registry.php`

**Interfaces (Produces):**
- `EPI_Feature_Registry::definitions(): array` — id ⇒ `[ 'title','description','group','dangerous'(bool),'danger_message'(string|''),'dependencies'(array),'boot'(callable) ]`. Order: pricing, product-display, shop, account, admin-email, admin-product, tools, debug.
- `EPI_Feature_Registry::groups(): array` — ordered `group_key ⇒ label`.
- `EPI_Feature_Registry::is_enabled( string $id ): bool` — option `epi_feature_flags`; missing key ⇒ true.
- `EPI_Feature_Registry::dependency_active( string $dep ): bool` — `woocommerce`⇒`class_exists('WooCommerce')`, `elementor`⇒`did_action('elementor/loaded')`, `acf`⇒`function_exists('get_field')`.
- `EPI_Feature_Registry::missing_dependencies( array $deps ): array` — labels of inactive deps.
- `EPI_Feature_Registry::boot(): void` — for each definition, if `is_enabled` AND all deps active, call `boot`.
- `EPI_Feature_Registry::save( array $posted_ids ): void` — write `epi_feature_flags` = every id ⇒ in-array bool.

**Boot callbacks:**
- Snippets: `function() { require EPI_PLUGIN_DIR . 'includes/snippets/<file>.php'; }`.
- `gallery` (deps woocommerce+elementor): add `elementor/widgets/register` (require widget + register), and `elementor/frontend/after_register_styles|scripts` → `EPI_Plugin::register_gallery_assets`.
- `meta-viewer` (dep woocommerce): `[ 'EPI_Product_Meta', 'init' ]`.
- `change-log` (dep woocommerce): `[ 'EPI_Product_Change_Log', 'init' ]`.

**Group labels:** Pricing, Product display, Shop behaviour, Account, Admin & email, Admin product tools, Tools, Debug.

**Definitions content** (dangerous = true for role-based-pricing, hide-prices-from-guests, hide-cart-for-guests, image-purge; danger copy: pricing/guest-price → "This controls live store prices. Turning it off changes what customers see and pay. Continue?"; hide-cart → "This currently blocks guest purchasing. Turning it off lets logged-out visitors add to cart. Continue?"; image-purge → "This tool permanently deletes media. Hiding it removes the Tools page. Continue?").

- [ ] Write the registry class with all 19 definitions and the methods above.
- [ ] **Verify:** `php -l includes/class-epi-feature-registry.php`.

---

### Task 3: Lab settings page (render + save + assets)

**Files:**
- Create: `includes/class-epi-lab-page.php`, `assets/css/epi-lab.css`, `assets/js/epi-lab.js`

**Interfaces:**
- Consumes: `EPI_Feature_Registry` (definitions, groups, is_enabled, missing_dependencies, save).
- Produces: `EPI_Lab_Page::init()` — hooks `admin_menu` (add_options_page, parent `options-general.php`, slug `bwlab`, cap `manage_options`), `admin_init` (handle save: verify nonce `epi_lab_save` + cap, then `EPI_Feature_Registry::save( wp_unslash( $_POST['epi_features'] ?? [] ) )`, redirect with `settings-updated`), `admin_enqueue_scripts` (enqueue css/js only on the `settings_page_bwlab` hook).

**Render:** grouped cards; each card = checkbox (name `epi_features[]`, value = id, `checked` when enabled), title, description, group, danger flag via `data-danger` + `data-danger-message`. Features with missing deps: checkbox disabled + "Requires X (not active)" note. One nonce field, one Save button.

**JS (`epi-lab.js`):** on checkbox change to unchecked → `confirm()` (use `data-danger-message` if present, else generic "Switch off this feature?"); if cancelled, re-check the box.

**CSS (`epi-lab.css`):** card grid, toggle styling, danger accent, disabled state.

- [ ] Write the three files.
- [ ] **Verify:** `php -l includes/class-epi-lab-page.php`; manual: page renders under Settings, toggles reflect state, off shows confirm, save persists.

---

### Task 4: Bootstrap rewire + rename + version bump

**Files:**
- Modify: `external-product-images.php`

**Steps:**
- [ ] Header `Plugin Name:` → `BlueWorx Lab | Forum Lighting`; update Description to reflect a multi-feature site plugin; `Version:` → `1.1.0`; `EPI_VERSION` → `1.1.0`.
- [ ] In `init()`: keep WooCommerce missing-notice guard. When WooCommerce active, `require_once` the existing `class-epi-product-meta.php` and `class-epi-product-change-log.php` (defines classes; static helpers always available) and run `EPI_Product_Change_Log::maybe_upgrade()`. Then `require_once` the registry and `EPI_Feature_Registry::boot()` on the appropriate hook. Always load + init `EPI_Lab_Page`.
- [ ] Add static `register_gallery_assets()` (the body of the current `register_assets`). Remove the old direct gallery/meta/change-log wiring now handled by the registry. Keep the Elementor missing-notice (shown only if the gallery feature is enabled and Elementor is absent — or keep the existing global notice; simplest: drop the global Elementor notice since the Lab page now shows "Requires Elementor (not active)").
- [ ] Keep `register_activation_hook` → `EPI_Plugin::activate` (installs the change-log table).
- [ ] **Verify:** `php -l external-product-images.php`.

---

### Task 5: readme + changelog

**Files:**
- Modify: `readme.txt`

**Steps:**
- [ ] `Stable tag: 1.1.0`. Update the top description to "site functionality plugin (BlueWorx Lab | Forum Lighting) with a feature control page". Add `= 1.1.0 =` changelog entry summarising: moved site snippets in as toggleable features; new Settings → BlueWorx Lab on/off control page; product gallery/meta/change-log now toggleable. Note: matching Code Snippets entries must be disabled.
- [ ] **Verify:** read back the changed section.

---

### Task 6: Package + handover

**Steps:**
- [ ] `php -l` sweep across all PHP files (final).
- [ ] Remove `external-product-images-1.0.5.zip` and `external-product-images-1.0.6.zip`.
- [ ] Build `external-product-images-1.1.0.zip` containing a top-level `external-product-images/` folder with ONLY: `external-product-images.php`, `readme.txt`, `includes/`, `assets/`. Exclude `.git`, `.gitattributes`, `docs/`, `AGENTS.md`, `*.zip`, `*.code-snippets`.
- [ ] Verify the zip has no `node_modules`, `.git`, or `docs` inside.
- [ ] Produce the explicit list of Code Snippets titles to disable.

## Self-Review

- **Spec coverage:** rename (T4), Settings menu (T3), verbatim snippets + £ fix (T1), registry + flags + deps (T2), toggles + off-warning (T3), existing features toggleable (T2/T4), version + zip + handover (T5/T6). ✓
- **Placeholders:** none — file list and methods are concrete.
- **Type consistency:** registry method names reused identically across T2/T3/T4.
- **Risk:** moved scripts keep global function names → Code Snippets entries MUST be disabled (T6 handover).
