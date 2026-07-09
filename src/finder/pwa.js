/**
 * Progressive Web App wiring: service-worker registration, an install coach,
 * and an update toast.
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
 *
 * The coach + toast are built as small vanilla-DOM elements (not Preact) so they
 * can react to `beforeinstallprompt` — which Chromium may fire before the app
 * mounts — and stay decoupled from the finder's component tree, matching how
 * this module already manipulates the manifest `<link>` directly.
 */

const COACH_DISMISS_KEY = 'obwPwaCoachDismissed';
const COACH_SNOOZE_MS = 7 * 24 * 60 * 60 * 1000; // re-offer after a week
const COACH_IOS_DELAY_MS = 4000; // let people look around before coaching iOS

// Captured `beforeinstallprompt` event (Android / desktop Chromium). Grabbed at
// module load because it can fire before `initPwa` runs; only *used* once the
// coach is deemed eligible.
let deferredPrompt = null;
// Set true by initPwa when the PWA is active (killswitch off). Guards the coach.
let coachEnabled = false;
// References to the transient UI, so we never build either one twice.
let coachEl = null;
let toastEl = null;
// Set true only when the user clicks Refresh. Gates the reload-on-activation so
// a first-ever install's `clients.claim()` (which also fires `controllerchange`,
// null -> worker) can't trigger a spurious reload — we reload only for an update
// the user asked for.
let updateRequested = false;

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

// ── Environment probes ─────────────────────────────────────────────────────

/** Already installed / launched from the home screen? */
function isStandalone() {
	if (typeof window === 'undefined') return false;
	return (
		(window.matchMedia &&
			window.matchMedia('(display-mode: standalone)').matches) ||
		// iOS Safari exposes standalone via navigator, not display-mode.
		window.navigator.standalone === true
	);
}

/** iOS Safari — no `beforeinstallprompt`, install is a manual Share-sheet step. */
function isIos() {
	if (typeof navigator === 'undefined') return false;
	return /iphone|ipad|ipod/i.test(navigator.userAgent);
}

// ── Dismissal persistence ──────────────────────────────────────────────────

/** True while the coach is snoozed (recent × dismissal or a prior install). */
function coachSnoozed() {
	try {
		const raw = localStorage.getItem(COACH_DISMISS_KEY);
		if (!raw) return false;
		if (raw === 'installed') return true; // permanent once installed
		const ts = parseInt(raw, 10);
		return Number.isFinite(ts) && Date.now() - ts < COACH_SNOOZE_MS;
	} catch (e) {
		return false;
	}
}

/** Persist a dismissal — permanent after an install, else a 7-day snooze. */
function snoozeCoach(permanent) {
	try {
		localStorage.setItem(
			COACH_DISMISS_KEY,
			permanent ? 'installed' : String(Date.now())
		);
	} catch (e) {
		/* storage unavailable — non-fatal, coach simply reappears next visit */
	}
}

// ── Small DOM helper ───────────────────────────────────────────────────────

function h(tag, props, children) {
	const node = document.createElement(tag);
	if (props) {
		Object.keys(props).forEach((k) => {
			if (k === 'class') node.className = props[k];
			else if (k === 'html') node.innerHTML = props[k];
			else if (k.indexOf('aria-') === 0 || k === 'role')
				node.setAttribute(k, props[k]);
			else node[k] = props[k];
		});
	}
	(children || []).forEach((c) =>
		node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c)
	);
	return node;
}

// iOS "share" glyph (square + up arrow), currentColor, sized in CSS.
const IOS_SHARE_SVG =
	'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
	'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
	'<path d="M12 15V3"/><polyline points="8 7 12 3 16 7"/>' +
	'<path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7"/></svg>';

// ── Install coach ──────────────────────────────────────────────────────────

function removeCoach() {
	if (coachEl && coachEl.parentNode) coachEl.parentNode.removeChild(coachEl);
	coachEl = null;
}

