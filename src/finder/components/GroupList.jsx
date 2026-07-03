/**
 * Shared accordion list for the Brewery and Venue tabs. Ports
 * brewerieslist.html / venuelist.html: each group is sorted by name, expands
 * on click, and lists its published beers (from the reverse relation) sorted
 * by post_name and filtered by the search term (matched on post_name, as the
 * legacy templates did). Beer rows resolve to the full beer via `beerLookup`
 * so tracker badges and the modal work identically to the Brews tab.
 */
import { Badges } from './Badges.jsx';
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
}) {
	const term = (search || '').toLowerCase();
	const sorted = [...groups].sort((a, b) =>
		a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
	);

	return (
		<section class="obwf-page-content obwf-list obwf-cf">
			{sorted.map((group) => {
				const isOpen = !!openIds[group.id];
				const beers = (group.beers || [])
					.filter((b) => b.post_status === 'publish')
					.filter((b) => !term || (b.post_name || '').toLowerCase().includes(term))
					.sort((a, b) => (a.post_name || '').localeCompare(b.post_name || ''));

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
												<h3 class="obwf-title">
													<a onClick={() => onSelect(beer)}>{beer.name}</a>
												</h3>
												<Badges flags={flags} />
												{beer.acf.style ? (
													<span class="obwf-style-small">{beer.acf.style}</span>
												) : null}
											</div>
										);
								  })
								: null}
							{isOpen && beers.length === 0 ? (
								<p class="obwf-empty">No published brews here yet.</p>
							) : null}
						</div>
					</div>
				);
			})}
		</section>
	);
}
