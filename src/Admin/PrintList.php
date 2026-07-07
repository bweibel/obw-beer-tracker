<?php
/**
 * Printable beer list (POC).
 *
 * A wp-admin surface that renders the same published beers the finder shows,
 * but as a print-optimized one-page-front-and-back tasting sheet organized in
 * tiers: venue → brewery → beer. Each beer row is a pen checkbox, the beer name
 * (bold), its style, and its ABV.
 *
 * Two pieces:
 *
 *   1. An admin screen ("Print List" under Brews) that shows the render stats
 *      (beer records, printed rows, venues) and a DROPPED-BEER LOG — every beer
 *      excluded because it is missing a venue and/or a brewery relation, so an
 *      operator can fix the data. This screen does NOT print; it links to:
 *   2. A chrome-free print view (an `admin_post_` handler that emits a complete
 *      standalone HTML document with inline print CSS and exits) that the
 *      operator prints from the browser (Ctrl-P).
 *
 * Data is read through {@see \OBW\BeerTracker\Shaping} — the same reduction the
 * finder REST payload uses — so the two presentations can never drift.
 *
 * Because beer↔venue and beer↔brewery are both many-to-many, a beer poured at
 * several venues is intentionally listed under each of them (the sheet is
 * organized for someone standing at a venue). "Printed rows" therefore counts
 * beer *placements*, not distinct beers — it is the number that must fit two
 * sides of Letter, and it is surfaced on the admin screen for exactly that
 * reason.
 *
 * Security: capability `manage_options` gates the screen AND the print handler;
 * the print link carries a nonce; all output is escaped.
 *
 * NOTE (POC): page header/footer and the sidebar ad are deliberately left as
 * empty, labelled placeholders — layout detail is a follow-up pass against real
 * test data.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Admin;

use OBW\BeerTracker\Shaping;

/**
 * Registers and renders the printable beer list admin screen + print view.
 */
final class PrintList {

	/**
	 * Capability required for the screen and the print handler.
	 */
	private const CAP = 'manage_options';

	/**
	 * Parent menu: the Brews (obw_beer) CPT menu.
	 */
	private const PARENT = 'edit.php?post_type=obw_beer';

	/**
	 * This screen's menu slug.
	 */
	private const SLUG = 'obw-beer-print';

	/**
	 * admin_post action (and nonce action) for the print view.
	 */
	private const ACTION_PRINT = 'obw_print_list';

	/**
	 * Number of newspaper columns the print sheet flows into. Previous years fit
	 * ~300 rows across five columns on two sides comfortably; tuned later.
	 */
	private const PRINT_COLUMNS = 5;

	// --- Beer forward-relation ACF field names (WP-2 contract) ----------------
	private const F_BREWERY = 'brewery_link';
	private const F_VENUE   = 'venue_link';

