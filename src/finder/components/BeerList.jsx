/**
 * The "Brews" tab: flat, filterable, sortable beer list. Ports beerlist.html
 * plus the controller's orderBy / search / filterFunction behavior.
 */
import { InteractiveBadges } from './Badges.jsx';
import { IconChevronDown, IconChevronUp } from './icons/Icons.jsx';

/**
 * Sort + filter beers to match the AngularJS pipeline:
 *   orderBy (name / abv, asc|desc)  →  name/style search  →  tracker filter.
 *
 * The typed search matches the beer name OR its style (`acf.style`) — style is
 * how beer folks actually browse ("IPA", "sour", "stout"). Brewery/venue are
 * deliberately NOT searched here: both have their own dedicated tabs, and
 * folding them in adds noise (a common brewery name would swamp results).
 */
function visibleBeers(beers, search, orderBy, filters, flagsFor) {
	const term = (search || '').toLowerCase();
	const anyFilter =
		filters.tasted || filters.notTasted || filters.favorited || filters.toTry;

	const filtered = beers.filter((beer) => {
		if (term) {
			const style = (beer.acf && beer.acf.style ? beer.acf.style : '').toLowerCase();
			if (!beer.name.toLowerCase().includes(term) && !style.includes(term)) {
				return false;
			}
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

// The two sortable fields, shown as a segmented control (shared .obwf-sort
// style with My List). Clicking the active field flips its direction.
const SORTS = [
	{ field: 'title.rendered', label: 'Name' },
	{ field: 'abv', label: 'ABV' },
];

export function BeerList({
	beers,
	search,
	orderBy,
	toggleOrderBy,
	filters,
	flagsFor,
	onSelect,
	toggleToTry,
	toggleTasted,
	toggleFavorited,
}) {
	const showAbv = orderBy === 'abv' || orderBy === '-abv';
	const list = visibleBeers(beers, search, orderBy, filters, flagsFor);

	const isActive = (field) => orderBy === field || orderBy === '-' + field;
	const orderArrow = (field) => {
		if (orderBy === field) return <IconChevronDown />; // ascending
		if (orderBy === '-' + field) return <IconChevronUp />; // descending
		return null;
	};

	return (
		<section class="obwf-page-content obwf-list obwf-cf">
			<div class="obwf-list-toolbar">
				<div class="obwf-sort" role="group" aria-label="Sort brews">
					{SORTS.map((s) => (
						<button
							key={s.field}
							type="button"
							class={'obwf-sort-btn' + (isActive(s.field) ? ' obwf-sort-btn--on' : '')}
							aria-pressed={isActive(s.field)}
							onClick={() => toggleOrderBy(s.field)}
						>
							{s.label} {orderArrow(s.field)}
						</button>
					))}
				</div>
			</div>

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
						<InteractiveBadges
							flags={flags}
							onToggleToTry={() => toggleToTry(beer.id)}
							onToggleTasted={() => toggleTasted(beer.id)}
							onToggleFavorited={() => toggleFavorited(beer.id)}
						/>
					</div>
				);
			})}
			{list.length === 0 ? (
				<p class="obwf-empty">No brews match your filters.</p>
			) : null}
		</section>
	);
}
