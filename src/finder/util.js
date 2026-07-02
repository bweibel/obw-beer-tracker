/**
 * Small sanitize / truncate helpers that replace AngularJS `$sce`, the
 * `cut_text` filter, and `to_trusted`.
 */

let decoderEl = null;

/**
 * Decode HTML entities in a WordPress `rendered` string to plain text.
 * WP titles arrive with entities (e.g. `Blackberry &amp; Sage`); AngularJS
 * used `$sce.trustAsHtml`, but for titles we only need the decoded text.
 *
 * @param {string} html
 * @returns {string}
 */
export function decodeEntities(html) {
	if (!html) return '';
	if (!decoderEl) {
		decoderEl = document.createElement('textarea');
	}
	decoderEl.innerHTML = String(html);
	return decoderEl.value;
}

/**
 * Port of the AngularJS `cut_text` filter: truncate to `maxlength`, appending
 * a `[...]` marker. Operates on the raw (possibly HTML) string, matching the
 * legacy behavior for the beer description.
 *
 * @param {string} text
 * @param {number} maxlength
 * @returns {string}
 */
export function cutText(text, maxlength) {
	if (!text) return '';
	return text.length > maxlength
		? text.substr(0, maxlength) + ' <strong>[...]</strong>'
		: text;
}

/**
 * The finder's REST root + nonce. Read (in priority order) from the mount
 * element's data attributes (set by the `[obw_beer_finder]` shortcode), then a
 * global `window.OBWFinder`, then a site-relative `/wp-json/` fallback so a
 * mocked/standalone mount still works.
 *
 * @returns {{ restUrl: string, nonce: string }}
 */
export function finderConfig() {
	let restUrl = '';
	let nonce = '';

	if (typeof document !== 'undefined') {
		const el = document.getElementById('obw-beer-finder-root');
		if (el) {
			restUrl = el.getAttribute('data-rest-url') || '';
			nonce = el.getAttribute('data-nonce') || '';
		}
	}

	if (!restUrl && typeof window !== 'undefined' && window.OBWFinder) {
		restUrl = window.OBWFinder.restUrl || '';
		nonce = window.OBWFinder.nonce || '';
	}

	return {
		restUrl: restUrl || '/wp-json/',
		nonce: nonce || '',
	};
}
