<?php
/**
 * CSV import service (WP-4 core).
 *
 * Framework-agnostic. Given parsed CSV rows it creates `obw_beer` posts, sets
 * their scalar ACF fields + `post_content`, and auto-links each beer to the
 * PERSISTENT brewery/venue directory using the owner-resolved strategy:
 *
 *   1. Canonical ID column (`brewery_id` / `venue_id`) → the referenced
 *      obw_brewery / obw_venue post, if it exists.
 *   2. Fallback: normalized (case/whitespace-trimmed) EXACT name match against
 *      existing brewery/venue post titles (`brewery` / `venue` columns).
 *
 * On a match the ACF bidirectional relationship field is set with
 * `update_field()`; ACF Pro keeps the reverse side in sync (we never write the
 * reverse relation ourselves). On NO match the brewery/venue is **never
 * created** — the beer + the unresolved id/name is recorded in the
 * {@see PendingStore} for the WP-5 review queue.
 *
 * ── CSV column contract ──────────────────────────────────────────────────────
 * Header order is irrelevant; names are matched case-insensitively (see
 * {@see CsvReader}). Multi-value cells use the pipe `|` delimiter.
 *
 *   REQUIRED
 *     name          Beer name → post_title.
 *
 *   OPTIONAL (beer fields)
 *     style         → ACF `style`  (field_5717e2b8ff426)
 *     abv           → ACF `abv`    (field_5717e3816cbaf)
 *     ibu           → ACF `ibu`    (field_5734e8f4ee5c4)
 *     untappd       → ACF `untappd`(field_5734e758eb388) — RAW slug, no URL prefix
 *     description   → post_content (NOT an ACF field)
 *
 *   OPTIONAL (relations — ID takes priority over name, per relation)
 *     brewery_id    One or more existing obw_brewery post IDs, `|`-separated.
 *     brewery       One or more brewery names (fallback when brewery_id empty).
 *     venue_id      One or more existing obw_venue post IDs, `|`-separated.
 *     venue         One or more venue names (fallback when venue_id empty).
 *
 * ── Annual reset / idempotency ──────────────────────────────────────────────
 * Beers are disposable and reset yearly. {@see reset_beers()} force-deletes the
 * ENTIRE prior `obw_beer` set (and {@see import()} clears the pending store when
 * run with $reset) before importing fresh. It NEVER touches brewery/venue posts
 * except to add relations. Re-running an import with $reset therefore lands on
 * the same end state — that is the sanctioned idempotency path. Running WITHOUT
 * $reset appends (beers have no natural key), so operators start each year with
 * the reset.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Import;

/**
 * Ingests parsed CSV rows into beers + directory relations.
 */
final class Importer {

	/**
	 * Delimiter for multi-value cells (multiple brewery/venue refs per beer).
	 */
	public const DELIMITER = '|';

	// ACF field keys (see IMPLEMENTATION_PLAN "discovered contracts").
	private const F_STYLE   = 'field_5717e2b8ff426';
	private const F_ABV     = 'field_5717e3816cbaf';
	private const F_IBU     = 'field_5734e8f4ee5c4';
	private const F_UNTAPPD = 'field_5734e758eb388';
	private const F_BREWERY = 'field_5965323de28fc';
	private const F_VENUE   = 'field_596538519f8af';

	/**
	 * Cached normalized-name → post-id maps, keyed by post type. Built lazily
	 * per import run so name-fallback matching is one query per directory.
	 *
	 * @var array<string,array<string,int>>
	 */
	private array $name_maps = [];

	public function __construct( private PendingStore $pending ) {}

