# OBW Beer Tracker — Phase 2 Spec (styling, icons, mobile, performance)

Phase 1 (WP-0…WP-7) shipped the plugin extraction + Preact finder + importer and
is tagged `v1.0.0`. Phase 2 is a polish/hardening milestone on the finder:
**styling fixes, an icon system, mobile/responsive, and performance.**

> **Status: LOCKED — ready for implementation.** All four §0 decisions are
> resolved and every workstream (§1–§4) is a concrete, confirmed spec. Badges
> (§2) already shipped. **Implementation order (per D4): §1 styling strip +
> `.obwf-*` scoping → §3 mobile → §2 icons → §4 perf route.** §2/§3 depend on §1
> landing first (they build on the new namespace / the affordance §1 removes).

---

## 0. Decisions to confirm (grilling — answer these first)

| # | Decision | Assumed default (spec is written to this) | Alternatives |
|---|----------|-------------------------------------------|--------------|
| D1 | **Design source of truth** | **RESOLVED — superseded.** Not "refine the legacy look." The finder's CSS reuses legacy theme class names, and the 10-year-old theme still compiles CSS for those same names, so the two stylesheets collide on the beerfinder page (see §1). Owner decision: **replicate, don't interface with, the legacy theme.** §1 is now a locked, concrete spec: strip the finder CSS to minimal-structural only, and rename every finder class to a plugin-scoped `.obwf-*` namespace so theme CSS can no longer reach the finder DOM. No punch-list needed for this workstream — see §1. | ~~Match Figma/brand comps; or fresh redesign.~~ (moot — see §1) |
| D2 | **Icon system** | **Build-time inline SVG sprite** — small hand-picked set, `currentColor`-themeable, zero runtime deps, best perf. Note: the three tracker badges (tasted/favorite/to-try) are **already shipped** as plugin-owned PNG icons (opacity 0.6/1, `role="img"` + `aria-label`, no borders/glyph overlays) — out of scope for this workstream, see §2. | Lucide/Feather via npm (tree-shaken); or icon font (Dashicons). |
| D3 | **Performance target** | **Lighthouse mobile ≥ 90** + fix the on-load REST fetch waterfall (see §4). | Best-effort/no numbers; or aggressive precomputed single-payload endpoint. |
| D4 | **Priority order** | **RESOLVED (adjusted): §1 styling strip + `.obwf-*` scoping first → §3 mobile → §2 icons → §4 perf.** The original "mobile-first" is overridden: responsive CSS built now would target class names §1 is about to rename (rework), so the clean-base style pass leads. | ~~Mobile-first~~; perf first; or one combined pass. |

**Decisions locked for §1 (styling):**
1. **Strip depth = minimal structural.** Remove all decorative/opinionated CSS
   (brand color fills, `border-radius`, `box-shadow`, `::before` glyphs). Keep
   only what's needed to stay functional/usable (modal overlay positioning,
   filter show/hide, list layout, row dividers, flex wrapping, `box-sizing`
   reset, loader). Result is plain-but-usable, ready for a future re-skin
   toward the theme.
2. **Class scoping = rename every finder class to a plugin-scoped `.obwf-*`
   namespace.** This is the mechanism that ends the collision — theme CSS
   selectors (`.beer-card`, `.obw-button`, etc.) simply no longer match
   anything in the finder DOM.

---

## 1. Styling fixes (workstream A) — locked spec

### 1.1 Rationale

The finder's CSS (`src/finder/style.css`) deliberately reuses the legacy
theme's class names (`.beer-card`, `.beer-filter`, `.obw-button`,
`.beer-list`, `.beer-badges`, `.sublist-open/closed`,
`.filter-box-show/hide`, and more) so it would "inherit" theme styling when
mounted inside the theme's `page-beerfinder.php`. But the 10-year-old theme
**still compiles its own CSS for those exact class names** (`library/scss/modules/_cards.scss`,
`_buttons.scss`, `breakpoints/_base.scss`). Because both stylesheets target
the same selectors on the same page, they collide — cascade order, specificity,
and load order all end up fighting each other, which is why the finder's
styling "strayed from the original" instead of matching it. The owner has
decided to **replicate the legacy look deliberately later, not inherit it
accidentally now** — so the fix is to strip the finder CSS down to bare
structure and fully isolate it under its own namespace, decoupling it from
the theme entirely. Re-skinning toward the legacy look becomes a future,
intentional pass against a clean base, not an accident of shared class names.

