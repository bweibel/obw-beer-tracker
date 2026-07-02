<?php
/**
 * Vite-aware asset loader.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker;

/**
 * The single front-end enqueue mechanism for the plugin.
 *
 * Two modes:
 *  - Production: reads `build/.vite/manifest.json` and enqueues the hashed JS
 *    entry (as an ES module) plus any CSS Vite associated with it.
 *  - Dev (HMR): when OBW_VITE_DEV is defined truthy (or the OBW_VITE_DEV env var
 *    is set), loads the Vite dev-server client + entry directly from the running
 *    dev server so hot module replacement works.
 *
 * Usage (WP-3 and later):
 *   Plugin::instance()->assets()->enqueue_entry( 'src/finder/main.jsx', 'obw-finder' );
 *
 * The returned handle is the main script handle, so callers can attach data via
 * wp_localize_script()/wp_add_inline_script() (e.g. REST root + nonce for WP-3).
 */
final class Assets {

	/**
	 * Default Vite dev-server origin (overridable via OBW_VITE_DEV_URL).
	 */
	private const DEFAULT_DEV_SERVER = 'http://localhost:5173';

	/**
	 * Cached decoded manifest (keyed by absolute manifest path).
	 *
	 * @var array<string, array<string,mixed>|null>
	 */
	private array $manifest_cache = [];

	/**
	 * Handles that must be emitted as ES modules.
	 *
	 * @var array<string,true>
	 */
	private array $module_handles = [];

	public function __construct() {
		add_filter( 'script_loader_tag', [ $this, 'filter_module_tag' ], 10, 3 );
	}

	/**
	 * Enqueue a Vite entry (and its CSS) by its manifest-relative source path.
	 *
	 * @param string        $entry  Entry source path relative to the Vite root,
	 *                              e.g. "src/finder/main.jsx".
	 * @param string        $handle Base handle for the enqueued assets.
	 * @param array<string> $deps   Script dependencies.
	 *
	 * @return string|null The main script handle, or null if nothing enqueued.
	 */
	public function enqueue_entry( string $entry, string $handle, array $deps = [] ): ?string {
		if ( $this->is_dev() ) {
			return $this->enqueue_dev( $entry, $handle, $deps );
		}

		return $this->enqueue_build( $entry, $handle, $deps );
	}

	/**
	 * Is the Vite dev server active for this request?
	 */
	public function is_dev(): bool {
		if ( defined( 'OBW_VITE_DEV' ) && OBW_VITE_DEV ) {
			return true;
		}

		$env = getenv( 'OBW_VITE_DEV' );

		return false !== $env && '' !== $env && '0' !== $env;
	}

	/**
	 * Dev-server origin, no trailing slash.
	 */
	private function dev_server(): string {
		if ( defined( 'OBW_VITE_DEV_URL' ) && is_string( OBW_VITE_DEV_URL ) && '' !== OBW_VITE_DEV_URL ) {
			return rtrim( OBW_VITE_DEV_URL, '/' );
		}

		$env = getenv( 'OBW_VITE_DEV_URL' );
		if ( is_string( $env ) && '' !== $env ) {
			return rtrim( $env, '/' );
		}

		return self::DEFAULT_DEV_SERVER;
	}

	/**
	 * Enqueue against the running Vite dev server (HMR).
	 *
	 * @param array<string> $deps Script dependencies.
	 */
	private function enqueue_dev( string $entry, string $handle, array $deps ): string {
		$origin = $this->dev_server();

		// The HMR client. Registered once, shared by every dev entry.
		$client_handle = 'obw-vite-client';
		if ( ! wp_script_is( $client_handle, 'registered' ) ) {
			wp_register_script( $client_handle, $origin . '/@vite/client', [], null, false );
			$this->module_handles[ $client_handle ] = true;
		}
		wp_enqueue_script( $client_handle );

		$script_deps = array_merge( [ $client_handle ], $deps );
		wp_enqueue_script( $handle, $origin . '/' . ltrim( $entry, '/' ), $script_deps, null, true );
		$this->module_handles[ $handle ] = true;

		return $handle;
	}

