/**
 * "My List" tab — the user's To-Try beers as a venue-grouped itinerary: each
 * venue that pours something on your list becomes a (collapsible) section
 * listing the to-try beers available there. A beer poured at multiple venues
 * appears under each of them, and each row notes the *other* venues it's also
 * poured at ("Also at …"). Beers not linked to a venue fall into a trailing
 * "Not listed at a venue" group.
 *
 * Client-side only: filters the full beer set by the tracker's `toTry` flag.
 * Because the tracker clears `toTry` when a beer is marked Tasted or Favorited,
 * acting on a row makes it drop off every section it appears in. Sort order
 * (by-count / A–Z) and per-venue collapse state are owned by App.jsx so they
 * survive tab switches within a session.
 */
import { InteractiveBadges } from './Badges.jsx';
import { IconChevronRight } from './icons/Icons.jsx';

const NO_VENUE_KEY = 'none';

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
	sort, // 'count' | 'name'
	onSortChange,
	collapsed, // { [venueId]: true } — present & truthy means collapsed
	onToggleCollapsed,
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

	const groups = [...venueMap.values()];
	if (sort === 'name') {
		groups.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));
	} else {
		// Most-to-try venues first (biggest payoff per stop), then alphabetical.
		groups.sort(
			(a, b) =>
				b.beers.length - a.beers.length ||
				a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
		);
	}
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

	// A single beer row. `currentVenueId` is the venue whose section we're in, so
	// we can list the *other* venues the beer is also poured at.
	const row = (beer, currentVenueId) => {
		const flags = flagsFor(beer.id);
		const alsoAt = ((beer.acf && beer.acf.venue_link) || [])
			.filter((v) => v.ID !== currentVenueId)
			.map((v) => v.post_title);
		return (
			<div class="obwf-row" key={beer.id}>
				<a class="obwf-row-main" onClick={() => onSelect(beer)}>
					<h3 class="obwf-title">{beer.name}</h3>
					{beer.acf.style ? (
						<div class="obwf-row-meta">
							<span class="obwf-style-small">{beer.acf.style}</span>
						</div>
					) : null}
					{alsoAt.length > 0 ? (
						<div class="obwf-mylist-alsoat">Also at: {alsoAt.join(', ')}</div>
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

	const venueSection = (key, name, list, venueId, extraClass) => {
		const isOpen = !collapsed[key];
		return (
			<div class={'obwf-mylist-venue' + (extraClass || '')} key={key}>
				<h2 class="obwf-mylist-venue-title">
					<button
						type="button"
						class="obwf-mylist-venue-toggle"
						aria-expanded={isOpen}
						onClick={() => onToggleCollapsed(key)}
					>
						<IconChevronRight
							class={'obwf-group-toggle-icon' + (isOpen ? ' obwf-icon--open' : '')}
						/>
						<span class="obwf-mylist-venue-name">{name}</span>
						<span class="obwf-mylist-venue-count">{list.length}</span>
					</button>
				</h2>
				{isOpen ? list.map((beer) => row(beer, venueId)) : null}
			</div>
		);
	};

	return (
		<section class="obwf-page-content obwf-list obwf-mylist obwf-cf">
			<header class="obwf-mylist-header">
				<h2 class="obwf-mylist-title">My To-Try List</h2>
				<span class="obwf-mylist-count">{toTry.length}</span>
				<div class="obwf-mylist-sort" role="group" aria-label="Sort venues">
					<button
						type="button"
						class={'obwf-mylist-sort-btn' + (sort !== 'name' ? ' obwf-mylist-sort-btn--on' : '')}
						aria-pressed={sort !== 'name'}
						onClick={() => onSortChange('count')}
					>
						Most
					</button>
					<button
						type="button"
						class={'obwf-mylist-sort-btn' + (sort === 'name' ? ' obwf-mylist-sort-btn--on' : '')}
						aria-pressed={sort === 'name'}
						onClick={() => onSortChange('name')}
					>
						A–Z
					</button>
				</div>
			</header>

			{groups.map((g) => venueSection(g.id, g.name, g.beers, g.id))}
			{noVenue.length > 0
				? venueSection(NO_VENUE_KEY, 'Not listed at a venue', noVenue, null, ' obwf-mylist-venue--none')
				: null}
		</section>
	);
}
