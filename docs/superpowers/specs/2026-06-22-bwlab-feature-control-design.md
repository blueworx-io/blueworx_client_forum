# BlueWorx Lab | Forum Lighting — Feature Control Centre

**Date:** 2026-06-22
**Status:** Approved design, pending spec review

## Goal

Move ~18 site customisations currently living in the **Code Snippets** plugin into
the existing **External Product Images** plugin, and rebrand that plugin as
**BlueWorx Lab | Forum Lighting** — the site's single custom-functionality plugin.

Each snippet becomes a self-contained **feature**. A new admin **control page**
lists every feature with an on/off switch (with a browser confirmation before
switching anything off) and action buttons for run-on-demand tools. The product
image gallery becomes just one feature among many.

## Background / current state

- Plugin: `external-product-images`, currently v1.0.6. Pure PHP/CSS/JS, no build step.
- Existing features: Elementor product gallery widget, product metadata viewer,
  product change log (with its own DB table `wp_epi_product_changes` and option
  `epi_change_log_db_version`).
- The snippets are a WordPress/WooCommerce grab-bag exported from Code Snippets
  (`forum-lighting-solutions.code-snippets.php`).

## Key decisions (from brainstorming)

1. **One plugin.** Everything goes into this plugin. Product images are just a feature.
2. **Rename display name** to `BlueWorx Lab | Forum Lighting`. Keep the folder slug
   (`external-product-images`), text domain, internal `EPI_` prefixes, and the
   existing change-log database **unchanged** so updating is non-breaking.
3. **Settings → BlueWorx Lab** control page with an **on/off switch per feature**.
   Switching a feature **off** triggers a browser confirmation; dangerous features get
   sterner wording.
4. **Scripts are kept verbatim.** Each snippet is dropped into its own file unchanged
   and is simply *included only when its switch is on*. No refactoring, no namespacing,
   no behaviour changes — the Lab page only controls whether each script loads. The one
   exception is the corrupted `£` symbol in the *Hide prices from guests* script
   (arrived as `Â£`); fixing it preserves the customer-facing output the site shows
   today. Garbled characters in comments/console output are left as-is.
5. **All features ship ON**, preserving today's behaviour exactly on update.
6. Both "temporary" snippets are kept verbatim and toggleable: the debug role-logger
   (still prints for every visitor when on) and the image-purge tool (keeps its own
   `Tools → Purge Product Images` admin page, which only appears when the feature is on).

## Architecture

### Feature registry (declarative)

Features are described declaratively in one registry — no per-feature class hierarchy.
Each entry is a definition with:

- `id` — unique slug, e.g. `role-based-pricing`.
- `title`, `description`, `group` — for the control page.
- `dangerous` (bool) + optional `danger_message` — drives the off-switch warning.
- `dependencies` — e.g. `['woocommerce']`, `['elementor']`, `['acf']`. A feature whose
  dependency is inactive is shown on the page as unavailable (toggle disabled) and is
  never booted.
- `boot` — a callback that activates the feature. For a moved snippet this simply
  `require`s its verbatim file from `includes/snippets/`; for an existing feature it
  calls that feature's init (e.g. `EPI_Product_Meta::init()`).

The snippet files in `includes/snippets/` hold each script **unchanged** (global
functions, `add_filter`/`add_shortcode` calls and all). Because a file is required at
most once per request and is only required when the feature is enabled, this gives
on/off control with zero edits to the script bodies. No namespacing is needed.

### Registry & flags

- `EPI_Feature_Registry` holds all feature definitions, returns them grouped, and
  resolves enabled state.
- Enabled state stored in a single option `epi_feature_flags` = `[ feature_id => bool ]`.
  **A missing key means enabled** (so a freshly-updated site has everything on, and any
  feature added in future also defaults on).
- Bootstrap (on `plugins_loaded` after the existing WooCommerce check): the registry
  iterates features and runs `boot` on each enabled, dependency-satisfied feature.
  Disabling a feature simply means its file/init never runs on the next page load — no
  dynamic unhooking required.

### Control page

- **Settings → BlueWorx Lab** submenu (capability `manage_options`, slug `bwlab`), via
  `add_options_page`.
