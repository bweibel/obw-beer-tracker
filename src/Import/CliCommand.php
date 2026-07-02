<?php
/**
 * WP-CLI command: `wp obw import`.
 *
 * Thin CLI layer over the framework-agnostic {@see Importer} service. Registered
 * only when WP-CLI is present (see {@see \OBW\BeerTracker\Plugin::init()}).
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Import;

/**
 * OBW beer-tracker CLI commands.
 */
final class CliCommand {

	/**
	 * Import the annual beer CSV: create beers and auto-link brewery/venue.
	 *
	 * Unmatched brewery/venue references are recorded in the pending-review
	 * store (never auto-created) and printed as a report.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file. Required columns: name. Optional: style, abv, ibu,
	 * untappd, description, brewery_id, brewery, venue_id, venue. Multi-value
	 * cells use "|".
	 *
	 * [--dry-run]
	 * : Parse and report what WOULD happen without mutating anything (no posts,
	 * no fields, no pending records, no reset).
	 *
	 * [--reset]
	 * : Annual reset — force-delete the ENTIRE prior obw_beer set and clear the
	 * pending store BEFORE importing. Never touches brewery/venue posts. This is
	 * the sanctioned "start new year" / idempotent re-import path.
	 *
	 * ## EXAMPLES
	 *
	 *     wp obw import beers-2026.csv --dry-run
	 *     wp obw import beers-2026.csv --reset
	 *
	 * @param array<int,string>    $args       Positional args ([0] => file).
	 * @param array<string,string> $assoc_args Flags.
	 *
	 * @when after_wp_load
	 */
	public function import( array $args, array $assoc_args ): void {
		$file    = $args[0] ?? '';
		$dry_run = isset( $assoc_args['dry-run'] );
		$reset   = isset( $assoc_args['reset'] );

		if ( '' === $file ) {
			\WP_CLI::error( 'Provide a path to the CSV file.' );
		}

		try {
			$rows = CsvReader::from_file( $file );
		} catch ( \RuntimeException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}

		if ( empty( $rows ) ) {
			\WP_CLI::warning( 'No data rows found in CSV.' );
		}

		$importer = new Importer( new PendingStore() );
		$result   = $importer->import( $rows, $dry_run, $reset );

		$this->report( $result );
	}

	/**
	 * Print the summary + unmatched report.
	 */
	private function report( ImportResult $result ): void {
		if ( $result->dry_run ) {
			\WP_CLI::log( \WP_CLI::colorize( '%Y[DRY RUN] nothing was mutated.%n' ) );
		}

		if ( $result->did_reset ) {
			\WP_CLI::log(
				sprintf(
					'Annual reset: %d prior beer(s) %s.',
					$result->reset_deleted,
					$result->dry_run ? 'would be deleted' : 'deleted'
				)
			);
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Import summary' );
		\WP_CLI::log( '--------------' );
		\WP_CLI::log( sprintf( '  Rows processed : %d', $result->rows_total ) );
		\WP_CLI::log( sprintf( '  Beers created  : %d', $result->rows_created ) );
		\WP_CLI::log( sprintf( '  Brewery links  : %d', $result->brewery_links ) );
		\WP_CLI::log( sprintf( '  Venue links    : %d', $result->venue_links ) );
		\WP_CLI::log( sprintf( '  Needs review   : %d', count( $result->unmatched ) ) );
		\WP_CLI::log( sprintf( '  Row errors     : %d', count( $result->errors ) ) );

		foreach ( $result->errors as $error ) {
			\WP_CLI::warning( $error );
		}

		if ( ! empty( $result->unmatched ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Unmatched (recorded for review, NOT created):' );
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static fn ( array $u ): array => [
						'row'       => $u['row'],
						'beer'      => $u['beer_title'],
						'relation'  => $u['relation'],
						'ref_type'  => $u['ref_type'],
						'ref_value' => $u['ref_value'],
					],
					$result->unmatched
				),
				[ 'row', 'beer', 'relation', 'ref_type', 'ref_value' ]
			);
		}

		\WP_CLI::success(
			$result->dry_run
				? 'Dry run complete.'
				: sprintf( '%d beer(s) imported.', $result->rows_created )
		);
	}
}
