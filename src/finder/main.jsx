import { render } from 'preact';
import './style.css';

/**
 * WP-0 placeholder. WP-3 replaces this with the real 3-tab finder SPA.
 * Mounts into the element the `[obw_beer_finder]` shortcode renders.
 */
function App() {
	return (
		<div className="obw-finder-placeholder">
			<h2>OBW Beer Finder</h2>
			<p>Preact scaffold is mounted. The finder SPA lands in WP-3.</p>
		</div>
	);
}

const MOUNT_ID = 'obw-beer-finder-root';

function mount() {
	const el = document.getElementById(MOUNT_ID);
	if (el) {
		render(<App />, el);
	}
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mount);
} else {
	mount();
}