	/**
	 * Wire hooks. Called from Plugin::init() only in admin context.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_' . self::ACTION_PRINT, [ $this, 'handle_print' ] );
	}

	/**
	 * Register the submenu page under Brews.
	 */
	public function add_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Print List', 'obw-beer-tracker' ),
			__( 'Print List', 'obw-beer-tracker' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Admin screen (stats + dropped-beer log). Does not print.
	// =========================================================================

	/**
	 * The admin screen: render stats and the dropped-beer log, plus a button that
	 * opens the chrome-free print view in a new tab.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'obw-beer-tracker' ) );
		}

		$build = $this->build();
		$stats = $build['stats'];

		$print_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_PRINT ),
			self::ACTION_PRINT
		);

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'OBW Printable Beer List', 'obw-beer-tracker' ) );

		printf(
			'<p><a class="button button-primary" href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( $print_url ),
			esc_html__( 'Open printable list (new tab)', 'obw-beer-tracker' )
		);

		// Render stats — printed rows is the number that must fit two sides.
		echo '<h2>' . esc_html__( 'Render summary', 'obw-beer-tracker' ) . '</h2>';
		echo '<ul style="list-style:disc;margin-left:20px">';
		printf( '<li>%s: %d</li>', esc_html__( 'Published beers', 'obw-beer-tracker' ), (int) $stats['beers'] );
		printf(
			'<li><strong>%s: %d</strong> %s</li>',
			esc_html__( 'Printed rows (beers × venues)', 'obw-beer-tracker' ),
			(int) $stats['rows'],
			esc_html__( '— this is what must fit two sides.', 'obw-beer-tracker' )
		);
		printf( '<li>%s: %d</li>', esc_html__( 'Venues on the sheet', 'obw-beer-tracker' ), (int) $stats['venues'] );
		printf( '<li>%s: %d</li>', esc_html__( 'Dropped beers (see below)', 'obw-beer-tracker' ), count( $build['dropped'] ) );
		echo '</ul>';

		$this->render_dropped_log( $build['dropped'] );

		echo '</div>';
	}

	/**
	 * Render the dropped-beer log so an operator can fix the source data.
	 *
	 * @param array<int,array{name:string,reason:string,id:int}> $dropped Dropped beers.
	 */
	private function render_dropped_log( array $dropped ): void {
		echo '<h2>' . esc_html__( 'Dropped beers (missing venue and/or brewery)', 'obw-beer-tracker' ) . '</h2>';

		if ( empty( $dropped ) ) {
			echo '<p>' . esc_html__( 'None — every published beer has both a venue and a brewery.', 'obw-beer-tracker' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'These beers are excluded from the print sheet because the tier they belong under is missing. Fix the relation on the beer to include it.', 'obw-beer-tracker' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
		echo '<th>' . esc_html__( 'Beer', 'obw-beer-tracker' ) . '</th>';
		echo '<th>' . esc_html__( 'Missing', 'obw-beer-tracker' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $dropped as $row ) {
			$edit = get_edit_post_link( $row['id'] );
			$name = '' !== $row['name'] ? $row['name'] : __( '(untitled)', 'obw-beer-tracker' );
			printf(
				'<tr><td>%s</td><td>%s</td></tr>',
				$edit
					? sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html( $name ) )
					: esc_html( $name ),
				esc_html( $row['reason'] )
			);
		}

		echo '</tbody></table>';
	}

	// =========================================================================
	// Print view (chrome-free standalone document).
	// =========================================================================

	/**
	 * Emit the print-optimized standalone HTML document and exit.
	 */
	public function handle_print(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'obw-beer-tracker' ) );
		}
		check_admin_referer( self::ACTION_PRINT );

		$build  = $this->build();
		$venues = $build['venues'];

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		echo "<!doctype html>\n";
		echo '<html><head><meta charset="utf-8" />';
		printf( '<title>%s</title>', esc_html__( 'OBW Beer List', 'obw-beer-tracker' ) );
		echo '<meta name="viewport" content="width=device-width, initial-scale=1" />';
		$this->print_styles();
		echo '</head><body>';

		// POC placeholders — layout detail is a follow-up pass.
		echo '<header class="obwpl-header">' . esc_html__( '[ header placeholder ]', 'obw-beer-tracker' ) . '</header>';
		echo '<div class="obwpl-body">';
		echo '<aside class="obwpl-ad">' . esc_html__( '[ ad ]', 'obw-beer-tracker' ) . '</aside>';

		echo '<button class="obwpl-print-btn no-print" onclick="window.print()">' . esc_html__( 'Print', 'obw-beer-tracker' ) . '</button>';

		echo '<main class="obwpl-columns">';
		if ( empty( $venues ) ) {
			echo '<p>' . esc_html__( 'No beers with both a venue and a brewery to print.', 'obw-beer-tracker' ) . '</p>';
		} else {
			foreach ( $venues as $venue ) {
				$this->render_venue( $venue );
			}
		}
		echo '</main>';

