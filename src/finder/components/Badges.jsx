/**
 * The three tracker status badges (to-try / tasted / favorite). Each is a
 * PNG-backed icon, dimmed when off and full-opacity when on (see style.css).
 * Keeps the legacy class names (`beer-badges`, `to-try`, `tasted`, `favorite`,
 * `on`). State is exposed to assistive tech via `role="img"` + a state-aware
 * `aria-label` rather than a visual glyph overlay.
 */
export function Badges({ flags }) {
	return (
		<div class="beer-badges">
			<div
				class={'to-try' + (flags.toTry ? ' on' : '')}
				role="img"
				aria-label={flags.toTry ? 'On your want-to-try list' : 'Not on your want-to-try list'}
			/>
			<div
				class={'tasted' + (flags.tasted ? ' on' : '')}
				role="img"
				aria-label={flags.tasted ? 'Tasted' : 'Not tasted'}
			/>
			<div
				class={'favorite' + (flags.favorited ? ' on' : '')}
				role="img"
				aria-label={flags.favorited ? 'Favorited' : 'Not favorited'}
			/>
		</div>
	);
}
