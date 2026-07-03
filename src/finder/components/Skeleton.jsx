/**
 * Placeholder rows shown while the finder data loads. Mirrors the real list row
 * layout (name + style block on the left, status badges on the right) so the
 * transition to real beers causes minimal layout shift / pop-in.
 */
const ROWS = 8;

export function SkeletonList() {
	return (
		<section class="obwf-page-content obwf-list obwf-cf" aria-hidden="true">
			{Array.from({ length: ROWS }).map((_, i) => (
				<div class="obwf-row obwf-row--skeleton" key={i}>
					<div class="obwf-row-main">
						<span class="obwf-skeleton obwf-skeleton-title" />
						<span class="obwf-skeleton obwf-skeleton-style" />
					</div>
					<span class="obwf-skeleton obwf-skeleton-badges" />
				</div>
			))}
		</section>
	);
}