### 1.2 Complete class rename map

Every class name and id currently emitted by `src/finder/style.css` and
`src/finder/components/*.jsx`, mapped to its new `.obwf-*` (or `#obwf-*`)
name. This map is the source of truth for implementation — rename in CSS and
JSX together, in the same commit, so nothing is left half-migrated.

**Wrapper / mount point:**

| Current | New | Notes |
|---|---|---|
| `#obw-beer-finder-root` | **unchanged** | Rendered by `src/Plugin.php` (`render_finder_shortcode`), not by the finder CSS/JSX. It's plugin-owned and already unique — no theme selector targets it, so it isn't part of the collision and doesn't need renaming. |
| `.obw-finder-app` | `.obwf-app` | The finder's root wrapper `<div>` in `App.jsx`. Not itself a legacy theme class (theme doesn't target it), but renamed anyway so the whole finder lives under one consistent `.obwf-` root, and so `.obwf-app` becomes the single stable scoping ancestor for every rule below. |
| `beer-tracker-page-html` / `beer-tracker-page-body` (added to `<html>`/`<body>` in `App.jsx`) | **unchanged** | These are an intentional hook *into* the theme's page-level chrome (header/sidebar layout on `page-beerfinder.php`), not part of the finder's own DOM styling. Out of scope for this strip — leave as-is. |

**Layout / structural utility:**

| Current | New |
|---|---|
| `.cf` | `.obwf-cf` |
| `.list-wrap` | `.obwf-list-wrap` |
| `.page-content` | `.obwf-page-content` |
| `.loader` | `.obwf-loader` |
| `.loading-wrap` | `.obwf-loading-wrap` |
| `.loading-token` | `.obwf-loading-token` |
| `.obw-error` | `.obwf-error` |
| `.obw-empty` | `.obwf-empty` |
| `.card-header` | `.obwf-card-header` |
| `.card-content` | `.obwf-card-content` |
| `.details` | `.obwf-details` |

**Lists (Brews / Brewery / Venue tabs):**

| Current | New | Notes |
|---|---|---|
| `.beer-list` | `.obwf-list` | |
| `.beer` (row) | `.obwf-row` | Bare `.beer` is also uncomfortably generic even pre-rename. |
| `.beer-title` | `.obwf-title` | Used for both list-row and modal heading — same visual role, one class. |
| `.style-small` (list-row style tag) | `.obwf-style-small` | Was colliding in name-space with modal's `.style` — see next row. |
| `.abv` | `.obwf-abv` | |
| `.brewery-title`, `.venue-title` (`GroupList.jsx`, `class={kind + '-title'}`) | `.obwf-group-title` | Both currently share one CSS rule (`.brewery-title, .venue-title { … }`); collapse to one class since they're visually identical. |
| `.brewery` (GroupList group wrapper, `class={kind}`) | `.obwf-group.obwf-group--brewery` | GroupList currently renders the bare `kind` value (`"brewery"` or `"venue"`) as the group wrapper's class. **Pre-existing internal naming collision, worth calling out**: this bare `"venue"` class also gets reused, unrelatedly, for each per-row wrapper in `BeerModal.jsx`'s "Available at" list. Renaming untangles both cases (see below) as well as fixing the theme collision. |
| `.venue` (GroupList group wrapper) | `.obwf-group.obwf-group--venue` | See above. |
| `.beer-sublist-wrap` | `.obwf-sublist` | |
| `.sublist-open` | `.obwf-sublist--open` | |
| `.sublist-closed` | `.obwf-sublist--closed` | |

**Filter bar (`FilterBar.jsx`):**

