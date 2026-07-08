/**
 * "My List" tab — the user's To-Try beers as a venue-grouped itinerary: each
 * venue that pours something on your list becomes a section listing the to-try
 * beers available there. A beer poured at multiple venues appears under each of
 * them (so at any stop you see everything on your list there); beers not linked
 * to a venue fall into a trailing "Not listed at a venue" group.
 *
 * Client-side only: filters the full beer set by the tracker's `toTry` flag.
 * Because the tracker clears `toTry` when a beer is marked Tasted or Favorited,
 * acting on a row makes it drop off every section it appears in — the list
 * shrinks as you work it. Rows reuse `InteractiveBadges` and open the modal.
 */
import { InteractiveBadges } from './Badges.jsx';

const byName = (a, b) =>
	(a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' });

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
	const toTry = beers
		.filter((b) => flagsFor(b.id).toTry)
		.filter((b) => !term || (b.name || '').toLowerCase().includes(term));

	// Fan the to-try beers out by venue. `venue_link` entries are the venues a
	// beer is poured at (unfiltered, matching BeerModal — the finder route leaves
	// their post_status null, so we can't filter on it).
	const venueMap = new Map(); // venue ID -> { id, name, beers: [] }
	const noVenue = [];
	for (const beer of toTry) {
		const venues = (beer.acf && beer.acf.venue_link) || [];
		if (venues.length === 0) {
			noVenue.push(beer);
			continue;
		}
		for (const v of venues) {
			let entry = venueMap.get(v.ID);
			if (!entry) {
				entry = { id: v.ID, name: v.post_title, beers: [] };
				venueMap.set(v.ID, entry);
			}
			entry.beers.push(beer);
		}
	}

	// Most-to-try venues first (biggest payoff per stop), then alphabetical.
	const groups = [...venueMap.values()].sort(
		(a, b) =>
			b.beers.length - a.beers.length ||
			a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
	);
	groups.forEach((g) => g.beers.sort(byName));
	noVenue.sort(byName);

	if (toTry.length === 0) {
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

	const row = (beer) => {
		const flags = flagsFor(beer.id);
		return (
			<div class="obwf-row" key={beer.id}>
				<a class="obwf-row-main" onClick={() => onSelect(beer)}>
					<h3 class="obwf-title">{beer.name}</h3>
					{beer.acf.style ? (
						<div class="obwf-row-meta">
							<span class="obwf-style-small">{beer.acf.style}</span>
						</div>
					) : null}
				</a>
				<InteractiveBadges
					flags={flags}
					onToggleToTry={() => toggleToTry(beer.id)}
					onToggleTasted={() => toggleTasted(beer.id)}
					onToggleFavorited={() => toggleFavorited(beer.id)}
				/>
			</div>
		);
	};

	const venueSection = (key, name, list, extraClass) => (
		<div class={'obwf-mylist-venue' + (extraClass || '')} key={key}>
			<h2 class="obwf-mylist-venue-title">
				<span class="obwf-mylist-venue-name">{name}</span>
				<span class="obwf-mylist-venue-count">{list.length}</span>
			</h2>
			{list.map(row)}
		</div>
	);

	return (
		<section class="obwf-page-content obwf-list obwf-mylist obwf-cf">
			<header class="obwf-mylist-header">
				<h2 class="obwf-mylist-title">My To-Try List</h2>
				<span class="obwf-mylist-count">{toTry.length}</span>
			</header>

			{groups.map((g) => venueSection(g.id, g.name, g.beers))}
			{noVenue.length > 0
				? venueSection('none', 'Not listed at a venue', noVenue, ' obwf-mylist-venue--none')
				: null}
		</section>
	);
}
