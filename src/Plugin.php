<?php
/**
 * Plugin bootstrap.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker;

/**
 * Central bootstrap: singleton, hook registration, subsystem wiring.
 *
 * Later work packages register their own subsystems here (PostTypes in WP-1,
 * REST/relationship glue in WP-2, the importer in WP-4, etc.). Keep this class
 * thin — it wires things together, it does not implement features.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 */
	private static ?Plugin $instance = null;

	/**
	 * Asset loader (Vite manifest / dev-server aware enqueuer).
	 */
	private Assets $assets;

	/**
	 * Whether init() has already run.
	 */
	private bool $booted = false;

	private function __construct() {
		$this->assets = new Assets();
	}

	/**
	 * Get (and lazily create) the singleton.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register WordPress hooks. Safe to call once; repeat calls are ignored.
	 */
	public function init(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', [ $this, 'load_textdomain' ] );

		// WP-1: the plugin now owns the beer/venue/brewery CPTs. Registered on
		// `init` priority 0 so they exist before the theme (and everything else
		// on the default priority) runs.
		( new PostTypes() )->register_hooks();

		// WP-2: ACF Local JSON autoload (bidirectional relationship fields +
		// native show_in_rest) and REST payload normalization to the finder
		// contract.
		( new Fields() )->register_hooks();

		// Phase 2 §4.1: precomputed, cached `/obw/v1/finder` route (replaces
		// the finder's three paginated core-REST fetches with one payload).
		( new Rest\FinderController() )->register_hooks();

		// PWA: installable manifest + offline service worker for the finder.
		( new ServiceWorker() )->register_hooks();

		// Placeholder finder mount for WP-0 acceptance; WP-3 replaces the app,
		// WP-6 wires the theme page to this shortcode.
		add_shortcode( 'obw_beer_finder', [ $this, 'render_finder_shortcode' ] );

		// WP-4: ensure the pending-review table exists (self-heals if the plugin
		// was activated before this WP shipped), then register the CLI command.
		Import\PendingStore::maybe_upgrade();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'obw', Import\CliCommand::class );
		}

		// WP-5: importer admin UI + review queue. Only in admin context (the page
		// and its handlers live under wp-admin); front-end never needs it.
		if ( is_admin() ) {
			( new Admin\ImportPage( new Import\PendingStore() ) )->register_hooks();

			// Printable venue → brewery → beer tasting sheet (POC).
			( new Admin\PrintList() )->register_hooks();
		}
	}

	/**
	 * The shared Assets loader. All front-end WPs enqueue through this.
	 */
	public function assets(): Assets {
		return $this->assets;
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'obw-beer-tracker',
			false,
			dirname( plugin_basename( OBW_BEER_TRACKER_FILE ) ) . '/languages'
		);
	}

	/**
	 * Render the finder mount point and enqueue the SPA bundle.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes (unused for now).
	 */
	public function render_finder_shortcode( $atts = [] ): string {
		$this->assets->enqueue_entry( 'src/finder/main.jsx', 'obw-finder' );

		// The finder reads its REST root + nonce from these data attributes.
		// (Passing config via the mount node avoids the inline-script/module-tag
		// interaction on our ES-module bundle; the JS falls back to /wp-json/
		// when the attributes are absent, so a mocked/standalone mount still
		// works.)
		// PWA registration data — only emitted when the killswitch is off. Absent
		// attributes make the finder JS skip service-worker registration and
		// manifest dedup entirely, so flipping the switch stops new installs.
		$pwa_attrs = '';
		if ( ServiceWorker::is_enabled() ) {
			$pwa_attrs = sprintf(
				' data-sw-url="%s" data-manifest-url="%s"',
				esc_url( home_url( '/obw-beer-tracker-sw.js' ) ),
				esc_url( home_url( '/obw-beer-tracker.webmanifest' ) )
			);
		}

		return sprintf(
			'<div id="obw-beer-finder-root" class="obw-beer-finder" data-rest-url="%s" data-nonce="%s"%s></div>',
			esc_url( rest_url() ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			$pwa_attrs
		);
	}

	/**
	 * Activation: flush rewrite rules so CPT permalinks (registered from WP-1)
	 * resolve immediately.
	 */
	public static function activate(): void {
		// WP-4: create the pending-review store table.
		Import\PendingStore::create_table();

		flush_rewrite_rules();
	}

	/**
	 * Deactivation: flush rewrite rules to drop any plugin-owned rules.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