| Current | New | Notes |
|---|---|---|
| `.beer-filter` | `.obwf-filterbar` | |
| `#rtd-floating-search` | `#obwf-search` | ID selector, not a class — same collision risk (higher specificity, in fact), same fix. Legacy theme id used for its own sticky/positioning CSS on the beerfinder page. |
| `.filter-header` | `.obwf-filter-header` | |
| `.filter-search-form` | `.obwf-search-form` | |
| `.filter-icon` | `.obwf-filter-toggle` | |
| `.filter-body` | `.obwf-filters` | |
| `.filter-box-show` | `.obwf-filters--open` | |
| `.filter-box-hide` | `.obwf-filters--closed` | |
| `.obw-check` | `.obwf-check` | |
| `.obw-check.on` | `.obwf-check--on` | |
| `.delete-wrapper` | `.obwf-delete-wrap` | |
| `.delete-data-button` | `.obwf-btn-delete` | Composes with `.obwf-btn` below (currently `class="obw-button delete-data-button"`). |

**Buttons (shared across `FilterBar.jsx` / `BeerModal.jsx`):**

| Current | New | Notes |
|---|---|---|
| `.obw-button` | `.obwf-btn` | |
| `.obw-button.is-active` (tab / filter toggle active state) | `.obwf-btn--active` | |
| `.obw-button-gold` | `.obwf-btn--gold` | Fill removed in the strip (§1.3) but the modifier class stays as a semantic hook (primary CTA — Untappd link). |
| `.obw-button-gray` | `.obwf-btn--gray` | |
| `.obw-button-gray.is-on` | `.obwf-btn--on` | Toggle state for Tasted/Favorite/Want-to-Try buttons in the modal. |
| `.ut-button` | `.obwf-btn-untappd` | |
| `#untappd-link` | **unchanged** | Plain id, not styled by theme or plugin CSS today; kept as a stable JS/analytics hook. |
| `.untappd-button-icon` | `.obwf-btn-untappd-icon` | |
| `.more-info-button` | `.obwf-btn-more-info` | |
| `.button-wrap` | `.obwf-actions` | |
| `.top-row` | `.obwf-actions--top` | |
| `.bottom-row` | `.obwf-actions--bottom` | |

**Modal (`BeerModal.jsx`):**

| Current | New |
|---|---|
| `.modal-wrap` | `.obwf-modal-overlay` |
| `.modal-wrap.on` | `.obwf-modal-overlay--open` |
| `.beer-card` | `.obwf-card` |
| `.beer-card.modal` | `.obwf-card.obwf-card--modal` |
| `.modal-close` | `.obwf-modal-close` |
| `.brewery` (modal brewery-name heading) | `.obwf-modal-brewery` |
| `.obw-gray` (utility class on the brewery heading; currently unstyled — no matching CSS rule today) | `.obwf-text-muted` |
| `.style` (modal details "Style: …" span) | `.obwf-style` |
| `.available-list` | `.obwf-available-list` |
| `.venue` (per-venue row inside "Available at") | `.obwf-venue-row` | *(disambiguated from the GroupList group wrapper above)* |
| `.venue-link` | `.obwf-venue-link` |
| `.beer-description-wrap` | `.obwf-description` |
| `.beer-description-inner` | `.obwf-description-inner` |

**Badges (`Badges.jsx`, `style.css`) — already implemented, renamed here only for namespace consistency, internals untouched:**

| Current | New |
|---|---|
| `.beer-badges` | `.obwf-badges` |
| `.to-try` | `.obwf-badge-to-try` |
| `.tasted` | `.obwf-badge-tasted` |
| `.favorite` | `.obwf-badge-favorite` |
| `.on` (badge state modifier) | `.obwf-badge--on` |

That's **~65 selectors** (classes + 2 ids) across `style.css` and the six
component files. Note the generic `.on` toggle class is used for three
unrelated things today (`modal-wrap.on`, `beer-badges … .on`, `obw-check.on`)
— each gets its own disambiguated `.obwf-*--on`/`--open` modifier above
rather than one shared `.obwf-on`, so state classes can't cross-apply by
accident within the new namespace either.

### 1.3 Strip inventory

Grounded in the current `src/finder/style.css` (as of this spec). "Remove"
means delete the declaration/rule entirely; "Keep" means keep it, renamed to
the new class per §1.2, with brand color references swapped for a neutral
default (`currentColor`, `#000`/`#fff`, or the surviving neutral tokens).

