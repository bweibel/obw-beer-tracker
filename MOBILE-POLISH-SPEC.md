# Beer Finder — Mobile Polish Spec (handoff)

Small, mobile-first improvements to the Preact beer finder. The finder is used
on phones, walking venue-to-venue during a week-long festival. Six approved
items, grouped into three independent work batches (A/B/C) that can be
implemented in parallel.

## Shared constraints (read before touching anything)

- **Framework is Preact, not React.** Hooks import from `preact/hooks`. JSX uses
  `class=` (not `className=`) and `onClick`, matching the existing components.
- **Match the surrounding code**: same JSDoc style, comment density, `obwf-`
  CSS class naming, and file structure. Read the neighboring components first.
- **Do not break the tracker localStorage contract** (`beerData` shape in
  `tracker.js`). Toggle business rules there are load-bearing — don't change them.
- **No new dependencies.** No build tooling changes.
- **Do NOT run `npm run build`** — your worktree has no `node_modules`. Edit
  source only; the integrator builds and browser-verifies after merge.
- All new styles go in `src/finder/style.css` under the existing `obwf-` scheme.
- Keep existing `aria-*` attributes; add them where you introduce controls.
- Preserve accessibility of tap targets: **interactive targets ≥ 44×44px**.
- End your run with a concise summary: files changed + what each change does.

Key files: `src/finder/components/{App,FilterBar,BeerList,GroupList,Badges,BeerModal}.jsx`,
`src/finder/tracker.js`, `src/finder/util.js`, `src/finder/style.css`.

---

## Batch A — Search persistence, search-input ergonomics, progress stat

Files: `components/App.jsx`, `components/FilterBar.jsx`, `style.css`.

### A1 — Persist search across tab switches (#2)
`App.jsx` `setListType()` currently resets `search` (and `orderBy`) on every tab
switch. **Stop resetting `search`** so a query survives when the user flips
Beer → Venue → Brewery. Leaving the `orderBy` reset as-is is fine (sort fields
differ per list).
- Accept: type a term on Brews, switch to Venue and Brewery — the term persists
  and filters each list.

### A2 — Search-input mobile ergonomics (#5)
On the `FilterBar` search `<input>`: `type="search"`, `inputmode="search"`,
`enterkeyhint="search"`, `autocapitalize="off"`, `autocorrect="off"`,
`spellcheck={false}`. Add a **clear (×) button** shown only when `search` is
non-empty that calls `setSearch('')` and refocuses the input. Style it as an
`obwf-` control inside the search field.
- Accept: mobile keyboard shows a search action; the × clears the field.

### A3 — Progress stat (#4)
Show a subtle **“Tasted N of M”** where `M` = total published beers
(`beers.length`) and `N` = number of beers whose tracker flag `tasted` is true
(`tracker.flagsFor(b.id).tasted`).
- Compute in `App.jsx`. **Compute inline each render (O(n), n≈300)** — do NOT
  `useMemo` on the `tracker` object (it’s a fresh object each render, so the memo
  would never update). It must update live as the user toggles Tasted.
- Render it small/muted near the top of the list or in the filter bar. **Mark
  the placement provisional** in a comment — it’s subject to on-device testing.
- Accept: toggling Tasted (from the modal) changes N immediately.

---

## Batch B — Modal polish

Files: `components/BeerModal.jsx`, `style.css`. Item #6.

Current modal (`BeerModal.jsx`): truncates description via `cutText(content, 240)`
with no way to expand; closes on overlay click and the close button; no Escape
key; no background scroll lock.

### B1 — Read more / less
When the fetched `content` exceeds the truncation length, render the truncated
HTML by default with a **“Read more”** toggle that expands to the full content
(and **“Read less”** to collapse). Preserve the existing loading behavior
(reserved min-height so buttons don’t jump). Keep using `dangerouslySetInnerHTML`
as the component already does.

### B2 — Background scroll lock
While the modal is open, lock background scrolling (e.g. toggle `overflow:hidden`
on `document.body`) and **restore it on close/unmount** via a `useEffect` cleanup.
Be careful: `App.jsx` also manages body classes — add/remove only your own lock,
don’t clobber theirs.

### B3 — Close on Escape
Add a `keydown` listener (in a `useEffect`, cleaned up) so **Escape** calls
`onClose`. Keep overlay-click and close-button behavior intact.
- Accept: on mobile the background doesn’t scroll while the modal is open; Esc
  closes on desktop; long descriptions expand/collapse.

---

## Batch C — Inline row toggles + subtle haptics

Files: `components/BeerList.jsx`, `components/GroupList.jsx`, `components/Badges.jsx`,
`components/App.jsx` (wiring), `tracker.js`, `style.css`.

### C1 — Inline tasted/favorite toggles on list rows (#1)
Today a row shows read-only `<Badges>` and the whole row calls `onSelect(beer)`
to open the modal. Add **tap targets on the row to toggle Tasted and Favorite
without opening the modal**, in both `BeerList` rows and `GroupList` rows.
- Handlers (`toggleTasted`, `toggleFavorited`) come from `App.jsx`’s `tracker`;
  thread them down to both list components.
- The toggle controls must `stopPropagation()` so they don’t also open the modal.
  The rest of the row still opens the modal.
- **Tap targets ≥ 44×44px** with clear spacing from the row-open area — the
  product owner specifically flagged the small-target frustration risk. Add a
  comment noting size/placement needs on-device testing.
- You may extend `Badges.jsx` into an interactive variant or add a small control
  alongside it — keep the read-only `<Badges>` usage in the modal unchanged.
- Accept: tapping the row’s Tasted/Favorite control flips state (badge updates
  live) without opening the modal; tapping elsewhere still opens the modal.

### C2 — Subtle haptics (#9)
In `tracker.js` toggle actions (`toggleTasted`, `toggleFavorited`, `toggleToTry`),
fire a **very short** `navigator.vibrate?.(10)` — feature-guarded, ~10ms, subtle.
Only on user-initiated toggles.
- Accept: a faint tick on supported devices when toggling; no errors where
  `navigator.vibrate` is absent.

---

## Integration notes (for the integrator, not the sub-agents)
- `App.jsx` is touched by A (setListType + progress) and C (wiring toggle
  handlers) — different regions, expect a trivial merge.
- `style.css` is appended by all three — additive, resolve by keeping all blocks.
- After merge: `npm run build`, then browser-verify on the live/Local site
  (search persistence, keyboard hints, progress count, modal behavior, row
  toggles on a real phone for tap-target feel).
