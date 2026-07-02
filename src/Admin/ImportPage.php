<?php
/**
 * Importer admin UI + review queue (WP-5).
 *
 * Adds an "Import Beers" page under the Brews (obw_beer) menu. The page lets a
 * non-CLI operator:
 *
 *   1. Upload the annual beer CSV → run {@see CsvReader::from_file()} then
 *      {@see Importer::import()} → see a summary (created / linked / needs-review).
 *   2. Optionally run the deliberate, confirmed "start new year" annual reset
 *      (maps to Importer::import(..., reset:true) — force-deletes the prior beer
 *      set + clears the pending store). This is opt-in, never the default.
 *   3. Work the REVIEW QUEUE: every unmatched brewery/venue reference the
 *      importer recorded (never auto-created) is listed here. Per row the
 *      operator can either
 *        (a) MAP the reference to an EXISTING brewery/venue (search/select) and
 *            link it, or
 *        (b) CREATE a FULL brewery/venue profile (title + ACF fields) and link
 *            it — the ONLY sanctioned brewery/venue creation path.
 *      Either way the beer↔brewery / beer↔venue relation is set via the ACF Pro
 *      native bidirectional field, so the reverse side is kept in sync by ACF.
 *
 * Security: capability `manage_options` gates the page AND every mutating
 * handler; every mutating action carries a nonce; all input is sanitized and all
 * output escaped; uploads are validated for type + size and cleaned up.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Admin;

use OBW\BeerTracker\Import\CsvReader;
use OBW\BeerTracker\Import\Importer;
use OBW\BeerTracker\Import\ImportResult;
use OBW\BeerTracker\Import\PendingStore;

/**
 * Registers and renders the importer admin page + review queue.
 */
final class ImportPage {

	/**
	 * Capability required for the page and every handler (resolved decision).
	 */
	private const CAP = 'manage_options';

	/**
	 * Parent menu: the Brews (obw_beer) CPT menu.
	 */
	private const PARENT = 'edit.php?post_type=obw_beer';

	/**
	 * This page's menu slug.
	 */
	private const SLUG = 'obw-beer-import';

	/**
	 * Max upload size accepted for the CSV (bytes).
	 */
	private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB.

	// --- Nonce actions --------------------------------------------------------
	private const NONCE_RUN     = 'obw_import_run';
	private const NONCE_MAP     = 'obw_import_resolve_map';
	private const NONCE_CREATE  = 'obw_import_resolve_create';
	private const NONCE_IGNORE  = 'obw_import_ignore';

	// --- Beer relationship ACF field keys (WP-2 contract) ---------------------
	private const F_BEER_BREWERY = 'field_5965323de28fc';
	private const F_BEER_VENUE   = 'field_596538519f8af';

	// --- Brewery profile ACF field keys ---------------------------------------
	private const F_BREWERY_LOGO    = 'field_572cfa02d8f61';
	private const F_BREWERY_ADDRESS = 'field_5717e401dd1bf';
	private const F_BREWERY_WEBSITE = 'field_5717e441dd1c1';

	// --- Venue profile ACF field keys -----------------------------------------
	private const F_VENUE_LOGO     = 'field_57478a0434c7d';
	private const F_VENUE_LOCATION = 'field_574789f534c7c';
	private const F_VENUE_WEBSITE  = 'field_574789e634c7b';
	private const F_VENUE_HOURS    = 'field_57478a0f34c7e';

	public function __construct( private PendingStore $pending ) {}

