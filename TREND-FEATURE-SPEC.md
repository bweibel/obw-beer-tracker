# Trending / aggregate favorites (admin-preview test run)

**Status:** IMPLEMENTED on `main` (working tree), pending review + verification.
Unlike the PWA work, this IS locally testable (REST + localStorage only, no
secure-context/SW dependency; `crypto.randomUUID` falls back on insecure
origins). Ships behind `wp obw trend off`.

## Goal & decisions (locked)

Anonymous, aggregate "crowd" counts per beer — **Most Wanted** (want-to-try
count) and **Most Loved** (favorited count) — collected from *all* users but, for
this test run, **displayed only to logged-in admins, inside the beer modal**. No
new tabs/sorts/sections. Preserves the finder's zero-auth, public-cacheable
model; the only new PII-free state is an anonymous random device UUID.

- **Collection: everyone** (Option A). The write endpoint + device-id reporting
  ship to all visitors so the preview reflects real data.
- **Display: admins only** (`current_user_can('manage_options')`), modal only.
- **Kill switch:** `wp obw trend off` (mirrors the PWA switch) disables writes,
  reads, and client reporting/display in one flip. Rollback lever for the live
  event.
- The public `/obw/v1/finder` payload is **left untouched** (no count folding) —
  avoids cache fragmentation and any leak of counts to end users.

## Data model — new table `{$prefix}obw_beer_track`

One row per (device, beer); toggles upsert, all-false deletes the row (keeps it
lean). Counting *distinct devices*, never events, so on/off/on can't inflate.

| column       | type              | note                          |
|--------------|-------------------|-------------------------------|
| `device_id`  | CHAR(36)          | client random UUID            |
| `beer_id`    | BIGINT UNSIGNED   | beer post ID                  |
| `totry`      | TINYINT(1)        | want-to-try flag              |
| `tasted`     | TINYINT(1)        | stored for completeness       |
| `favorited`  | TINYINT(1)        |                               |
| `updated_at` | DATETIME          |                               |
| PRIMARY KEY  | (`device_id`, `beer_id`) | upsert target          |
| KEY          | (`beer_id`)       | for the GROUP BY aggregate    |

Created via `dbDelta` in `TrackStore::create_table()`, called from
`Plugin::activate()` (mirrors `PendingStore`). `uninstall.php` gains a guarded
`DROP TABLE` for this table only (anonymous, disposable — unlike brewery/venue).

## Endpoints (`src/Rest/TrendController.php`)

**`POST /obw/v1/track`** — public (`permission_callback => __return_true`), but:
- No-op (200) when the kill switch is off.
- Body: `{ deviceId, beers: [ { id, totry, tasted, favorited } ] }` — an array so
  one call covers both a single toggle and the one-time backfill.
- Validate: `deviceId` matches a UUID regex; each `id` is a positive int that is
  a published `obw_beer`; flags coerced to 0/1. Upsert via `$wpdb->replace()`;
  delete the row when all three flags are 0.
- Abuse: the (device,beer) PK bounds each device to one row per beer, so spam
  overwrites rather than accumulates. A soft per-IP transient throttle caps
  request rate. No Turnstile on toggles (kills UX); stakes are low.

**`GET /obw/v1/trend/(?P<id>\d+)`** — `permission_callback =>` admin only
(`current_user_can('manage_options')`; the client flag hides UI, this enforces
it). Returns `{ totry, favorited, tasted }` for the beer, read from a ~5-min
transient of the full `GROUP BY` aggregate (one query per window regardless of
how many beers an admin opens).

## Kill switch + CLI (mirror the PWA pattern)

`TrackStore::is_enabled()` — precedence: `OBW_TREND_DISABLED` constant →
`obw_beer_tracker_trend_disabled` option → `obw_beer_tracker_trend_enabled`
filter. `CliCommand::trend()` adds `wp obw trend on|off` next to `wp obw pwa`.

## Client

- **Device id** (`api.js`): `obwDeviceId` in localStorage, `crypto.randomUUID()`
  with a fallback. Created lazily on first report.
- **Reporting** (`tracker.js`): after a toggle updates localStorage, fire-and-
  forget POST the changed beer's new flags. One-time **backfill** of the whole
  existing `beerData` on first load after launch (guarded by an
  `obwTrendBackfilled` localStorage flag) so pre-existing lists seed the counts.
  All reporting is gated by a `trendEnabled` flag — skip entirely when off.
- **Flags to client:** two new mount data-attributes (same channel as
  `data-sw-url`), emitted by the shortcode: `data-trend-enabled` (kill switch
  off) and `data-can-view-trending` (`manage_options`). App threads
  `canViewTrending` → `BeerModal`, `trendEnabled` → the tracker.
- **Display** (`BeerModal.jsx`): when `canViewTrending`, fetch `/trend/{id}` on
  open (parallel to the existing content fetch) and render a subtle, clearly-
  labeled line: *"Admin preview · Want to try: N · Favorited: N."* Real numbers
  (admin-only, so gaming/low-count optics don't matter). Nothing renders for
  non-admins, and without the flag the fetch never fires.
- **Style** (`style.css`): one muted `.obwf-admin-stat` line in the modal.

## Files
New: `src/Trend/TrackStore.php`, `src/Rest/TrendController.php`.
Edit: `src/Plugin.php` (activate + register hooks + mount attrs),
`src/Import/CliCommand.php` (`trend`), `uninstall.php` (guarded drop),
`src/finder/api.js`, `src/finder/tracker.js`,
`src/finder/components/App.jsx`, `src/finder/components/BeerModal.jsx`,
`src/finder/style.css`.

## Acceptance criteria
1. Any visitor toggling want-to-try/favorite writes an anonymous row; toggling
   off/on nets to one row; all-off removes it. No login required.
2. A logged-out user sees **no** counts anywhere and the modal makes no trend
   request. An admin sees the "Admin preview" line with correct counts.
3. `GET /obw/v1/trend/{id}` returns 401/403 for non-admins (server-enforced).
4. Backfill runs once; counts reflect lists built before launch.
5. `wp obw trend off` stops writes (no-op), reads (empty), and client
   reporting/display; `on` restores. No redeploy needed.
6. `/obw/v1/finder` is byte-for-byte unchanged; public caching unaffected.
7. No PII stored (random UUID only); table drops on uninstall.

## Verification (Local)
1. `npm run build`; confirm clean.
2. Logged OUT: toggle beers → confirm rows appear (`wp db query`), modal shows no
   counts, and no `/trend` request fires (Network tab).
3. Logged IN as admin: open a beer modal → "Admin preview" counts render and
   match the table; hit `/obw/v1/trend/{id}` directly logged-out → 401/403.
4. `wp obw trend off` → toggles stop writing, admin modal shows nothing; `on`
   restores.

## Notes / non-goals
- Counts key on beer_id; next year's import churns IDs → counts orphan. Fine —
  trending is annual/ephemeral and *should* reset each event. Tied to the shelved
  importer ID-stability item.
- No public-facing trending UI yet (that's the point of the test run); revealing
  it later is a small follow-up once the data looks good.
