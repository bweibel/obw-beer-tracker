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

/**
 * Interactive variant used on list rows (Brews / Brewery / Venue tabs): the
 * Tasted and Favorite icons become real `<button>`s so a tap flips tracker
 * state inline, without opening the beer modal. To-try stays a read-only
 * indicator here — the spec only calls for inline Tasted/Favorite toggles;
 * the full to-try toggle still lives in the modal. The read-only `<Badges>`
 * above is unchanged and still used there.
 *
 * Each button's `onClick` calls `stopPropagation()` so the tap doesn't also
 * bubble to the row's modal-open handler. Buttons are padded to a 44x44px
 * tap target (see `.obwf-badge-btn` in style.css) — exact size/placement
 * flagged by the product owner as needing on-device testing.
 */
export function InteractiveBadges({ flags, onToggleTasted, onToggleFavorited }) {
	return (
		<div class="obwf-badges obwf-badges--interactive">
			<div
				class={'obwf-badge-to-try' + (flags.toTry ? ' obwf-badge--on' : '')}
				role="img"
				aria-label={flags.toTry ? 'On your want-to-try list' : 'Not on your want-to-try list'}
			/>
			<button
				type="button"
				class={'obwf-badge-btn obwf-badge-tasted' + (flags.tasted ? ' obwf-badge--on' : '')}
				aria-pressed={flags.tasted}
				aria-label={
					flags.tasted ? 'Tasted. Tap to mark as not tasted.' : 'Not tasted. Tap to mark as tasted.'
				}
				onClick={(e) => {
					e.stopPropagation();
					onToggleTasted();
				}}
			/>
			<button
				type="button"
				class={'obwf-badge-btn obwf-badge-favorite' + (flags.favorited ? ' obwf-badge--on' : '')}
				aria-pressed={flags.favorited}
				aria-label={
					flags.favorited
						? 'Favorited. Tap to remove favorite.'
						: 'Not favorited. Tap to favorite.'
				}
				onClick={(e) => {
					e.stopPropagation();
					onToggleFavorited();
				}}
			/>
		</div>
	);
}