- Renders feature **cards grouped by category**: Pricing, Product display, Shop
  behaviour, Account, Admin/email, Admin/product tools, Tools, Debug. Each card shows
  title, plain-English description, category, dependency status, and a toggle switch.
- Saving: a single form, posted back with a nonce and `manage_options` check; toggle
  input is sanitised into the flags option.
- **Off-switch warning:** a small Lab-page JS file intercepts toggling a switch to
  *off* and shows a `confirm()` dialog. Dangerous features supply sterner text.
- The Lab page is **toggles only** — no action buttons. Run-on-demand tools keep their
  own existing admin pages (see image purge), which appear only when enabled.

### Existing features become registry entries

- **External image gallery** — `boot` hooks the Elementor widget + asset registration.
  Deps: `woocommerce`, `elementor`.
- **Product metadata viewer** — `boot` calls `EPI_Product_Meta::init()`. Dep: `woocommerce`.
- **Product change log** — `boot` calls `EPI_Product_Change_Log::init()`. Dep:
  `woocommerce`. The activation hook still installs/upgrades the table regardless of the
  toggle, so the table always exists; the toggle only controls recording/display.

## Feature list (19)

Shortcodes and hooks preserved exactly unless noted. ⚠️ = off-switch danger warning.

### Pricing
- **Role-based pricing** ⚠️ — `woocommerce_product_get_price` /
  `woocommerce_product_variation_get_price`, EUR `woocommerce_currency` for
  `price_customer_3`, helper for the `ECD-special-prices` attribute, and the
  `[dynamic_product_price]` shortcode. *(Merged from one snippet block.)*
- **Hide prices from guests** ⚠️ — `woocommerce_get_price_html`: guests see nothing,
  members see ex-VAT only.

### Product display
- **External image gallery** *(existing)* — Elementor widget.
- **Hide internal attributes** — `woocommerce_display_product_attributes` filter
  hiding `pa_epim-{id}` (hardcoded ID list **kept as-is**), `pa_bullet-*`, `pa_ecd-*`,
  and labels starting "bullet".
- **Hide empty / N/A attributes** — `woocommerce_display_product_attributes` filter.
- **Product bullets** — `[product_bullets]` shortcode.
- **Fitting instructions button** — `[fitting_instructions_button]` shortcode (remote
  `wp_remote_head` existence check on the SKU PDF).
- **Datasheet button** — `[datasheet_button_alt]` shortcode.
- **Product video button** — `[product_video_button]` shortcode **and** the
  `woocommerce_display_product_attributes` filter that hides the video attribute.
- **Product filter widget** — `[product_category_filter]` shortcode with its inline
  JS + CSS (categories + lamp type/fitting class/IP rating/colour).

### Shop behaviour
- **Hide cart & purchasing for guests** ⚠️ (mild) — *(merged ×2)*
  `woocommerce_is_purchasable` returns false for guests **and** `init` removes the
  loop/single add-to-cart template actions for guests.
- **Restrict search to products** — `pre_get_posts` limits main search to `product`.

### Account
- **Account details (ACF)** — `[show_user_acf_fields]` shortcode. Dep: `acf`.

### Admin / email
- **Clean WooCommerce email footer** — *(merged ×2)* empties
  `woocommerce_email_footer_text` and removes the `mobile_messaging` action.
- **Catalogue drag-ordering** — `catalogue` CPT `page-attributes` support,
  `menu_order` default ordering, jQuery-UI sortable + `save_catalogue_drag_order`
  AJAX (nonce + `edit_posts` preserved).

### Admin / product tools
- **Product metadata viewer** *(existing)*.
- **Product change log** *(existing)*.

### Tools (run-on-demand)
- **Product image purge** ⚠️ — the `One_Time_WC_Product_Image_Purge` class kept
  verbatim. It keeps its own `Tools → Purge Product Images` admin page (scan / delete /
  clear, confirm-before-delete, auto-continuing batch). The page appears only when the
  feature is enabled.

### Debug
- **Log user role** — `wp_footer` console line, **kept verbatim** (prints for every
  visitor when enabled). Toggleable.

## Cleanup performed during the move

