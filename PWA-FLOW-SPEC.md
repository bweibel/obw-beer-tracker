# PWA homescreen-flow enhancements

**Status:** IMPLEMENTED on `main`, pending LIVE verification (could not be tested
locally — Local serves an insecure origin, so the SW won't register there).
Ships to prod behind the `wp obw pwa off` kill switch; rollback target if the
whole change misbehaves is commit `6adcc7f` (Feature 2). Two additions to the
existing (working) PWA: an **install coach** (discovery) and an **update toast**
(freshness). All client work lives in `src/finder/pwa.js` + `src/finder/style.css`,
plus one line removed from `assets/pwa/sw-template.js`. No PHP / REST / manifest
change.

## Why

The plumbing (root-scoped SW, manifest, offline shell, killswitch) is solid, but
the *flow* leaks in two places the grill flagged:

1. **Nobody is invited to install.** Android fires `beforeinstallprompt` and we
   ignore it; iOS Safari fires nothing and needs manual "Share → Add to Home
   Screen," which no one does unprompted. For a ~10-day event, if people don't
   install in session one, they never do.
2. **Updates are invisible mid-event.** A new build = new SW, but the open tab
   keeps running the already-parsed old bundle until a manual refresh. There's
   no "new version — refresh" path — even though the SW already has the *client*
   end of one (`message` handler for `'obw-skip-waiting'`) sitting unused because
   `install` currently calls `self.skipWaiting()` unconditionally.

## Feature A — Install coach (additive, zero SW-behavior change)

A small, dismissible banner the finder app owns, created as vanilla DOM by
`pwa.js` (consistent with how it already manipulates the manifest `<link>`), so
it needs nothing from the Preact tree and can react to `beforeinstallprompt`,
which may fire before the app mounts.

**Gating (show nothing unless all true):**
- PWA is enabled (already implied: `initPwa` returns early if `data-sw-url` is
  absent — the killswitch path).
- Not already installed: `matchMedia('(display-mode: standalone)').matches` is
  false AND `navigator.standalone !== true` (iOS).
- Not recently dismissed: no un-expired `obwPwaCoachDismissed` localStorage
  timestamp (re-show allowed after 7 days; permanent once `appinstalled` fires).

**Per-platform behavior:**
- **Android / desktop Chromium:** capture `beforeinstallprompt`
  (`preventDefault()`, stash the event), then reveal the banner with an "Add to
  home screen" button that calls `deferredPrompt.prompt()`. Hide on
  `appinstalled` and persist a permanent dismissal.
- **iOS Safari** (`/iphone|ipad|ipod/` and not standalone): no native prompt is
  possible — show instructional copy with the iOS share glyph: "Tap Share, then
  Add to Home Screen." Shown after a short engagement delay.
- **Anything else** (no `beforeinstallprompt`, not iOS): show nothing — we can't
  install and won't fake it.

**Dismissal:** an × writes the 7-day timestamp; the banner never nags within a
session or across a week.

## Feature B — Update toast (changes the SW update model)

**Risk acknowledged:** this alters update semantics for the *already-installed
base during the live event*. It is the standard, safer model (no surprise
asset/JS swap under a running session), but it must be verified against a real
install → redeploy → toast → refresh cycle before shipping.

**`sw-template.js`:** remove the `self.skipWaiting()` from the `install` handler
so an updated worker enters `waiting` instead of taking over immediately. First
installs (no existing controller) still activate normally. Keep the existing
`message`/`obw-skip-waiting` handler and `activate → clients.claim()`.

**`pwa.js`:** after `register(swUrl)`:
- If `reg.waiting && navigator.serviceWorker.controller` → an update is already
  waiting → show the toast now.
- On `reg` `updatefound` → the new worker's `statechange` to `installed` *with*
  an existing `controller` → show the toast.
- Toast: "A new version is available." + a **Refresh** action that
  `reg.waiting.postMessage('obw-skip-waiting')`.
- Reload exactly once on activation: a `controllerchange` listener guarded by a
  `refreshing` flag calls `location.reload()`.

## Files
- `assets/pwa/sw-template.js` — delete one `.then(() => self.skipWaiting())`.
- `src/finder/pwa.js` — install-coach + update-toast wiring (the bulk).
- `src/finder/style.css` — `.obwf-pwa-coach` / `.obwf-pwa-toast` (fixed, dark,
  brand accent, reduced-motion-guarded).
- No PHP: the manifest, iOS meta, icons, and killswitch are already in place.

## Acceptance criteria
1. Desktop Chromium / Android: after engagement, an "Add to home screen" banner
   appears; clicking it triggers the native install prompt; installing (or ×)
   hides it and it doesn't return (× → for 7 days).
2. iOS Safari: the Share→Add-to-Home-Screen coaching appears (no native prompt);
   × dismisses for 7 days.
3. Already-installed / standalone launch: **no** coach, **no** toast noise.
4. Redeploy with an open tab: the "new version — Refresh" toast appears; Refresh
   activates the waiting worker and reloads **once** into the new build.
5. First-ever install still works (no controller ⇒ no toast, SW activates).
6. Killswitch (`wp obw pwa off`) still fully tears down — no coach/toast, and the
   self-destruct SW path is untouched.
7. `prefers-reduced-motion` honored; keyboard can reach/activate every control;
   nothing blocks the finder UI or shifts layout on load.

## Verification (Local)
1. `npm run build`; confirm clean.
2. Desktop Chrome DevTools → Application: install via the banner; confirm
   standalone launch shows no banner.
3. Rebuild (new hash) with the installed app/tab open → confirm the update toast,
   click Refresh, confirm a single reload into the new assets.
4. iOS Safari (or device emulation): confirm the share-sheet coaching copy.
5. `wp obw pwa off` → confirm coach + toast vanish and the SW self-destructs.

## Out of scope
Push notifications, background sync, install analytics, app `shortcuts`,
richer offline UX. Manifest/icons (already shipped).
