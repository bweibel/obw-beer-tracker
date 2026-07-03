/**
 * Inline-SVG UI glyphs (Phase 2 §2). Individual Preact components — no
 * `<use>` sprite, zero runtime deps. Each renders its own `<svg>`,
 * `currentColor`-themeable (fill/stroke), sized in `em` via the shared
 * `.obwf-icon` rule in style.css, and `aria-hidden="true"` since the
 * accessible name lives on the interactive control that hosts the icon, not
 * the glyph itself.
 *
 * Paths are copied from the Feather icon set (MIT license,
 * https://feathericons.com) purely for a consistent stroke weight across this
 * batch. That choice is provisional — if the wider site standardizes on a
 * different icon set later, this file is the single place to swap paths.
 */

const BASE_PROPS = {
	xmlns: 'http://www.w3.org/2000/svg',
	viewBox: '0 0 24 24',
	fill: 'none',
	stroke: 'currentColor',
	'stroke-width': '2',
	'stroke-linecap': 'round',
	'stroke-linejoin': 'round',
	'aria-hidden': 'true',
	focusable: 'false',
};

/** Feather "x" — modal close. */
export function IconClose({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<line x1="18" y1="6" x2="6" y2="18" />
			<line x1="6" y1="6" x2="18" y2="18" />
		</svg>
	);
}

/** Feather "chevron-up" — descending sort marker. */
export function IconChevronUp({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<polyline points="18 15 12 9 6 15" />
		</svg>
	);
}

/** Feather "chevron-down" — ascending sort marker. */
export function IconChevronDown({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<polyline points="6 9 12 15 18 9" />
		</svg>
	);
}

/**
 * Feather "chevron-right" — accordion open/closed affordance (GroupList).
 * Rotates 90deg via the `.obwf-icon--open` modifier when the sublist is
 * expanded; see style.css.
 */
export function IconChevronRight({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<polyline points="9 18 15 12 9 6" />
		</svg>
	);
}

/** Feather "sliders" — filter toggle. */
export function IconFilter({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<line x1="4" y1="21" x2="4" y2="14" />
			<line x1="4" y1="10" x2="4" y2="3" />
			<line x1="12" y1="21" x2="12" y2="12" />
			<line x1="12" y1="8" x2="12" y2="3" />
			<line x1="20" y1="21" x2="20" y2="16" />
			<line x1="20" y1="12" x2="20" y2="3" />
			<line x1="1" y1="14" x2="7" y2="14" />
			<line x1="9" y1="8" x2="15" y2="8" />
			<line x1="17" y1="16" x2="23" y2="16" />
		</svg>
	);
}

/** Feather "trash-2" — Reset button. */
export function IconTrash({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<polyline points="3 6 5 6 21 6" />
			<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
			<line x1="10" y1="11" x2="10" y2="17" />
			<line x1="14" y1="11" x2="14" y2="17" />
		</svg>
	);
}

/** Feather "external-link" — Untappd / More Info buttons. */
export function IconExternalLink({ class: className = '' }) {
	return (
		<svg {...BASE_PROPS} class={'obwf-icon ' + className}>
			<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
			<polyline points="15 3 21 3 21 9" />
			<line x1="10" y1="14" x2="21" y2="3" />
		</svg>
	);
}
