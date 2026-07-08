# Beer List — Features & Polish Plan

Working planning doc for the 2026 post-import feature/polish push on the OBW beer
finder. **One section per feature.** Each section is a self-contained brief written
for handoff to a **sub-agent (Sonnet, medium effort)** — it should be actionable
without reading the rest of this doc.

## Shared working agreement (applies to every section)

- **Env:** implement + verify against the **Local** WordPress instance only. The
  owner handles the clean-zip deploy to prod separately (do **not** build a deploy
  zip).
- **Build:** the finder is a Vite bundle. After any `src/finder/**` change run
  `npm run build` (from the plugin root) and hard-reload the finder page; the PHP
  enqueues the hashed bundle from `build/.vite/manifest.json`.
- **Finder page:** `/brews/beer-finder/`. A beer modal opens by tapping any beer
  row. (Service worker / PWA is irrelevant to visual work and needs trusted SSL —
  ignore it; plain page load is fine for verifying UI.)
- **Git:** commit to the current branch (`main`) in the plugin repo, one commit per
  feature, present-tense subject matching the existing log style
  (e.g. `Modal: …`, `Filter bar: …`).
- **Namespace:** every finder CSS class/selector is scoped `.obwf-*` under
  `.obwf-app` so it can't collide with the legacy theme. Keep new classes in that
  namespace. **We stay decoupled: mimic the theme's values, never import/apply the
  theme's own classes** (see Site design reference).
- **Reduced motion:** a global `@media (prefers-reduced-motion: reduce)` block in
  `style.css` already kills `.obwf-app` transitions/animations — new transitions
  inherit that guard automatically; don't add a second one.

## Site design reference (match the parent theme)

Overall direction as of 2026-07-08: the finder should **look like it belongs to the
OBW site**, not like a separate app. The finder was originally built visually
self-contained (minimal, no decorative styling); we are now deliberately steering it
to **mimic the parent theme** `rtd_ohio-brew-week-theme`. Mimic (replicate values in
`.obwf-*`), don't inherit (don't apply theme classes) — the React finder must not
depend on theme CSS at runtime.

Source of truth in the theme (SCSS):
- Buttons: `library/scss/modules/_buttons.scss` — the `rtd-button` mixin +
  `.obw-button` and its variants.
- Palette + type: `library/scss/partials/_variables.scss`,
  `library/scss/partials/_typography.scss`.

**Site button spec (`.obw-button` / `rtd-button`):** `font-family: "OpenSans",
helvetica, arial, sans-serif` (**already loaded on the finder page by the theme**),
`font-weight: bold`, `text-transform: uppercase`, `font-size: 16px`,
`line-height: 16px`; `background:#fff`; `border: 1px solid <color>`; `color:<color>`;
`padding: .75em 1em`; **no border-radius (square corners)**; `text-align:center`;
`transition: background-color .35s ease`. Hover/focus/active **fills**:
`background:<color>; color:#fff`. (The theme also adds a decorative "bubbles" hover
animation — **do not** replicate that; the simple fill transition is enough.)

**Site palette (`$obw-*`):** red `#CC4747` · orange `#F47238` · gray `#2C2C2C` ·
gold `#E2A052` · blue `#3B5998` · brown `#483A34` · text `#323232` · white `#FFF`.
Note the finder's existing `--obwf-yellow: #e2a052` is actually the site **gold**
(it was mislabeled "brand orange"); the real site orange is `#F47238` and the real
site gray is `#2C2C2C` (finder currently uses a lighter `#4a4a4a`).

---

## Feature 1 — Modal buttons: match site styling (icons + site palette)

**Status:** ready for handoff (Sonnet / medium). **Revises commit `fa7a921`.**

> **Revision note:** an earlier pass (`fa7a921`, already on `main`) restyled these
> buttons with rounded corners, 1.5px outlines, `system-ui` at 0.9rem, and pastel
> tint-fills. The owner judged that it **drifted too far from the site**. This brief
> **replaces that decorative direction with site-mimicry** (see Site design
> reference above). You are reworking the same two files, not starting from the
> pre-`fa7a921` state.

### Goal
Make the beer-modal action buttons read as **OBW-site buttons**: square, OpenSans
bold uppercase, 1px outline that fills on interaction, using the real `$obw-*`
palette. Untappd becomes an **icon-only gold button** (official Untappd mark). The
three tracker actions become **gray site-buttons that fill with a per-action color
when ON** (Favorite = red, Tasted = gold, Want-To-Try = brown), keeping their
bottle-cap icons.

### Files to touch
- `src/finder/components/BeerModal.jsx` — the two action rows (`.obwf-actions--top`
  and `.obwf-actions--bottom`, currently ~lines 179–223 post-`fa7a921`).
