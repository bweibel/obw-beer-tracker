# OBW Beer Tracker — Implementation Plan (Subagent Handoff)

> Source of truth for the extraction/modernization of the Ohio Brew Week beer
> tracker. Written for handoff to implementation subagents. Each **Work Package
> (WP-n)** is a self-contained unit with context, deliverables, and acceptance
> criteria. Respect the dependency graph at the bottom before parallelizing.

## 0. Context & conventions (read first — applies to every WP)

**Project:** Ohio Brew Week is an *annual* beer-festival WordPress site. Today a
legacy "Bones"-based classic-editor theme (`rtd_ohio-brew-week-theme`) owns the
data model and an AngularJS 1.x "beer finder." We are extracting the finder +
data model into a new plugin and modernizing.

**Environment:** WP 7.0, PHP 8.5, Node 20, WP-CLI 2.12, ACF **Pro** installed.
Local by Flywheel. Site root: `.../local/app/public`. Theme:
`.../wp-content/themes/rtd_ohio-brew-week-theme`. New plugin:
`.../wp-content/plugins/obw-beer-tracker`.

**Naming / conventions (use everywhere):**
- Plugin slug / text domain: `obw-beer-tracker`
- PHP namespace root: `OBW\BeerTracker`; all globals/hooks/options prefixed `obw_`
- Main file: `obw-beer-tracker.php`; bootstrap class `OBW\BeerTracker\Plugin`
- Target PHP 8.1+ syntax (typed properties, enums, match, constructor promotion)
- **Never** silently create breweries/venues on import (see WP-5)
- Keep the existing localStorage contract unless a WP explicitly changes it
  (key `beerData`, shape `{ beers: [{ id, tasted, favorited, toTry }] }`)

**Locked decisions (do not relitigate):**
- Plugin owns CPTs + data model; theme becomes presentation and must **degrade
  gracefully** if the plugin is inactive.
- Annual lifecycle: beers are disposable/reset yearly (no history retained);
  breweries + venues persist as a stable directory.
- Two-way beer↔brewery and beer↔venue via **ACF Pro native bidirectional
  relationship fields**.
- Tracker state stays **client-side localStorage only** (no accounts/server).
  Existing users' saved data need NOT be preserved.
- Finder rewritten **AngularJS → Preact**, bundled with **Vite**. All three
  "Show by" tabs (Brews / Brewery / Venue) live in the plugin SPA.
- Drop `acf-to-rest-api` (+ recursive variant); expose ACF via ACF Pro native
  `show_in_rest`.
- ACF field definitions stay in the DB/ACF UI (external dep) for now.
- Git: the **new plugin gets its own repo**; theme stays untracked.

**Current-state file map (for reference):**
- CPTs: theme `library/custom-post-types.php` → requires `beer.php`,
  `venue.php`, `brewery.php`, `news.php`, `sponsor.php` on `init` priority 0.
- Finder page: theme `page-beerfinder.php` (hardcodes `<script>` tags).
- Finder app: theme `library/js/beerfinder.js` (+ hand-minified `.min.js`).
- NG templates: theme `library/ngtemplates/*.html` (hardcoded `templateUrl`).
- Dead single-beer tracker: theme `library/js/singlebeer.js` (enqueue commented
  out at `functions.php:102-113`).
- Theme lists that consume the data: `archive-obw_brewery.php`,
  `archive-obw_venue.php`, `page-venues.php`, `single-obw_*.php`, `sidebar-*`.

---

## WP-0 — Repo & plugin scaffold

**Goal:** A running, empty-but-valid plugin with its own git repo and the
Vite/Preact toolchain wired to WordPress enqueuing.

**Deliverables:**
- `git init` inside `wp-content/plugins/obw-beer-tracker/` + `.gitignore`
  (`/node_modules`, `/build`, `/vendor`, `.DS_Store`, `*.log`). Initial commit.
- `obw-beer-tracker.php` header (Plugin Name, Version 0.1.0, Requires PHP 8.1,
  Requires Plugins: `advanced-custom-fields-pro` if honored, text domain).
