/**
 * Shared accordion list for the Brewery and Venue tabs. Ports
 * brewerieslist.html / venuelist.html: each group is sorted by name, expands
 * on click, and lists its published beers (from the reverse relation) sorted
 * by post_name and filtered by the search term (matched on post_name, as the
 * legacy templates did). Beer rows resolve to the full beer via `beerLookup`
 * so tracker badges and the modal work identically to the Brews tab.
 */
import { InteractiveBadges } from './Badges.jsx';
import { IconChevronRight } from './icons/Icons.jsx';

export function GroupList({
	groups,
	kind, // 'brewery' | 'venue' — for CSS class parity
	search,
	openIds,
	toggleOpen,
	beerLookup,
	flagsFor,
	onSelect,
	toggleToTry,
	toggleTasted,
	toggleFavorited,
}) {
	const term = (search || '').toLowerCase();
	// Resolve each group's renderable (published, in-lookup) beers up front so we
	// can drop empty breweries/venues entirely instead of showing a bare header.
	const withBeers = groups
		.map((group) => ({
			group,
			beers: (group.beers || [])
				.filter((b) => b.post_status === 'publish')
				.filter((b) => beerLookup[b.ID])
				.filter((b) => !term || (b.post_name || '').toLowerCase().includes(term))
				.sort((a, b) => (a.post_name || '').localeCompare(b.post_name || '')),
		}))
		.filter(({ beers }) => beers.length > 0);
	const sorted = withBeers.sort((a, b) =>
		a.group.name.localeCompare(b.group.name, undefined, { sensitivity: 'base' })
	);

	return (
		<section class="obwf-page-content obwf-list obwf-cf">
			{sorted.map(({ group, beers }) => {
				const isOpen = !!openIds[group.id];

				return (
					<div class={'obwf-group obwf-group--' + kind} key={group.id}>
						<h2 class="obwf-group-title">
							<a onClick={() => toggleOpen(group.id)} aria-expanded={isOpen}>
								<IconChevronRight
									class={'obwf-group-toggle-icon' + (isOpen ? ' obwf-icon--open' : '')}
								/>{' '}
								{group.name}
							</a>
						</h2>
						<div
							class={
								'obwf-sublist ' +
								(isOpen ? 'obwf-sublist--open' : 'obwf-sublist--closed')
							}
						>
							{isOpen
								? beers.map((rel) => {
										const beer = beerLookup[rel.ID];
										if (!beer) return null;
										const flags = flagsFor(beer.id);
										return (
											<div class="obwf-row" key={rel.ID}>
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
								  })
								: null}
						</div>
					</div>
				);
			})}
		</section>
	);
}
