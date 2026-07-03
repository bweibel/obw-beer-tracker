/**
 * Root finder component. Owns data loading, the "show by" tab (listType), the
 * search/filter/order UI state, and the active-beer modal. Replaces the
 * AngularJS BeerListController.
 */
import { useEffect, useMemo, useRef, useState } from 'preact/hooks';
import { loadFinderData } from '../api.js';
import { useTracker } from '../tracker.js';
import { FilterBar } from './FilterBar.jsx';
import { BeerList } from './BeerList.jsx';
import { GroupList } from './GroupList.jsx';
import { BeerModal } from './BeerModal.jsx';

const DEFAULT_FILTERS = {
	tasted: false,
	notTasted: false,
	favorited: false,
	toTry: false,
};

export function App() {
	const [beers, setBeers] = useState([]);
	const [breweries, setBreweries] = useState([]);
	const [venues, setVenues] = useState([]);
	const [loading, setLoading] = useState(true);
	const [error, setError] = useState('');

	const [listType, setListTypeState] = useState('beer');
	const [search, setSearch] = useState('');
	const [orderBy, setOrderBy] = useState('title.rendered');
	const [filters, setFilters] = useState(DEFAULT_FILTERS);
	const [openIds, setOpenIds] = useState({});
	const [activeBeer, setActiveBeer] = useState(null);

	const tracker = useTracker();
	const listWrapRef = useRef(null);

	useEffect(() => {
		let cancelled = false;
		setLoading(true);
		// Phase 2 §4.1: one request for the whole dataset (falls back to the
		// three-type core-REST loading internally if the precomputed route is
		// unavailable) instead of three separate loaders.
		loadFinderData()
			.then((data) => {
				if (cancelled) return;
				setBeers(data.beers);
				setBreweries(data.breweries);
				setVenues(data.venues);
			})
			.catch((e) => {
				if (!cancelled) setError(e.message || 'Failed to load beers.');
			})
			.finally(() => {
				if (!cancelled) setLoading(false);
			});

		// Add the body/html classes the theme styles key off of.
		document.documentElement.classList.add('beer-tracker-page-html');
		document.body.classList.add('beer-tracker-page-body');
		return () => {
			cancelled = true;
			document.documentElement.classList.remove('beer-tracker-page-html');
			document.body.classList.remove('beer-tracker-page-body');
		};
	}, []);

	// id -> beer, for the brewery/venue reverse-relation rows.
	const beerLookup = useMemo(() => {
		const map = {};
		for (const b of beers) map[b.id] = b;
		return map;
	}, [beers]);

	// Keep the modal's beer object referentially fresh isn't needed — tracker
	// flags are read via flagsFor(id), so the modal reflects toggles live.

	const setListType = (type) => {
		setListTypeState(type);
		setSearch('');
		setOrderBy('title.rendered');
		// Gentle scroll to the list, replacing the jQuery animate() in the
		// legacy listButtonClick. No theme-path dependency.
		setTimeout(() => {
			if (listWrapRef.current) {
				listWrapRef.current.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
		}, 50);
	};

	const toggleFilter = (key) =>
		setFilters((prev) => ({ ...prev, [key]: !prev[key] }));

	const toggleOrderBy = (field) =>
		setOrderBy((prev) => (prev === field ? '-' + field : field));

	const toggleOpen = (id) =>
		setOpenIds((prev) => ({ ...prev, [id]: !prev[id] }));

	const closeModal = () => setActiveBeer(null);

	const activeFlags = activeBeer
		? tracker.flagsFor(activeBeer.id)
		: DEFAULT_FILTERS;

	return (
		<div class="obwf-app">
			<FilterBar
				listType={listType}
				setListType={setListType}
				search={search}
				setSearch={setSearch}
				filters={filters}
				toggleFilter={toggleFilter}
				orderBy={orderBy}
				toggleOrderBy={toggleOrderBy}
				onDelete={tracker.deleteAll}
			/>

			<div class="obwf-list-wrap" ref={listWrapRef}>
				{error ? <p class="obwf-error">{error}</p> : null}

				{listType === 'beer' ? (
					<BeerList
						beers={beers}
						search={search}
						orderBy={orderBy}
						filters={filters}
						flagsFor={tracker.flagsFor}
						onSelect={setActiveBeer}
					/>
				) : null}

				{listType === 'breweries' ? (
					<GroupList
						groups={breweries}
						kind="brewery"
						search={search}
						openIds={openIds}
						toggleOpen={toggleOpen}
						beerLookup={beerLookup}
						flagsFor={tracker.flagsFor}
						onSelect={setActiveBeer}
					/>
				) : null}

				{listType === 'venue' ? (
					<GroupList
						groups={venues}
						kind="venue"
						search={search}
						openIds={openIds}
						toggleOpen={toggleOpen}
						beerLookup={beerLookup}
						flagsFor={tracker.flagsFor}
						onSelect={setActiveBeer}
					/>
				) : null}

				{loading ? (
					<aside class="obwf-loader">
						<div class="obwf-loading-wrap">
							<div class="obwf-loading-token">
								<h4>Loading&hellip;</h4>
							</div>
						</div>
					</aside>
				) : null}
			</div>

			<BeerModal
				beer={activeBeer}
				flags={activeFlags}
				onClose={closeModal}
				onTasted={tracker.toggleTasted}
				onFavorited={tracker.toggleFavorited}
				onToTry={tracker.toggleToTry}
			/>
		</div>
	);
}