- PSR-4 autoload (Composer or a small SPL autoloader) for `OBW\BeerTracker\`.
- `Plugin` bootstrap: singleton/`init()`, registers hooks, defines
  `OBW_BEER_TRACKER_FILE`, `_PATH`, `_URL`, `_VERSION` constants.
- Activation/deactivation hooks: flush rewrite rules on both (CPTs registered in
  WP-1). Uninstall left as a no-op for now (beers disposable, dirs persist).
- Vite toolchain: `package.json` (`preact`, `vite`, `@preact/preset-vite`),
  `vite.config.js` with `build.manifest = true`, `build.outDir = 'build'`,
  input `src/finder/main.jsx`. `npm run build` + `npm run dev`.
- PHP asset loader `Assets` class that reads `build/.vite/manifest.json` and
  enqueues the hashed JS/CSS; supports the Vite dev server (HMR) when a
  `OBW_VITE_DEV` constant/env is set. This is the single mechanism all later
  front-end WPs enqueue through.

**Acceptance:** Plugin activates with no notices on PHP 8.5; `npm run build`
produces `build/` + manifest; a placeholder Preact component mounts on a test
shortcode/page via the manifest-based enqueue.

**Depends on:** nothing.

---

## WP-1 — Data model: CPTs move into the plugin

**Goal:** The plugin owns `obw_beer`, `obw_venue`, `obw_brewery`. Theme stops
registering them.

**Deliverables:**
- Port CPT registrations from theme `library/{beer,venue,brewery}.php` into
  `OBW\BeerTracker\PostTypes` (one registrar, `init` priority 0). Preserve
  existing `rewrite` slugs (`brew`, and confirm venue/brewery slugs from theme
  files) so permalinks/theme templates keep working. Keep `show_in_rest = true`.
- Do **not** move `obw_news` / `obw_sponsor` — those stay in the theme (out of
  scope). Leave theme `custom-post-types.php` registering only news + sponsor.
- Remove beer/venue/brewery registration from the theme (coordinate with WP-6 so
  there's never a double-registration window; gate theme side behind
  `! post_type_exists('obw_beer')` during transition).

**Acceptance:** With plugin active, all three CPTs exist once, admin menus work,
existing brewery/venue posts still load and keep their permalinks. With plugin
inactive, WP-6's guards prevent fatals (verified in WP-6).

**Depends on:** WP-0.

---

## WP-2 — Relationships: ACF Pro bidirectional fields + REST shape

**Goal:** Replace the one-directional `brewery_link` / `venue_link` post-object
arrays with true two-way relationships, and expose everything the SPA needs via
**native** ACF REST (no `acf-to-rest-api`).

**Deliverables:**
- Define ACF Pro **bidirectional relationship fields** (in ACF UI; then export
  to `acf-json/` inside the plugin so the shape is at least captured for the
  team even though DB remains source of truth):
  - Beer → Breweries (a beer is *made by* 1–n breweries) ↔ Brewery → Beers.
  - Beer → Venues (a beer is *served at* 1–n venues) ↔ Venue → Beers.
- Keep scalar beer fields the SPA relies on: `style`, `abv`, `ibu`, `untappd`.
- Turn on **`show_in_rest`** for these ACF fields (ACF Pro native). Define the
  intended REST payload shape and document it here for WP-3/WP-6:
  ```jsonc
  // GET /wp-json/wp/v2/obw_beer?per_page=100&page=N
  {
    "id": 123, "title": {"rendered": "..."}, "link": "...",
    "acf": {
      "style": "IPA", "abv": 6.2, "ibu": 55, "untappd": "beerslug-or-id",
      "brewery_link": [{ "ID": 10, "post_title": "...", "post_name": "..." }],
      "venue_link":   [{ "ID": 44, "post_title": "...", "post_name": "..." }]
    }
  }
  ```
  Preserve field **names** `brewery_link` / `venue_link` and the `untappd`
  prefixing behavior (`https://untappd.com/b/` + value) so WP-3 is a straight
  port. If ACF native REST returns a different relation shape than the above,
  normalize it with a `rest_prepare_obw_beer` filter to this contract.
- **Deactivate/remove** `acf-to-rest-api` and `acf-to-rest-api-recursive-master`
  (coordinate with the site owner; verify no other consumer depends on them
  first — grep theme + plugins).

**Acceptance:** `GET /wp-json/wp/v2/obw_beer` returns the documented shape with
brewery/venue relations, with `acf-to-rest-api` deactivated. Editing the
relationship from the brewery side is reflected on the beer side and vice versa.

**Depends on:** WP-1.

