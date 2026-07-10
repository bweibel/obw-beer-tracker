<?php
/**
 * Trending / aggregate-favorites routes.
 *
 *   POST /obw/v1/track          — public, anonymous write of a device's flags.
 *   GET  /obw/v1/trend/{id}     — admin-only aggregate counts for a beer.
 *
 * Collection is universal (that's the point of the crowd aggregate); the read is
 * gated to `manage_options` so, for the current test run, only admins see the
 * numbers. The public `/obw/v1/finder` payload is intentionally left untouched
 * here (no count folding) to avoid cache fragmentation / leaking counts.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Rest;

use OBW\BeerTracker\Trend\TrackStore;

/**
 * Registers and serves the trend write/read routes.
 */
final class TrendController {

	private const NAMESPACE = 'obw/v1';

	/** Canonical UUID shape for the anonymous device id. */
	private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/** Max beers accepted per request (bounds a backfill / abuse). */
	private const MAX_BEERS = 500;

	/** Per-IP write budget per minute (soft abuse guard). */
	private const RATE_LIMIT = 60;

	/**
	 * Wire the routes.
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register both routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/track',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'track' ],
				// Public by design: anonymous visitors contribute to the aggregate.
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/trend/(?P<id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'trend' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'id' => [
						'validate_callback' => static fn( $value ) => is_numeric( $value ),
					],
				],
			]
		);
	}

	/**
	 * Read permission: admins only (defense in depth behind the client flag).
	 */
	public function can_view(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Record a device's flags for one or more beers. Fire-and-forget from the
	 * client — the response body is informational only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function track( \WP_REST_Request $request ): \WP_REST_Response {
		// Killswitch: accept-and-ignore so the client needn't special-case it.
		if ( ! TrackStore::is_enabled() ) {
			return new \WP_REST_Response( [ 'ok' => true, 'skipped' => true ], 200 );
		}

		if ( $this->rate_limited() ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'rate_limited' ], 429 );
		}

		$device = (string) $request->get_param( 'deviceId' );
		if ( ! preg_match( self::UUID_RE, $device ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'bad_device_id' ], 400 );
		}

		$beers = $request->get_param( 'beers' );
		if ( ! is_array( $beers ) ) {
			return new \WP_REST_Response( [ 'ok' => false, 'error' => 'no_beers' ], 400 );
		}
		$beers = array_slice( $beers, 0, self::MAX_BEERS );

		$store   = new TrackStore();
		$written = 0;
		foreach ( $beers as $beer ) {
			if ( ! is_array( $beer ) ) {
				continue;
			}
			$id = isset( $beer['id'] ) ? (int) $beer['id'] : 0;
			// Only accept ids that are real, published beers — never trust the id.
			if ( $id <= 0 || 'obw_beer' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			$store->put(
				$device,
				$id,
				! empty( $beer['totry'] ),
				! empty( $beer['tasted'] ),
				! empty( $beer['favorited'] )
			);
			++$written;
		}

		return new \WP_REST_Response( [ 'ok' => true, 'written' => $written ], 200 );
	}

	/**
	 * Aggregate counts for a beer (admin-only).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function trend( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$counts = ( new TrackStore() )->counts_for( $id );

		return new \WP_REST_Response( $counts, 200 );
	}

	/**
	 * Soft per-IP rate limit via a 1-minute transient counter.
	 */
	private function rate_limited(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		if ( '' === $ip ) {
			return false;
		}

		$key   = 'obw_trend_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}
}
