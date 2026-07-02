/**
 * The three tracker status badges (to-try / tasted / favorite). Keeps the
 * legacy class names (`beer-badges`, `to-try`, `tasted`, `favorite`, `on`) so
 * theme CSS continues to style them when hosted in the theme page.
 */
export function Badges({ flags }) {
	return (
		<div class="beer-badges">
			<div class={'to-try' + (flags.toTry ? ' on' : '')} title="Want to try" />
			<div class={'tasted' + (flags.tasted ? ' on' : '')} title="Tasted" />
			<div class={'favorite' + (flags.favorited ? ' on' : '')} title="Favorited" />
		</div>
	);
}
