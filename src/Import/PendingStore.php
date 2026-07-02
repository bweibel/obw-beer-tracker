<?php
/**
 * Pending-review store for unmatched brewery/venue references.
 *
 * WP-4 records every brewery/venue reference the importer could NOT resolve
 * (never auto-creating the brewery/venue). WP-5 builds the admin review queue
 * on top of this store, letting an operator map each pending row to an existing
 * directory post or create a full profile and link it.
 *
 * Backing: a dedicated custom table `{$wpdb->prefix}obw_import_pending`. A table
 * (rather than a CPT or a single option) was chosen because the queue is
 * queryable by status/beer, grows/shrinks per row, and must persist across
 * sessions without cluttering the admin post lists. Created on activation and
 * self-heals via {@see self::maybe_upgrade()} on load.
 *
 * Schema:
 *   id          BIGINT UNSIGNED  PK, auto-increment
 *   beer_id     BIGINT UNSIGNED  the created obw_beer post (0 for dry-run/failed)
 *   beer_title  TEXT             beer name at import time (context)
 *   relation    VARCHAR(20)      'brewery' | 'venue'
 *   ref_type    VARCHAR(10)      'id' | 'name' (how the CSV referenced it)
 *   ref_value   TEXT             the unresolved id or name string
 *   row_index   INT              1-based CSV row for operator reference
 *   status      VARCHAR(20)      'pending' | 'resolved' | 'ignored'
 *   created_at  DATETIME         when recorded
 *   resolved_at DATETIME NULL    when an operator dispositioned it
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Import;

/**
 * CRUD wrapper around the pending-review custom table.
 */
final class PendingStore {

	/**
	 * Bumped whenever the table schema changes; drives {@see maybe_upgrade()}.
	 */
	private const DB_VERSION = '1';

	/**
	 * Option key holding the installed schema version.
	 */
	private const VERSION_OPTION = 'obw_import_pending_db_version';

	/**
	 * Unprefixed table name.
	 */
	private const TABLE = 'obw_import_pending';

	/**
	 * Fully-qualified table name (with the site's table prefix).
	 */
	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the table. Safe to call repeatedly (dbDelta is idempotent).
	 * Called from the plugin activation hook and {@see maybe_upgrade()}.
	 */
	public static function create_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			beer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			beer_title TEXT NOT NULL,
			relation VARCHAR(20) NOT NULL DEFAULT '',
			ref_type VARCHAR(10) NOT NULL DEFAULT '',
			ref_value TEXT NOT NULL,
			row_index INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY beer_id (beer_id)
		) {$collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Ensure the table exists / is current. Cheap enough to run on load; only
	 * touches the DB when the stored version differs.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::create_table();
	}

	/**
	 * Insert a pending record. Returns the new row id (0 on failure).
	 *
	 * @param array{beer_id?:int,beer_title?:string,relation:string,ref_type:string,ref_value:string,row_index?:int} $record Record.
	 */
	public function add( array $record ): int {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			[
				'beer_id'    => (int) ( $record['beer_id'] ?? 0 ),
				'beer_title' => (string) ( $record['beer_title'] ?? '' ),
				'relation'   => (string) $record['relation'],
				'ref_type'   => (string) $record['ref_type'],
				'ref_value'  => (string) $record['ref_value'],
				'row_index'  => (int) ( $record['row_index'] ?? 0 ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch records, optionally filtered by status.
	 *
	 * @param string|null $status 'pending'|'resolved'|'ignored' or null for all.
	 * @return array<int,array<string,mixed>>
	 */
	public function all( ?string $status = 'pending' ): array {
		global $wpdb;

		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
		} else {
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC", $status ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Count records with a given status.
	 */
	public function count( ?string $status = 'pending' ): int {
		global $wpdb;

		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
		);
	}

	/**
	 * Fetch a single record by id.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = $this->table();
		$row   = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update a record's status (WP-5 uses this when an operator dispositions a
	 * row). Sets `resolved_at` for terminal statuses.
	 */
	public function update_status( int $id, string $status ): bool {
		global $wpdb;

		$resolved_at = in_array( $status, [ 'resolved', 'ignored' ], true )
			? current_time( 'mysql' )
			: null;

		$result = $wpdb->update(
			$this->table(),
			[
				'status'      => $status,
				'resolved_at' => $resolved_at,
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete a single record.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete( $this->table(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Clear every pending record. Called by the annual reset (the prior beer set
	 * is gone, so its unresolved references are stale). Returns rows removed.
	 */
	public function clear(): int {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query( "DELETE FROM {$table}" );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
