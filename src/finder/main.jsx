import { render } from 'preact';
import { App } from './components/App.jsx';
import { initPwa } from './pwa.js';
import './style.css';

/**
 * Mount the OBW Beer Finder SPA into the element rendered by the
 * `[obw_beer_finder]` shortcode. No hardcoded theme paths — the REST root and
 * nonce arrive via `window.OBWFinder` (localized by the shortcode), with a
 * `/wp-json/` fallback so the app also runs in a mocked/standalone context.
 */
const MOUNT_ID = 'obw-beer-finder-root';

function mount() {
	const el = document.getElementById(MOUNT_ID);
	if (el) {
		render(<App />, el);
		initPwa();
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}
