/**
 * REST data layer.
 *
 * Phase 2 §4.1: the finder's full dataset (beers/breweries/venues) is fetched
 * in ONE request from the plugin's precomputed `/obw/v1/finder` route instead
 * of the old three-type, per-type-paginated core-REST waterfall. The route's
 * response is cached client-side (§4.3, see `loadFinderCache`/`saveFinderCache`
 * below) keyed by its ETag, and revalidated with `If-None-Match` on load.
 *
 * If the custom route is unavailable (plugin/route missing, 404, network
 * error) this falls back to the original three-type core-REST `fetchAll`
 * loops so the finder still works, just without the single-payload win.
 */

import { decodeEntities, finderConfig } from './util.js';

const PER_PAGE = 100;
const UNTAPPD_PREFIX = 'https://untappd.com/b/';

// Separate from tracker.js's `beerData` key — this is a read-through cache of
// server data, not user tracker state. Clearing one must not clear the other.
const CACHE_KEY = 'obwFinderDataCache';
// Fallback freshness window used only when we don't have (or can't revalidate)
// an ETag — keeps a revisit instant without risking indefinite staleness.
const CACHE_TTL_MS = 30 * 60 * 1000; // 30 minutes

/** In-memory cache of per-beer modal content, keyed by beer id (§4.2). */
const contentCache = new Map();

function restBase() {
	const { restUrl, nonce } = finderConfig();
	return { base: restUrl.replace(/\/$/, ''), nonce };
}

function readClientCache() {
	try {
		const raw = localStorage.getItem(CACHE_KEY);
		if (!raw) return null;
		const parsed = JSON.parse(raw);
		if (!parsed || typeof parsed !== 'object') return null;
		return parsed;
	} catch (e) {
		return null;
	}
}

function writeClientCache(entry) {
	try {
		localStorage.setItem(CACHE_KEY, JSON.stringify(entry));
	} catch (e) {
		/* storage full / unavailable — non-fatal, just skip caching */
	}
}

/**
 * Fetch every published post of a type, following pagination. Used only by
 * the core-REST fallback path.
 *
 * @param {string} type REST base (obw_beer|obw_venue|obw_brewery).
 * @returns {Promise<Array<object>>}
 */
async function fetchAll(type) {
	const { base, nonce } = restBase();
	const url = `${base}/wp/v2/${type}`;
	const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

	const first = await fetch(
		`${url}?per_page=${PER_PAGE}&page=1&post_status=publish&_embed=false`,
		{ headers, credentials: 'same-origin' }
	);
	if (!first.ok) {
		throw new Error(`Failed to load ${type}: ${first.status}`);
	}

	const totalPages = parseInt(first.headers.get('X-WP-TotalPages') || '1', 10) || 1;
	let items = await first.json();

	if (totalPages > 1) {
		const requests = [];
		for (let page = 2; page <= totalPages; page++) {
			requests.push(
				fetch(`${url}?per_page=${PER_PAGE}&page=${page}&post_status=publish`, {
					headers,
					credentials: 'same-origin',
				}).then((r) => (r.ok ? r.json() : []))
			);
		}
		const rest = await Promise.all(requests);
		items = items.concat(...rest);
	}

	return items;
}

/**
 * Normalize a raw beer REST record (core REST shape) into the shape the UI
 * consumes. Ports parseBeerData(): float abv/ibu, prepend the Untappd URL,
 * decode title.
 *
 * @param {object} raw
 * @returns {object}
 */
function parseBeer(raw) {
	const acf = raw.acf || {};
	const abv = acf.abv != null && acf.abv !== '' ? parseFloat(acf.abv) : undefined;
	const ibu = acf.ibu != null && acf.ibu !== '' ? parseFloat(acf.ibu) : undefined;
	const untappd = acf.untappd ? UNTAPPD_PREFIX + acf.untappd : '';

	return {
		id: raw.id,
		name: decodeEntities(raw.title && raw.title.rendered ? raw.title.rendered : raw.name),
		link: raw.link,
		abv,
		ibu,
		acf: {
			style: acf.style || '',
			abv,
			ibu,
			untappd,
			brewery_link: Array.isArray(acf.brewery_link) ? acf.brewery_link : [],
			venue_link: Array.isArray(acf.venue_link) ? acf.venue_link : [],
		},
	};
}

