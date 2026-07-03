<?php
/**
 * The precomputed `/obw/v1/finder` route (Phase 2 §4.1).
 *
 * Replaces the finder's three paginated core-REST `fetchAll()` loops with a
 * single cached payload: `{ beers, breweries, venues }`. Built from the same
 * {@see \OBW\BeerTracker\Shaping} helpers the `rest_prepare_*` normalizers use
 * (see {@see \OBW\BeerTracker\Fields}) so the two payload shapes cannot drift.
 *
 * `content` is deliberately excluded from `beers` — the modal lazy-fetches it
 * per beer on open (§4.2); see `src/finder/components/BeerModal.jsx`.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Rest;

use OBW\BeerTracker\Shaping;

/**
 * Registers and serves `GET /wp-json/obw/v1/finder`.
 */
final class FinderController {

	private const NAMESPACE = 'obw/v1';
	private const ROUTE     = '/finder';

	/**
	 * Wire the route + the cache invalidation hooks it depends on.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		( new FinderCache() )->register_hooks();
	}

	/**
	 * Register the route. Public GET — the finder dataset is published,
	 * publicly-visible content; no auth is required to read it.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_finder' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Serve the cached blob, honoring `If-None-Match` with a 304.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_finder( \WP_REST_Request $request ) {
		$blob = FinderCache::get();
		if ( null === $blob ) {
			$blob = $this->build_blob();
			FinderCache::set( $blob );
		}

		$etag         = $blob['etag'];
		$if_none_match = $request->get_header( 'if_none_match' );

		if ( is_string( $if_none_match ) && trim( $if_none_match ) === $etag ) {
			$response = new \WP_REST_Response( null, 304 );
			$response->header( 'ETag', $etag );
			$response->header( 'Cache-Control', 'public, max-age=0, must-revalidate' );

			return $response;
		}

		$response = new \WP_REST_Response(
			[
				'beers'     => $blob['beers'],
				'breweries' => $blob['breweries'],
				'venues'    => $blob['venues'],
			],
			200
		);
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=0, must-revalidate' );

		return $response;
	}

	/**
	 * Assemble the full finder dataset from published posts.
	 *
	 * @return array{beers:array<int,mixed>,breweries:array<int,mixed>,venues:array<int,mixed>,etag:string}
	 */
	private function build_blob(): array {
		$beers     = array_map( [ $this, 'shape_beer' ], $this->published_posts( 'obw_beer' ) );
		$breweries = array_map(
			fn ( \WP_Post $post ) => $this->shape_group( $post, 'brewery_link' ),
			$this->published_posts( 'obw_brewery' )
		);
		$venues    = array_map(
			fn ( \WP_Post $post ) => $this->shape_group( $post, 'venue_link' ),
			$this->published_posts( 'obw_venue' )
		);

		$blob = [
			'beers'     => array_values( $beers ),
			'breweries' => array_values( $breweries ),
			'venues'    => array_values( $venues ),
		];

		$blob['etag'] = '"' . md5( (string) wp_json_encode( $blob ) ) . '"';

		return $blob;
	}

	/**
	 * Fetch every published post of a type.
	 *
	 * @return array<int,\WP_Post>
	 */
	private function published_posts( string $post_type ): array {
		return get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'title',
				'order'          => 'ASC',
			]
		);
	}

	/**
	 * Shape a beer to the finder's bulk-payload contract (no `content`).
	 *
	 * @return array<string,mixed>
	 */
	private function shape_beer( \WP_Post $post ): array {
		return [
			'id'   => $post->ID,
			'name' => get_the_title( $post ),
			'link' => get_permalink( $post ) ?: '',
			'acf'  => [
				'style'        => (string) ( Shaping::scalar( $post->ID, 'style' ) ?? '' ),
				'abv'          => Shaping::number( $post->ID, 'abv' ),
				'ibu'          => Shaping::number( $post->ID, 'ibu' ),
				'untappd'      => (string) ( Shaping::scalar( $post->ID, 'untappd' ) ?? '' ),
				'brewery_link' => Shaping::relation( $post->ID, 'brewery_link' ),
				'venue_link'   => Shaping::relation( $post->ID, 'venue_link' ),
			],
		];
	}

	/**
	 * Shape a brewery/venue to the finder's bulk-payload contract: the reverse
	 * relation's beers, slimmed to `{ ID, post_title, post_name, post_status }`.
	 *
	 * @return array<string,mixed>
	 */
	private function shape_group( \WP_Post $post, string $relation_field ): array {
		return [
			'id'    => $post->ID,
			'name'  => get_the_title( $post ),
			'link'  => get_permalink( $post ) ?: '',
			'beers' => Shaping::relation( $post->ID, $relation_field, true ),
		];
	}
}
