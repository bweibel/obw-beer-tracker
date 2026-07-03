# OBW Beer Tracker

Ohio Brew Week's beer finder and data model, extracted from the
`rtd_ohio-brew-week-theme` classic theme into a self-contained plugin.

The plugin owns:

- The **`obw_beer`**, **`obw_venue`**, and **`obw_brewery`** custom post types.
- The **relationships** between them (ACF Pro native bidirectional fields).
- The **Preact beer finder SPA** (`[obw_beer_finder]` shortcode), replacing the
  legacy AngularJS `beerfinder.js`.
- The **annual CSV importer** (admin UI + WP-CLI) that auto-links beers to the
  persistent brewery/venue directory and queues unmatched rows for human review.

- **Namespace:** `OBW\BeerTracker` (PSR-4, `src/`)
- **Text domain / slug:** `obw-beer-tracker`
- **Prefix:** `obw_`
- **Requires:** WordPress 6.5+, PHP 8.1+, **ACF Pro** (`advanced-custom-fields-pro`)

---

## Data model & lifecycle

- **Breweries and venues persist** across years as a stable directory. The
  importer never creates them — see the review queue below.
- **Beers are annual and disposable.** Each year's set is replaced via the
  importer's opt-in reset; no beer history is kept.
- **Finder state** (tasted / favorited / toTry) lives **client-side only** in
  `localStorage` under the `beerData` key. No accounts, no server persistence.

### Relationships

Two-way via ACF Pro native `bidirectional` relationship fields (existing DB
field keys were reused — no data migration):

| Beer field       | Key                    | Reverse side          | Reverse key            |
| ---------------- | ---------------------- | --------------------- | ---------------------- |
| `brewery_link`   | `field_5965323de28fc`  | Brewery → Beers       | `field_5965347353292`  |
| `venue_link`     | `field_596538519f8af`  | Venue → Beers         | `field_59653dc9532f2`  |

Scalar beer fields: `style`, `abv`, `ibu`, `untappd` (stored as a **raw slug** —
the finder re-prepends `https://untappd.com/b/`). The beer **description** is
`post_content`, not an ACF field.

> **ACF-field-in-DB fragility.** Field definitions remain **DB-owned** (the DB is
> the source of truth). The `acf-json/` directory in this plugin is a *captured
> export* for the team, not an authoritative sync — changing a field in the ACF
> UI will not update code, and vice versa. Treat the field keys above as a
> contract. See `acf-json/group_5b9fd8cc7804a.json` (beer group).

### REST contract

`rest_prepare_obw_beer` (in `src/Fields.php`) normalizes the beer `acf` payload
served on core REST (`GET /wp-json/wp/v2/brew?per_page=100&page=N`) to:

```jsonc
{
  "style": "IPA", "abv": 6.2, "ibu": 55, "untappd": "beerslug-or-id",
  "brewery_link": [{ "ID": 10, "post_title": "…", "post_name": "…" }],
  "venue_link":   [{ "ID": 44, "post_title": "…", "post_name": "…" }]
}
```

CPT `rest_base` values: beer = `brew`, venue = `venue`, brewery = `brewery`.

---

## Build & development (finder SPA)

The finder is a Preact app bundled with Vite. Build output lands in `build/`
with a manifest at `build/.vite/manifest.json`, which the PHP `Assets` loader
reads to enqueue hashed files.

```bash
npm install        # once
npm run build      # production build -> build/
npm run dev        # Vite dev server + HMR on http://localhost:5173
```

**Dev/HMR mode:** define `OBW_VITE_DEV` (truthy) so the PHP asset loader points
at the Vite dev server instead of the built manifest. Override the URL with
`OBW_VITE_DEV_URL` (default `http://localhost:5173`). Remember to `npm run build`
and commit the `build/` output for production — it is enqueued from the manifest.

Entry points are declared in `vite.config.js` under `rollupOptions.input`
(currently `finder: src/finder/main.jsx`). The shortcode `[obw_beer_finder]`
renders `<div id="obw-beer-finder-root">` and enqueues the `finder` entry.

---

## Import workflow

The CSV carries canonical `brewery_id` / `venue_id` columns (owner
pre-normalizes the CSV). Matching is **ID first, normalized exact-name as
fallback**. Multiple relations are `|`-separated. Header:

```
name,style,abv,ibu,untappd,description,brewery_id,brewery,venue_id,venue
```

See `tests/fixtures/sample-beers.csv` for a worked example (exact-ID match,
name fallback, multi-relation, and an orphan that must land in review).

For each row the importer creates the `obw_beer` post + scalar fields +
description, then resolves brewery/venue relations. **On no match it does NOT
create the brewery/venue** — it records the beer and the unresolved name/ID in
the pending-review store (`OBW\BeerTracker\Import\PendingStore`).

### WP-CLI

```bash
wp obw import beers-2026.csv            # import + auto-link, queue unmatched
wp obw import beers-2026.csv --dry-run  # mutate nothing; print the summary
wp obw import beers-2026.csv --reset    # ANNUAL RESET: delete prior beers first
```

`--dry-run` mutates nothing. Re-running is idempotent per the reset rule; the
importer never touches brewery/venue posts except to add relations.

### Admin UI

Under **Brews → Import Beers** (`admin.php?page=obw-beer-import`,
cap: `manage_options`):

- **Import tab** — upload a CSV → runs the importer → shows the
  created / linked / needs-review / errors summary. The **annual reset** ("start
  a new year") is opt-in, double-gated behind an explicit checkbox + JS confirm.
- **Review Queue tab** — lists pending unmatched rows. Per row an operator can:
  - **Map** the unresolved ref to an existing brewery/venue (sets the relation);
  - **Create** a full brewery/venue profile (title + ACF logo/address/location/
    website/hours) and link it — the *only* sanctioned creation path; or
  - **Ignore** it.

  Both resolve paths append to the ACF bidirectional field; ACF Pro syncs the
  reverse side. Security: `manage_options` on every handler, per-action nonces,
  PRG redirects, sanitized input / escaped output, validated + capped uploads.

---

## Graceful degradation

The theme consumes plugin-owned data and must not fatal when the plugin is
inactive. Theme code guards on `post_type_exists('obw_beer')` /
`shortcode_exists('obw_beer_finder')`; `page-beerfinder.php` shows a friendly
notice in place of the finder when the shortcode is unavailable.

---

## Layout

```
obw-beer-tracker.php     Bootstrap: constants, autoloader, activation hooks
src/
  Plugin.php             Container / init; registers admin only in is_admin()
  PostTypes.php          Registers the three CPTs
  Fields.php             ACF REST normalization (rest_prepare_obw_beer)
  Assets.php             Vite manifest -> wp_enqueue (dev-server aware)
  finder/                Preact SPA (main.jsx, components/, api.js, tracker.js)
  Import/                Importer, CsvReader, PendingStore, ImportResult, CliCommand
  Admin/ImportPage.php   Import + Review Queue admin screens
acf-json/                Captured ACF field-group exports (see fragility note)
build/                   Vite output (committed; enqueued from the manifest)
```

See `IMPLEMENTATION_PLAN.md` for the full work-package history and the discovered
contracts, and `HANDOFF.md` for the original subagent launch notes.
