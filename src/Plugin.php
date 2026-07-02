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

		// Placeholder finder mount for WP-0 acceptance; WP-3 replaces the app,
		// WP-6 wires the theme page to this shortcode.
		add_shortcode( 'obw_beer_finder', [ $this, 'render_finder_shortcode' ] );
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

		return '<div id="obw-beer-finder-root" class="obw-beer-finder"></div>';
	}

	/**
	 * Activation: flush rewrite rules so CPT permalinks (registered from WP-1)
	 * resolve immediately.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: flush rewrite rules to drop any plugin-owned rules.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