	/**
	 * Import a batch of parsed CSV rows.
	 *
	 * @param array<int,array<string,string>> $rows    Rows keyed by normalized header.
	 * @param bool                             $dry_run When true, mutate NOTHING.
	 * @param bool                             $reset   When true, run the annual reset first.
	 */
	public function import( array $rows, bool $dry_run = false, bool $reset = false ): ImportResult {
		$result          = new ImportResult();
		$result->dry_run = $dry_run;

		// Fresh maps for this run (directory may have changed since last call).
		$this->name_maps = [];

		if ( $reset ) {
			$result->did_reset     = true;
			$result->reset_deleted = $this->reset_beers( $dry_run );
			if ( ! $dry_run ) {
				$this->pending->clear();
			}
		}

		$row_number = 1; // 1-based, matching the data rows after the header.
		foreach ( $rows as $row ) {
			++$row_number;

			$name = trim( (string) ( $row['name'] ?? '' ) );
			if ( '' === $name ) {
				$result->add_error( sprintf( 'Row %d skipped: missing required "name".', $row_number ) );
				continue;
			}

			++$result->rows_total;

			$beer_id = $this->create_beer( $row, $name, $dry_run );
			$result->add_beer( $row_number, $beer_id, $name );

			$this->link_relation( $result, $row, $row_number, $beer_id, $name, 'brewery', 'obw_brewery', self::F_BREWERY, $dry_run );
			$this->link_relation( $result, $row, $row_number, $beer_id, $name, 'venue', 'obw_venue', self::F_VENUE, $dry_run );
		}

		// Commit point for anything that needs to react to a completed import
		// (e.g. Phase 2 §4.1's FinderCache invalidation). Never fires for a dry
		// run, since nothing was mutated. save_post/deleted_post already cover
		// per-post cache busting during the run; this catches the reverse
		// relations that ACF writes directly to brewery/venue meta (no
		// save_post fires on those) so the group blob doesn't go stale.
		if ( ! $dry_run && function_exists( 'do_action' ) ) {
			do_action( 'obw_beer_tracker_imported', $result );
		}

		return $result;
	}

