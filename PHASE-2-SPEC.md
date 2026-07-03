# OBW Beer Tracker — Phase 2 Spec (styling, icons, mobile, performance)

Phase 1 (WP-0…WP-7) shipped the plugin extraction + Preact finder + importer and
is tagged `v1.0.0`. Phase 2 is a polish/hardening milestone on the finder:
**styling fixes, an icon system, mobile/responsive, and performance.**

> **Status: DRAFT — assumptions pending confirmation.** Written from best-judgment
> defaults after the grilling session was left open. Confirm/redirect the four
> decisions in **§0** before work starts; the rest of the spec follows from them.

---

## 0. Decisions to confirm (grilling — answer these first)

| # | Decision | Assumed default (spec is written to this) | Alternatives |
|---|----------|-------------------------------------------|--------------|
| D1 | **Design source of truth** | **Refine the legacy look** — keep OBW palette (`--obw-gold #c8a24a` / gray / red / green) and the existing class names; polish spacing, type, and states. **Needs a punch-list of specific issues from you.** | Match Figma/brand comps; or fresh redesign at my judgment. |
| D2 | **Icon system** | **Build-time inline SVG sprite** — small hand-picked set, `currentColor`-themeable, zero runtime deps, best perf. | Lucide/Feather via npm (tree-shaken); or icon font (Dashicons). |
| D3 | **Performance target** | **Lighthouse mobile ≥ 90** + fix the on-load REST fetch waterfall (see §4). | Best-effort/no numbers; or aggressive precomputed single-payload endpoint. |
| D4 | **Priority order** | **Mobile-first → styling → icons → perf** (most festival-user impact first). | Style+icons first; perf first; or one combined pass. |

**The one blocking input:** for D1 (assumed) I need a **punch-list of the specific
styling problems** you're seeing (screenshots/notes per breakpoint). Without it,
§1 stays generic.

---

## 1. Styling fixes (workstream A)

- Work from your punch-list; each item → a tracked fix with a before/after check.
- Consolidate the finder's visual tokens: promote the current `:root` colors to a
  small documented token set (color, spacing scale, radius, shadow, font sizes)
  in `src/finder/style.css` so fixes are systematic, not one-off.
- Audit interactive **states** across the three tabs: hover/focus/active/disabled
  on filters, sort, tab switches, accordion open/close, modal open/close.
- **Accessibility as part of styling:** visible focus rings, sufficient contrast
  (WCAG AA) on the gold/red/green against backgrounds, and reduced-motion support.
- Keep styles **scoped** under `.obw-finder-app` (already the pattern) so the
  plugin stays portable and doesn't leak into or depend on theme CSS.

**Acceptance:** every punch-list item resolved; no unstyled/again-broken states;
AA contrast on primary UI; visual parity or improvement vs. current on desktop.

## 2. Icon system (workstream B)

- Assumed **inline SVG sprite** built at compile time (D2). Define a fixed icon
  inventory the finder needs:
  - Tracker states: **tasted** (check), **favorite** (heart/star), **to-try**
    (bookmark/flag) — filled/outline variants for on/off.
  - Filter/sort affordances, **tab** glyphs (brews/brewery/venue), **close** (×),
    **external link** (Untappd / more-info), **search**, chevron (accordion).
- All icons inherit `currentColor`, sized via `em`, with `aria-hidden` +
  adjacent visually-hidden labels (or `aria-label` on interactive controls).
- Replace the current CSS-drawn badges + ad-hoc glyphs in `BeerModal.jsx` /
  `FilterBar.jsx` with the icon component.

**Acceptance:** one icon component/source; no icon font request; icons themeable
by color; screen-reader labels on every actionable icon.

## 3. Mobile / responsive (workstream C) — *first per D4*

- Define breakpoints and a **mobile-first** layout: single-column beer list,
  touch-sized targets (≥44px), sticky/collapsible filter bar, full-screen or
  bottom-sheet modal on small screens.
- Verify the three tabs, filters, sort, accordion, and modal on real viewport
  widths (≈360, 390, 768, 1024). No horizontal scroll; the filter UI must be
  reachable one-handed.
- Confirm the finder page chrome from the theme (`page-beerfinder.php` sidebar,
  header) doesn't crowd the app on mobile.

**Acceptance:** usable one-handed on a phone; no layout breakage or overflow at
the target widths; modal and filters are thumb-friendly.

## 4. Performance (workstream D)

Baseline (built, Phase 1): **finder JS ≈ 25.5 KB, CSS ≈ 5.6 KB** (already far
below the old AngularJS bundle). The real cost is **data loading**, not JS size.

- **REST fetch waterfall (primary target):** `src/finder/api.js` pulls
  `obw_beer` / `obw_venue` / `obw_brewery` at `per_page=100` with page 1 first,
  then the remaining pages. On a large year this is a sequential-ish waterfall.
  Options (assumed target = Lighthouse ≥90, so start with the cheap wins):
  1. Parallelize page 2..N (already partly done) and the three post types.
  2. Trim payload with `_fields=` to exactly what the finder renders.
  3. Cache client-side (localStorage + short TTL / ETag) so revisits are instant.
  4. *If needed / D3=aggressive:* a single plugin REST route returning a
     precomputed, object-cache/transient-backed JSON blob (one request), busted
     on beer save/import. Biggest win, biggest lift.
- Ship a production build check: minified, gzip/brotli sizes recorded; no
  source maps in prod enqueue.
- Measure with Lighthouse mobile + WebPageTest-style cold load; record before/after.

**Acceptance:** meets the D3 target; documented before/after load metrics; no
render-blocking finder assets; payload trimmed to used fields.

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