/**
 * Normalize a brewery/venue record. Keeps the reverse-relation beers list
 * (brewery_link / venue_link) which the plugin's REST normalizer slimmed to
 * `{ ID, post_title, post_name, post_status }`.
 *
 * @param {object} raw
 * @param {string} relField brewery_link|venue_link
 * @returns {object}
 */
function parseGroup(raw, relField) {
	const acf = raw.acf || {};
	return {
		id: raw.id,
		name: decodeEntities(raw.title && raw.title.rendered ? raw.title.rendered : raw.name),
		link: raw.link,
		beers: Array.isArray(acf[relField]) ? acf[relField] : raw.beers || [],
	};
}

/**
 * Normalize the `/obw/v1/finder` route's beer entry (already the target
 * shape: `{ id, name, link, acf }`, no `content`) — still runs through
 * `parseBeer` for entity-decoding/type-coercion parity with the fallback path.
 */
function parseFinderBeer(raw) {
	return parseBeer(raw);
}

function parseFinderGroup(raw, relField) {
	return parseGroup(raw, relField);
}

/**
 * Load the whole finder dataset in one request from the precomputed route,
 * with an ETag-aware client cache (§4.3) and a core-REST fallback (§4.1) if
 * the route errors or doesn't exist.
 *
 * @returns {Promise<{beers: object[], breweries: object[], venues: object[]}>}
 */
export async function loadFinderData() {
	const { base, nonce } = restBase();
	const url = `${base}/obw/v1/finder`;
	const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

	const cached = readClientCache();
	if (cached && cached.etag) {
		headers['If-None-Match'] = cached.etag;
	}

	let res;
	try {
		res = await fetch(url, { headers, credentials: 'same-origin' });
	} catch (e) {
		return loadFinderDataFallback(cached);
	}

	if (res.status === 304 && cached) {
		return normalizeFinderPayload(cached.data);
	}

	if (!res.ok) {
		return loadFinderDataFallback(cached);
	}

	let payload;
	try {
		payload = await res.json();
	} catch (e) {
		return loadFinderDataFallback(cached);
	}

	const etag = res.headers.get('ETag') || '';
	writeClientCache({ etag, data: payload, savedAt: Date.now() });

	return normalizeFinderPayload(payload);
}

/**
 * Fall back to a fresh-enough client cache (TTL, no working revalidation) or
 * else the core-REST three-type path.
 */
async function loadFinderDataFallback(cached) {
	if (cached && cached.data && Date.now() - (cached.savedAt || 0) < CACHE_TTL_MS) {
		return normalizeFinderPayload(cached.data);
	}

	const [beers, breweries, venues] = await Promise.all([
		fetchAll('obw_beer').then((raw) => raw.map(parseBeer)),
		fetchAll('obw_brewery').then((raw) => raw.map((r) => parseGroup(r, 'brewery_link'))),
		fetchAll('obw_venue').then((raw) => raw.map((r) => parseGroup(r, 'venue_link'))),
	]);

	return { beers, breweries, venues };
}

function normalizeFinderPayload(payload) {
	const beers = Array.isArray(payload && payload.beers) ? payload.beers.map(parseFinderBeer) : [];
	const breweries = Array.isArray(payload && payload.breweries)
		? payload.breweries.map((r) => parseFinderGroup(r, 'brewery_link'))
		: [];
	const venues = Array.isArray(payload && payload.venues)
		? payload.venues.map((r) => parseFinderGroup(r, 'venue_link'))
		: [];

	return { beers, breweries, venues };
}

/**
 * §4.2: lazy-fetch a single beer's post content on modal open. Cached in
 * memory for the session (module-level Map) — a beer opened twice in one
 * visit doesn't re-fetch.
 *
 * @param {number|string} beerId
 * @returns {Promise<string>} The rendered content HTML (possibly empty).
 */
export async function loadBeerContent(beerId) {
	if (contentCache.has(beerId)) {
		return contentCache.get(beerId);
	}

	const { base, nonce } = restBase();
	const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

	const promise = fetch(`${base}/wp/v2/obw_beer/${beerId}?_fields=content`, {
		headers,
		credentials: 'same-origin',
	})
		.then((r) => (r.ok ? r.json() : null))
		.then((data) => (data && data.content && data.content.rendered ? data.content.rendered : ''))
		.catch(() => '');

	contentCache.set(beerId, promise);
	const content = await promise;
	contentCache.set(beerId, content); // replace the in-flight promise with the resolved value
	return content;
}