| Area | REMOVE (decorative) | KEEP (minimal structural) |
|---|---|---|
| Tokens (`:root`) | `--obw-gold`, `--obw-red`, `--obw-green` and every rule that fills/colors with them | `--obw-gray`/`--obw-line` survive as neutral tokens (rename to `--obwf-gray`/`--obwf-line`) for text/dividers only |
| App shell | — | `box-sizing` reset, base `font-family`/base text color, `.cf` clearfix |
| Filter bar | dark `--obw-gray` panel fill + white-on-dark color scheme, `border-radius` on the panel/input/buttons, gold hover/active fill on `.obw-button`, gold fill + checkmark styling on `.obw-check.on`, red fill on `.delete-data-button` | show/hide toggle (`filter-box-hide/show` → `.obwf-filters--open/closed`) and the ≥768px "always visible" media query, `button-wrap` flex/gap/wrap layout, plain 1px neutral border for grouping in place of the fill |
| Lists | `::before` `▸` triangle + gold color on brewery/venue title links, gold text-color on row-title hover | row divider (`border-bottom` on `.beer`/`.obwf-row`, neutral `--obwf-line`), row flex/wrap/gap layout, `beer-title`/`style-small`/`abv` font-size hierarchy (kept as plain neutral-gray de-emphasis, not brand color) |
| Accordion (brewery/venue sublists) | — (nothing decorative beyond the title glyph above) | `sublist-open/closed` display toggle, left indent + neutral `border-left` divider |
| Badges | *(already resolved — not touched by this pass)* | *(already resolved — not touched by this pass)* |
| Loader | — | centered text block, neutral gray |
| Modal | `border-radius`, `box-shadow` on the modal card, `📍` `::before` glyph on `.venue-link` | fixed-overlay positioning (`inset: 0`, `z-index`, flex centering, scroll), white modal background (contrast, not brand), spacing/typography for title/brewery/details/description, `.button-wrap`/`top-row`/`bottom-row` layout |
| Buttons | `border-radius`, gold fill (`.obw-button-gold`), gray fill (`.obw-button-gray.is-on` state color swap), hover/active brand-color swaps | plain bordered button (1px solid neutral/`currentColor`), `is-active`/`is-on` **state must stay visibly distinguishable** — spec assumes a neutral treatment (e.g., filled vs. outline, or bold vs. regular) rather than a color swap; exact treatment is an implementer call within "no decorative styling" |

Two explicit judgment calls made in this pass (flagging for confirmation,
not blocking): (1) `.obw-error` keeps a red-ish neutral-but-legible treatment
for error text since "error = red" is a usability convention, not brand
styling — open to being flattened further if the owner disagrees; (2) toggle
states (`is-active`, `is-on`, `.obwf-check--on`) keep *some* non-color visual
change (weight/outline/fill) because "no visible state" would fail the
"all interactive states still work" acceptance criterion below.

### 1.4 Ripple / impact notes

- **Decoupling is the point.** After the rename, `page-beerfinder.php` and the
  theme's `_cards.scss` / `_buttons.scss` / `breakpoints/_base.scss` still
  define `.beer-card`, `.beer-filter`, `.obw-button`, etc. — but nothing in
  the finder DOM carries those class names anymore, so those theme rules
  simply don't match. That's intentional and is what ends the bleed-through;
  no theme file needs to change for this workstream.
- **No plugin PHP depends on the legacy class names.** Verified via
  `grep -rn "beer-card\|beer-filter\|beer-badges\|beer-list\|sublist-\|filter-box-\|obw-button\|modal-wrap" --include=*.php` across the plugin: **zero matches.** The only
  PHP touching finder markup is `src/Plugin.php`'s `render_finder_shortcode`,
  which only emits `#obw-beer-finder-root` (unchanged, see §1.2) — the plugin
  never renders or targets the legacy class names server-side, so the rename
  is contained entirely to `src/finder/style.css` and `src/finder/components/*.jsx`.
- Badge CSS (`.beer-badges`/`.tasted`/`.favorite`/`.to-try`) is already
  finished functionally (PNG icons, opacity states, `role="img"` +
  `aria-label`) and is **not** being re-specified — it's included in the
  rename map (§1.2) purely so it moves into the `.obwf-*` namespace in the
  same pass as everything else, keeping the finder 100% theme-isolated.
- Because `#obwf-search` (renamed from `#rtd-floating-search`) is a
  higher-specificity id selector, double-check no other plugin code (e.g. a
  future analytics/JS hook) still queries the old id after the rename.

### 1.5 Acceptance criteria