- `src/finder/style.css` — the button rules (`.obwf-card .obwf-btn*` /
  `.obwf-track-btn*`, currently ~lines 683–817 post-`fa7a921`) and `:root` tokens
  (lines 12–19).
- `src/finder/icons/` — add the Untappd logo asset (see below).
- No PHP / REST / build-config changes.

### Current code state (post-`fa7a921`, what you're changing)
- Top row: `Untappd` (`obwf-btn--gold obwf-btn-untappd`, text + `IconExternalLink`)
  and `More Info` (`obwf-btn obwf-btn-more-info`, text + `IconExternalLink`).
- Bottom row: three `<button class="obwf-track-btn obwf-track-btn--{totry,tasted,favorite}">`
  each with an `.obwf-track-ico` span, `aria-pressed`, gaining `obwf-track-btn--on`.
- `style.css` has: a shared base with `border-radius:6px`, 1.5px/1px borders,
  `font-size:.9rem`, `letter-spacing:.01em`; `--obwf-brown:#6b4423`; per-action ON
  states using **light rgba tints** (`.obwf-track-btn--{totry,tasted,favorite}.obwf-track-btn--on`);
  the `.obwf-track-ico` PNG crossfade (reused from the header badges, off-cap →
  on-cap); a shared `:focus-visible` ring. Keep the crossfade mechanism and the
  `aria-pressed`/focus-ring; **change the look to the site spec below.**

### Design spec (mimic the site — see Site design reference)

**Tokens (`:root`):** correct the palette to the site values.
- Add `--obwf-orange: #F47238` (real site orange).
- Change `--obwf-brown` from `#6b4423` → **`#483A34`** (site brown). Only the track
  buttons use it, so this is safe.
- Keep `--obwf-yellow: #e2a052` (it *is* the site gold; leave the name to avoid
  touching its other references at lines ~752/783 — or add an alias `--obwf-gold`).
- Do **not** repurpose the global `--obwf-gray` (#4a4a4a) — it's used across the
  list/sort/etc. For the button gray use the site value **`#2C2C2C`** directly (or a
  new button-only token, e.g. `--obwf-btn-ink: #2C2C2C`). Keep blast radius to the
  modal buttons.

**Shared button base** — apply to all modal action buttons (Untappd, More Info,
the three trackers). Replace the current base rule:
- `font-family: "OpenSans", helvetica, arial, sans-serif;`
- `font-size: 16px; line-height: 16px; font-weight: bold; text-transform: uppercase;`
  drop the `letter-spacing:.01em`.
- `padding: .75em 1em; min-height: 44px;` (keep the 44px tap target as an
  enhancement over the theme — it doesn't change the look).
- `border: 1px solid currentColor; border-radius: 0;` ← **square, remove the 6px.**
- `background:#fff; color:<variant>; cursor:pointer; text-decoration:none;`
- `display:inline-flex; align-items:center; justify-content:center; gap:.5rem;`
- `transition: background-color .35s ease, color .2s ease;`

**A) Untappd — icon-only gold button** (`.obwf-btn--gold` / `.obwf-btn-untappd`)
- Default: white bg, 1px gold (`--obwf-yellow`) border, gold icon.
- Hover/focus/active: **fill gold, icon white** (site model).
- Content: **remove the "Untappd" text and the external-link glyph**; render the
  **official Untappd mark only**, with `aria-label="View on Untappd"` on the `<a>`.
- **Asset (owner-provided):** the owner is supplying the Untappd logo file at
  **`src/finder/icons/untappd.svg`**. Do **not** attempt to source/trace your own —
  wire up this path. Reference it the same way the tracker caps are referenced
  (Vite resolves the URL): render it inside the `<a>` (e.g. as a background-image on
  a sized `<span class="obwf-untappd-ico">`, ~20–22px, or an `<img>` with `alt=""`),
  with `aria-label="View on Untappd"` on the `<a>`.
  - **If the file is a single-color SVG using `currentColor`/`fill:currentColor`:**
    color it via the button's `color` so it flips gold→white on the hover-fill like
    a real site button.
  - **If it's the full-color Untappd mark (fixed colors):** it won't flip on hover;
    in that case make the button neutral **white with a 1px gray border** (no gold
    fill) so the logo reads on its own, and skip the color-flip. Note which case
    applied.
  - **If `src/finder/icons/untappd.svg` is missing at implementation time:** build
    everything else, and leave the Untappd button rendering a temporary
    `IconExternalLink` + `aria-label="View on Untappd"` placeholder wired to the same
    gold button, with a `TODO: swap in untappd.svg` comment — do not block the rest
    of the feature on the asset.

**B) More Info — gray site-button** (`.obwf-btn-more-info`)
- Gray variant: white bg, 1px `#2C2C2C` border, `#2C2C2C` text; hover fills
  `#2C2C2C` with white text.
