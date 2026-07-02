<?php
/**
 * ACF field wiring: Local JSON autoload + REST payload normalization.
 *
 * WP-2 owns the beer/brewery/venue relationship model. The field definitions
 * live as ACF Local JSON inside this plugin (`acf-json/`) so the shape is
 * version-controlled with the code, and ACF Pro auto-syncs them. Native ACF
 * `show_in_rest` exposes the fields under the `acf` key of the core REST
 * responses; this class additionally normalizes the beer/brewery/venue relation
 * payloads to the exact contract the finder SPA (WP-3) consumes.
 *
 * Contract (GET /wp-json/wp/v2/obw_beer):
 *   acf.style        string
 *   acf.abv          number|null
 *   acf.ibu          number|null
 *   acf.untappd      string  (raw slug/id — finder prepends https://untappd.com/b/)
 *   acf.brewery_link [{ ID, post_title, post_name }, ...]
 *   acf.venue_link   [{ ID, post_title, post_name }, ...]
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker;

/**
 * Registers the plugin's ACF Local JSON path and the REST normalizers.
 */
final class Fields {

	/**
	 * Wire hooks into WordPress / ACF.
	 */
	public function register_hooks(): void {
		add_filter( 'acf/settings/load_json', [ $this, 'add_json_load_path' ] );
		add_filter( 'acf/settings/save_json', [ $this, 'set_json_save_path' ] );

		// Normalize the beer REST payload to the documented contract. Runs late
		// (after ACF / acf-to-rest-api have populated the `acf` key) so the shape
		// is guaranteed regardless of which REST integration is active.
		add_filter( 'rest_prepare_obw_beer', [ $this, 'prepare_beer' ], 20, 3 );

		// WP-3: normalize the brewery/venue *reverse* relations so the finder's
		// Brewery/Venue tabs can run off core REST. Native ACF `show_in_rest`
		// returns each reverse relation as a full WP_Post-ish array (dozens of
		// fields per beer — a heavy payload), and the deep recursive acf-to-rest
		// variant returns them empty for some venues (a pre-existing bug). Both
		// filters below reduce the `brewery_link` / `venue_link` reverse payloads
		// to `[{ ID, post_title, post_name, post_status }]` — `post_status` is
		// included because the tabs display only published beers.
		add_filter( 'rest_prepare_obw_brewery', [ $this, 'prepare_brewery' ], 20, 3 );
		add_filter( 'rest_prepare_obw_venue', [ $this, 'prepare_venue' ], 20, 3 );
	}

	/**
	 * Tell ACF to load field groups from this plugin's acf-json directory.
	 *
	 * @param array<int,string> $paths Existing load paths.
	 * @return array<int,string>
	 */
	public function add_json_load_path( array $paths ): array {
		$paths[] = OBW_BEER_TRACKER_PATH . 'acf-json';

		return $paths;
	}

	/**
	 * Save field-group edits made in the ACF admin UI back into this plugin so
	 * the version-controlled JSON stays authoritative.
	 *
	 * @param string $path Current save path.
	 * @return string
	 */
	public function set_json_save_path( string $path ): string {
		return OBW_BEER_TRACKER_PATH . 'acf-json';
	}

	/**
	 * Normalize the beer REST payload to the finder contract.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post     The post being prepared.
	 * @return \WP_REST_Response
	 */
	public function prepare_beer( $response, $post, $request ) {
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$acf = ( isset( $data['acf'] ) && is_array( $data['acf'] ) ) ? $data['acf'] : [];

		$acf['style']        = (string) ( self::scalar( $post->ID, 'style' ) ?? '' );
		$acf['abv']          = self::number( $post->ID, 'abv' );
		$acf['ibu']          = self::number( $post->ID, 'ibu' );
		$acf['untappd']      = (string) ( self::scalar( $post->ID, 'untappd' ) ?? '' );
		$acf['brewery_link'] = self::relation( $post->ID, 'brewery_link' );
		$acf['venue_link']   = self::relation( $post->ID, 'venue_link' );

		$data['acf'] = $acf;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Normalize the brewery REST payload: slim the reverse `brewery_link` beers.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post     The post being prepared.
	 * @return \WP_REST_Response
	 */
	public function prepare_brewery( $response, $post, $request ) {
		return $this->prepare_reverse_relation( $response, $post, 'brewery_link' );
	}

	/**
	 * Normalize the venue REST payload: slim the reverse `venue_link` beers.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post     The post being prepared.
	 * @return \WP_REST_Response
	 */
	public function prepare_venue( $response, $post, $request ) {
		return $this->prepare_reverse_relation( $response, $post, 'venue_link' );
	}

	/**
	 * Shared: reduce a brewery/venue reverse relation to the finder's shape:
	 * an ordered array of { ID, post_title, post_name, post_status }.
	 *
	 * @param \WP_REST_Response $response The response object.
	 * @param \WP_Post          $post     The post being prepared.
	 * @param string            $field    Reverse relation field name.
	 * @return \WP_REST_Response
	 */
	private function prepare_reverse_relation( $response, $post, string $field ) {
		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$acf            = ( isset( $data['acf'] ) && is_array( $data['acf'] ) ) ? $data['acf'] : [];
		$acf[ $field ]  = self::relation( $post->ID, $field, true );
		$data['acf']    = $acf;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Read a scalar ACF value.
	 *
	 * @return mixed
	 */
	private static function scalar( int $post_id, string $field ) {
		return function_exists( 'get_field' ) ? get_field( $field, $post_id ) : null;
	}

	/**
	 * Read a numeric ACF value as float, or null when empty.
	 */
	private static function number( int $post_id, string $field ): ?float {
		$value = self::scalar( $post_id, $field );
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Read a relationship ACF value and reduce it to the finder's minimal shape:
	 * an ordered array of { ID, post_title, post_name }.
	 *
	 * Reads the raw stored value (post IDs) with `get_field( …, false )` so the
	 * result is deterministic regardless of the field's return_format. When
	 * `$with_status` is true (brewery/venue reverse relations) a `post_status`
	 * key is added so the finder's Brewery/Venue tabs can show only published
	 * beers.
	 *
	 * @param bool $with_status Include the related post's `post_status`.
	 * @return array<int,array<string,int|string>>
	 */
	private static function relation( int $post_id, string $field, bool $with_status = false ): array {
		$raw = function_exists( 'get_field' ) ? get_field( $field, $post_id, false ) : null;
		if ( empty( $raw ) ) {
			return [];
		}

		$out = [];
		foreach ( (array) $raw as $item ) {
			// Tolerate IDs, WP_Post objects, or ACF post arrays.
			if ( $item instanceof \WP_Post ) {
				$related_id = $item->ID;
			} elseif ( is_array( $item ) && isset( $item['ID'] ) ) {
				$related_id = (int) $item['ID'];
			} elseif ( is_object( $item ) && isset( $item->ID ) ) {
				$related_id = (int) $item->ID;
			} else {
				$related_id = (int) $item;
			}

			if ( $related_id <= 0 ) {
				continue;
			}

			$related = get_post( $related_id );
			if ( ! $related instanceof \WP_Post ) {
				continue;
			}

			$entry = [
				'ID'         => $related->ID,
				'post_title' => $related->post_title,
				'post_name'  => $related->post_name,
			];
			if ( $with_status ) {
				$entry['post_status'] = $related->post_status;
			}

			$out[] = $entry;
		}

		return $out;
	}
}
