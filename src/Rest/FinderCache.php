<?php
/**
 * Cache + invalidation for the precomputed `/obw/v1/finder` blob (Phase 2 §4.1).
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Rest;

/**
 * Wraps the transient (falls back to the object cache when persistent) that
 * stores the assembled finder payload, plus a version counter used to bust it
 * without needing to know (or guess) every transient key that may exist.
 *
 * Versioning strategy: the transient key embeds a *shape* version (bumped in
 * code whenever the payload contract changes) and a *data* version (an option
 * bumped at runtime whenever the underlying posts change). Bumping either
 * makes the previously-cached blob unreachable — old entries simply expire via
 * their TTL rather than needing explicit deletion, so invalidation is a cheap,
 * race-safe `autoload=no` option increment.
 */
final class FinderCache {

	/**
	 * Bump this when the response SHAPE changes (fields added/removed/renamed)
	 * so a stale blob is never served under the new contract.
	 */
	private const SHAPE_VERSION = 1;

	/**
	 * Option name holding the current data version.
	 */
	private const VERSION_OPTION = 'obw_beer_tracker_finder_data_version';

	/**
	 * Transient key prefix.
	 */
	private const TRANSIENT_PREFIX = 'obw_finder_v';

	/**
	 * How long a blob may live before being recomputed even without an
	 * explicit invalidation (belt-and-suspenders against a missed hook).
	 */
	private const TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Wire the invalidation hooks: post writes/deletes for the three CPTs, and
	 * the importer's completion hook (covers both plain imports and the
	 * annual reset, since {@see \OBW\BeerTracker\Import\Importer::import()}
	 * fires it once at the end of the run either way).
	 */
	public function register_hooks(): void {
		foreach ( [ 'obw_beer', 'obw_brewery', 'obw_venue' ] as $post_type ) {
			add_action( "save_post_{$post_type}", [ self::class, 'invalidate' ] );
		}

		// save_post only fires on create/update; deletions (including the
		// importer's force-delete annual reset) need their own hook.
		add_action( 'deleted_post', [ self::class, 'invalidate_on_delete' ], 10, 2 );
		add_action( 'trashed_post', [ self::class, 'invalidate' ] );

		// Importer commit point: fired once at the end of Importer::import()
		// for both plain imports and resets (never on dry runs).
		add_action( 'obw_beer_tracker_imported', [ self::class, 'invalidate' ] );
	}

	/**
	 * Only invalidate on `deleted_post` when the deleted post was one of the
	 * three data-model types (the hook fires for every post type).
	 *
	 * @param int      $post_id Deleted post id (already gone from the DB).
	 * @param \WP_Post $post    The post object as it was just before removal.
	 */
	public static function invalidate_on_delete( int $post_id, \WP_Post $post ): void {
		if ( in_array( $post->post_type, [ 'obw_beer', 'obw_brewery', 'obw_venue' ], true ) ) {
			self::invalidate();
		}
	}

	/**
	 * Bump the data version, making any currently-cached blob unreachable.
	 */
	public static function invalidate(): void {
		$current = (int) get_option( self::VERSION_OPTION, 1 );
		update_option( self::VERSION_OPTION, $current + 1, false );
	}

	/**
	 * The current cache key (shape version + data version).
	 */
	public static function key(): string {
		$data_version = (int) get_option( self::VERSION_OPTION, 1 );

		return self::TRANSIENT_PREFIX . self::SHAPE_VERSION . '_' . $data_version;
	}

	/**
	 * Fetch the cached blob, or null on a miss.
	 *
	 * @return array{beers:array<int,mixed>,breweries:array<int,mixed>,venues:array<int,mixed>,etag:string}|null
	 */
	public static function get(): ?array {
		$cached = get_transient( self::key() );

		return is_array( $cached ) ? $cached : null;
	}

	/**
	 * Store the assembled blob under the current key.
	 *
	 * @param array<string,mixed> $blob
	 */
	public static function set( array $blob ): void {
		set_transient( self::key(), $blob, self::TTL );
	}
}
