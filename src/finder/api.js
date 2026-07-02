/**
 * REST data layer. Fetches obw_beer / obw_venue / obw_brewery from core REST,
 * paginating via the `X-WP-TotalPages` header (per_page=100) — the same
 * strategy the legacy AngularJS finder used (beerfinder.js loadBeers).
 */

import { decodeEntities, finderConfig } from './util.js';

const PER_PAGE = 100;
const UNTAPPD_PREFIX = 'https://untappd.com/b/';

/**
 * Fetch every published post of a type, following pagination.
 *
 * @param {string} type REST base (obw_beer|obw_venue|obw_brewery).
 * @returns {Promise<Array<object>>}
 */
async function fetchAll(type) {
	const { restUrl, nonce } = finderConfig();
	const base = `${restUrl.replace(/\/$/, '')}/wp/v2/${type}`;
	const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

	const first = await fetch(
		`${base}?per_page=${PER_PAGE}&page=1&post_status=publish&_embed=false`,
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
				fetch(`${base}?per_page=${PER_PAGE}&page=${page}&post_status=publish`, {
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
 * Normalize a raw beer REST record into the shape the UI consumes.
 * Ports parseBeerData(): float abv/ibu, prepend the Untappd URL, decode title.
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
		name: decodeEntities(raw.title && raw.title.rendered),
		link: raw.link,
		content: raw.content && raw.content.rendered ? raw.content.rendered : '',
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
		name: decodeEntities(raw.title && raw.title.rendered),
		link: raw.link,
		beers: Array.isArray(acf[relField]) ? acf[relField] : [],
	};
}

export async function loadBeers() {
	const raw = await fetchAll('obw_beer');
	return raw.map(parseBeer);
}

export async function loadBreweries() {
	const raw = await fetchAll('obw_brewery');
	return raw.map((r) => parseGroup(r, 'brewery_link'));
}

export async function loadVenues() {
	const raw = await fetchAll('obw_venue');
	return raw.map((r) => parseGroup(r, 'venue_link'));
}