	/**
	 * Enqueue against the production build manifest.
	 *
	 * @param array<string> $deps Script dependencies.
	 */
	private function enqueue_build( string $entry, string $handle, array $deps ): ?string {
		$manifest = $this->manifest();
		if ( null === $manifest || ! isset( $manifest[ $entry ] ) || ! is_array( $manifest[ $entry ] ) ) {
			if ( function_exists( 'wp_trigger_error' ) ) {
				wp_trigger_error(
					__METHOD__,
					sprintf( 'OBW Beer Tracker: Vite entry "%s" not found in build manifest. Run `npm run build`.', $entry )
				);
			}

			return null;
		}

		$chunk    = $manifest[ $entry ];
		$build_url = rtrim( OBW_BEER_TRACKER_URL, '/' ) . '/build/';

		// JS entry (ES module).
		if ( ! empty( $chunk['file'] ) ) {
			wp_enqueue_script(
				$handle,
				$build_url . $chunk['file'],
				$deps,
				OBW_BEER_TRACKER_VERSION,
				true
			);
			$this->module_handles[ $handle ] = true;
		}

		// CSS emitted for this entry (own + imported chunks).
		$css_files = $this->collect_css( $manifest, $entry );
		$i         = 0;
		foreach ( $css_files as $css ) {
			wp_enqueue_style(
				$handle . '-' . $i,
				$build_url . $css,
				[],
				OBW_BEER_TRACKER_VERSION
			);
			++$i;
		}

		return ! empty( $chunk['file'] ) ? $handle : null;
	}

	/**
	 * Gather CSS for an entry, following imported chunks. Returns unique paths.
	 *
	 * @param array<string, array<string,mixed>> $manifest Decoded manifest.
	 * @param string                             $key      Manifest key.
	 * @param array<string,true>                 $seen     Visited keys (recursion guard).
	 *
	 * @return array<int,string>
	 */
	private function collect_css( array $manifest, string $key, array &$seen = [] ): array {
		if ( isset( $seen[ $key ] ) || ! isset( $manifest[ $key ] ) || ! is_array( $manifest[ $key ] ) ) {
			return [];
		}
		$seen[ $key ] = true;

		$chunk = $manifest[ $key ];
		$css   = [];

		if ( ! empty( $chunk['css'] ) && is_array( $chunk['css'] ) ) {
			foreach ( $chunk['css'] as $file ) {
				$css[] = $file;
			}
		}

		if ( ! empty( $chunk['imports'] ) && is_array( $chunk['imports'] ) ) {
			foreach ( $chunk['imports'] as $import ) {
				$css = array_merge( $css, $this->collect_css( $manifest, $import, $seen ) );
			}
		}

		return array_values( array_unique( $css ) );
	}

	/**
	 * Load + cache the Vite manifest. Returns null when the build is missing.
	 *
	 * @return array<string, array<string,mixed>>|null
	 */
	private function manifest(): ?array {
		$path = rtrim( OBW_BEER_TRACKER_PATH, '/' ) . '/build/.vite/manifest.json';

		if ( array_key_exists( $path, $this->manifest_cache ) ) {
			return $this->manifest_cache[ $path ];
		}

		$decoded = null;
		if ( is_readable( $path ) ) {
			$raw  = file_get_contents( $path );
			$json = is_string( $raw ) ? json_decode( $raw, true ) : null;
			if ( is_array( $json ) ) {
				$decoded = $json;
			}
		}

		$this->manifest_cache[ $path ] = $decoded;

		return $decoded;
	}

	/**
	 * Add type="module" to our script tags (Vite output is ESM).
	 *
	 * @param string $tag    The <script> tag markup.
	 * @param string $handle The script handle.
	 * @param string $src    The script src.
	 */
	public function filter_module_tag( string $tag, string $handle, string $src ): string {
		if ( ! isset( $this->module_handles[ $handle ] ) ) {
			return $tag;
		}

		if ( str_contains( $tag, ' type="module"' ) || str_contains( $tag, " type='module'" ) ) {
			return $tag;
		}

		return sprintf(
			'<script type="module" src="%s" id="%s-js"></script>' . "\n",
			esc_url( $src ),
			esc_attr( $handle )
		);
	}
}