	/**
	 * Wire hooks. Called from Plugin::init() only in admin context.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_' . self::NONCE_RUN, [ $this, 'handle_run' ] );
		add_action( 'admin_post_' . self::NONCE_MAP, [ $this, 'handle_resolve_map' ] );
		add_action( 'admin_post_' . self::NONCE_CREATE, [ $this, 'handle_resolve_create' ] );
		add_action( 'admin_post_' . self::NONCE_IGNORE, [ $this, 'handle_ignore' ] );
	}

	/**
	 * Register the submenu page under Brews.
	 */
	public function add_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Import Beers', 'obw-beer-tracker' ),
			__( 'Import Beers', 'obw-beer-tracker' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	// =========================================================================
	// Rendering
	// =========================================================================

	/**
	 * Page controller: renders either the Import tab or the Review Queue tab.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'obw-beer-tracker' ) );
		}

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'import';

		$pending_count = $this->pending->count( 'pending' );

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'OBW Beer Importer', 'obw-beer-tracker' ) );

		$this->render_admin_notice();
		$this->render_tabs( $view, $pending_count );

		if ( 'review' === $view ) {
			$this->render_review_tab();
		} else {
			$this->render_import_tab();
		}

		echo '</div>';
	}

	/**
	 * The two-tab nav.
	 */
	private function render_tabs( string $view, int $pending_count ): void {
		$import_url = esc_url( $this->page_url() );
		$review_url = esc_url( $this->page_url( [ 'view' => 'review' ] ) );

		echo '<h2 class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			$import_url, // Already escaped.
			'import' === $view ? 'nav-tab-active' : '',
			esc_html__( 'Import', 'obw-beer-tracker' )
		);
		printf(
			'<a href="%s" class="nav-tab %s">%s <span class="count">(%d)</span></a>',
			$review_url, // Already escaped.
			'review' === $view ? 'nav-tab-active' : '',
			esc_html__( 'Review Queue', 'obw-beer-tracker' ),
			(int) $pending_count
		);
		echo '</h2>';
	}

	/**
	 * Import tab: upload form + last-run summary.
	 */
	private function render_import_tab(): void {
		$result = $this->take_summary();

		if ( null !== $result ) {
			$this->render_summary( $result );
		}
		?>
		<h2><?php esc_html_e( 'Upload beer CSV', 'obw-beer-tracker' ); ?></h2>
		<p class="description">
			<?php
			esc_html_e(
				'Columns: name (required); style, abv, ibu, untappd, description, brewery_id, brewery, venue_id, venue (optional). Multi-value cells use "|". Breweries/venues are matched by ID then exact name; unmatched references go to the Review Queue and are never auto-created.',
				'obw-beer-tracker'
			);
			?>
		</p>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE_RUN ); ?>" />
			<?php wp_nonce_field( self::NONCE_RUN ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="obw_csv"><?php esc_html_e( 'CSV file', 'obw-beer-tracker' ); ?></label></th>
					<td><input type="file" name="obw_csv" id="obw_csv" accept=".csv,text/csv,text/plain" required /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Dry run', 'obw-beer-tracker' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="dry_run" value="1" />
							<?php esc_html_e( 'Preview only — parse and report, mutate nothing.', 'obw-beer-tracker' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Start a new year', 'obw-beer-tracker' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="reset" id="obw_reset" value="1" />
							<strong><?php esc_html_e( 'Annual reset: permanently delete ALL existing beers and clear the review queue before importing.', 'obw-beer-tracker' ); ?></strong>
						</label>
						<p class="description">
							<?php esc_html_e( 'Breweries and venues are never touched. This cannot be undone. You will be asked to confirm on submit.', 'obw-beer-tracker' ); ?>
						</p>
						<p>
							<label>
								<input type="checkbox" name="reset_confirm" value="yes" />
								<?php esc_html_e( 'I understand the annual reset deletes all current beers.', 'obw-beer-tracker' ); ?>
							</label>
						</p>
					</td>
				</tr>
			</table>

			<?php
			submit_button( __( 'Run import', 'obw-beer-tracker' ) );
			?>
		</form>
		<script>
		// Deliberate, explicit confirmation for the destructive annual reset.
		document.querySelector('form[enctype]')?.addEventListener('submit', function (e) {
			var reset = document.getElementById('obw_reset');
			if (reset && reset.checked) {
				if (!window.confirm(<?php echo wp_json_encode( __( 'Annual reset will PERMANENTLY DELETE all existing beers and clear the review queue. Continue?', 'obw-beer-tracker' ) ); ?>)) {
					e.preventDefault();
				}
			}
		});
		</script>
		<?php
	}

	/**
	 * Render an import result summary block.
	 */
	private function render_summary( ImportResult $result ): void {
		$data = $result->to_array();
		echo '<div class="notice notice-info"><div style="padding:6px 12px">';
		printf( '<h2 style="margin-top:8px">%s</h2>', esc_html__( 'Last import summary', 'obw-beer-tracker' ) );

		if ( $data['dry_run'] ) {
			printf( '<p><strong>%s</strong></p>', esc_html__( 'DRY RUN — nothing was changed.', 'obw-beer-tracker' ) );
		}
		if ( $data['did_reset'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of beers. */
						_n( 'Annual reset: %d prior beer removed.', 'Annual reset: %d prior beers removed.', (int) $data['reset_deleted'], 'obw-beer-tracker' ),
						(int) $data['reset_deleted']
					)
				)
			);
		}

