# Venue Distance / "Near Me" — Spec (SHELVED)

**Status:** shelved before implementation. Design questions in §4 are still open
and must be answered before building. This doc captures the grounding + the
decisions so it's a warm start later.

Original ask (mobile-polish item #8): sort venues by distance / surface what's
pourable near the user.

---

## 1. Goal

Help a festival-goer decide **which venue to go to next**, using their phone's
location. Secondary: show how far a given venue is.

## 2. Grounding findings (verified against prod data 2026-07)

- **Coordinates already exist.** Every `obw_venue` has a populated ACF
  `location` (google_map) field with real `lat`/`lng` (+ address, city, etc.).
  Example: Black Diamond Brewery → `lat 39.4592449, lng -82.2346454`. **No
  geocoding needed.**
- **But the coords aren't in the finder payload.** `/obw/v1/finder` returns
  venues slimmed to `{ id, name, link, beers }` (acf stripped). The `location`
  object only exists on the raw `/wp/v2/obw_venue/{id}` endpoint. Fetching 30
  venues client-side is a non-starter → **a server change is required** (see §5).
- **This is regional, not a walkable downtown.** Venues span multiple Athens
  County towns (e.g. Nelsonville, Athens), potentially 10–20 miles apart, some
  across the Hocking River. Straight-line distance can diverge substantially
  from road distance at that scale — this reframes the whole feature (it's not
  "rank 20 bars on Court Street").

## 3. Technical notes

- **Distance math:** haversine (great-circle) is free, instant, offline-capable,
  no API/key. Runs client-side from coords in the cached payload → works with
  the offline PWA.
- **Driving distance/time** would need a routing API (Google/Mapbox): API key,
  cost, network-only, breaks the offline story. Deliberately out of scope for v1
  (see the "Directions link" alternative in §4.3).
- **Privacy:** location is used only on-device for math; it never leaves the
  browser. Good talking point.
- **Secure context:** geolocation (like the service worker) requires HTTPS —
  fine on prod, already the case.

## 4. OPEN QUESTIONS (must resolve before building)

Each has a recommendation, but none is decided.

### 4.1 What decision is this making?
Ranking venues by proximity, a per-venue distance label, or both?
- **Rec:** ranking is the real value; the label is a cheap add-on. Open concern:
  straight-line ranking across a river/highway can mislead — is "wrong-but-
  simple" acceptable, or is that a dealbreaker?

### 4.2 Where does it surface?
(a) "Near me" sort on the **Venue** tab; (b) reorder **My List** venues by
distance instead of by to-try count ("your itinerary, nearest first");
(c) both.
- **Rec:** (b) is the killer combo and the main reason to build this. Confirm
  whether the plain Venue tab needs it too.

### 4.3 Straight-line vs driving — and do we show a number at all?
- **Rec:** haversine ordering + a subtle "~X mi" **plus a per-venue "Directions"
  link** that hands off to the native maps app (`https://maps.google.com/?q=lat,lng`
  or `geo:` URI) for real routing. Zero API cost; that's what people actually tap.
- Alternative framing worth considering: maybe v1 is *just* the Directions link
  (no in-app distance at all).

### 4.4 Consent & failure UX
Ask for location on tab open, or behind an explicit **"Near me" button**?
- **Rec:** explicit button (clear intent). On denied/unavailable/timeout, fall
  back to the current ordering (by-count / alphabetical). Must never leave the
  view broken or empty.

### 4.5 One-shot or live?
`getCurrentPosition` once (+ manual "update location") vs `watchPosition`
(re-sorts as you walk; battery cost + list reshuffles under the thumb).
- **Rec:** one-shot on demand for v1.

### 4.6 Scope cut — what is v1?
- **Proposed MVP:** server adds `lat/lng` to the finder payload → a "Near me"
  control that requests location on tap, haversine-sorts venues (My List and/or
  Venue tab), shows a subtle "~X mi," falls back gracefully, and adds a
  "Directions" link per venue. **Out of scope for v1:** live tracking, driving
  time, map view.
- Open: is the in-app distance number even wanted, or is this really just "add a
  Directions link"?

## 5. Required server change (regardless of UX answers)

Extend the `/obw/v1/finder` venue normalizer (see `src/Rest/FinderController.php`
and `src/Fields.php`) to include coordinates, e.g.:

```
venue: { id, name, link, beers, lat, lng }   // lat/lng from ACF location, or null
```

Keep it null-safe: some venues may have empty/malformed `location`. Bumps the
finder ETag/cache automatically. Client treats missing coords as "distance
unknown," sorts those venues last, and can still show a Directions link if an
address exists.

## 6. Edge cases to handle
- Venue with empty/malformed coords → "distance unknown," sorted last.
- User far from the festival (not in Athens County) → distances are huge but
  ordering still valid; don't over-promise precision.
- GPS accuracy in a small downtown is ± tens of meters — fine for ranking
  miles-apart venues, not for "which side of the street."
- Permission previously denied at the browser level → detect and show the
  fallback ordering with a hint, don't spam prompts.
