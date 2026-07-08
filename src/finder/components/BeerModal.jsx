/**
 * Beer detail modal. Ports the modal markup from the theme's
 * page-beerfinder.php: title, brewery link(s), badges, style/ABV, "available
 * at" venues, description, Untappd + More Info buttons, and the tracker
 * action buttons. Closes on overlay click or the close button (replacing the
 * AngularJS `click-outside` directive).
 */
import { useEffect, useRef, useState } from 'preact/hooks';
import { loadBeerContent } from '../api.js';
import { Badges } from './Badges.jsx';
import { IconClose, IconExternalLink } from './icons/Icons.jsx';

export function BeerModal({ beer, flags, onClose, onTasted, onFavorited, onToTry }) {
	// §4.2: `content` is not part of the bulk finder payload — fetch it lazily
	// per beer when the modal opens, cached in memory (api.js) for the
	// session so re-opening the same beer doesn't re-fetch.
	const [content, setContent] = useState('');
	// Track the in-flight fetch so we can reserve description space (min-height)
	// while loading and avoid the content popping in and shoving the buttons down.
	const [loading, setLoading] = useState(true);
	// Batch B / B1: "Read more" starts collapsed for every beer; reset when the
	// modal is (re)opened for a different beer so it doesn't carry over.
	const [expanded, setExpanded] = useState(false);
	// Whether the collapsed (line-clamped) description actually overflows — set by
	// measuring real heights, not a char-count guess, so "Read more" shows exactly
	// when there's hidden text. Full content is always rendered and clamped via
	// CSS; the clamp height matches the reserved min-height, so loading the text
	// never grows the card.
	const [overflows, setOverflows] = useState(false);
	const innerRef = useRef(null);

	useEffect(() => {
		let cancelled = false;
		setContent('');
		setLoading(true);
		setExpanded(false);
		setOverflows(false);
		if (!beer) return undefined;

		loadBeerContent(beer.id).then((html) => {
			if (!cancelled) {
				setContent(html);
				setLoading(false);
			}
		});

		return () => {
			cancelled = true;
		};
	}, [beer && beer.id]);

	// After the (collapsed) description renders, measure whether it's clipped.
	useEffect(() => {
		const el = innerRef.current;
		setOverflows(!!el && !!content && el.scrollHeight > el.clientHeight + 1);
	}, [content]);

	// Batch B / B2: lock background scroll while the modal is open, restoring
	// it on close/unmount. App.jsx manages its own body classes (see its
	// `beer-tracker-page-body` add/remove) — this only ever touches the one
	// class it owns, so it can't clobber App.jsx's.
	useEffect(() => {
		if (!beer) return undefined;
		// Lock BOTH the root element and body: on this theme the viewport scroll
		// container is <html>, so overflow:hidden on <body> alone does nothing.
		document.documentElement.classList.add('obwf-scroll-locked');
		document.body.classList.add('obwf-scroll-locked');
		return () => {
			document.documentElement.classList.remove('obwf-scroll-locked');
			document.body.classList.remove('obwf-scroll-locked');
		};
	}, [beer]);

	// Batch B / B3: close on Escape, in addition to overlay-click and the
	// close button.
	useEffect(() => {
		if (!beer) return undefined;
		const onKeyDown = (e) => {
			if (e.key === 'Escape') onClose();
		};
		document.addEventListener('keydown', onKeyDown);
		return () => {
			document.removeEventListener('keydown', onKeyDown);
		};
	}, [beer, onClose]);

	if (!beer) return null;

	const acf = beer.acf || {};
	const breweries = acf.brewery_link || [];
	const venues = acf.venue_link || [];
	const hasAbv = typeof acf.abv === 'number' && !Number.isNaN(acf.abv);

	return (
		<div class="obwf-modal-overlay obwf-modal-overlay--open" onClick={onClose}>
			<aside class="obwf-card obwf-card--modal" onClick={(e) => e.stopPropagation()}>
				<button class="obwf-modal-close" onClick={onClose} aria-label="Close">
					<IconClose />
				</button>

				<header class="obwf-card-header obwf-cf">
					<h3 class="obwf-title">
						<a href={beer.link} target="_blank" rel="noopener">
							{beer.name}
						</a>
					</h3>
					<h4 class="obwf-modal-brewery obwf-text-muted">
						{breweries.map((b, i) => (
							<span key={b.ID}>
								{i > 0 ? ' & ' : ''}
								<a href={`/brewery/${b.post_name}`} target="_blank" rel="noopener">
									{b.post_title}
								</a>
							</span>
						))}
					</h4>
					<Badges flags={flags} />
				</header>

				<section class="obwf-card-content obwf-cf">
					<div class="obwf-details">
						{acf.style ? (
							<span class="obwf-style">
								<strong>Style:</strong> {acf.style}
							</span>
						) : null}
						<span class="obwf-abv">
							<strong>ABV:</strong>{' '}
							{hasAbv && acf.abv >= 0.1
								? `${acf.abv}%`
								: '0% - non-alcoholic'}
						</span>
					</div>

					{venues.length > 0 ? (
						<div class="obwf-available-list">
							<strong>Available at:</strong>
							<br />
							{venues.map((v) => (
								<div class="obwf-venue-row" key={v.ID}>
									<a class="obwf-venue-link" href={`/venue/${v.post_name}`}>
										{v.post_title}
									</a>
								</div>
							))}
						</div>
					) : null}

					{loading || content ? (
						<div
							class={
								'obwf-description' +
								(overflows && !expanded ? ' obwf-description--clamped' : '')
							}
						>
							{content ? (
								<div
									ref={innerRef}
									class={
										'obwf-description-inner' +
										(expanded ? '' : ' obwf-description-inner--collapsed')
									}
									dangerouslySetInnerHTML={{ __html: content }}
								/>
							) : null}
							{overflows ? (
								<button
									type="button"
									class="obwf-description-toggle"
									onClick={() => setExpanded((v) => !v)}
									aria-expanded={expanded}
								>
									{expanded ? 'Read less' : 'Read more'}
								</button>
							) : null}
						</div>
					) : null}

					<div class="obwf-actions obwf-actions--top">
						{acf.untappd ? (
							<a
								href={acf.untappd}
								target="_blank"
								rel="noopener"
								class="obwf-btn--gold obwf-btn-untappd"
								id="untappd-link"
							>
								Untappd{' '}
								<span class="obwf-btn-untappd-icon">
									<IconExternalLink />
								</span>
							</a>
						) : null}
						<a
							href={beer.link}
							class="obwf-btn obwf-btn-more-info"
							target="_blank"
							rel="noopener"
						>
							More Info <IconExternalLink />
						</a>
					</div>

					<div class="obwf-actions obwf-actions--bottom">
						<button
							type="button"
							class={
								'obwf-track-btn obwf-track-btn--totry' +
								(flags.toTry ? ' obwf-track-btn--on' : '')
							}
							aria-pressed={flags.toTry}
							onClick={() => onToTry(beer.id)}
						>
							<span class="obwf-track-ico obwf-track-ico--totry" />
							Want To Try
						</button>
						<button
							type="button"
							class={
								'obwf-track-btn obwf-track-btn--tasted' +
								(flags.tasted ? ' obwf-track-btn--on' : '')
							}
							aria-pressed={flags.tasted}
							onClick={() => onTasted(beer.id)}
						>
							<span class="obwf-track-ico obwf-track-ico--tasted" />
							Tasted
						</button>
						<button
							type="button"
							class={
								'obwf-track-btn obwf-track-btn--favorite' +
								(flags.favorited ? ' obwf-track-btn--on' : '')
							}
							aria-pressed={flags.favorited}
							onClick={() => onFavorited(beer.id)}
						>
							<span class="obwf-track-ico obwf-track-ico--favorite" />
							Favorite
						</button>
					</div>
				</section>
			</aside>
		</div>
	);
}