**Risk/notes:** Existing beer data is disposable, so no beer-side relation
migration needed. Breweries/venues persist — confirm their existing meta keys
survive the field redefinition (don't rename brewery/venue own fields like
image/location; only add the reverse-relation field).

---

## WP-3 — Finder SPA (Preact): 3 tabs + tracker modal

**Goal:** Feature-parity rewrite of `beerfinder.js` in Preact, mounted via the
WP-0 asset pipeline, with hardcoded theme paths eliminated.

**Deliverables:**
- `src/finder/` Preact app: data layer (fetch `obw_beer`/`obw_venue`/
  `obw_brewery` from core REST, client-side pagination via `X-WP-TotalPages`,
  `per_page=100`), state store (signals or `useReducer`), components:
  - Tabs / "Show by": **Brews**, **Brewery**, **Venue** (port `listType` switch).
  - Beer list + filters (tasted / notTasted / favorited / toTry), sort/order.
  - Beer modal (details, available-at venues, untappd/more-info buttons).
  - Venue list + brewery list (accordion open/close behavior).
- **Tracker logic** ported exactly, including business rules: favoriting forces
  `tasted=true`; tasting clears `toTry`; delete-all confirm. Persist to
  localStorage key `beerData`, shape `{ beers:[{id,tasted,favorited,toTry}] }`.
  (Free to redesign the format — existing data not preserved — but keep it
  simple and documented.)
- Replace AngularJS `ngtemplates/*.html` + `$sce`/`cut_text`/`to_trusted` with
  Preact components + a small sanitize/truncate util.
- Mount target: a shortcode `[obw_beer_finder]` **and/or** a body-class hook so
  the theme's Beer Finder page template can host it (coordinate with WP-6). No
  hardcoded `/wp-content/themes/...` paths anywhere.
- Remove reliance on jQuery and the AngularJS libs.

**Acceptance:** On the Beer Finder page the Preact app loads all beers, all three
tabs work, filters/sort work, the modal opens with brewery/venue links, and
tasted/favorite/toTry persist across reloads via localStorage. Lighthouse JS
payload materially smaller than the AngularJS bundle.

**Depends on:** WP-0 (pipeline), WP-2 (REST shape). Can start UI scaffolding
against a mocked payload in parallel with WP-2.

---

## WP-4 — CSV importer core (matching + auto-linking)

**Goal:** The headline goal — kill the manual brewery/venue linking bottleneck.
A reusable import service that ingests the annual beer CSV, auto-links to the
persistent brewery/venue directory, and records unmatched rows for review.

**Deliverables:**
- `OBW\BeerTracker\Import\Importer` service (framework-agnostic; UI layers in
  WP-5 sit on top). Input: parsed CSV rows. For each beer row:
  - Create the `obw_beer` post + scalar fields (style/abv/ibu/untappd).
  - Resolve brewery(ies) and venue(s): match strategy, in priority order:
    1. Canonical ID column if present (CSV is pre-cleanable — support an
       optional `brewery_id` / `venue_id` column referencing existing posts).
    2. Exact name match (case/whitespace-normalized) against existing
       brewery/venue posts.
  - On match: set the ACF bidirectional relationship (both sides via WP-2).
  - On **no match: DO NOT create** the brewery/venue. Record the beer + the
    unresolved name/ID in a **pending-review store** (custom table or a
    `obw_import_pending` option/CPT) with enough context to resolve later.
- Idempotency / annual reset: a documented "start new year" path that clears the
  prior beer set before/at import (beers disposable). Never touches brewery/venue
  posts except to add relations.
- A WP-CLI command `wp obw import <file.csv> [--dry-run]` that runs the service
  and prints a summary + a report of unmatched rows.
- Structured result object (created, linked, unmatched[]) consumed by WP-5.

**Acceptance:** Running the CLI against a sample CSV creates beers, auto-links
every row whose brewery/venue exists, and lists (does not create) the rest.
`--dry-run` mutates nothing. Re-running is safe/idempotent per the reset rule.

**Depends on:** WP-1, WP-2.

---

## WP-5 — Importer admin UI + review queue

**Goal:** Non-CLI operators run the import and resolve unmatched rows in
wp-admin.

**Deliverables:**
- Admin page (under the Brews menu): CSV upload → runs WP-4 service → shows
  summary (created / linked / needs-review counts).