- Finder renders on the beerfinder page with **zero theme-CSS bleed-through**:
  load the page, inspect finder nodes in devtools, and confirm no theme
  stylesheet rule (anything from the theme's `_cards.scss`/`_buttons.scss`/
  `breakpoints/_base.scss`) matches any element inside `.obwf-app` — every
  matched rule should come from the plugin's `style.css` only.
- All interactive states still work and remain visually distinguishable:
  hover/focus/active on buttons, filter show/hide, tab switching, accordion
  open/close, modal open/close, toggle states (tasted/favorite/to-try,
  is-active tabs).
- No decorative styling remains: no brand color fills, no `border-radius`,
  no `box-shadow`, no `::before` glyphs, per §1.3's REMOVE column.
- Every finder class/id is under the `.obwf-*` / `#obwf-*` namespace per the
  §1.2 map — no legacy class name remains anywhere in `style.css` or the
  `components/*.jsx` files (grep for the old names returns nothing outside
  git history).
- `#obw-beer-finder-root` mount id and the `beer-tracker-page-html`/
  `beer-tracker-page-body` body/html hook classes are confirmed unchanged
  and still functioning (theme page chrome still keys off them).

## 2. Icon system (workstream B) — locked spec

**D2 resolved:** individual **inline SVG components** (not a `<use>` sprite —
overkill at this count), each rendering its own `<svg>`, `currentColor`-themeable,
zero runtime deps. The badges stay PNG, so the finder's iconography is
deliberately **mixed**: PNG for the 3 tracker-state badges, inline SVG for UI
glyphs.

- **Tracker-state badges are DONE — out of scope.** `Badges.jsx` /
  `.beer-badges` (renamed `.obwf-badges` per §1.2) already ship as plugin-owned
  PNG icons (opacity 0.6/1 on/off, `role="img"` + state-aware `aria-label`, no
  borders/overlays). Do not re-spec or replace.

### 2.1 In-scope icons (replace existing glyphs + one new affordance)

Iconify the glyphs that exist today (for consistent cross-platform rendering —
the `🗑` and `☰` render differently per OS — plus theming + a11y), and add the
one affordance §1 removes:

| Icon | Replaces | Location |
|---|---|---|
| close (×) | `&times;` | `BeerModal.jsx:23` (modal close) |
| chevron up / down | `▼` / `▲` sort markers | `FilterBar.jsx:40-41` |
| sliders / filter | `☰` (`&#9776;`) hamburger | `FilterBar.jsx:70` (filter toggle) |
| trash | `🗑` (`&#128465;`) emoji | `FilterBar.jsx:136` (Reset) |
| arrow / external-link | `>` (`&gt;`) carets | `BeerModal.jsx:94, 103` (Untappd / More Info) |
| **chevron (accordion)** | *(net-new)* — §1 deletes the decorative `▸` on brewery/venue titles, leaving the sublist expand/collapse with **no** visual affordance; add an open/closed chevron to restore it | `GroupList.jsx` sublist toggle |

### 2.2 Out of scope (this round)

- **Tab glyphs** (Brews / Brewery / Venue) — tabs stay text-only.
- **Search magnifier** — no current use; the search input keeps its placeholder.

Both are net-new polish; revisit later if wanted.

### 2.3 Source & implementation

- Pull all paths from **one MIT set (Feather)** so weight/style are consistent.
  Noted as provisional — may be swapped later to match the wider site's
  iconography; keeping them single-source now makes that swap one place.
- `currentColor` fill/stroke, sized via `em`, `aria-hidden="true"` on the
  `<svg>`. Interactive controls carry the accessible name on the **button**, not
  the icon: preserve the existing `aria-label`s (`BeerModal.jsx:22` "Close",
  `FilterBar.jsx:67` "Toggle filters"); the Reset button keeps its visible
  "Reset" text + `title`. No icon-only control left without a name.

**Acceptance:** one icon source (single Feather-derived set); inline SVG only —
no icon font, no extra HTTP request; every icon themeable via `currentColor`;
the accordion has a visible open/closed affordance again; screen-reader name on
every actionable icon; badges untouched (still PNG).

## 3. Mobile / responsive (workstream C) — *sequenced after §1*