	/**
	 * Annual reset: force-delete every `obw_beer` post. Never touches
	 * brewery/venue posts. Returns the number deleted (or that would be, in a
	 * dry run).
	 */
	public function reset_beers( bool $dry_run = false ): int {
		$ids = get_posts(
			[
				'post_type'      => 'obw_beer',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( $dry_run ) {
			return count( $ids );
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;

			// Clear the beer's relations FIRST so ACF's native bidirectional
			// wiring removes this beer from every brewery/venue reverse field.
			// (ACF does not auto-clean reverse references on post deletion, so a
			// plain force-delete would leave dead beer IDs accreting in the
			// persistent directory's meta year over year.) This only *removes*
			// relations — it never edits brewery/venue content.
			if ( function_exists( 'update_field' ) ) {
				update_field( self::F_BREWERY, [], $id );
				update_field( self::F_VENUE, [], $id );
			}

			// Force-delete: bypass trash so re-import starts clean.
			if ( wp_delete_post( $id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Create the beer post + scalar fields + post_content. Returns the new post
	 * id, or 0 in dry-run mode.
	 *
	 * @param array<string,string> $row  Parsed row.
	 * @param string               $name Beer name (already trimmed, non-empty).
	 */
	private function create_beer( array $row, string $name, bool $dry_run ): int {
		if ( $dry_run ) {
			return 0;
		}

		$beer_id = wp_insert_post(
			[
				'post_type'    => 'obw_beer',
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => (string) ( $row['description'] ?? '' ),
			],
			true
		);

		if ( is_wp_error( $beer_id ) || 0 === (int) $beer_id ) {
			return 0;
		}

		$beer_id = (int) $beer_id;

		$this->set_field( self::F_STYLE, $this->clean( $row['style'] ?? '' ), $beer_id );
		$this->set_field( self::F_UNTAPPD, $this->clean( $row['untappd'] ?? '' ), $beer_id );

		$abv = $this->numeric( $row['abv'] ?? '' );
		if ( null !== $abv ) {
			$this->set_field( self::F_ABV, $abv, $beer_id );
		}

		$ibu = $this->numeric( $row['ibu'] ?? '' );
		if ( null !== $ibu ) {
			$this->set_field( self::F_IBU, $ibu, $beer_id );
		}

		return $beer_id;
	}

	/**
	 * Resolve one relation (brewery or venue) for a beer and either link the
	 * matched directory posts or record the unmatched references.
	 *
	 * @param ImportResult         $result    Accumulating result.
	 * @param array<string,string> $row       Parsed row.
	 * @param int                  $row_number 1-based CSV row.
	 * @param int                  $beer_id   Beer post id (0 in dry-run).
	 * @param string               $beer_name Beer title (context).
	 * @param string               $relation  'brewery' | 'venue'.
	 * @param string               $post_type Target CPT.
	 * @param string               $field_key ACF relationship field key.
	 */
	private function link_relation(
		ImportResult $result,
		array $row,
		int $row_number,
		int $beer_id,
		string $beer_name,
		string $relation,
		string $post_type,
		string $field_key,
		bool $dry_run
	): void {
		// Priority: ID column first; name column only as a fallback.
		$id_values   = $this->split( $row[ $relation . '_id' ] ?? '' );
		$name_values = $this->split( $row[ $relation ] ?? '' );

		if ( ! empty( $id_values ) ) {
			$refs     = $id_values;
			$ref_type = 'id';
		} elseif ( ! empty( $name_values ) ) {
			$refs     = $name_values;
			$ref_type = 'name';
		} else {
			return; // No reference for this relation on this row.
		}

		$matched_ids = [];
		foreach ( $refs as $ref ) {
			$post_id = 'id' === $ref_type
				? $this->resolve_by_id( $ref, $post_type )
				: $this->resolve_by_name( $ref, $post_type );

			if ( $post_id > 0 ) {
				$matched_ids[] = $post_id;
				continue;
			}

			// No match → record for review; NEVER create the directory post.
			$pending_id = 0;
			if ( ! $dry_run ) {
				$pending_id = $this->pending->add(
					[
						'beer_id'    => $beer_id,
						'beer_title' => $beer_name,
						'relation'   => $relation,
						'ref_type'   => $ref_type,
						'ref_value'  => $ref,
						'row_index'  => $row_number,
					]
				);
			}
			$result->add_unmatched( $row_number, $beer_id, $beer_name, $relation, $ref_type, $ref, $pending_id );
		}

		if ( empty( $matched_ids ) ) {
			return;
		}

		$matched_ids = array_values( array_unique( $matched_ids ) );

		$count = count( $matched_ids );
		if ( 'brewery' === $relation ) {
			$result->brewery_links += $count;
		} else {
			$result->venue_links += $count;
		}

		if ( ! $dry_run && $beer_id > 0 && function_exists( 'update_field' ) ) {
			// Native ACF bidirectional keeps the reverse side in sync.
			update_field( $field_key, $matched_ids, $beer_id );
		}
	}

	/**
	 * Resolve a canonical ID reference to an existing post of the target type.
	 * Returns the post id on success, 0 otherwise.
	 */
	private function resolve_by_id( string $ref, string $post_type ): int {
		$id = (int) trim( $ref );
		if ( $id <= 0 ) {
			return 0;
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== $post_type || 'trash' === $post->post_status ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Resolve a name reference via normalized exact match against the directory.
	 * Returns the post id on success, 0 otherwise.
	 */
	private function resolve_by_name( string $ref, string $post_type ): int {
		$key = $this->normalize( $ref );
		if ( '' === $key ) {
			return 0;
		}

		$map = $this->name_map( $post_type );

		return $map[ $key ] ?? 0;
	}

	/**
	 * Build (and cache) the normalized-name → id map for a directory post type.
	 *
	 * @return array<string,int>
	 */
	private function name_map( string $post_type ): array {
		if ( isset( $this->name_maps[ $post_type ] ) ) {
			return $this->name_maps[ $post_type ];
		}

		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$map = [];
		foreach ( $posts as $id ) {
			$key = $this->normalize( get_the_title( (int) $id ) );
			if ( '' === $key || isset( $map[ $key ] ) ) {
				// First-writer-wins on the rare duplicate title; both are valid
				// targets and the operator can reassign via the review queue.
				continue;
			}
			$map[ $key ] = (int) $id;
		}

		$this->name_maps[ $post_type ] = $map;

		return $map;
	}

	/**
	 * Set an ACF field, falling back to post meta if ACF is unavailable.
	 *
	 * @param mixed $value Field value.
	 */
	private function set_field( string $field_key, $value, int $post_id ): void {
		if ( function_exists( 'update_field' ) ) {
			update_field( $field_key, $value, $post_id );
			return;
		}

		update_post_meta( $post_id, $field_key, $value );
	}

	/**
	 * Split a multi-value cell on the delimiter, trimming and dropping blanks.
	 *
	 * @return array<int,string>
	 */
	private function split( string $value ): array {
		if ( '' === trim( $value ) ) {
			return [];
		}

		$parts = array_map( 'trim', explode( self::DELIMITER, $value ) );

		return array_values( array_filter( $parts, static fn ( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Normalize a name for exact matching: trim, collapse whitespace, lowercase.
	 */
	private function normalize( string $name ): string {
		$name = preg_replace( '/\s+/u', ' ', trim( $name ) ) ?? '';

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
	}

	/**
	 * Trim a scalar cell.
	 */
	private function clean( string $value ): string {
		return trim( $value );
	}

	/**
	 * Parse a numeric cell to float, or null when empty/non-numeric.
	 */
	private function numeric( string $value ): ?float {
		$value = trim( $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}
}