- Text-only, uppercase "MORE INFO" (drop the external-link glyph to match the
  site's icon-free buttons).

**C) Tracker toggles — gray buttons, per-action fill when ON**
Base (OFF): gray site-button (1px `#2C2C2C` border, `#2C2C2C` label, white bg),
uppercase label + `.obwf-track-ico` off-cap. Keep `aria-pressed`.
ON (`.obwf-track-btn--on`, per-action modifier) — **fill with the action color,
white label**, swap to the on-cap:
| Action | ON fill + border | Label |
|---|---|---|
| Want To Try (`--totry`) | `--obwf-brown` #483A34 | #fff |
| Tasted (`--tasted`) | `--obwf-yellow` #e2a052 (gold) | #fff |
| Favorite (`--favorite`) | `--obwf-red` #cc4747 | #fff |

Replace the current light-`rgba` tint ON rules with these **solid fills**. This
mirrors the site's white-text-on-color active state. (Gold-fill + white text repeats
the ~2:1 contrast look, but it's exactly what the theme's own `.gold-button:hover`
does — accepted as a site convention.)

**Icon-on-fill caveat (flag for eyeball):** on the ON (filled) state the on-cap is a
colored disc that may blend into the same-colored fill — e.g. the amber `tasted_on`
glass on the gold fill can look muddy; the white interior glyph of `favorite_on`
(white heart) / `wishlist_on` (white plus) should still read on red/brown. Ship the
off-cap→on-cap swap as-is, but call this out for on-device review; if a state looks
muddy (Tasted most likely), the fix is to show a **white monochrome glyph** for that
ON state instead of the colored cap.

**Keep:** the `.obwf-track-ico` `::before/::after` PNG crossfade mechanism, the
`aria-pressed` on the toggles, and a visible `:focus-visible` ring on all buttons
(the theme sets `outline:none`, but we keep a ring for a11y — this is an intentional
enhancement, leave it).

### Cleanup
- Remove `border-radius`, `letter-spacing`, and the `system-ui`/0.9rem type from the
  button rules (superseded by the site spec).
- Remove the light-`rgba` tint ON backgrounds (replaced by solid fills).
- After edits, `grep -rn "border-radius\|system-ui\|obwf-btn--gray" src/finder/` and
  confirm no stray decorative button styling remains from `fa7a921`.

### Acceptance criteria
1. All modal buttons render as **OBW-site buttons**: square corners, OpenSans bold
   **UPPERCASE**, 1px outline, white bg, filling solid (white text) on hover/active
   — visually of a piece with `.obw-button` elsewhere on the site.
2. **Untappd** is icon-only (official mark, `aria-label="View on Untappd"`), gold
   outline → fills gold on hover; hidden entirely when `acf.untappd` is empty
   (keep that conditional). **More Info** is a gray text button.
3. The three trackers are gray when OFF and **fill with brown / gold / red when ON**
   (Want-To-Try / Tasted / Favorite), white label, with the correct on-cap; toggling
   persists (handlers `onToTry`/`onTasted`/`onFavorited` unchanged) and stays in
   sync with the header badges.
4. `aria-pressed` on each toggle; `:focus-visible` ring on all buttons; keyboard
   `Tab`/`Enter`/`Space` operate them.
5. `prefers-reduced-motion` still suppresses the fill/crossfade transitions (via the
   existing global guard — verify, don't re-add).
6. No `.obwf-*` rule leaks outside the modal; the filter bar, list rows, and header
   badges are unchanged. The global `--obwf-gray` is untouched.

### Verification (Local)
1. `npm run build` from the plugin root; report the output tail; confirm no errors.
2. Load `/brews/beer-finder/`, hard-reload; open a beer **with** and **without** an
   Untappd link (button absent when empty). Compare a modal button side-by-side with
   a real `.obw-button` elsewhere on the site — they should look like siblings.
3. Toggle each tracker; reopen the beer to confirm persistence + header-badge sync;
   eyeball the ON on-cap against each fill (esp. Tasted/gold — see caveat).
4. Keyboard-only pass + a `prefers-reduced-motion: reduce` pass (DevTools).
5. Narrow-viewport (≤390px) check: the uppercase labels ("WANT TO TRY" is long) wrap
   cleanly and don't overflow.

### Out of scope
List-row / badge styling; filter bar; REST/data; the global `--obwf-gray`
reconciliation (possible later token cleanup); the "Read more" toggle and close
button (leave unless the shared type change trivially covers them).
