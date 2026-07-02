/**
 * Beer detail modal. Ports the modal markup from the theme's
 * page-beerfinder.php: title, brewery link(s), badges, style/ABV, "available
 * at" venues, description, Untappd + More Info buttons, and the tracker
 * action buttons. Closes on overlay click or the close button (replacing the
 * AngularJS `click-outside` directive).
 */
import { cutText } from '../util.js';
import { Badges } from './Badges.jsx';

export function BeerModal({ beer, flags, onClose, onTasted, onFavorited, onToTry }) {
	if (!beer) return null;

	const acf = beer.acf || {};
	const breweries = acf.brewery_link || [];
	const venues = acf.venue_link || [];
	const hasAbv = typeof acf.abv === 'number' && !Number.isNaN(acf.abv);

	return (
		<div class="modal-wrap on" onClick={onClose}>
			<aside class="beer-card modal" onClick={(e) => e.stopPropagation()}>
				<button class="modal-close" onClick={onClose} aria-label="Close">
					&times;
				</button>

				<header class="card-header cf">
					<h3 class="beer-title">
						<a href={beer.link} target="_blank" rel="noopener">
							{beer.name}
						</a>
					</h3>
					<h4 class="brewery obw-gray">
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

				<section class="card-content cf">
					<div class="details">
						{acf.style ? (
							<span class="style">
								<strong>Style:</strong> {acf.style}
							</span>
						) : null}
						<span class="abv">
							<strong>ABV:</strong>{' '}
							{hasAbv && acf.abv >= 0.1
								? `${acf.abv}%`
								: '0% - non-alcoholic'}
						</span>
					</div>

					{venues.length > 0 ? (
						<div class="available-list">
							<strong>Available at:</strong>
							<br />
							{venues.map((v) => (
								<div class="venue" key={v.ID}>
									<a class="venue-link" href={`/venue/${v.post_name}`}>
										{v.post_title}
									</a>
								</div>
							))}
						</div>
					) : null}

					{beer.content ? (
						<div class="beer-description-wrap">
							<div
								class="beer-description-inner"
								dangerouslySetInnerHTML={{
									__html: cutText(beer.content, 240),
								}}
							/>
						</div>
					) : null}

					<div class="button-wrap top-row">
						{acf.untappd ? (
							<a
								href={acf.untappd}
								target="_blank"
								rel="noopener"
								class="obw-button-gold ut-button"
								id="untappd-link"
							>
								Untappd <span class="untappd-button-icon">&gt;</span>
							</a>
						) : null}
						<a
							href={beer.link}
							class="obw-button more-info-button"
							target="_blank"
							rel="noopener"
						>
							More Info &gt;
						</a>
					</div>

					<div class="button-wrap bottom-row">
						<button
							class={'obw-button-gray' + (flags.toTry ? ' is-on' : '')}
							onClick={() => onToTry(beer.id)}
						>
							Want To Try
						</button>
						<button
							class={'obw-button-gray' + (flags.tasted ? ' is-on' : '')}
							onClick={() => onTasted(beer.id)}
						>
							Tasted
						</button>
						<button
							class={'obw-button-gray' + (flags.favorited ? ' is-on' : '')}
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