		echo '<ul style="list-style:disc;margin-left:20px">';
		printf( '<li>%s: %d</li>', esc_html__( 'Rows processed', 'obw-beer-tracker' ), (int) $data['rows_total'] );
		printf( '<li>%s: %d</li>', esc_html__( 'Beers created', 'obw-beer-tracker' ), (int) $data['rows_created'] );
		printf( '<li>%s: %d</li>', esc_html__( 'Brewery links', 'obw-beer-tracker' ), (int) $data['brewery_links'] );
		printf( '<li>%s: %d</li>', esc_html__( 'Venue links', 'obw-beer-tracker' ), (int) $data['venue_links'] );
		printf(
			'<li><strong>%s: %d</strong> — <a href="%s">%s</a></li>',
			esc_html__( 'Needs review', 'obw-beer-tracker' ),
			count( $data['unmatched'] ),
			esc_url( $this->page_url( [ 'view' => 'review' ] ) ),
			esc_html__( 'open the Review Queue', 'obw-beer-tracker' )
		);
		printf( '<li>%s: %d</li>', esc_html__( 'Row errors', 'obw-beer-tracker' ), count( $data['errors'] ) );
		echo '</ul>';

		if ( ! empty( $data['errors'] ) ) {
			echo '<p><strong>' . esc_html__( 'Errors:', 'obw-beer-tracker' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $data['errors'] as $error ) {
				printf( '<li>%s</li>', esc_html( (string) $error ) );
			}
			echo '</ul>';
		}