		echo '</div>'; // .obwpl-body
		echo '<footer class="obwpl-footer">' . esc_html__( '[ footer placeholder ]', 'obw-beer-tracker' ) . '</footer>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Render one venue block (venue heading → brewery sub-blocks → beer rows).
	 *
	 * @param array<string,mixed> $venue Venue tree node.
	 */
	private function render_venue( array $venue ): void {
		echo '<section class="obwpl-venue">';
		printf( '<h2 class="obwpl-venue-title">%s</h2>', esc_html( (string) $venue['title'] ) );

		foreach ( $venue['breweries'] as $brewery ) {
			echo '<div class="obwpl-brewery">';
			printf( '<h3 class="obwpl-brewery-title">%s</h3>', esc_html( (string) $brewery['title'] ) );

			foreach ( $brewery['beers'] as $beer ) {
				$this->render_beer_row( $beer );
			}
			echo '</div>';
		}
		echo '</section>';
	}

	/**
	 * Render a single beer row: checkbox, bold name, style, ABV.
	 *
	 * @param array{name:string,style:string,abv:?float} $beer Beer row.
	 */
	private function render_beer_row( array $beer ): void {
		$parts = [ sprintf( '<strong class="obwpl-beer-name">%s</strong>', esc_html( $beer['name'] ) ) ];

		if ( '' !== $beer['style'] ) {
			$parts[] = sprintf( '<span class="obwpl-beer-style">%s</span>', esc_html( $beer['style'] ) );
		}
		if ( null !== $beer['abv'] ) {
			$parts[] = sprintf(
				'<span class="obwpl-beer-abv">%s%%</span>',
				esc_html( rtrim( rtrim( number_format( $beer['abv'], 1 ), '0' ), '.' ) )
			);
		}

		printf(
			'<div class="obwpl-row"><span class="obwpl-check" aria-hidden="true"></span> %s</div>',
			implode( ' <span class="obwpl-sep">&middot;</span> ', $parts )
		);
	}

	// =========================================================================
	// Data assembly (via Shaping — no drift from the finder).
	// =========================================================================

