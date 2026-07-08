/**
 * OBW Beer Tracker — service worker (template).
 *
 * This file is NOT served as-is. `ServiceWorker.php` reads it, replaces the
 * "__OBW_PWA_CONFIG__" token with a JSON config object built from the Vite
 * build manifest (so the precache list carries the exact hashed asset URLs of
 * the current build), and streams the result from a ROOT-scoped URL
 * (/obw-beer-tracker-sw.js) so its scope covers the finder page.
 *
 * Strategy:
 *   - App shell (bundle JS/CSS, PWA icons, the finder page HTML): cache-first,
 *     precached at install and versioned by build.
 *   - Finder dataset route + per-beer content: stale-while-revalidate, so a
 *     revisit is instant and refreshes in the background.
 *   - Navigations: network-first, falling back to the cached finder shell when
 *     offline (this is what makes the installed app open with no signal).
 *   - Everything else same-origin: pass through untouched. Cross-origin and
 *     non-GET requests are never handled.
 */

const CONFIG = __OBW_PWA_CONFIG__;
const CACHE = 'obw-pwa-' + CONFIG.version;

self.addEventListener('install', (event) => {
	event.waitUntil(
		caches
			.open(CACHE)
			.then((cache) => cache.addAll(CONFIG.precache))
			// A single missing precache entry must not abort the whole install.
			.catch(() => undefined)
			.then(() => self.skipWaiting())
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches
			.keys()
			.then((keys) =>
				Promise.all(
					keys
						.filter((k) => k.startsWith('obw-pwa-') && k !== CACHE)
						.map((k) => caches.delete(k))
				)
			)
			.then(() => self.clients.claim())
	);
});

/** Cache a response only if it's a usable, same-origin 200. */
function putIfOk(cache, request, response) {
	if (response && response.status === 200 && response.type === 'basic') {
		cache.put(request, response.clone());
	}
	return response;
}

function staleWhileRevalidate(request) {
	return caches.open(CACHE).then((cache) =>
		cache.match(request).then((cached) => {
			const network = fetch(request)
				.then((res) => putIfOk(cache, request, res))
				.catch(() => cached);
			return cached || network;
		})
	);
}

function cacheFirst(request) {
	return caches.open(CACHE).then((cache) =>
		cache.match(request).then(
			(cached) =>
				cached ||
				fetch(request).then((res) => putIfOk(cache, request, res))
		)
	);
}

function networkFirstShell(request) {
	return caches.open(CACHE).then((cache) =>
		fetch(request)
			.then((res) => putIfOk(cache, request, res))
			.catch(() =>
				cache
					.match(request)
					.then((cached) => cached || cache.match(CONFIG.navigateFallback))
			)
	);
}

self.addEventListener('fetch', (event) => {
	const request = event.request;
	if (request.method !== 'GET') return;

	const url = new URL(request.url);
	if (url.origin !== self.location.origin) return; // never touch cross-origin

	const href = url.href;

	// The finder dataset + per-beer content: refresh in the background.
	if (
		href.indexOf(CONFIG.finderRoute) === 0 ||
		href.indexOf(CONFIG.contentPrefix) === 0
	) {
		event.respondWith(staleWhileRevalidate(request));
		return;
	}

	// Precached app-shell assets (hashed bundle, CSS, icons).
	if (CONFIG.precache.indexOf(href) !== -1) {
		event.respondWith(cacheFirst(request));
		return;
	}

	// In-app navigations: network-first, offline-fallback to the finder shell.
	if (request.mode === 'navigate') {
		event.respondWith(networkFirstShell(request));
		return;
	}

	// Anything else same-origin: leave it to the browser.
});

self.addEventListener('message', (event) => {
	if (event.data === 'obw-skip-waiting') self.skipWaiting();
});