		echo '</div></div>';
	}

	/**
	 * Review Queue tab: one card per pending row with map / create / ignore.
	 */
	private function render_review_tab(): void {
		$rows = $this->pending->all( 'pending' );

		echo '<h2>' . esc_html__( 'Review Queue', 'obw-beer-tracker' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Nothing to review. Every imported beer was linked automatically.', 'obw-beer-tracker' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Each row is a brewery/venue reference the importer could not match. Map it to an existing directory entry, or create a full profile — then it links to the beer with a two-way relationship.', 'obw-beer-tracker' ) . '</p>';

		foreach ( $rows as $row ) {
			$this->render_review_row( $row );
		}
	}

	/**
	 * Render a single pending row's resolution controls.
	 *
	 * @param array<string,mixed> $row Pending-store record.
	 */
	private function render_review_row( array $row ): void {
		$id          = (int) $row['id'];
		$beer_id     = (int) $row['beer_id'];
		$beer_title  = (string) $row['beer_title'];
		$relation    = (string) $row['relation']; // 'brewery' | 'venue'.
		$ref_type    = (string) $row['ref_type'];
		$ref_value   = (string) $row['ref_value'];
		$post_type   = 'brewery' === $relation ? 'obw_brewery' : 'obw_venue';
		$noun        = 'brewery' === $relation ? __( 'brewery', 'obw-beer-tracker' ) : __( 'venue', 'obw-beer-tracker' );
		$beer_exists = $beer_id > 0 && get_post_status( $beer_id );

		echo '<div class="card" style="max-width:none;margin:12px 0;padding:12px 16px">';

		printf(
			'<p style="margin:0 0 8px"><strong>%s</strong> — %s <code>%s</code>%s</p>',
			esc_html( $beer_title ),
			esc_html(
				sprintf(
					/* translators: 1: relation noun (brewery/venue), 2: reference type (id/name). */
					__( 'unmatched %1$s (%2$s):', 'obw-beer-tracker' ),
					$noun,
					$ref_type
				)
			),
			esc_html( $ref_value ),
			$beer_exists ? '' : ' <em>' . esc_html__( '(beer post missing — resolving will only ignore this row)', 'obw-beer-tracker' ) . '</em>'
		);

		echo '<div style="display:flex;gap:32px;flex-wrap:wrap">';

		// --- (a) Map to existing --------------------------------------------
		echo '<div>';
		echo '<h4 style="margin:0 0 6px">' . esc_html__( 'Map to existing', 'obw-beer-tracker' ) . '</h4>';
		$this->render_map_form( $id, $relation, $post_type, $ref_value );
		echo '</div>';

		// --- (b) Create full profile ----------------------------------------
		echo '<div>';
		echo '<h4 style="margin:0 0 6px">' . esc_html__( 'Or create a new profile', 'obw-beer-tracker' ) . '</h4>';
		$this->render_create_form( $id, $relation, $ref_type, $ref_value );
		echo '</div>';

		// --- Ignore ----------------------------------------------------------
		echo '<div>';
		echo '<h4 style="margin:0 0 6px">' . esc_html__( 'Or dismiss', 'obw-beer-tracker' ) . '</h4>';
		$this->render_ignore_form( $id );
		echo '</div>';

		echo '</div></div>';
	}

	/**
	 * Map-to-existing form: a select of every directory post of the type.
	 */
	private function render_map_form( int $pending_id, string $relation, string $post_type, string $ref_value ): void {
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_MAP ) . '" />';
		echo '<input type="hidden" name="pending_id" value="' . esc_attr( (string) $pending_id ) . '" />';
		wp_nonce_field( self::NONCE_MAP . '_' . $pending_id );

		echo '<select name="target_id" required>';
		echo '<option value="">' . esc_html__( '— select —', 'obw-beer-tracker' ) . '</option>';
		$want = $this->normalize( $ref_value );
		foreach ( $posts as $pid ) {
			$title    = get_the_title( (int) $pid );
			$selected = ( '' !== $want && $this->normalize( $title ) === $want ) ? ' selected' : '';
			printf(
				'<option value="%d"%s>%s (#%d)</option>',
				(int) $pid,
				$selected, // Static ' selected' literal.
				esc_html( $title ),
				(int) $pid
			);
		}
		echo '</select> ';
		submit_button( __( 'Map & link', 'obw-beer-tracker' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Create-full-profile form. Fields differ per relation type.
	 */
	private function render_create_form( int $pending_id, string $relation, string $ref_type, string $ref_value ): void {
		$prefill = 'name' === $ref_type ? $ref_value : '';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_CREATE ) . '" />';
		echo '<input type="hidden" name="pending_id" value="' . esc_attr( (string) $pending_id ) . '" />';
		wp_nonce_field( self::NONCE_CREATE . '_' . $pending_id );

		echo '<p><label>' . esc_html__( 'Title', 'obw-beer-tracker' ) . '<br />';
		printf( '<input type="text" name="title" value="%s" required style="width:260px" /></label></p>', esc_attr( $prefill ) );

		echo '<p><label>' . esc_html__( 'Website', 'obw-beer-tracker' ) . '<br />';
		echo '<input type="url" name="website" placeholder="https://" style="width:260px" /></label></p>';

		if ( 'brewery' === $relation ) {
			echo '<p><label>' . esc_html__( 'Address', 'obw-beer-tracker' ) . '<br />';
			echo '<input type="text" name="address" style="width:260px" /></label></p>';
		} else {
			echo '<p><label>' . esc_html__( 'Location / address', 'obw-beer-tracker' ) . '<br />';
			echo '<input type="text" name="location" style="width:260px" /></label></p>';
			echo '<p><label>' . esc_html__( 'Hours', 'obw-beer-tracker' ) . '<br />';
			echo '<textarea name="hours" rows="2" style="width:260px"></textarea></label></p>';
		}

		echo '<p><label>' . esc_html__( 'Logo image', 'obw-beer-tracker' ) . '<br />';
		echo '<input type="file" name="logo" accept="image/*" /></label></p>';

		submit_button( __( 'Create & link', 'obw-beer-tracker' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Ignore/dismiss form.
	 */
	private function render_ignore_form( int $pending_id ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_IGNORE ) . '" />';
		echo '<input type="hidden" name="pending_id" value="' . esc_attr( (string) $pending_id ) . '" />';
		wp_nonce_field( self::NONCE_IGNORE . '_' . $pending_id );
		submit_button( __( 'Ignore', 'obw-beer-tracker' ), 'delete', 'submit', false );
		echo '</form>';
	}

	// =========================================================================
	// Handlers (all: capability + nonce, PRG redirect back)
	// =========================================================================

	/**
	 * Handle the CSV upload + import run.
	 */
	public function handle_run(): void {
		$this->guard( self::NONCE_RUN );

		if ( empty( $_FILES['obw_csv'] ) || ! isset( $_FILES['obw_csv']['name'] ) ) {
			$this->redirect_back( [], 'no_file' );
		}

		$dry_run = ! empty( $_POST['dry_run'] );
		$reset   = ! empty( $_POST['reset'] );

		// The destructive reset requires a matching explicit confirmation.
		if ( $reset && ( empty( $_POST['reset_confirm'] ) || 'yes' !== sanitize_text_field( wp_unslash( $_POST['reset_confirm'] ) ) ) ) {
			$this->redirect_back( [], 'reset_unconfirmed' );
		}

		$path = $this->accept_upload( 'obw_csv' );
		if ( null === $path ) {
			$this->redirect_back( [], 'bad_upload' );
		}

		try {
			$rows = CsvReader::from_file( $path );
		} catch ( \RuntimeException $e ) {
			$this->cleanup( $path );
			$this->redirect_back( [], 'parse_error' );
			return; // Unreachable; redirect exits.
		}

		$importer = new Importer( $this->pending );
		$result   = $importer->import( $rows, $dry_run, $reset );

		$this->cleanup( $path );
		$this->stash_summary( $result );

		$this->redirect_back( [], 'imported' );
	}

	/**
	 * Handle "map to existing" resolution.
	 */
	public function handle_resolve_map(): void {
		$pending_id = isset( $_POST['pending_id'] ) ? absint( wp_unslash( $_POST['pending_id'] ) ) : 0;
		$this->guard( self::NONCE_MAP . '_' . $pending_id );

		$record = $this->pending->get( $pending_id );
		if ( null === $record || 'pending' !== ( $record['status'] ?? '' ) ) {
			$this->redirect_back( [ 'view' => 'review' ], 'gone' );
		}

		$target_id = isset( $_POST['target_id'] ) ? absint( wp_unslash( $_POST['target_id'] ) ) : 0;
		$relation  = (string) $record['relation'];
		$post_type = 'brewery' === $relation ? 'obw_brewery' : 'obw_venue';

		$target = $target_id > 0 ? get_post( $target_id ) : null;
		if ( ! $target instanceof \WP_Post || $target->post_type !== $post_type ) {
			$this->redirect_back( [ 'view' => 'review' ], 'bad_target' );
		}

		if ( ! $this->link_relation( (int) $record['beer_id'], $relation, $target_id ) ) {
			$this->redirect_back( [ 'view' => 'review' ], 'link_failed' );
		}

		$this->pending->update_status( $pending_id, 'resolved' );
		$this->redirect_back( [ 'view' => 'review' ], 'mapped' );
	}

	/**
	 * Handle "create full profile & link" resolution — the only sanctioned
	 * brewery/venue creation path.
	 */
	public function handle_resolve_create(): void {
		$pending_id = isset( $_POST['pending_id'] ) ? absint( wp_unslash( $_POST['pending_id'] ) ) : 0;
		$this->guard( self::NONCE_CREATE . '_' . $pending_id );

		$record = $this->pending->get( $pending_id );
		if ( null === $record || 'pending' !== ( $record['status'] ?? '' ) ) {
			$this->redirect_back( [ 'view' => 'review' ], 'gone' );
		}

		$relation  = (string) $record['relation'];
		$post_type = 'brewery' === $relation ? 'obw_brewery' : 'obw_venue';

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		if ( '' === $title ) {
			$this->redirect_back( [ 'view' => 'review' ], 'no_title' );
		}

		$new_id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_title'  => $title,
			],
			true
		);

		if ( is_wp_error( $new_id ) || 0 === (int) $new_id ) {
			$this->redirect_back( [ 'view' => 'review' ], 'create_failed' );
		}
		$new_id = (int) $new_id;

		$this->fill_profile_fields( $new_id, $relation );

		if ( ! $this->link_relation( (int) $record['beer_id'], $relation, $new_id ) ) {
			$this->redirect_back( [ 'view' => 'review' ], 'link_failed' );
		}

		$this->pending->update_status( $pending_id, 'resolved' );
		$this->redirect_back( [ 'view' => 'review' ], 'created' );
	}

	/**
	 * Handle "ignore/dismiss" of a pending row.
	 */
	public function handle_ignore(): void {
		$pending_id = isset( $_POST['pending_id'] ) ? absint( wp_unslash( $_POST['pending_id'] ) ) : 0;
		$this->guard( self::NONCE_IGNORE . '_' . $pending_id );

		$record = $this->pending->get( $pending_id );
		if ( null === $record ) {
			$this->redirect_back( [ 'view' => 'review' ], 'gone' );
		}

		$this->pending->update_status( $pending_id, 'ignored' );
		$this->redirect_back( [ 'view' => 'review' ], 'ignored' );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Set the new brewery/venue profile's ACF fields from the create form.
	 */
	private function fill_profile_fields( int $post_id, string $relation ): void {
		$website = isset( $_POST['website'] ) ? esc_url_raw( wp_unslash( $_POST['website'] ) ) : '';

		if ( 'brewery' === $relation ) {
			if ( '' !== $website ) {
				$this->set_field( self::F_BREWERY_WEBSITE, $website, $post_id );
			}
			$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
			if ( '' !== $address ) {
				$this->set_field( self::F_BREWERY_ADDRESS, [ 'address' => $address ], $post_id );
			}
			$this->maybe_attach_logo( self::F_BREWERY_LOGO, $post_id );
		} else {
			if ( '' !== $website ) {
				$this->set_field( self::F_VENUE_WEBSITE, $website, $post_id );
			}
			$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
			if ( '' !== $location ) {
				$this->set_field( self::F_VENUE_LOCATION, [ 'address' => $location ], $post_id );
			}
			$hours = isset( $_POST['hours'] ) ? wp_kses_post( wp_unslash( $_POST['hours'] ) ) : '';
			if ( '' !== $hours ) {
				$this->set_field( self::F_VENUE_HOURS, $hours, $post_id );
			}
			$this->maybe_attach_logo( self::F_VENUE_LOGO, $post_id );
		}
	}

	/**
	 * Sideload the optional logo upload into the media library and set the ACF
	 * image field to the new attachment id. No-op when no file was provided.
	 */
	private function maybe_attach_logo( string $field_key, int $post_id ): void {
		if ( empty( $_FILES['logo'] ) || empty( $_FILES['logo']['name'] ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload(
			'logo',
			$post_id,
			[],
			[
				'test_form' => false,
				'mimes'     => [
					'jpg|jpeg|jpe' => 'image/jpeg',
					'gif'          => 'image/gif',
					'png'          => 'image/png',
					'webp'         => 'image/webp',
				],
			]
		);

		if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
			$this->set_field( $field_key, (int) $attachment_id, $post_id );
		}
	}

	/**
	 * Append a directory post to a beer's ACF bidirectional relation without
	 * disturbing relations set during import. ACF Pro syncs the reverse side.
	 *
	 * @return bool True on success (or when there is no beer to link to).
	 */
	private function link_relation( int $beer_id, string $relation, int $target_id ): bool {
		if ( $beer_id <= 0 || ! get_post_status( $beer_id ) ) {
			// Beer post is gone (e.g. reset happened after the record was written).
			// Nothing to link; treat as success so the row can be dispositioned.
			return true;
		}

		if ( ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) {
			return false;
		}

		$field_key = 'brewery' === $relation ? self::F_BEER_BREWERY : self::F_BEER_VENUE;

		$current = get_field( $field_key, $beer_id, false );
		$ids     = is_array( $current ) ? array_map( 'intval', $current ) : [];
		$ids[]   = $target_id;
		$ids     = array_values( array_unique( array_filter( $ids ) ) );

		update_field( $field_key, $ids, $beer_id );

		return true;
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
	 * Validate + accept a CSV upload. Returns the moved file path, or null.
	 */
	private function accept_upload( string $field ): ?string {
		$file = $_FILES[ $field ] ?? null;
		if ( ! is_array( $file ) || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
			return null;
		}

		// Size guard.
		if ( (int) ( $file['size'] ?? 0 ) > self::MAX_UPLOAD_BYTES ) {
			return null;
		}

		// Extension + MIME guard: only CSV / plain text.
		$check = wp_check_filetype_and_ext(
			(string) $file['tmp_name'],
			(string) $file['name'],
			[
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			]
		);
		$ext = strtolower( (string) pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'csv', 'txt' ], true ) ) {
			return null;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$overrides = [
			'test_form' => false,
			'mimes'     => [
				'csv' => 'text/csv',
				'txt' => 'text/plain',
			],
		];

		$moved = wp_handle_upload( $file, $overrides );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) || empty( $moved['file'] ) ) {
			return null;
		}

		return (string) $moved['file'];
	}

	/**
	 * Delete a temporary uploaded file.
	 */
	private function cleanup( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Capability + nonce guard for a mutating handler. Dies on failure.
	 */
	private function guard( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'obw-beer-tracker' ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Stash an import result for display after the PRG redirect.
	 */
	private function stash_summary( ImportResult $result ): void {
		set_transient( $this->summary_key(), $result->to_array(), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Pull (and clear) the stashed summary, rehydrated into an ImportResult.
	 */
	private function take_summary(): ?ImportResult {
		$data = get_transient( $this->summary_key() );
		if ( ! is_array( $data ) ) {
			return null;
		}
		delete_transient( $this->summary_key() );

		$result = new ImportResult();
		$result->dry_run       = ! empty( $data['dry_run'] );
		$result->did_reset     = ! empty( $data['did_reset'] );
		$result->reset_deleted = (int) ( $data['reset_deleted'] ?? 0 );
		$result->rows_total    = (int) ( $data['rows_total'] ?? 0 );
		$result->rows_created  = (int) ( $data['rows_created'] ?? 0 );
		$result->brewery_links = (int) ( $data['brewery_links'] ?? 0 );
		$result->venue_links   = (int) ( $data['venue_links'] ?? 0 );
		$result->beers         = is_array( $data['beers'] ?? null ) ? $data['beers'] : [];
		$result->unmatched     = is_array( $data['unmatched'] ?? null ) ? $data['unmatched'] : [];
		$result->errors        = is_array( $data['errors'] ?? null ) ? $data['errors'] : [];

		return $result;
	}

	/**
	 * Per-user transient key for the last import summary.
	 */
	private function summary_key(): string {
		return 'obw_import_summary_' . get_current_user_id();
	}

	/**
	 * Build a URL to this admin page.
	 *
	 * @param array<string,string> $args Extra query args.
	 */
	private function page_url( array $args = [] ): string {
		$base = add_query_arg(
			array_merge( [ 'post_type' => 'obw_beer', 'page' => self::SLUG ], $args ),
			admin_url( 'edit.php' )
		);
		return $base;
	}

	/**
	 * Redirect back to the page with a notice code, then exit.
	 *
	 * @param array<string,string> $args   Extra query args (e.g. view).
	 * @param string               $notice Notice code.
	 */
	private function redirect_back( array $args, string $notice ): void {
		$args['obw_notice'] = $notice;
		wp_safe_redirect( $this->page_url( $args ) );
		exit;
	}

	/**
	 * Render an admin notice from the ?obw_notice code, if any.
	 */
	private function render_admin_notice(): void {
		if ( empty( $_GET['obw_notice'] ) ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['obw_notice'] ) );

		$map = [
			'imported'          => [ 'success', __( 'Import complete. See the summary below.', 'obw-beer-tracker' ) ],
			'mapped'            => [ 'success', __( 'Reference mapped to the existing entry and linked.', 'obw-beer-tracker' ) ],
			'created'           => [ 'success', __( 'New profile created and linked.', 'obw-beer-tracker' ) ],
			'ignored'           => [ 'success', __( 'Row dismissed.', 'obw-beer-tracker' ) ],
			'no_file'           => [ 'error', __( 'No file was uploaded.', 'obw-beer-tracker' ) ],
			'bad_upload'        => [ 'error', __( 'Upload rejected: only CSV / text files up to 10 MB are accepted.', 'obw-beer-tracker' ) ],
			'parse_error'       => [ 'error', __( 'Could not parse the CSV (missing header or unreadable file).', 'obw-beer-tracker' ) ],
			'reset_unconfirmed' => [ 'error', __( 'Annual reset was requested but not confirmed — nothing was changed.', 'obw-beer-tracker' ) ],
			'gone'              => [ 'error', __( 'That review row is no longer pending.', 'obw-beer-tracker' ) ],
			'bad_target'        => [ 'error', __( 'The selected entry was not a valid brewery/venue.', 'obw-beer-tracker' ) ],
			'no_title'          => [ 'error', __( 'A title is required to create a profile.', 'obw-beer-tracker' ) ],
			'create_failed'     => [ 'error', __( 'Could not create the profile post.', 'obw-beer-tracker' ) ],
			'link_failed'       => [ 'error', __( 'Could not set the relationship (ACF unavailable?).', 'obw-beer-tracker' ) ],
		];

		if ( ! isset( $map[ $code ] ) ) {
			return;
		}

		[ $type, $message ] = $map[ $code ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Normalize a name for exact matching: trim, collapse whitespace, lowercase.
	 */
	private function normalize( string $name ): string {
		$name = preg_replace( '/\s+/u', ' ', trim( $name ) ) ?? '';
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
	}
}
