#!/usr/bin/env python3
"""
Normalize the raw OBW 2026 beer spreadsheet into the obw-beer-tracker importer
CSV contract: name,style,abv,ibu,untappd,description,brewery_id,brewery,venue_id,venue

Resolves brewery/venue spelling variants to directory post IDs (ID-matching
sidesteps the curly-apostrophe / typo problems), cleans ABV (%/x100), reduces
Untappd URLs to raw slugs, recovers misplaced descriptions, and drops the
Guinness joke row. See memory: beer-import-data-quirks.

Usage: python3 normalize.py            # writes beers-2026-clean.csv + report
"""
import csv, re, sys, os

HERE = os.path.dirname(os.path.abspath(__file__))
RAW = os.path.join(HERE, "Main Beer List 2026 - Sheet1.csv")
OUT = os.path.join(HERE, "beers-2026-clean.csv")

def norm(s: str) -> str:
    s = (s or "").replace("’", "'").replace("&#8217;", "'")
    return re.sub(r"\s+", " ", s).strip().lower()

# --- Brewery variant -> directory ID (confirmed against import/breweries.json) --
BREWERY_ID = {
    "50 west": 1344, "50 west brewing company": 1344,
    "black diamond": 10749,
    "brew dog": 4079, "brew dog brewing": 4079, "brewdog": 4079,
    "collision bend": 10859,
    "columbus brewing co": 3096, "columbus brewing company": 3096,
    "combustion": 4195, "combustion brewery": 4195, "combustion brewing": 4195,
    "dafuque beer company": 10014,
    "devil's kettle": 1349, "devils kettle": 1349,
    "dutch creek winery": 3006,
    "fat head": 1351, "fat head's": 1351, "fat head's brewery": 1351, "fat heads": 1351,
    "garare beer": 10177, "grarage beer": 10177,
    "great lakes": 1356,
    "jackie o's": 1357, "jackie o's brewery": 1357,
    "land grant": 1360,
    "little fish": 1362, "little fish brewing company": 1362,
    "mad tree brewing": 1364,
    "market garden brewery": 3003,
    "phoenix brewing company": 1370,
    "rhinegeist": 1373, "rhinegeist brewery": 1373, "rhinegiest": 1373,
    "rice family brewing": 9306,
    "rockmill": 1374,
    "saucy brew works": 7610,
    "seventh son": 1376, "seventh son brewing company": 1376,
    "the brew kettle": 1346,
    "west end ciderhouse": 1894,
    "wolf's ridge brewing": 3002,
    # collab-part short forms
    "sixth sense": 2727, "yellow springs": 1382,
}

# --- New breweries: normalized variant -> canonical title. IDs filled after the
# owner pre-creates them (Q6). None = ID-less (preview: falls back to name match).
NEW_BREWERY_TITLE = {
    "nocterra": "Nocterra Brewing Company",
    "nocterra brewing": "Nocterra Brewing Company",
    "nocterra brewing company": "Nocterra Brewing Company",
    "northern row": "Northern Row",
    "third eye brewing": "Third Eye Brewing",
    "appalachian artisan ales": "Appalachian Artisan Ales",
    "three tigers brewing": "Three Tigers Brewing",
    "wooly pig": "Wooly Pig",
}
# Fill these after pre-creation, e.g. {"Nocterra Brewing Company": 12345, ...}
NEW_BREWERY_ID: dict[str, int] = {}

# When a brewery can't be resolved to a directory ID (an as-yet-uncreated new
# brewery), drop the whole row rather than fall back to name matching. Flip to
# False to re-enable name-fallback once NEW_BREWERY_ID is populated.
DROP_UNRESOLVED = True

# Collapse the venue-centric spreadsheet's per-venue duplicate rows into one beer
# post per (brewery + name), unioning their venues. Keeps the finder's Brews /
# Brewery tabs unique while the Venue tab + print sheet still show a beer under
# every venue it's poured at (via the many-to-many venue_link).
DEDUPE = True

# --- Venue variant -> directory ID (or list for the dual-venue Dutch Creek rows) --
VENUE_ID = {
    "black diamond brewery and distillery": 10811,
    "casa nueva": 203,
    "cat's eye saloon": 201, "cats eye": 201,
    "ciderhouse mfg": 7151,      # -> West End Distillery (Q2)
    "ciderhouse tavern": 118,    # -> West End Ciderhouse (Q2)
    "courtside pizza": 199,
    "devil's kettle": 196,
    "dutch creek winery": 7688,
    "dutch creek winery / eclipse company store": [7688, 2744],  # both (Q2c)
    "eclipse": 2744, "eclipse company store": 2744,
    "jackie o's brewpub": 26, "jackie o's taproom": 71,
    "little fish brewing company": 190,
    "north end kitchen & bar": 9081,
    "ou inn": 187,
    "overhang": 186,
    "pigskin": 184,
    "the ci": 200,
    "the crystal": 197,
    "the jbar": 191,
    "the pub": 183,
    "the side bar": 9843,
    "the union": 289,
    "tony's": 179, "tony's tavern": 179,
}

