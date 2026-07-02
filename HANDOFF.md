# OBW Beer Tracker — Subagent Handoff Kit

Copy-paste launch prompts for handing each Work Package to an implementation
subagent. **Full spec lives in `IMPLEMENTATION_PLAN.md` (same folder).** Every
subagent must read that file's Section 0 + its WP section before doing anything.

## Standing preamble (prepend to every subagent prompt)

> You are implementing part of the OBW Beer Tracker plugin. Before writing any
> code, read `wp-content/plugins/obw-beer-tracker/IMPLEMENTATION_PLAN.md` in
> full — especially **Section 0** (env, naming conventions, locked decisions,
> file map) and the specific **Work Package** named below. Also read the project
> memory files if available. Honor all locked decisions; do not relitigate them.
> Environment: WP 7.0, PHP 8.5 (target 8.1+ syntax), Node 20, WP-CLI 2.12, ACF
> Pro. Plugin slug `obw-beer-tracker`, namespace `OBW\BeerTracker`, prefix
> `obw_`. Deliver against the WP's acceptance criteria, commit to the plugin's
> git repo, and report what you did + how you verified acceptance.

## Launch order & parallelism
- **Sequence solo first:** WP-0 → WP-1 → WP-2 (critical foundation; don't
  parallelize these — later packages depend on their contracts).
- **Then fan out:** after WP-2 lands, run **WP-3** (SPA) and **WP-4** (importer
  core) in parallel. WP-5 follows WP-4; WP-6 follows WP-3; WP-7 is last.
- One subagent per WP. Have each land as its own reviewed commit.

## Per-WP prompts

### WP-0 — Scaffold
> Implement **WP-0 (Repo & plugin scaffold)**. Deliver the git repo + .gitignore,
> `obw-beer-tracker.php` header, PSR-4 autoload, `Plugin` bootstrap + constants,
> activation/deactivation hooks, and the **Vite + Preact toolchain wired to a
> manifest-based WP asset loader** (with dev-server/HMR support). Prove a
> placeholder Preact component mounts via the manifest enqueue.

### WP-1 — CPTs into plugin
> Implement **WP-1**. Port `obw_beer`/`obw_venue`/`obw_brewery` CPT registration
> from the theme into the plugin (preserve rewrite slugs; keep `show_in_rest`).
> Leave `obw_news`/`obw_sponsor` in the theme. Coordinate the handoff so there's
> no double-registration (gate theme side behind `! post_type_exists(...)`).

### WP-2 — Relationships + REST
> Implement **WP-2**. Define ACF Pro **bidirectional** relationship fields for
> beer↔brewery and beer↔venue, export to `acf-json/`, keep scalar beer fields
> (style/abv/ibu/untappd), enable native `show_in_rest`, and normalize the beer
> REST payload to the exact contract documented in the plan (preserve
> `brewery_link`/`venue_link` names + untappd prefixing). Verify, then deactivate
> `acf-to-rest-api` (+ recursive variant) after confirming no other consumer.

### WP-3 — Preact finder SPA
> Implement **WP-3**. Feature-parity rewrite of the AngularJS `beerfinder.js` in
> Preact: 3 "Show by" tabs (Brews/Brewery/Venue), filters/sort, beer modal, and
> the tracker (localStorage `beerData`, business rules: favorite→forces tasted,
> taste→clears toTry). No hardcoded theme paths; mount via `[obw_beer_finder]`.
> You may start against a mocked payload matching WP-2's REST contract.

### WP-4 — Importer core
> Implement **WP-4**. Build the reusable `Importer` service + `wp obw import`
> CLI: create beers from CSV, resolve brewery/venue by ID-column-then-exact-name,
> set bidirectional relations on match, and **record unmatched rows in a pending
> store WITHOUT creating breweries/venues**. Support `--dry-run` and the annual
> beer-reset path. Return a structured result for WP-5.

### WP-5 — Importer admin + review queue
> Implement **WP-5**. Admin upload page + **review queue** for unmatched rows,
> letting a human map to an existing brewery/venue OR create a full profile
> (image/location/etc.) — the only sanctioned creation path. Nonces, caps,
> sanitization/escaping, upload validation, persistent pending store.

### WP-6 — Theme decoupling
> Implement **WP-6**. Repoint theme brewery/venue PHP lists (`archive-obw_*`,
> `page-venues`, singles, sidebars) at the new bidirectional data; convert
> `page-beerfinder.php` to host the Preact mount and remove the hardcoded angular
> `<script>` tags; add graceful-degradation guards so nothing fatals when the
> plugin is inactive.

### WP-7 — Cleanup
> Implement **WP-7**. Remove dead `singlebeer.js` + its commented enqueue,
> AngularJS libs, `beerfinder.js`/min, `ngtemplates/*`, and `acf-to-rest-api`
> assumptions. Write the plugin `README.md` (build/dev/import/annual-reset) and
> tag `v1.0.0`.

## After restart — quick orientation for future-me
1. Read the two memory files (`beer-tracker-plugin-extraction`, `-architecture`).
2. Read `IMPLEMENTATION_PLAN.md` here.
3. Resolve the four "Open items to confirm" at the end of the plan with the user
   before/at WP-2 and WP-4 (slugs, other acf-to-rest-api consumers, CSV headers,
   importer capability).
4. Launch subagents per the order above using these prompts.
