<?php
/**
 * Anonymous aggregate tracker store (trending / "crowd" counts).
 *
 * Backs the opt-in-by-default "Most Wanted / Most Loved" aggregate: every device
 * upserts one row per beer with its want-to-try / tasted / favorited flags, and
 * the counts are read as distinct-device sums. Deliberately anonymous — the only
 * identifier is a random client UUID (no PII, no account) — so it preserves the
 * finder's zero-auth model. Collection is universal; display is gated to admins
 * elsewhere (see TrendController + the shortcode's data attributes).
 *
 * Backing: a dedicated custom table `{$wpdb->prefix}obw_beer_track`, chosen (over
 * post meta / options) because it is queried with a GROUP BY aggregate and
 * grows/shrinks per (device, beer). Created on activation, self-heals via
 * {@see maybe_upgrade()}, and dropped on uninstall (anonymous, disposable —
 * unlike the brewery/venue directory, which must never be destroyed).
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Trend;

/**
 * CRUD + aggregate wrapper around the anonymous tracker table.
 */
final class TrackStore {

	/** Bumped whenever the schema changes; drives {@see maybe_upgrade()}. */
	private const DB_VERSION = '1';

	/** Option holding the installed schema version. */
	private const VERSION_OPTION = 'obw_beer_track_db_version';

	/** Unprefixed table name. */
	private const TABLE = 'obw_beer_track';

	/** Transient caching the full aggregate map. */
	private const COUNT_CACHE = 'obw_beer_track_counts';

	/** Aggregate cache lifetime (seconds) — a trending preview tolerates lag. */
	private const COUNT_TTL = 300;

	/**
	 * Is aggregate collection + preview active?
	 *
	 * Killswitch, in precedence order (mirrors {@see \OBW\BeerTracker\ServiceWorker}):
	 *   1. `OBW_TREND_DISABLED` constant (wp-config) — always-available hatch.
	 *   2. `obw_beer_tracker_trend_disabled` option — flip with `wp obw trend off`.
	 *   3. `obw_beer_tracker_trend_enabled` filter — programmatic control.
	 */
	public static function is_enabled(): bool {
		if ( defined( 'OBW_TREND_DISABLED' ) && OBW_TREND_DISABLED ) {
			return false;
		}
		if ( get_option( 'obw_beer_tracker_trend_disabled' ) ) {
			return false;
		}
		return (bool) apply_filters( 'obw_beer_tracker_trend_enabled', true );
	}

	/** Fully-qualified table name. */
	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the table. Idempotent (dbDelta). Called from activation and
	 * {@see maybe_upgrade()}.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			device_id CHAR(36) NOT NULL,
			beer_id BIGINT UNSIGNED NOT NULL,
			totry TINYINT(1) NOT NULL DEFAULT 0,
			tasted TINYINT(1) NOT NULL DEFAULT 0,
			favorited TINYINT(1) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (device_id, beer_id),
			KEY beer_id (beer_id)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure the table exists / is current. Cheap on load; only hits the DB when
	 * the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_table();
	}

	/**
	 * Drop the table + its options. Called from uninstall (data is anonymous and
	 * disposable). Inlined equivalent lives in uninstall.php for the no-autoload
	 * uninstall context.
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * Upsert one device's flags for a beer. An all-false state removes the row so
	 * the table only ever holds live marks.
	 *
	 * Note: does NOT invalidate the aggregate cache — writes are frequent and
	 * reads (admin-only) tolerate up to COUNT_TTL of lag, so we let the transient
	 * expire naturally rather than re-query on every toggle.
	 */
	public function put( string $device_id, int $beer_id, bool $totry, bool $tasted, bool $favorited ): void {
		global $wpdb;

		if ( ! $totry && ! $tasted && ! $favorited ) {
			$wpdb->delete(
				$this->table(),
				[
					'device_id' => $device_id,
					'beer_id'   => $beer_id,
				],
				[ '%s', '%d' ]
			);
			return;
		}

		$wpdb->replace(
			$this->table(),
			[
				'device_id'  => $device_id,
				'beer_id'    => $beer_id,
				'totry'      => $totry ? 1 : 0,
				'tasted'     => $tasted ? 1 : 0,
				'favorited'  => $favorited ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			],
			[ '%s', '%d', '%d', '%d', '%d', '%s' ]
		);
	}

	/**
	 * Distinct-device counts per beer, cached in a transient.
	 *
	 * @return array<int,array{totry:int,tasted:int,favorited:int}>
	 */
	public function counts(): array {
		$cached = get_transient( self::COUNT_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			"SELECT beer_id, SUM(totry) AS totry, SUM(tasted) AS tasted, SUM(favorited) AS favorited
			 FROM {$table} GROUP BY beer_id",
			ARRAY_A
		);

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ (int) $r['beer_id'] ] = [
					'totry'     => (int) $r['totry'],
					'tasted'    => (int) $r['tasted'],
					'favorited' => (int) $r['favorited'],
				];
			}
		}

		set_transient( self::COUNT_CACHE, $out, self::COUNT_TTL );
		return $out;
	}

	/**
	 * Counts for a single beer (zeros if none).
	 *
	 * @return array{totry:int,tasted:int,favorited:int}
	 */
	public function counts_for( int $beer_id ): array {
		$all = $this->counts();
		return $all[ $beer_id ] ?? [
			'totry'     => 0,
			'tasted'    => 0,
			'favorited' => 0,
		];
	}
}