> **Sequencing (per D4, adjusted):** held until the §1 style strip + `.obwf-*`
> rename lands. Responsive CSS built now would target class names §1 renames
> (`filter-box-show/hide`, the 768px query, `.beer-card`, `#rtd-floating-search`)
> — so the clean-base style pass goes first, then this workstream builds on the
> `.obwf-*` namespace. Decisions below are locked; implementation waits.

### 3.1 Test context (must test in the theme grid)
- The finder is **not** full-viewport on tablet/desktop: `page-beerfinder.php`
  mounts it in `main.m-all t-2of3 d-3of4` with a widget sidebar beside it
  (`sidebar-beerfinder.php`). Effective finder width = full viewport on mobile
  (stacked, sidebar drops below), ~2/3 on tablet, and ~**1024px** in the main
  column at desktop. **All responsive testing runs inside the theme page grid,**
  not the standalone mount — the standalone width doesn't match production.
- Verify the three tabs, filters, sort, accordion, and modal at viewport widths
  ≈360 / 390 / 768 / 1024, in-context. No horizontal scroll at any width; the
  filter UI reachable one-handed.

### 3.2 Sticky filter bar (reimplement, all breakpoints)
- The legacy finder pinned the search via a JS-toggled `position: fixed` on
  `.floating-finder.fixed`, **mobile only**. Reimplement it **cleanly as CSS
  `position: sticky`** on the filter bar (`.obwf-filterbar` / `#obwf-search`),
  working at **all breakpoints** (mobile, tablet, desktop) — no scroll-driven JS
  class toggling.
- Set `top:` with an offset for any theme sticky-header height so the bar pins
  *below* the header, not under it.
- Keep the existing mobile collapse-behind-hamburger (`.obwf-filters--open/closed`
  + the filter toggle) so the sticky bar stays compact one-handed; filters stay
  always-visible ≥768px per the current media query.

### 3.3 Modal on small screens (centered card, near-full-bleed)
- **Keep the centered fixed-overlay card** (current behavior) — no bottom-sheet
  this round (revisit later if wanted). On small screens widen toward
  **near-full-bleed** (shrink the current `3vh 1rem` side padding, let the card
  fill the available width) and enlarge touch targets.

### 3.4 Touch targets (≥44px)
- Bring all interactive controls to a **≥44px** hit area: filter/sort/tab
  buttons (currently ≈30px), modal action buttons (≈36px), the filter toggle,
  the modal close, and the tracker badges. Prefer padding / `min-height` over
  shrinking type.

### 3.5 Deferred to pre-launch (not this workstream)
- The modal renders post HTML via `dangerouslySetInnerHTML` (`BeerModal.jsx`),
  and long unbroken names lack `overflow-wrap`. Current festival data is
  controlled, so overflow from embedded media / long tokens is low-risk now —
  add `max-width:100%` / `overflow-wrap` guards as a **pre-launch** hardening
  pass, tracked but out of scope here.

**Acceptance:** usable one-handed on a phone; sticky filter bar reachable while
scrolling at every breakpoint; no horizontal scroll or overflow at 360/390/768/
1024 tested **inside the theme grid**; modal near-full-bleed and thumb-friendly;
every interactive target ≥44px.

## 4. Performance (workstream D) — locked spec

**Baseline (re-measure after §1):** finder JS ≈ 25.7 KB; CSS is currently
≈ 12.6 KB (badge PNG data-URIs inlined) and the §1 strip will pull it back down
— treat both as pre-Phase-2 and record fresh numbers against a production build.
JS/CSS size is already far below the old AngularJS bundle; **the real cost is
data loading**, not asset size.