/** Build + show the coach for the given platform mode ('android' | 'ios'). */
function renderCoach(mode) {
	if (coachEl) return; // already up

	const dismiss = h(
		'button',
		{
			type: 'button',
			class: 'obwf-pwa-dismiss',
			'aria-label': 'Dismiss',
			onclick: () => {
				snoozeCoach(false);
				removeCoach();
			},
		},
		['×']
	);

	let body;
	if (mode === 'android') {
		const installBtn = h(
			'button',
			{
				type: 'button',
				class: 'obwf-pwa-install',
				onclick: async () => {
					if (!deferredPrompt) return;
					const evt = deferredPrompt;
					deferredPrompt = null;
					removeCoach();
					try {
						evt.prompt();
						await evt.userChoice; // accepted/dismissed both fine
					} catch (e) {
						/* prompt already consumed — ignore */
					}
				},
			},
			['Add to home screen']
		);
		body = h('div', { class: 'obwf-pwa-coach-body' }, [
			h('p', { class: 'obwf-pwa-coach-text' }, [
				'Install the Beer Finder for one-tap access all week.',
			]),
			installBtn,
		]);
	} else {
		// iOS: we cannot trigger the prompt — coach the manual Share-sheet step.
		const icon = h('span', { class: 'obwf-pwa-share', html: IOS_SHARE_SVG });
		body = h('div', { class: 'obwf-pwa-coach-body' }, [
			h('p', { class: 'obwf-pwa-coach-text' }, [
				'Install the Beer Finder: tap ',
				icon,
				' Share, then “Add to Home Screen.”',
			]),
		]);
	}

	coachEl = h(
		'div',
		{ class: 'obwf-pwa-coach', role: 'region', 'aria-label': 'Install app' },
		[body, dismiss]
	);
	document.body.appendChild(coachEl);
}

/** Show the coach iff eligible; picks the platform mode. No-op otherwise. */
function maybeShowCoach() {
	if (!coachEnabled || coachEl) return;
	if (isStandalone() || coachSnoozed()) return;

	if (deferredPrompt) {
		renderCoach('android'); // native prompt is available to fire
	} else if (isIos()) {
		renderCoach('ios');
	}
	// else: not installable here (no prompt, not iOS) — stay silent.
}

// ── Update toast ───────────────────────────────────────────────────────────

/** Build + show the "new version — Refresh" toast for a registration. */
function showUpdateToast(reg) {
	if (toastEl) return; // already shown this session

	const refresh = h(
		'button',
		{
			type: 'button',
			class: 'obwf-pwa-refresh',
			onclick: () => {
				refresh.disabled = true;
				refresh.textContent = 'Updating…';
				updateRequested = true;
				// Tell the waiting worker to take over; `controllerchange` reloads us.
				if (reg.waiting) reg.waiting.postMessage('obw-skip-waiting');
			},
		},
		['Refresh']
	);

	toastEl = h(
		'div',
		{ class: 'obwf-pwa-toast', role: 'status', 'aria-live': 'polite' },
		[
			h('span', { class: 'obwf-pwa-toast-text' }, [
				'A new version is available.',
			]),
			refresh,
		]
	);
	document.body.appendChild(toastEl);
}

/** Wire update detection on a fresh registration. */
function watchForUpdates(reg) {
	// An update installed on a previous visit may already be waiting.
	if (reg.waiting && navigator.serviceWorker.controller) {
		showUpdateToast(reg);
	}
	// A worker that finishes installing *while a controller exists* is an update
	// (not a first install), so it's safe to offer the refresh.
	reg.addEventListener('updatefound', () => {
		const nw = reg.installing;
		if (!nw) return;
		nw.addEventListener('statechange', () => {
			if (nw.state === 'installed' && navigator.serviceWorker.controller) {
				showUpdateToast(reg);
			}
		});
	});
}

function registerServiceWorker(swUrl) {
	if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
		return;
	}

	// Reload exactly once when the new worker takes control — but ONLY if the
	// user asked for the update. A first install's clients.claim() also fires
	// controllerchange; without the `updateRequested` gate that would reload the
	// page out from under a first-time visitor.
	let refreshing = false;
	navigator.serviceWorker.addEventListener('controllerchange', () => {
		if (!updateRequested || refreshing) return;
		refreshing = true;
		window.location.reload();
	});

	const register = () => {
		navigator.serviceWorker
			.register(swUrl, { scope: '/' })
			.then((reg) => watchForUpdates(reg))
			.catch(() => {
				/* registration failed (insecure context / private mode) — app still works online */
			});
	};

	if (document.readyState === 'complete') {
		register();
	} else {
		window.addEventListener('load', register, { once: true });
	}
}

// Capture the install prompt as early as the module loads; surface the coach if
// initPwa has already run (order-independent).
if (typeof window !== 'undefined') {
	window.addEventListener('beforeinstallprompt', (e) => {
		e.preventDefault();
		deferredPrompt = e;
		maybeShowCoach();
	});
	window.addEventListener('appinstalled', () => {
		deferredPrompt = null;
		snoozeCoach(true); // permanent — never coach an installed user again
		removeCoach();
	});
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

	// Coach is now allowed. Android may have already handed us a prompt; iOS gets
	// a short delay so the banner doesn't slam up before people see the list.
	coachEnabled = true;
	if (deferredPrompt) {
		maybeShowCoach();
	} else if (isIos() && !isStandalone() && !coachSnoozed()) {
		setTimeout(maybeShowCoach, COACH_IOS_DELAY_MS);
	}
}
