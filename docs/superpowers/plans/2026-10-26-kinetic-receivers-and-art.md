# Kinetic page: receiver table, switch photo, step icons — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the Kinetic Forum Page up to Steve's approved Figma updates: an editable six-receiver table in section 04, the new switch photo in section 01, new step icons in section 02.

**Architecture:** The receiver table is a new block of fields on the existing "Where they work" panel (a repeater, built from the controls the page editor already has), rendered by `section-where.php`. The live page was seeded before the table existed, so a one-off upgrade fills the six receivers in once. The photo and icons ship with the plugin, like the art they replace.

**Tech Stack:** PHP (WordPress plugin), the vendored BlueWorx page editor library, plain CSS, Playwright.

**Spec:** Approved in chat 2026-10-26 (Jess's notes + Figma file `Lgi3gotr7AnERIT2VgvXQW`, frame `1:453` "Frame 1": section 01 = `1:561`, section 02 = `1:601` and step frames `1:1245`, `1:1284`, `1:1322`, section 04 = `1:707`). Pre-fill the six receivers: yes.

## Global Constraints

- Version 1.18.0 in `package.json`, plugin header and `EPI_VERSION`; changelog entry under `[1.18.0]`.
- No new dependency. No change to the vendored `blueworx-page-editor/` copy.
- Repeater cells only use `text`, `number`, `textarea`, `select`, `toggle`, `media`.
- Colours from Figma: header and closing rule `#051a2d`, row rules `#ebebeb`, tag and tick `#a71f31`, dash `#9a9a9a`.

## Review Focus

- A receiver with no photo: header cell still lines up, no broken image.
- A receiver list longer or shorter than six: the table still renders, scrolls sideways on a phone rather than squashing.
- Steve empties the list: the whole "Choosing the receiver" block disappears, heading included.
- A record with other than three parts in section 01: the leader lines (drawn for three) are left out.
- The upgrade must never overwrite receivers someone already entered, and runs once.

---

### Task 1: Receiver fields, table and seed values

**Files:**
- Modify: `includes/class-epi-forum-page-editor.php` (where panel)
- Modify: `includes/forum-page/section-where.php`
- Modify: `includes/seed/kinetic-wireless-switches.php`
- Create: `assets/seed/receiver-*.png` (six photos from Figma `1:828` … `1:851`), `assets/img/forum-page/receiver-tick.svg`
- Modify: `assets/css/epi-forum-page.css`
- Test: `tests/forum-page-seed.spec.js`, `tests/forum-page-front.spec.js`

**Interfaces — produces:** fields `where_receivers_eyebrow`, `where_receivers_heading`, `where_receivers_intro`, `where_receivers_note` (text) and repeater `where_receivers` with cells `image` (media), `name`, `connection`, `channels`, `function`, `max_load`, `size` (text), `app` and `wired` (toggle).

- [ ] Failing tests: seeded page shows a table `.epi-forum-page__receivers` with 6 header cells, "Mini RF + WiFi Receiver", row "Max load" containing "2x 6A / 2x 600w LED", 3 ticks in the "Smart App control" row; a new page whose receiver list is empty shows no `.epi-forum-page__receivers`.
- [ ] Run, see them fail.
- [ ] Add fields, render table (connection doubles as the red tag above the name, upper-cased by CSS), seed values, CSS.
- [ ] Run, see them pass. Commit.

### Task 2: One-off upgrade for the live page

**Files:** Modify `includes/class-epi-forum-page-seed.php`.

**Interfaces — produces:** `EPI_Forum_Page_Seed::maybe_add_receivers()` hooked on `init` at 21, option `epi_forum_page_receivers_seeded`, stamped before the attempt. Finds the Kinetic page by slug; writes only the `where_receivers*` values, and only when `where_receivers` reads empty.

- [ ] Check on the local harness: blank the receiver meta and the option, reload, receivers come back; enter a receiver by hand, clear the option, reload, the hand entry stays.
- [ ] Commit.

### Task 3: Section 01 photo and section 02 icons

**Files:** Modify `includes/forum-page/section-what.php`, `assets/css/epi-forum-page.css`; create `assets/img/forum-page/what-switch.png`, `what-leaders.svg`, `what-dots.svg`; replace `step-*.svg`; delete `what-plate.svg`.

- [ ] Failing test: section 01 image `src` matches `what-switch`; step one image `src` matches `step-press`.
- [ ] Photo composition positioned in percentages of the 544×389 panel; leaders only with exactly three parts; stacked layout under 900px hides the leaders.
- [ ] Pass, compare against Figma screenshots. Commit.

### Task 4: Version, changelog, PR

- [ ] Bump to 1.18.0, changelog, run PHPCS once, open PR.
