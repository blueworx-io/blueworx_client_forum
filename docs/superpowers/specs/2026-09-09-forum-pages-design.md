# Forum Pages — design

Date: 2026-09-09
Status: approved, ready for an implementation plan

## Goal

Long-form support pages for Forum Lighting — the first is "Getting started with kinetic
wireless switches" — where every word, image and link on the live page can be edited by the
site owner, and no page builder is involved.

The Figma design is one page of fourteen sections. Sections 01 (header chrome) and 08 (footer)
are the site's existing chrome and are not rebuilt. Everything between them is what this
feature owns.

Figma: file `ocfLyNE9GtyOYMxqq7Hwt7`, frame `1:5`, plus frames `1:611`, `1:646`, `1:682`
(steps 2–4 of the "How they work" sequence).

## Decisions taken

- **A record type, not a one-off.** The design is labelled "Info Page 03", and the same run of
  sections serves the next one. It is a registered post type so it gets revisions,
  capabilities and REST for free.
- **A fixed run of sections, each able to be switched off.** No reordering and no section
  library. Revisit once there are two or three real pages to learn from.
- **The site's chrome stays the site's.** An Elementor single template for the post type holds
  the header and footer; one shortcode widget in it renders the sections.
- **Product cards point at real WooCommerce products**, so titles, images and SKUs cannot drift
  from the shop.
- **Photography comes from the WordPress media library.** ePim stays for product imagery, which
  is what it is for.

## Out of scope

- The site header, navigation and footer.
- Building the Elementor template itself — that is done once in the site's admin, and this spec
  says what it must contain.
- Content for pages other than the kinetic switches page.
- Reordering sections, or choosing which sections a page has beyond switching one off.

## Architecture

**The record.** A `forum_page` post type, registered with `show_ui` so WordPress's own list
table and Add New create records, public with its own permalink, supporting title, revisions
and author. Its admin menu section is labelled "Forum Pages". The list table links each row to
the editor screen.

**The editor.** One screen registered with the shared page editor library, storing to the post.
The library owns the shell — page header, tabs, panels, one save bar. The plugin declares only
the schema. A panel marked `hideable` gets its own show/hide switch from the library, which is
how a section is switched off.

**The front end.** A `[forum_page]` shortcode with no attributes. It reads the record currently
being viewed and renders the visible sections in the fixed order below. Placed anywhere other
than a Forum Page it renders nothing, and says why to a logged-in editor only.

**Feature flag.** The whole feature registers in the existing feature registry under a new
"Content" group, so it can be switched off like every other feature in this plugin.

**Storage.** Post meta, written by the library. Nothing stores to options.

## Derived, never typed

Two things the editor never maintains by hand, because they go wrong the moment a section is
switched off:

- **Section numbers** (01, 02, 03 … in the design) are counted from the visible sections in
  order at render time.
- **The "on this page" bar** is built from the visible sections, each using its own `nav_label`
  field for the wording.

## The editor screen

Slug `forum-page`, store `post`, post type `forum_page`, capability `edit_posts`.

Five tabs, plus the Publish tab the library appends itself:

| Tab | Panels |
|---|---|
| Page top | Hero, On this page |
| Explainer | What they are, How they work |
| Evidence | Advantages, Where they work, Comparison |
| Products | The range, Specifying |
| Close | FAQs, Closing CTA |

Every panel except Hero is `hideable`. Every hideable panel carries a `nav_label` text field
feeding the "on this page" bar.

### Hero — `hero`

`breadcrumb_label` text · `eyebrow` text · `heading` text · `intro` textarea ·
`primary_cta_label` text · `primary_cta_url` text (url format) · `secondary_cta_label` text ·
`secondary_cta_url` text (url format) · `meta_category` text · `meta_read_time` text ·
`meta_updated` text · `image` media

### On this page — `sectionbar`

`bar_label` text (default "ON THIS PAGE") · `phone` text

The links themselves are derived.

### What they are — `what`

`nav_label` · `heading` · `body` richtext · `pullquote` textarea ·
`parts` repeater (cells: `title` text, `desc` text) — the rocker / generator / transmitter chips

