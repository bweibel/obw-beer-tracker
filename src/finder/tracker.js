/**
 * Client-side tracker state. Ports the AngularJS tracker business rules exactly
 * and keeps the localStorage contract intact:
 *
 *   key:   "beerData"
 *   shape: { beers: [ { id, tasted, favorited, toTry } ] }
 *
 * Business rules (from beerfinder.js):
 *   - favoriting forces tasted = true
 *   - un-tasting something favorited clears the favorite
 *   - tasting (or favoriting) something clears its "to try" flag
 *   - delete-all wipes beerData (+ helpHide / adHide) and resets every flag
 */

import { useCallback, useState } from 'preact/hooks';

const STORAGE_KEY = 'beerData';

const EMPTY = { tasted: false, favorited: false, toTry: false };

/**
 * Fire a very short, feature-guarded haptic tick for a user-initiated toggle.
 * No-op (and no error) on devices/browsers without `navigator.vibrate`.
 */
function tick() {
	if (typeof navigator !== 'undefined') {
		navigator.vibrate?.(10);
	}
}

/**
 * Read the stored tracker map from localStorage into an id -> flags object.
 *
 * @returns {Record<number, {tasted:boolean,favorited:boolean,toTry:boolean}>}
 */
function readStore() {
	let parsed = null;
	try {
		parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
	} catch (e) {
		parsed = null;
	}
	const map = {};
	if (parsed && Array.isArray(parsed.beers)) {
		for (const b of parsed.beers) {
			if (b && b.id != null) {
				map[b.id] = {
					tasted: !!b.tasted,
					favorited: !!b.favorited,
					toTry: !!b.toTry,
				};
			}
		}
	}
	return map;
}

/**
 * Serialize the id -> flags map back to the `{ beers: [...] }` contract.
 *
 * @param {Record<number, object>} map
 */
function writeStore(map) {
	const beers = Object.keys(map).map((id) => ({
		id: Number(id),
		tasted: map[id].tasted,
		favorited: map[id].favorited,
		toTry: map[id].toTry,
	}));
	try {
		localStorage.setItem(STORAGE_KEY, JSON.stringify({ beers }));
	} catch (e) {
		/* storage full / unavailable — non-fatal */
	}
}

/**
 * Tracker hook. Returns the flag map plus the three toggle actions and the
 * delete-all handler.
 */
export function useTracker() {
	const [map, setMap] = useState(readStore);

	const flagsFor = useCallback(
		(id) => map[id] || EMPTY,
		[map]
	);

	const update = useCallback((id, mutate) => {
		setMap((prev) => {
			const current = prev[id] || EMPTY;
			const next = mutate({ ...current });
			const updated = { ...prev, [id]: next };
			writeStore(updated);
			return updated;
		});
	}, []);

	const toggleTasted = useCallback(
		(id) => {
			tick(); // subtle haptic feedback on the user-initiated toggle
			update(id, (f) => {
				f.tasted = !f.tasted;
				if (f.favorited && !f.tasted) {
					f.favorited = false; // can't favorite what you haven't tried
				}
				if (f.tasted && f.toTry) {
					f.toTry = false; // pull off the "to try" list
				}
				return f;
			});
		},
		[update]
	);

	const toggleFavorited = useCallback(
		(id) => {
			tick();
			update(id, (f) => {
				f.favorited = !f.favorited;
				if (f.favorited && !f.tasted) {
					f.tasted = true; // favoriting forces tasted
				}
				if (f.favorited && f.toTry) {
					f.toTry = false;
				}
				return f;
			});
		},
		[update]
	);

	const toggleToTry = useCallback(
		(id) => {
			tick();
			update(id, (f) => {
				f.toTry = !f.toTry;
				return f;
			});
		},
		[update]
	);

	const deleteAll = useCallback(() => {
		const ok =
			typeof window === 'undefined' ||
			window.confirm(
				'Are you sure you want to delete all of your Beer Tracker data?'
			);
		if (!ok) return;
		try {
			localStorage.removeItem(STORAGE_KEY);
			localStorage.removeItem('helpHide');
			sessionStorage.removeItem('adHide');
		} catch (e) {
			/* ignore */
		}
		setMap({});
	}, []);

	return {
		flagsFor,
		toggleTasted,
		toggleFavorited,
		toggleToTry,
		deleteAll,
	};
}