**Current data path (what we're replacing):** `src/finder/api.js` calls core
REST `/wp/v2/obw_beer|obw_brewery|obw_venue` at `per_page=100`, page 1 then
pages 2..N. The three types already fire concurrently (`App.jsx:43-59`) and
pages 2..N already parallelize — so the *only* remaining waterfall is the
unavoidable "one round-trip per type to learn `X-WP-TotalPages`, then the rest."
Each response is a full untrimmed post object, and the `rest_prepare_*`
normalizer (`Fields.php`) runs `get_post()` per related item on every post —
read-time fan-out multiplied across paginated requests. This path was always
meant to be temporary.

### 4.1 Primary: single precomputed finder route (locked — build now)

Add a plugin REST route (e.g. `GET /wp-json/obw/v1/finder`) that returns the
**entire finder dataset in one response**, precomputed and cached:

- **Payload shape** = exactly what the finder consumes, no more:
  - `beers`: `{ id, name, link, acf: { style, abv, ibu, untappd, brewery_link, venue_link } }` — **no `content`** (see §4.2).
  - `breweries` / `venues`: `{ id, name, link, beers: [{ ID, post_title, post_name, post_status }] }`.
- **Reuse the existing shaping**, don't fork it: build the blob from the same
  `relation()` reduction that `Fields.php` already uses, so the route and the
  legacy `rest_prepare_*` normalizers can't drift. (Factor the shared shaping
  into a helper both call.)
- **Cache:** store the assembled blob in a transient / object cache. The
  per-item `get_post()` fan-out now runs **once per write**, not per read.
- **Invalidation:** bust on `save_post_obw_beer` / `_obw_brewery` / `_obw_venue`,
  on importer commit, and on the annual reset. Version the cache key so a stale
  blob is never served after a shape change.
- **Rewrite `api.js`** to hit this one route (replacing the three paginated
  `fetchAll` loops); keep a fallback path only if the route 404s (plugin/route
  missing), so the finder still degrades to core REST.

### 4.2 Modal content: drop from bulk, lazy-fetch on open (locked)

`beer.content` is **modal-only** (`BeerModal.jsx:74` is its sole consumer;
`BeerList`/`GroupList` never read it). It stays **out of the §4.1 blob**. When a
modal opens, fetch that one beer's content on demand (core REST
`/wp/v2/obw_beer/{id}?_fields=content`), cache it in memory for the session, and
render once it arrives (the modal already mounts asynchronously). This removes
the single largest per-record payload item from the initial load.

### 4.3 Client cache (locked)

Cache the §4.1 response client-side (localStorage) keyed by the route's `ETag`
(or a short TTL) so revisits/returns are instant. This is **separate** from the
tracker-state store in `tracker.js` (different key); clearing tracker data must
not clear the data cache and vice-versa. Server-side blob invalidation (§4.1)
plus ETag revalidation keeps it from going stale.

> Note: the old "cheap wins" (parallelize types, `_fields=` trimming) are now
> **subsumed** by §4.1 — the custom route returns only the used fields by
> construction, so top-level `_fields` and pagination tuning on the core
> endpoints are moot for the main load. (A `_fields` caveat worth remembering if
> we ever fall back to core REST: sub-field trimming inside `acf` doesn't work,
> because the priority-20 `prepare_beer` filter overwrites the whole `acf` array
> after core's field-limiting runs — only top-level `_fields` trims.)

### 4.4 Measurement (pin the environment)

D3's "Lighthouse mobile ≥ 90" is only meaningful against a **production build,
inside the theme grid, with mobile throttling** — not the Vite dev server or the
standalone mount. Record before/after (cold load + the §4.1 route's transfer
size). "No source maps in prod" is already true (Vite emits none by default;
`Assets.php` only enqueues manifest files) — confirm, don't re-solve.

**Acceptance:** the finder loads its full dataset in **one** request via
`/wp-json/obw/v1/finder`; that route is cache-backed and busted on
save/import/reset; `content` is absent from the bulk payload and lazy-loaded per
modal; client cache makes revisits instant without staleness; Lighthouse mobile
≥ 90 on a production build in the theme grid, with before/after metrics recorded.

---

## Cross-cutting notes

- **Two repos:** finder work lands in the **plugin** repo (`v1.x`); any theme
  chrome/CSS changes land in the **local theme** repo and carry the manual
  prod-merge caveat (unrelated histories — see `theme-repo-prod-merge` memory).
- Sequence each workstream as its own reviewed commit; tag a `v1.1.0` when the
  milestone lands.
- Keep everything scoped/portable — do not reintroduce a theme dependency in the
  finder.

## Open questions beyond §0
- Any **brand assets** (logo lockups, sponsor badges, festival imagery) the
  finder should incorporate this round, or purely UI polish?
- Is an **offline/PWA** angle in scope for the festival (spotty venue wifi), or
  explicitly out?
- Target browsers/devices min-spec (older Android at the festival)?