### How they work — `how`

`nav_label` · `heading` · `intro` textarea ·
`steps` repeater (cells: `title` text, `body` textarea, `image` media)

The four Figma frames are one section in four states. The steps render as a stepper switched in
the browser, not as four sections.

### Advantages — `advantages`

`nav_label` · `heading` · `wins_label` text · `wins` repeater (cell: `item` text) ·
`allow_label` text · `allow` repeater (cell: `item` text)

### Where they work — `where`

`nav_label` · `heading` · `intro` textarea ·
`cards` repeater (cells: `image` media, `title` text, `body` textarea)

These are the gallery items — six in the design, any number in the editor.

### Comparison — `comparison`

`nav_label` · `heading` · `col1_label` text · `col2_label` text · `col3_label` text ·
`rows` repeater (cells: `label` text, `col1` text, `col2` text, `col3` text)

### The range — `range`

`nav_label` · `heading` · `intro` textarea ·
`products` repeater (cells: `product` record, `blurb` text) ·
`more_label` text · `more_url` text (url format)

Title, image, SKU and link come from the picked product. `blurb` is the one-line description,
which is editorial and not on the product.

**This panel depends on a change to the shared design system — see below.**

### Specifying — `specifying`

`nav_label` · `heading` · `points` repeater (cells: `title` text, `body` textarea) ·
`trouble_label` text · `troubleshooting` repeater (cells: `title` text, `body` textarea)

Point numbers are counted at render time.

### FAQs — `faqs`

`nav_label` · `heading` · `items` repeater (cells: `question` text, `answer` textarea)

### Closing CTA — `cta`

`eyebrow` text · `heading` text · `body` textarea · `primary_cta_label` text ·
`primary_cta_url` text (url format) · `secondary_cta_label` text · `secondary_cta_url` text
(url format) · `stats` repeater (cells: `value` text, `label` text)

## The one dependency on the foundation

A repeater row may hold `text`, `number`, `textarea`, `select`, `toggle` or `media`. Picking a
WooCommerce product needs a `record` cell, which the list does not have.

That control is added to the design system first, in `bluegroup_core_foundation`: `record` added
to `Schema::REPEATER_KINDS`, a matching case in `Repeater()` in the browser file, and the test
that enforces the pairing. Then released, and the design system and editor library copies in
this plugin are re-pulled.

The range panel is built after that lands. Nothing else in this spec waits for it.

## Front-end rendering

One partial per section under `includes/forum-page/`, one stylesheet
`assets/css/epi-forum-page.css`, one script `assets/js/epi-forum-page.js`. Assets enqueue only
when the shortcode actually renders. Naming follows the plugin's existing `epi-` convention.

The script does two things: the four-step stepper, and the FAQ accordion. Both work without it —
steps and answers render open and stacked if the script does not load.

## Accessibility

- One `h1` (the hero heading), `h2` per section, `h3` per card, in order.
- The stepper is a tab list with real roles and keyboard support; the FAQ is buttons with
  `aria-expanded`.
- Image alt text comes from the attachment, and a card with no alt text is flagged in the
  editor rather than shipped silently.
- Both CTAs are links, not buttons with handlers.

## Testing

Playwright against the local WordPress harness, not a hosted site:

- The editor opens on a real record, and says the record could not be found without a valid id.
- A save round-trips: change the hero heading, save, reload, it is still there.
- The front end renders the hero heading and a gallery card from a seeded record.
- A section switched off disappears from the page and from the "on this page" bar, and the
  section numbering closes up behind it.
- The stepper switches steps and the FAQ opens.

Tests create the record they need rather than assuming site state.

## Build order

1. Foundation: the `record` repeater cell (separate repo, separate release).
2. Post type, feature flag, and the editor screen — every panel except The range.
3. Front end: shortcode, section partials, styles, script.
4. The range panel, once the foundation release is in.
5. Write down what the Elementor single template must contain, for the site build.

## Version

Minor bump — new feature — with the changelog updated alongside it, in the same pull request.