	/**
	 * Build the venue → brewery → beer tree plus the dropped-beer log and stats.
	 *
	 * @return array{
	 *   venues: array<int,array{title:string,breweries:array<int,array{title:string,beers:array<int,array{name:string,style:string,abv:?float}>}>}>,
	 *   dropped: array<int,array{name:string,reason:string,id:int}>,
	 *   stats: array{beers:int,rows:int,venues:int}
	 * }
	 */
	private function build(): array {
		$beers = get_posts(
			[
				'post_type'      => 'obw_beer',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		$venues  = [];
		$dropped = [];
		$rows    = 0;

		foreach ( $beers as $beer ) {
			$venue_rel   = Shaping::relation( $beer->ID, self::F_VENUE );
			$brewery_rel = Shaping::relation( $beer->ID, self::F_BREWERY );

			$reason = $this->drop_reason( ! empty( $venue_rel ), ! empty( $brewery_rel ) );
			if ( null !== $reason ) {
				$dropped[] = [
					'id'     => (int) $beer->ID,
					'name'   => (string) $beer->post_title,
					'reason' => $reason,
				];
				continue;
			}

			$row = [
				'name'  => (string) $beer->post_title,
				'style' => (string) ( Shaping::scalar( $beer->ID, 'style' ) ?? '' ),
				'abv'   => Shaping::number( $beer->ID, 'abv' ),
			];

			// Place the beer under every (venue, brewery) pair it belongs to.
			foreach ( $venue_rel as $venue ) {
				$vid = (int) $venue['ID'];
				if ( ! isset( $venues[ $vid ] ) ) {
					$venues[ $vid ] = [
						'title'     => (string) $venue['post_title'],
						'breweries' => [],
					];
				}

				foreach ( $brewery_rel as $brewery ) {
					$bid = (int) $brewery['ID'];
					if ( ! isset( $venues[ $vid ]['breweries'][ $bid ] ) ) {
						$venues[ $vid ]['breweries'][ $bid ] = [
							'title' => (string) $brewery['post_title'],
							'beers' => [],
						];
					}
					$venues[ $vid ]['breweries'][ $bid ]['beers'][] = $row;
					++$rows;
				}
			}
		}

		$this->sort_tree( $venues );

		return [
			'venues'  => $venues,
			'dropped' => $dropped,
			'stats'   => [
				'beers'  => count( $beers ),
				'rows'   => $rows,
				'venues' => count( $venues ),
			],
		];
	}

	/**
	 * Describe why a beer is dropped, or null if it has both tiers.
	 */
	private function drop_reason( bool $has_venue, bool $has_brewery ): ?string {
		if ( ! $has_venue && ! $has_brewery ) {
			return __( 'venue and brewery', 'obw-beer-tracker' );
		}
		if ( ! $has_venue ) {
			return __( 'venue', 'obw-beer-tracker' );
		}
		if ( ! $has_brewery ) {
			return __( 'brewery', 'obw-beer-tracker' );
		}
		return null;
	}

	/**
	 * Sort the tree A→Z at every tier: venues, breweries within a venue, and
	 * beers within a brewery (all by title, case-insensitive).
	 *
	 * @param array<int,array<string,mixed>> $venues Tree (by reference).
	 */
	private function sort_tree( array &$venues ): void {
		$by_title = static fn ( array $a, array $b ): int => strcasecmp( (string) $a['title'], (string) $b['title'] );

		uasort( $venues, $by_title );
		foreach ( $venues as &$venue ) {
			uasort( $venue['breweries'], $by_title );
			foreach ( $venue['breweries'] as &$brewery ) {
				usort(
					$brewery['beers'],
					static fn ( array $a, array $b ): int => strcasecmp( $a['name'], $b['name'] )
				);
			}
			unset( $brewery );
		}
		unset( $venue );
	}

	// =========================================================================
	// Print CSS
	// =========================================================================

	/**
	 * Inline the print stylesheet. Minimal, structural, POC-grade: five-column
	 * flow, tight type, tier hierarchy, a pen checkbox, two-sided Letter target.
	 */
	private function print_styles(): void {
		$columns = (int) self::PRINT_COLUMNS;
		?>
		<style>
			@page { size: letter; margin: 0.4in; }
			* { box-sizing: border-box; }
			body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; }

			.obwpl-header, .obwpl-footer {
				padding: 4px 8px; font-size: 8pt; color: #666;
				border: 1px dashed #ccc; text-align: center;
			}
			.obwpl-ad {
				float: right; width: 120px; min-height: 120px; margin: 0 0 8px 8px;
				padding: 4px; font-size: 8pt; color: #666;
				border: 1px dashed #ccc; text-align: center;
			}

			.obwpl-print-btn {
				margin: 8px 0; padding: 6px 14px; font-size: 11pt; cursor: pointer;
			}

			.obwpl-columns {
				column-count: <?php echo $columns; ?>;
				column-gap: 12px;
				font-size: 7pt;
				line-height: 1.25;
			}

			.obwpl-venue { break-inside: avoid-column; margin: 0 0 6px; }
			.obwpl-venue-title {
				font-size: 9pt; margin: 4px 0 2px; padding-bottom: 1px;
				border-bottom: 1px solid #000; break-after: avoid;
			}
			.obwpl-brewery { break-inside: avoid-column; margin: 0 0 3px; }
			.obwpl-brewery-title {
				font-size: 7.5pt; margin: 2px 0 1px; break-after: avoid;
			}

			.obwpl-row { margin: 0 0 1px; padding-left: 12px; text-indent: -12px; }
			.obwpl-check {
				display: inline-block; width: 8px; height: 8px;
				border: 1px solid #000; vertical-align: middle; margin-right: 3px;
			}
			.obwpl-beer-name { font-weight: 700; }
			.obwpl-beer-style, .obwpl-beer-abv, .obwpl-sep { color: #333; }

			@media print {
				.no-print { display: none !important; }
				.obwpl-header, .obwpl-footer, .obwpl-ad { border-style: solid; border-color: #999; }
			}
		</style>
		<?php
	}
}
