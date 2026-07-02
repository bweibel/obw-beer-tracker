<?php
/**
 * CSV → row-array reader.
 *
 * Small framework-agnostic helper that turns a CSV file into the list of
 * associative rows {@see Importer::import()} expects. Header order is
 * irrelevant: the first line is treated as the header and every column name is
 * lower-cased and trimmed so `Brewery_ID`, `brewery_id`, and ` brewery_id `
 * all map to the same key. Both the WP-CLI command and the WP-5 upload UI feed
 * their file through this.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Import;

/**
 * Reads a CSV file into normalized associative rows.
 */
final class CsvReader {

	/**
	 * Parse a CSV file into a list of associative rows keyed by normalized
	 * (lower-cased, trimmed) header names.
	 *
	 * @param string $path Absolute path to a readable CSV file.
	 * @return array<int,array<string,string>>
	 *
	 * @throws \RuntimeException When the file cannot be read or has no header.
	 */
	public static function from_file( string $path ): array {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( sprintf( 'CSV file not readable: %s', $path ) );
		}

		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			throw new \RuntimeException( sprintf( 'Could not open CSV file: %s', $path ) );
		}

		try {
			$header = fgetcsv( $handle );
			if ( false === $header || null === $header ) {
				throw new \RuntimeException( 'CSV file is empty (no header row).' );
			}

			// Strip a UTF-8 BOM from the first header cell if present.
			if ( isset( $header[0] ) ) {
				$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
			}

			$keys = array_map(
				static fn ( $col ): string => strtolower( trim( (string) $col ) ),
				$header
			);

			$rows = [];
			while ( false !== ( $data = fgetcsv( $handle ) ) ) {
				// Skip fully blank lines.
				if ( null === $data || ( 1 === count( $data ) && ( null === $data[0] || '' === trim( (string) $data[0] ) ) ) ) {
					continue;
				}

				$row = [];
				foreach ( $keys as $i => $key ) {
					if ( '' === $key ) {
						continue;
					}
					$row[ $key ] = isset( $data[ $i ] ) ? (string) $data[ $i ] : '';
				}
				$rows[] = $row;
			}

			return $rows;
		} finally {
			fclose( $handle );
		}
	}
}
