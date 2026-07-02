<?php
/**
 * Structured result of an import run.
 *
 * Framework-agnostic value object returned by {@see Importer::import()}. The
 * WP-CLI command (WP-4) and the admin UI + review queue (WP-5) both consume
 * this shape; keep it serializable (see {@see self::to_array()}).
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Import;

/**
 * Immutable-ish tally of what an import did (or, for a dry run, would do).
 */
final class ImportResult {

	/**
	 * Whether this was a dry run (nothing was mutated).
	 */
	public bool $dry_run = false;

	/**
	 * Whether the annual reset (prior-beer clear) ran as part of this import.
	 */
	public bool $did_reset = false;

	/**
	 * How many prior `obw_beer` posts the reset deleted (or would delete).
	 */
	public int $reset_deleted = 0;

	/**
	 * Number of CSV rows processed (excludes rows skipped for a fatal error).
	 */
	public int $rows_total = 0;

	/**
	 * Number of `obw_beer` posts created (or that would be created).
	 */
	public int $rows_created = 0;

	/**
	 * Number of brewery relations linked across all beers.
	 */
	public int $brewery_links = 0;

	/**
	 * Number of venue relations linked across all beers.
	 */
	public int $venue_links = 0;

	/**
	 * Created beers, in row order.
	 *
	 * @var array<int,array{row:int,beer_id:int,title:string}>
	 */
	public array $beers = [];

	/**
	 * Unmatched brewery/venue references, with enough context to resolve later.
	 * Mirrors the pending-store records written during a live run.
	 *
	 * @var array<int,array{row:int,beer_id:int,beer_title:string,relation:string,ref_type:string,ref_value:string,pending_id:int}>
	 */
	public array $unmatched = [];

	/**
	 * Non-fatal problems (e.g. a row missing a beer name that was skipped).
	 *
	 * @var array<int,string>
	 */
	public array $errors = [];

	/**
	 * Record a created (or would-be-created) beer.
	 */
	public function add_beer( int $row, int $beer_id, string $title ): void {
		$this->beers[] = [
			'row'     => $row,
			'beer_id' => $beer_id,
			'title'   => $title,
		];
		++$this->rows_created;
	}

	/**
	 * Record an unmatched brewery/venue reference.
	 */
	public function add_unmatched(
		int $row,
		int $beer_id,
		string $beer_title,
		string $relation,
		string $ref_type,
		string $ref_value,
		int $pending_id = 0
	): void {
		$this->unmatched[] = [
			'row'        => $row,
			'beer_id'    => $beer_id,
			'beer_title' => $beer_title,
			'relation'   => $relation,
			'ref_type'   => $ref_type,
			'ref_value'  => $ref_value,
			'pending_id' => $pending_id,
		];
	}

	/**
	 * Record a non-fatal error.
	 */
	public function add_error( string $message ): void {
		$this->errors[] = $message;
	}

	/**
	 * Serialize for JSON / transient storage (WP-5).
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return [
			'dry_run'       => $this->dry_run,
			'did_reset'     => $this->did_reset,
			'reset_deleted' => $this->reset_deleted,
			'rows_total'    => $this->rows_total,
			'rows_created'  => $this->rows_created,
			'brewery_links' => $this->brewery_links,
			'venue_links'   => $this->venue_links,
			'beers'         => $this->beers,
			'unmatched'     => $this->unmatched,
			'errors'        => $this->errors,
		];
	}
}