def id2title(path):
    import json
    with open(os.path.join(HERE, path)) as fh:
        data = json.load(fh)
    out = {}
    for e in data:
        t = e["title"]["rendered"].replace("&#8217;", "'").replace("&#038;", "&").replace("&amp;", "&")
        out[e["id"]] = t
    return out

BREW_TITLE = id2title("breweries.json")

def clean_abv(s, flags):
    s = (s or "").replace("%", "").strip()
    if not s:
        return ""
    try:
        v = float(s)
    except ValueError:
        flags.append(("abv_unparseable", s))
        return ""
    if v > 30:
        flags.append(("abv_div100", f"{s} -> {v/100:g}"))
        v = v / 100
    return str(int(v)) if v == int(v) else ("%.2f" % v).rstrip("0").rstrip(".")

def clean_untappd(u, desc, stats):
    u = (u or "").strip()
    if "untappd.com/b/" in u:
        m = re.search(r"untappd\.com/b/([^?#\s]+)", u)
        slug = m.group(1).rstrip("/") if m else ""
        if slug:
            stats["untappd_slug"] += 1
            return slug, desc
        stats["untappd_nonbeer"] += 1
        return "", desc
    if "untappd.com" in u:  # brand page / search URL
        stats["untappd_nonbeer"] += 1
        return "", desc
    if u and u.lower() not in ("n/a", "na", "not available.", "not available"):
        # Misplaced prose in the Untappd column; recover it as description if empty.
        if not desc:
            stats["prose_to_desc"] += 1
            return "", u
        return "", desc
    return "", desc

def split_collab(s):
    if "," in s or re.search(r"\s+x\s+", s, re.I):
        parts = re.split(r"\s+x\s+|,", s, flags=re.I)
    else:
        parts = [s]
    return [p.strip() for p in parts if p.strip()]

def resolve_brewery(raw, report):
    tokens = []  # each: ("id", int) | ("new", title, id_or_None) | ("miss", raw)
    for p in split_collab(raw):
        n = norm(p)
        if n in BREWERY_ID:
            tokens.append(("id", BREWERY_ID[n]))
        elif n in NEW_BREWERY_TITLE:
            title = NEW_BREWERY_TITLE[n]
            tokens.append(("new", title, NEW_BREWERY_ID.get(title)))
        else:
            report["brewery_miss"].add(p)
            tokens.append(("miss", p))
    ids, names, all_ids = [], [], True
    for t in tokens:
        if t[0] == "id":
            ids.append(t[1]); names.append(BREW_TITLE.get(t[1], ""))
        elif t[0] == "new" and t[2]:
            ids.append(t[2]); names.append(t[1])
        elif t[0] == "new":
            all_ids = False; names.append(t[1]); report["new_rows"] += 1
        else:
            all_ids = False; names.append(t[3] if len(t) > 3 else t[1])
    if all_ids and ids:
        return "|".join(str(i) for i in ids), "", False
    if DROP_UNRESOLVED:
        return "", "", True  # unresolved new brewery -> drop the row
    return "", "|".join(names), False  # name-fallback (all names, importer-safe)

def resolve_venue(raw, report):
    n = norm(raw)
    if n in VENUE_ID:
        v = VENUE_ID[n]
        ids = v if isinstance(v, list) else [v]
        return "|".join(str(i) for i in ids), ""
    report["venue_miss"].add(raw)
    return "", raw

