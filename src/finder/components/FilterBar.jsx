/**
 * Search + filter + "show by" tabs + order controls. Ports beerfilter.html and
 * the related controller handlers (filterClick, listButtonClick, orderByClick,
 * toggleControls, deleteClick).
 */
import { useState } from 'preact/hooks';

const SHOW_ONLY = [
	{ key: 'notTasted', label: 'Not Tasted' },
	{ key: 'tasted', label: 'Tasted' },
	{ key: 'favorited', label: 'Favorited' },
	{ key: 'toTry', label: 'Want to Try' },
];

const TABS = [
	{ key: 'beer', label: 'Brews' },
	{ key: 'breweries', label: 'Brewery' },
	{ key: 'venue', label: 'Venue' },
];

function Check({ on }) {
	return <span class={'obwf-check' + (on ? ' obwf-check--on' : '')} aria-hidden="true" />;
}

export function FilterBar({
	listType,
	setListType,
	search,
	setSearch,
	filters,
	toggleFilter,
	orderBy,
	toggleOrderBy,
	onDelete,
}) {
	const [open, setOpen] = useState(false);
	const isBeer = listType === 'beer';

	const orderArrow = (field) => {
		if (orderBy === field) return ' ▼'; // ▼ asc marker (legacy)
		if (orderBy === '-' + field) return ' ▲';
		return '';
	};

	return (
		<aside class="obwf-card obwf-filterbar" id="obwf-search">
			<header class="obwf-card-header">
				<h5>Search</h5>
			</header>

			<div class="obwf-filter-header">
				<section class="obwf-card-content">
					<form
						onSubmit={(e) => e.preventDefault()}
						class="obwf-search-form"
					>
						<input
							type="text"
							placeholder="Search Brews by Name"
							title="Search & Filter the list of brews"
							value={search}
							onInput={(e) => setSearch(e.currentTarget.value)}
						/>
						<button
							type="button"
							class="obwf-filter-toggle"
							aria-label="Toggle filters"
							onClick={() => setOpen((o) => !o)}
						>
							&#9776;
						</button>
					</form>
				</section>
			</div>

			<div class={'obwf-filters ' + (open ? 'obwf-filters--open' : 'obwf-filters--closed')}>
				{isBeer ? (
					<>
						<header class="obwf-card-header">
							<h5>Show only:</h5>
						</header>
						<section class="obwf-card-content obwf-actions">
							{SHOW_ONLY.map((f) => (
								<button
									key={f.key}
									class="obwf-btn"
									onClick={() => toggleFilter(f.key)}
								>
									<Check on={filters[f.key]} /> {f.label}
								</button>
							))}
						</section>
					</>
				) : null}

				<header class="obwf-card-header">
					<h5>Show by:</h5>
				</header>
				<section class="obwf-card-content obwf-actions">
					{TABS.map((t) => (
						<button
							key={t.key}
							class={'obwf-btn' + (listType === t.key ? ' obwf-btn--active' : '')}
							onClick={() => setListType(t.key)}
						>
							{t.label}
						</button>
					))}
				</section>

				{isBeer ? (
					<>
						<header class="obwf-card-header">
							<h5>Order by:</h5>
						</header>
						<section class="obwf-card-content obwf-actions">
							<button
								class="obwf-btn"
								onClick={() => toggleOrderBy('title.rendered')}
							>
								Name{orderArrow('title.rendered')}
							</button>
							<button class="obwf-btn" onClick={() => toggleOrderBy('abv')}>
								ABV{orderArrow('abv')}
							</button>
						</section>
					</>
				) : null}

				<div class="obwf-delete-wrap">
					<button
						class="obwf-btn obwf-btn-delete"
						onClick={onDelete}
						title="Delete all tracker data"
					>
						&#128465; Reset
					</button>
				</div>
			</div>
		</aside>
	);
}
