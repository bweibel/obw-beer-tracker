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
	return <span class={'obw-check' + (on ? ' on' : '')} aria-hidden="true" />;
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
		<aside class="beer-card beer-filter" id="rtd-floating-search">
			<header class="card-header">
				<h5>Search</h5>
			</header>

			<div class="filter-header">
				<section class="card-content">
					<form
						onSubmit={(e) => e.preventDefault()}
						class="filter-search-form"
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
							class="filter-icon"
							aria-label="Toggle filters"
							onClick={() => setOpen((o) => !o)}
						>
							&#9776;
						</button>
					</form>
				</section>
			</div>

			<div class={'filter-body ' + (open ? 'filter-box-show' : 'filter-box-hide')}>
				{isBeer ? (
					<>
						<header class="card-header">
							<h5>Show only:</h5>
						</header>
						<section class="card-content button-wrap">
							{SHOW_ONLY.map((f) => (
								<button
									key={f.key}
									class="obw-button"
									onClick={() => toggleFilter(f.key)}
								>
									<Check on={filters[f.key]} /> {f.label}
								</button>
							))}
						</section>
					</>
				) : null}

				<header class="card-header">
					<h5>Show by:</h5>
				</header>
				<section class="card-content button-wrap">
					{TABS.map((t) => (
						<button
							key={t.key}
							class={'obw-button' + (listType === t.key ? ' is-active' : '')}
							onClick={() => setListType(t.key)}
						>
							{t.label}
						</button>
					))}
				</section>

				{isBeer ? (
					<>
						<header class="card-header">
							<h5>Order by:</h5>
						</header>
						<section class="card-content button-wrap">
							<button
								class="obw-button"
								onClick={() => toggleOrderBy('title.rendered')}
							>
								Name{orderArrow('title.rendered')}
							</button>
							<button class="obw-button" onClick={() => toggleOrderBy('abv')}>
								ABV{orderArrow('abv')}
							</button>
						</section>
					</>
				) : null}

				<div class="delete-wrapper">
					<button
						class="obw-button delete-data-button"
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
