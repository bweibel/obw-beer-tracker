/**
 * The three tracker status badges (to-try / tasted / favorite). Each is a
 * PNG-backed icon, dimmed when off and full-opacity when on (see style.css).
 * Classes live under the `.obwf-*` namespace (`obwf-badges`,
 * `obwf-badge-to-try`, `obwf-badge-tasted`, `obwf-badge-favorite`,
 * `obwf-badge--on`). State is exposed to assistive tech via `role="img"` + a
 * state-aware `aria-label` rather than a visual glyph overlay.
 */
export function Badges({ flags }) {
	return (
		<div class="obwf-badges">
			<div
				class={'obwf-badge-to-try' + (flags.toTry ? ' obwf-badge--on' : '')}
				role="img"
				aria-label={flags.toTry ? 'On your want-to-try list' : 'Not on your want-to-try list'}
			/>
			<div
				class={'obwf-badge-tasted' + (flags.tasted ? ' obwf-badge--on' : '')}
				role="img"
				aria-label={flags.tasted ? 'Tasted' : 'Not tasted'}
			/>
			<div
				class={'obwf-badge-favorite' + (flags.favorited ? ' obwf-badge--on' : '')}
				role="img"
				aria-label={flags.favorited ? 'Favorited' : 'Not favorited'}
			/>
		</div>
	);
}
