/**
 * The "Brews" tab: flat, filterable, sortable beer list. Ports beerlist.html
 * plus the controller's orderBy / search / filterFunction behavior.
 */
import { Badges } from './Badges.jsx';

/**
 * Sort + filter beers to match the AngularJS pipeline:
 *   orderBy (name / abv, asc|desc)  →  title search  →  tracker filter.
 */
function visibleBeers(beers, search, orderBy, filters, flagsFor) {
	const term = (search || '').toLowerCase();
	const anyFilter =
		filters.tasted || filters.notTasted || filters.favorited || filters.toTry;

	const filtered = beers.filter((beer) => {
		if (term && !beer.name.toLowerCase().includes(term)) {
			return false;
		}
		if (!anyFilter) return true;
		const f = flagsFor(beer.id);
		return (
			(filters.tasted && f.tasted) ||
			(filters.notTasted && !f.tasted) ||
			(filters.favorited && f.favorited) ||
			(filters.toTry && f.toTry)
		);
	});

	const desc = orderBy.startsWith('-');
	const field = desc ? orderBy.slice(1) : orderBy;

	filtered.sort((a, b) => {
		let cmp;
		if (field === 'abv') {
			const av = typeof a.abv === 'number' ? a.abv : -1;
			const bv = typeof b.abv === 'number' ? b.abv : -1;
			cmp = av - bv;
		} else {
			cmp = a.name.localeCompare(b.name, undefined, { sensitivity: 'base' });
		}
		return desc ? -cmp : cmp;
	});

	return filtered;
}

export function BeerList({ beers, search, orderBy, filters, flagsFor, onSelect }) {
	const showAbv = orderBy === 'abv' || orderBy === '-abv';
	const list = visibleBeers(beers, search, orderBy, filters, flagsFor);

	return (
		<section class="obwf-page-content obwf-list obwf-cf">
			{list.map((beer) => {
				const flags = flagsFor(beer.id);
				return (
					<div class="obwf-row" key={beer.id}>
						<a class="obwf-row-main" onClick={() => onSelect(beer)}>
							<h3 class="obwf-title">{beer.name}</h3>
							{beer.acf.style || showAbv ? (
								<div class="obwf-row-meta">
									{beer.acf.style ? (
										<span class="obwf-style-small">{beer.acf.style}</span>
									) : null}
									{showAbv ? (
										<span class="obwf-abv">
											<strong>ABV</strong>:{' '}
											{typeof beer.abv === 'number' ? beer.abv : 'N/A'}
										</span>
									) : null}
								</div>
							) : null}
						</a>
						<Badges flags={flags} />
					</div>
				);
			})}
			{list.length === 0 ? (
				<p class="obwf-empty">No brews match your filters.</p>
			) : null}
		</section>
	);
}
