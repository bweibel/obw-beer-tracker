/**
 * Beer detail modal. Ports the modal markup from the theme's
 * page-beerfinder.php: title, brewery link(s), badges, style/ABV, "available
 * at" venues, description, Untappd + More Info buttons, and the tracker
 * action buttons. Closes on overlay click or the close button (replacing the
 * AngularJS `click-outside` directive).
 */
import { useEffect, useState } from 'preact/hooks';
import { cutText } from '../util.js';
import { loadBeerContent } from '../api.js';
import { Badges } from './Badges.jsx';
import { IconClose, IconExternalLink } from './icons/Icons.jsx';

export function BeerModal({ beer, flags, onClose, onTasted, onFavorited, onToTry }) {
	// §4.2: `content` is not part of the bulk finder payload — fetch it lazily
	// per beer when the modal opens, cached in memory (api.js) for the
	// session so re-opening the same beer doesn't re-fetch.
	const [content, setContent] = useState('');

	useEffect(() => {
		let cancelled = false;
		setContent('');
		if (!beer) return undefined;

		loadBeerContent(beer.id).then((html) => {
			if (!cancelled) setContent(html);
		});

		return () => {
			cancelled = true;
		};
	}, [beer && beer.id]);

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

					{content ? (
						<div class="obwf-description">
							<div
								class="obwf-description-inner"
								dangerouslySetInnerHTML={{
									__html: cutText(content, 240),
								}}
							/>
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
							class={'obwf-btn--gray' + (flags.toTry ? ' obwf-btn--on' : '')}
							onClick={() => onToTry(beer.id)}
						>
							Want To Try
						</button>
						<button
							class={'obwf-btn--gray' + (flags.tasted ? ' obwf-btn--on' : '')}
							onClick={() => onTasted(beer.id)}
						>
							Tasted
						</button>
						<button
							class={'obwf-btn--gray' + (flags.favorited ? ' obwf-btn--on' : '')}
							onClick={() => onFavorited(beer.id)}
						>
							Favorite
						</button>
					</div>
				</section>
			</aside>
		</div>
	);
}