def dedupe_beers(rows):
    """Merge rows sharing (brewery_id, space-insensitive name) into one beer,
    unioning venues. Returns (deduped_rows, info)."""
    from collections import Counter, OrderedDict
    groups = OrderedDict()
    for r in rows:
        key = (r["brewery_id"], norm(r["name"]).replace(" ", ""))
        groups.setdefault(key, []).append(r)

    out, abv_conf, merged = [], [], 0
    for rs in groups.values():
        if len(rs) == 1:
            out.append(rs[0])
            continue
        merged += len(rs) - 1
        primary = max(rs, key=lambda r: len(r["description"]))
        name = Counter(r["name"] for r in rs).most_common(1)[0][0]

        def pick(field):
            if primary[field].strip():
                return primary[field]
            return next((r[field] for r in rs if r[field].strip()), "")

        abvs = sorted({r["abv"].strip() for r in rs if r["abv"].strip()})
        if len(abvs) > 1:
            abv_conf.append((name, pick("abv"), abvs))

        seen = []
        for r in rs:
            for v in r["venue_id"].split("|"):
                v = v.strip()
                if v and v not in seen:
                    seen.append(v)

        m = dict(primary)
        m.update(name=name, style=pick("style"), abv=pick("abv"),
                 untappd=pick("untappd"), venue_id="|".join(seen), venue="")
        out.append(m)
    return out, {"merged": merged,
                 "groups": sum(1 for g in groups.values() if len(g) > 1),
                 "abv_conf": abv_conf}


def main():
    report = {"brewery_miss": set(), "venue_miss": set(), "new_rows": 0}
    stats = {"untappd_slug": 0, "untappd_nonbeer": 0, "prose_to_desc": 0}
    abv_flags = []
    rows_in = rows_out = dropped = dropped_newbrew = 0
    out_rows = []

    with open(RAW, newline="") as fh:
        r = csv.reader(fh)
        next(r)  # header
        for row in r:
            if len(row) < 5 or not any(c.strip() for c in row):
                continue
            rows_in += 1
            name = row[4].strip()
            if not name:
                dropped += 1  # Guinness joke row / blank
                continue
            style = row[5].strip()
            desc = row[7].strip()
            abv = clean_abv(row[6], abv_flags)
            untappd, desc = clean_untappd(row[8] if len(row) > 8 else "", desc, stats)
            brewery_id, brewery, drop_row = resolve_brewery(row[3].strip(), report)
            if drop_row:
                dropped_newbrew += 1
                continue
            venue_id, venue = resolve_venue(row[2].strip(), report)
            out_rows.append({"name": name, "style": style, "abv": abv, "ibu": "",
                             "untappd": untappd, "description": desc,
                             "brewery_id": brewery_id, "brewery": brewery,
                             "venue_id": venue_id, "venue": venue})
            rows_out += 1

    dedupe_info = {"merged": 0, "groups": 0, "abv_conf": []}
    if DEDUPE:
        out_rows, dedupe_info = dedupe_beers(out_rows)

    cols = ["name", "style", "abv", "ibu", "untappd", "description",
            "brewery_id", "brewery", "venue_id", "venue"]
    with open(OUT, "w", newline="") as fh:
        w = csv.DictWriter(fh, fieldnames=cols)
        w.writeheader()
        w.writerows(out_rows)

    # ---- Report ----
    print(f"rows in (non-blank): {rows_in}")
    print(f"rows after cleanup:  {rows_out}")
    print(f"rows dropped:        {dropped}  (Guinness joke / blank rows)")
    print(f"rows dropped (new brewery, not yet created): {dropped_newbrew}")
    if DEDUPE:
        print(f"\nDedupe: merged {dedupe_info['merged']} duplicate rows across "
              f"{dedupe_info['groups']} beers -> {rows_out - dedupe_info['merged']} unique beers written")
        if dedupe_info["abv_conf"]:
            print(f"  ABV conflicts (kept first value, review upstream):")
            for name, kept, alts in dedupe_info["abv_conf"]:
                print(f"      {name!r}: kept {kept}, saw {alts}")
    print(f"\nABV: {len(abv_flags)} fixes")
    div = [f for f in abv_flags if f[0] == "abv_div100"]
    bad = [f for f in abv_flags if f[0] == "abv_unparseable"]
    print(f"  /100 corrections: {len(div)}")
    for _, m in div:
        print(f"      {m}")
    if bad:
        print(f"  unparseable (blanked): {[m for _, m in bad]}")
    print(f"\nUntappd: {stats['untappd_slug']} slugs, "
          f"{stats['untappd_nonbeer']} non-beer blanked, "
          f"{stats['prose_to_desc']} prose recovered to description")
    print(f"\nNew-brewery placements (name-fallback): {report['new_rows']}")
    ni = [t for t in NEW_BREWERY_TITLE.values() if t not in NEW_BREWERY_ID]
    print(f"  still ID-less: {sorted(set(ni))}")
    print(f"\nUnmatched breweries: {sorted(report['brewery_miss']) or 'NONE'}")
    print(f"Unmatched venues:    {sorted(report['venue_miss']) or 'NONE'}")
    print(f"\nwrote {OUT}")

if __name__ == "__main__":
    main()