- **Review queue** screen listing unmatched rows. Per row, a human can:
  - Map the unresolved name to an **existing** brewery/venue (search/select),
    which then sets the relationship; or
  - Create a **full** brewery/venue profile (title, image, location, and the
    other brewery/venue fields) and link it. (This is the only sanctioned
    creation path — importer itself never creates.)
- Nonces + capability checks (`manage_options` or a custom `obw_manage_import`
  cap), sanitized/escaped throughout, file-type/size validation on upload.
- Persist the pending store between sessions so review can happen over time.

**Acceptance:** Operator uploads a CSV, sees the summary, opens the review queue,
resolves each unmatched row (map-or-create), and the corresponding beer ends up
correctly linked with two-way relationships. Security review clean (nonces,
caps, escaping).

**Depends on:** WP-4.

---

## WP-6 — Theme decoupling & graceful degradation

**Goal:** The theme consumes plugin-owned data, renders its brewery/venue PHP
lists against the new model, and never fatals if the plugin is inactive.

**Deliverables:**
- Repoint theme PHP lists (`archive-obw_brewery.php`, `archive-obw_venue.php`,
  `page-venues.php`, singles, `sidebar-*`) at the new relationship data (e.g.
  "beers at this venue" / "beers by this brewery") using the WP-2 bidirectional
  fields instead of the old one-directional arrays.
- Convert the Beer Finder page template (`page-beerfinder.php`) to host the WP-3
  Preact mount (shortcode/hook) and **remove** the hardcoded angular/beerfinder
  `<script>` tags.
- **Graceful degradation:** wrap CPT/finder-dependent theme code in guards
  (`post_type_exists('obw_beer')`, `shortcode_exists('obw_beer_finder')`,
  `function_exists(...)`), showing a friendly empty state / admin notice when the
  plugin is inactive. No fatals, no white screens.
- Coordinate the CPT-registration handoff window with WP-1 (theme stops
  registering beer/venue/brewery once plugin is active).

**Acceptance:** With plugin active, all theme brewery/venue/beer pages render
correctly against the new model and the finder page shows the Preact app. With
plugin **deactivated**, no page fatals; affected areas degrade with a notice.

**Depends on:** WP-1, WP-2, WP-3.

---

## WP-7 — Cleanup & decommission

**Goal:** Remove the legacy tracker footprint from the theme.

**Deliverables:**
- Delete dead single-beer tracker (`library/js/singlebeer.js` + commented
  enqueue block `functions.php:102-113`).
- Remove AngularJS libs, `beerfinder.js`/`.min.js`, `ngtemplates/*.html`, and the
  hand-minified finder assets now superseded by the Preact build.
- Remove `acf-to-rest-api` plugin references/assumptions once WP-2 verified.
- Doc pass: plugin `README.md` (build, dev server, import workflow, annual
  reset), and note the ACF-field-in-DB fragility + the acf-json export location.

**Acceptance:** No references to Angular/`beerfinder`/`singlebeer`/
`acf-to-rest-api` remain in theme or plugin except intentional history. Site
fully functional. Final commit tagged `v1.0.0` in the plugin repo.

**Depends on:** WP-2, WP-3, WP-6.

---

## Dependency graph / suggested sequencing

```
WP-0 ──┬─ WP-1 ─┬─ WP-2 ─┬─ WP-3 ─┐
       │        │        ├─ WP-4 ─ WP-5
       │        └────────┴─ WP-6 ──┴─ WP-7
       └─ (asset pipeline unblocks WP-3 UI scaffolding early, mocked data)
```

- **Critical path:** WP-0 → WP-1 → WP-2 → WP-6 → WP-7.
- **Parallelizable:** WP-3 (SPA) and WP-4/WP-5 (importer) can run concurrently
  once WP-2's REST shape + relationship fields are fixed. WP-3 UI can start
  against a mocked payload right after WP-0.
- Each WP should land as its own reviewed commit/PR in the plugin repo with the
  acceptance criteria demonstrably met.

## Open items to confirm with the owner (non-blocking)
- Exact venue/brewery rewrite slugs to preserve (read from current theme files).
- Whether any other consumer depends on `acf-to-rest-api` before removal.
- Final CSV column contract (headers, whether we add `brewery_id`/`venue_id`).
- Capability model for the importer (reuse `manage_options` vs custom cap).
```