Deliberately minimal — the scripts are not refactored. Only:

- **One encoding fix:** the corrupted `£` in the *Hide prices from guests* output
  (`Â£` → `£`) so the customer-facing price renders correctly, as it does today.
  Mojibake inside comments/console output is left as-is.
- **Toggle grouping (not code changes):** the two "hide cart for guests" blocks share
  one switch; the two "email footer" blocks share one switch. Both underlying scripts
  still run verbatim when the switch is on.
- Existing nonce/capability checks are preserved untouched. The **Lab save** adds its
  own nonce + `manage_options` check (new code, not part of the moved scripts).

## Code Snippets deactivation (handover step)

When this ships, the matching Code Snippets entries **must be switched off** or the
site will fatal-error on duplicate declarations. Deliverable includes the explicit
list of snippet titles to disable. (We do not deactivate them automatically.)

## File layout

```
external-product-images.php          ← bootstrap: deps check + registry boot; header rename
includes/
  class-epi-feature-registry.php     ← declarative feature list + flag resolution
  class-epi-lab-page.php             ← Settings → BlueWorx Lab page render + toggle saving
  snippets/                          ← each moved script, VERBATIM, one file per snippet
    role-based-pricing.php
    hide-prices-from-guests.php      ← the only edited file (£ fix)
    hide-internal-attributes.php
    hide-na-attributes.php
    product-bullets.php
    fitting-instructions-button.php
    datasheet-button.php
    product-video-button.php
    product-category-filter.php
    hide-cart-for-guests.php         ← both original blocks, verbatim, one file
    restrict-search.php
    acf-account-details.php
    email-footer.php                 ← both original blocks, verbatim, one file
    catalogue-ordering.php
    image-purge.php                  ← One_Time_WC_Product_Image_Purge, verbatim
    debug-log-user-role.php
  class-epi-images-provider.php, class-epi-widget.php   ← existing, unchanged
  class-epi-product-meta.php, class-epi-product-change-log.php   ← existing, unchanged
assets/
  css/epi-lab.css                    ← control page styles
  js/epi-lab.js                      ← off-switch confirmation
  (existing gallery + product-meta assets retained)
```

The gallery, metadata viewer and change log stay as their current classes; their
registry entries just call the existing init methods. Only the moved Code Snippets
scripts live under `includes/snippets/`.

## Out of scope (non-goals)

- No converting hardcoded values (attribute ID list, pricing percentages, EUR rule,
  URLs) into configurable settings — behaviour is preserved verbatim.
- No renaming the folder slug, text domain, or internal `EPI_` prefixes.
- No new database tables (reuse one option + the existing change-log table).
- No automatic deactivation of the Code Snippets entries.

## Versioning & packaging

- Minor bump (new feature): **1.0.6 → 1.1.0**. Update `Version:` header, `EPI_VERSION`
  constant, and readme `Stable tag` + changelog.
- Build the deployment zip `external-product-images-1.1.0.zip` (root folder is the
  plugin; exclude `.git`, `docs`, dev artifacts). Remove the stale `1.0.5` and `1.0.6`
  zips first.

## Verification (manual — no build/test harness in repo)

1. `php -l` each new/changed PHP file (if PHP is available locally).
2. Activate plugin: **Settings → BlueWorx Lab** lists all features, all ON.
3. Toggle a feature off → confirm warning appears; dangerous features show sterner
   text. Save → behaviour stops; re-enable → behaviour returns.
4. Shortcodes still render on product pages; pricing/guest rules behave as before.
5. Image purge: its `Tools → Purge Product Images` page appears when on; dry scan →
   delete (batch) → clear, all behind confirms.
6. Debug role line prints to the console when the feature is on.

## Risks

- **Duplicate-declaration fatals** if Code Snippets entries aren't disabled — the
  moved scripts keep their original global function names, so the matching Code
  Snippets entries MUST be turned off. Mitigated by the explicit handover list.
- **Accidentally disabling a critical feature** (pricing, guest price-hiding) affects
  the live store instantly — mitigated by danger confirmations and all-on defaults.
- **Image purge is destructive** — keeps its existing confirm + backup warning.
