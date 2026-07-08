/**
 * "My List" tab — the user's To-Try beers as one flat, name-sorted list, each
 * row tagged with the venue(s) that pour it (venue chips). Client-side only:
 * filters the full beer set by the tracker's `toTry` flag. Because the tracker
 * clears `toTry` when a beer is marked Tasted or Favorited, acting on a row
 * makes it drop off this list automatically — the list shrinks as you work it.
 *
 * Rows reuse `InteractiveBadges` (tap to toggle to-try/tasted/favorite) and the
 * row body opens the beer modal, identical to the other tabs.
 */
import { InteractiveBadges } from './Badges.jsx';

export function MyList({
	beers,
	search,
	flagsFor,
	onSelect,
	toggleToTry,
	toggleTasted,
	toggleFavorited,
}) {
	const term = (search || '').toLowerCase();
	const list = beers
		.filter((b) => flagsFor(b.id).toTry)
		.filter((b) => !term || (b.name || '').toLowerCase().includes(term))
		.sort((a, b) =>
			(a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
		);

	if (list.length === 0) {
		return (
			<section class="obwf-page-content obwf-list obwf-cf">
				<p class="obwf-empty obwf-mylist-empty">
					{term
						? 'None of your want-to-try brews match that search.'
						: 'Your list is empty. Tap the “to-try” badge on any brew to add it here.'}
				</p>
			</section>
		);
	}

	return (
		<section class="obwf-page-content obwf-list obwf-mylist obwf-cf">
			<header class="obwf-mylist-header">
				<h2 class="obwf-mylist-title">My To-Try List</h2>
				<span class="obwf-mylist-count">{list.length}</span>
			</header>

			{list.map((beer) => {
				const flags = flagsFor(beer.id);
				// Show all linked venues, unfiltered — matches BeerModal's
				// "Available at" list. (The finder route leaves `post_status`
				// null on venue_link entries, so filtering on it would wrongly
				// drop every chip.)
				const venues = (beer.acf && beer.acf.venue_link) || [];
				return (
					<div class="obwf-row" key={beer.id}>
						<a class="obwf-row-main" onClick={() => onSelect(beer)}>
							<h3 class="obwf-title">{beer.name}</h3>
							{beer.acf.style ? (
								<div class="obwf-row-meta">
									<span class="obwf-style-small">{beer.acf.style}</span>
								</div>
							) : null}
							<div class="obwf-venue-chips">
								{venues.length > 0 ? (
									venues.map((v) => (
										<span class="obwf-venue-chip" key={v.ID}>
											{v.post_title}
										</span>
									))
								) : (
									<span class="obwf-venue-chip obwf-venue-chip--none">
										Not listed at a venue
									</span>
								)}
							</div>
						</a>
						<InteractiveBadges
							flags={flags}
							onToggleToTry={() => toggleToTry(beer.id)}
							onToggleTasted={() => toggleTasted(beer.id)}
							onToggleFavorited={() => toggleFavorited(beer.id)}
						/>
					</div>
				);
			})}
		</section>
	);
}
