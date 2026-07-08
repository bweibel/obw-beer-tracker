/**
 * Progressive Web App wiring.
 *
 * The service worker is served from a ROOT URL (see ServiceWorker.php) so its
 * scope covers the finder page. Both the SW URL and the app manifest URL arrive
 * via the mount node's data attributes, which the shortcode only emits when the
 * PWA killswitch is off — so if they're absent we do nothing at all.
 *
 * Manifest dedup: the theme hardcodes a generic site-wide `<link rel="manifest">`
 * ahead of ours in the document, and browsers honor the FIRST manifest link. To
 * keep this feature entirely plugin-side (no theme edit), we remove any manifest
 * link that isn't ours on the finder page before the browser evaluates install.
 */

function mount() {
	return typeof document !== 'undefined'
		? document.getElementById('obw-beer-finder-root')
		: null;
}

/** Remove foreign `<link rel="manifest">` so our app manifest is the one used. */
function dedupeManifest(manifestUrl) {
	if (!manifestUrl || typeof document === 'undefined') return;

	const links = document.querySelectorAll('link[rel="manifest"]');
	let ours = null;
	links.forEach((link) => {
		// href is resolved to absolute by the DOM; compare against our absolute URL.
		if (link.href === manifestUrl) {
			ours = link;
		} else {
			link.parentNode && link.parentNode.removeChild(link);
		}
	});

	// If the server-rendered link was missing for any reason, add ours.
	if (!ours) {
		const link = document.createElement('link');
		link.rel = 'manifest';
		link.href = manifestUrl;
		document.head.appendChild(link);
	}
}

function registerServiceWorker(swUrl) {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return;
	}

	const register = () => {
		navigator.serviceWorker.register(swUrl, { scope: '/' }).catch(() => {
			/* registration failed (insecure context / private mode) — app still works online */
		});
	};

	if (document.readyState === 'complete') {
		register();
	} else {
		window.addEventListener('load', register, { once: true });
	}
}

/**
 * Entry point called from main.jsx after the app mounts. No-ops unless the
 * killswitch-gated data attributes are present.
 */
export function initPwa() {
	const el = mount();
	if (!el) return;

	const swUrl = el.getAttribute('data-sw-url');
	if (!swUrl) return; // killswitch off (or non-finder mount) — do nothing.

	dedupeManifest(el.getAttribute('data-manifest-url') || '');
	registerServiceWorker(swUrl);
}
