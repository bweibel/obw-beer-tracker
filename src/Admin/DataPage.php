<?php
/**
 * "Data" admin page — anonymous aggregate finder usage + annual reset.
 *
 * A top-level admin menu ("Data") surfacing the trending aggregate for admins:
 * unique devices, and total want-to-try / tasted / favorited marks this year,
 * plus a deliberate "reset for the year" that wipes the aggregate table (never
 * visitors' own localStorage lists).
 *
 * Security: capability `manage_options` gates the page AND the reset handler; the
 * reset carries a nonce + an explicit confirmation checkbox + a JS confirm.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker\Admin;

use OBW\BeerTracker\Trend\TrackStore;

/**
 * Registers and renders the "Data" admin dashboard.
 */
final class DataPage {

	/** Capability for the page and the reset handler. */
	private const CAP = 'manage_options';

	/** Top-level menu slug. */
	private const SLUG = 'obw-beer-data';

	/** Nonce action / admin-post hook for the reset. */
	private const NONCE_RESET = 'obw_data_reset';

	/**
	 * Wire hooks. Called from Plugin::init() only in admin context.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_' . self::NONCE_RESET, [ $this, 'handle_reset' ] );
	}

	/**
	 * Register the top-level "Data" menu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'OBW Finder Data', 'obw-beer-tracker' ),
			__( 'OBW Data', 'obw-beer-tracker' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render_page' ],
			'dashicons-chart-bar',
			58
		);
	}

	// =========================================================================
	// Rendering
	// =========================================================================

	/**
	 * Page controller.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'obw-beer-tracker' ) );
		}

		$totals = ( new TrackStore() )->totals();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'OBW Finder Data', 'obw-beer-tracker' ) );

		$this->render_admin_notice();

		if ( ! TrackStore::is_enabled() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Trending collection is currently OFF (wp obw trend off). Existing totals are shown, but no new activity is being recorded.', 'obw-beer-tracker' )
			);
		}

		echo '<p class="description">' . esc_html__( 'Anonymous, aggregate usage of the beer finder this year. Each "device" is a random ID stored in a visitor\'s browser — no accounts, no personal data.', 'obw-beer-tracker' ) . '</p>';

		$this->render_stats( $totals );
		$this->render_top_lists();
		$this->render_reset_form();

		echo '</div>';
	}

	/**
	 * Stat cards.
	 *
	 * @param array{devices:int,beers:int,totry:int,tasted:int,favorited:int} $t Totals.
	 */
	private function render_stats( array $t ): void {
		$cards = [
			[ __( 'Unique devices', 'obw-beer-tracker' ), $t['devices'], __( 'browsers that marked ≥1 beer', 'obw-beer-tracker' ) ],
			[ __( 'Want to try', 'obw-beer-tracker' ), $t['totry'], __( 'active want-to-try marks', 'obw-beer-tracker' ) ],
			[ __( 'Tasted', 'obw-beer-tracker' ), $t['tasted'], __( 'total tasted marks', 'obw-beer-tracker' ) ],
			[ __( 'Favorited', 'obw-beer-tracker' ), $t['favorited'], __( 'total favorite marks', 'obw-beer-tracker' ) ],
			[ __( 'Beers marked', 'obw-beer-tracker' ), $t['beers'], __( 'distinct beers with any mark', 'obw-beer-tracker' ) ],
		];

		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0 28px">';
		foreach ( $cards as $card ) {
			[ $label, $value, $hint ] = $card;
			echo '<div class="card" style="min-width:160px;padding:12px 16px;margin:0">';
			printf( '<div style="font-size:30px;font-weight:600;line-height:1.1">%s</div>', esc_html( number_format_i18n( (int) $value ) ) );
			printf( '<div style="font-weight:600;margin-top:4px">%s</div>', esc_html( $label ) );
			printf( '<div class="description">%s</div>', esc_html( $hint ) );
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Top-10 beers for each tracked flag, side by side.
	 */
	private function render_top_lists(): void {
		$store = new TrackStore();
		$lists = [
			'totry'     => __( 'Top 10 — Want to try', 'obw-beer-tracker' ),
			'tasted'    => __( 'Top 10 — Tasted', 'obw-beer-tracker' ),
			'favorited' => __( 'Top 10 — Favorited', 'obw-beer-tracker' ),
		];

		echo '<h2>' . esc_html__( 'Top beers', 'obw-beer-tracker' ) . '</h2>';
		echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:8px 0 28px">';

		foreach ( $lists as $flag => $heading ) {
			$rows = $store->top( $flag, 10 );

			echo '<div class="card" style="min-width:240px;flex:1 1 240px;padding:12px 16px;margin:0">';
			printf( '<h3 style="margin:0 0 8px">%s</h3>', esc_html( $heading ) );

			if ( empty( $rows ) ) {
				echo '<p class="description" style="margin:0">' . esc_html__( 'No data yet.', 'obw-beer-tracker' ) . '</p>';
			} else {
				echo '<ol style="margin:0 0 0 20px;padding:0">';
				foreach ( $rows as $r ) {
					$title = get_the_title( $r['beer_id'] );
					if ( '' === $title ) {
						/* translators: %d: beer post id */
						$title = sprintf( __( '(removed #%d)', 'obw-beer-tracker' ), $r['beer_id'] );
					}
					$edit = get_edit_post_link( $r['beer_id'] );
					$name = $edit
						? sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html( $title ) )
						: esc_html( $title );

					printf(
						'<li style="margin:2px 0">%s — <strong>%s</strong></li>',
						$name, // Already escaped above.
						esc_html( number_format_i18n( $r['count'] ) )
					);
				}
				echo '</ol>';
			}
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * "Reset for the year" form — destructive, double-confirmed.
	 */
	private function render_reset_form(): void {
		echo '<h2>' . esc_html__( 'Reset for the year', 'obw-beer-tracker' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Permanently clears all aggregate finder data above (device count + every mark) to start a fresh event year. Visitors\' own saved lists are NOT affected. This cannot be undone.', 'obw-beer-tracker' ) . '</p>';

		echo '<form id="obw-data-reset-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NONCE_RESET ) . '" />';
		wp_nonce_field( self::NONCE_RESET );
		echo '<p><label><input type="checkbox" name="reset_confirm" value="yes" /> ' . esc_html__( 'I understand this permanently deletes all aggregate finder data.', 'obw-beer-tracker' ) . '</label></p>';
		submit_button( __( 'Reset finder data', 'obw-beer-tracker' ), 'delete' );
		echo '</form>';
		?>
		<script>
		document.getElementById('obw-data-reset-form')?.addEventListener('submit', function (e) {
			if (!window.confirm(<?php echo wp_json_encode( __( 'This permanently deletes ALL aggregate finder data (device count + marks). Continue?', 'obw-beer-tracker' ) ); ?>)) {
				e.preventDefault();
			}
		});
		</script>
		<?php
	}

	// =========================================================================
	// Handler
	// =========================================================================

	/**
	 * Handle the reset submission (capability + nonce + explicit confirm).
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'obw-beer-tracker' ) );
		}
		check_admin_referer( self::NONCE_RESET );

		if ( empty( $_POST['reset_confirm'] ) || 'yes' !== sanitize_text_field( wp_unslash( $_POST['reset_confirm'] ) ) ) {
			$this->redirect_back( 'reset_unconfirmed' );
		}

		$deleted = ( new TrackStore() )->reset();
		$this->redirect_back( 'reset_done', $deleted );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Redirect back to this page with a notice code, then exit.
	 */
	private function redirect_back( string $notice, int $count = 0 ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'       => self::SLUG,
					'obw_notice' => $notice,
					'obw_n'      => $count,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render an admin notice from ?obw_notice, if present.
	 */
	private function render_admin_notice(): void {
		if ( empty( $_GET['obw_notice'] ) ) {
			return;
		}

		$code = sanitize_key( wp_unslash( $_GET['obw_notice'] ) );

		if ( 'reset_done' === $code ) {
			$n = isset( $_GET['obw_n'] ) ? absint( wp_unslash( $_GET['obw_n'] ) ) : 0;
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of records removed. */
						_n( 'Finder data reset — %s record removed.', 'Finder data reset — %s records removed.', $n, 'obw-beer-tracker' ),
						number_format_i18n( $n )
					)
				)
			);
			return;
		}

		if ( 'reset_unconfirmed' === $code ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'Reset was not confirmed — nothing was changed.', 'obw-beer-tracker' )
			);
		}
	}
}
