<?php
/**
 * PWA support: web app manifest + service worker, served from ROOT-scoped URLs.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

namespace OBW\BeerTracker;

/**
 * Makes the beer finder an installable, offline-capable PWA.
 *
 * Both the manifest and the service worker are streamed by PHP from root-level
 * URLs (via rewrite rules) rather than shipped as static files under the plugin
 * directory. Two reasons:
 *
 *  1. Scope. A service worker's control is limited to its own URL path and
 *     below. Served from `/obw-beer-tracker-sw.js` it gets the default scope
 *     `/`, which covers the finder page whatever slug the editor gives it — no
 *     `Service-Worker-Allowed` gymnastics, no static file under /wp-content/.
 *  2. Freshness. The SW body is generated from the Vite build manifest, so its
 *     precache list always names the exact hashed bundle/CSS of the current
 *     build. A new build changes the cache version automatically.
 *
 * The manifest `<link>` and iOS meta tags are only emitted on the finder page.
 */
final class ServiceWorker {

	private const SW_PATH       = 'obw-beer-tracker-sw.js';
	private const MANIFEST_PATH = 'obw-beer-tracker.webmanifest';
	private const QUERY_VAR     = 'obw_pwa';
	private const FINDER_TPL    = 'page-beerfinder.php';
	private const REWRITE_OPT   = 'obw_pwa_rewrite_ver';

	/** Brand orange (matches the theme's existing site-wide theme-color). */
	private const THEME_COLOR = '#ea751b';
	/** App chrome / splash background (matches the finder's dark UI). */
	private const BG_COLOR = '#2b2b2b';

	/**
	 * Is the PWA (service worker + manifest) currently active?
	 *
	 * Killswitch, in precedence order:
	 *   1. `OBW_PWA_DISABLED` constant (wp-config) — the always-available escape
	 *      hatch even if the DB/admin is unreachable.
	 *   2. `obw_beer_tracker_pwa_disabled` option — flip with `wp obw pwa off`
	 *      (no redeploy needed).
	 *   3. `obw_beer_tracker_pwa_enabled` filter — for programmatic control.
	 *
	 * When disabled: no manifest/registration is advertised AND the service
	 * worker URL serves a self-destruct build (see {@see serve_killswitch()}),
	 * so already-installed workers uninstall themselves on their next update.
	 */
	public static function is_enabled(): bool {
		if ( defined( 'OBW_PWA_DISABLED' ) && OBW_PWA_DISABLED ) {
			return false;
		}
		if ( get_option( 'obw_beer_tracker_pwa_disabled' ) ) {
			return false;
		}
		return (bool) apply_filters( 'obw_beer_tracker_pwa_enabled', true );
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_action( 'init', [ $this, 'maybe_flush_rules' ], 11 );
		add_filter( 'query_vars', [ $this, 'register_query_var' ] );
		// Priority 0: serve before core's redirect_canonical() (which runs at 10
		// and would otherwise 301 our extension-bearing path to a trailing slash).
		add_action( 'template_redirect', [ $this, 'maybe_serve' ], 0 );
		add_action( 'wp_head', [ $this, 'render_head_tags' ], 5 );
	}

	/**
	 * Map two clean root URLs onto the query var this class serves.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^' . self::SW_PATH . '$', 'index.php?' . self::QUERY_VAR . '=sw', 'top' );
		add_rewrite_rule( '^' . self::MANIFEST_PATH . '$', 'index.php?' . self::QUERY_VAR . '=manifest', 'top' );
	}

	/**
	 * Flush rewrite rules once per plugin version so the two routes above resolve
	 * without a manual permalink re-save after deploy.
	 */
	public function maybe_flush_rules(): void {
		if ( get_option( self::REWRITE_OPT ) === OBW_BEER_TRACKER_VERSION ) {
			return;
		}
		flush_rewrite_rules();
		update_option( self::REWRITE_OPT, OBW_BEER_TRACKER_VERSION, false );
	}

	/**
	 * @param array<int,string> $vars Registered public query vars.
	 * @return array<int,string>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the SW or manifest if this request is for one of them.
	 */
	public function maybe_serve(): void {
		$what = get_query_var( self::QUERY_VAR );
		if ( 'sw' === $what ) {
			$this->serve_service_worker();
		} elseif ( 'manifest' === $what ) {
			$this->serve_manifest();
		}
	}

	/**
	 * Stream the web app manifest.
	 */
	private function serve_manifest(): void {
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );

		$icons_base = OBW_BEER_TRACKER_URL . 'assets/pwa/';
		$manifest   = [
			'name'             => 'Ohio Brew Week — Beer Finder',
			'short_name'       => 'Beer Finder',
			'description'      => 'Find, track, and favorite beers during Ohio Brew Week.',
			'start_url'        => $this->finder_url(),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'theme_color'      => self::THEME_COLOR,
			'background_color' => self::BG_COLOR,
			'icons'            => [
				[
					'src'     => $icons_base . 'icon-192.png',
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				],
				[
					'src'     => $icons_base . 'icon-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				],
			],
		];

		echo wp_json_encode( $manifest );
		exit;
	}

	/**
	 * Stream the service worker, config injected from the build manifest.
	 */
	private function serve_service_worker(): void {
		if ( ! self::is_enabled() ) {
			$this->serve_killswitch();
			return;
		}

		$template = OBW_BEER_TRACKER_PATH . 'assets/pwa/sw-template.js';
		$body     = is_readable( $template ) ? (string) file_get_contents( $template ) : '';
		if ( '' === $body ) {
			status_header( 404 );
			exit;
		}

		$config = $this->sw_config();
		$body   = str_replace(
			'__OBW_PWA_CONFIG__',
			(string) wp_json_encode( $config ),
			$body
		);

		nocache_headers();
		header( 'Content-Type: text/javascript; charset=utf-8' );
		// Root path already yields scope '/', but be explicit for clarity.
		header( 'Service-Worker-Allowed: /' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- JS body.
		exit;
	}

	/**
	 * Serve a self-destruct service worker. Installed instantly, it deletes the
	 * plugin's caches and unregisters itself, then reloads open finder tabs so
	 * they fall back to the plain (network) site. This is what makes the
	 * killswitch reach browsers that already registered the real worker: they
	 * re-fetch this URL on their next update check and get this body instead.
	 */
	private function serve_killswitch(): void {
		nocache_headers();
		header( 'Content-Type: text/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo <<<'JS'
			self.addEventListener('install', () => self.skipWaiting());
			self.addEventListener('activate', (event) => {
				event.waitUntil(
					(async () => {
						const keys = await caches.keys();
						await Promise.all(
							keys.filter((k) => k.indexOf('obw-pwa-') === 0).map((k) => caches.delete(k))
						);
						await self.registration.unregister();
						const clients = await self.clients.matchAll({ type: 'window' });
						clients.forEach((c) => c.navigate(c.url));
					})()
				);
			});
			JS;
		exit;
	}

	/**
	 * Build the service-worker config: precache list (from the Vite manifest),
	 * runtime-cache route prefixes, and a version string that changes with the
	 * build so a new deploy invalidates the old cache.
	 *
	 * @return array<string,mixed>
	 */
	private function sw_config(): array {
		$build_url = rtrim( OBW_BEER_TRACKER_URL, '/' ) . '/build/';
		$icons     = OBW_BEER_TRACKER_URL . 'assets/pwa/';
		$assets    = $this->build_assets();

		$precache = [ $this->finder_url() ];
		foreach ( $assets as $rel ) {
			$precache[] = $build_url . $rel;
		}
		$precache[] = $icons . 'icon-192.png';
		$precache[] = $icons . 'icon-512.png';

		$version = OBW_BEER_TRACKER_VERSION . '-' . substr( md5( implode( '|', $assets ) ), 0, 8 );

		return [
			'version'          => $version,
			'precache'         => array_values( array_unique( $precache ) ),
			'navigateFallback' => $this->finder_url(),
			'finderRoute'      => rest_url( 'obw/v1/finder' ),
			'contentPrefix'    => rest_url( 'wp/v2/obw_beer/' ),
		];
	}

	/**
	 * The hashed JS + CSS files of the finder entry, build-root-relative, read
	 * from the Vite manifest (empty array if the build is missing).
	 *
	 * @return array<int,string>
	 */
	private function build_assets(): array {
		$path = rtrim( OBW_BEER_TRACKER_PATH, '/' ) . '/build/.vite/manifest.json';
		if ( ! is_readable( $path ) ) {
			return [];
		}
		$json = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $json ) || ! isset( $json['src/finder/main.jsx'] ) ) {
			return [];
		}

		$chunk = $json['src/finder/main.jsx'];
		$files = [];
		if ( ! empty( $chunk['file'] ) ) {
			$files[] = $chunk['file'];
		}
		if ( ! empty( $chunk['css'] ) && is_array( $chunk['css'] ) ) {
			foreach ( $chunk['css'] as $css ) {
				$files[] = $css;
			}
		}
		return $files;
	}

	/**
	 * Emit the manifest link + iOS install meta on the finder page only.
	 */
	public function render_head_tags(): void {
		if ( ! self::is_enabled() || ! $this->is_finder_page() ) {
			return;
		}

		printf(
			'<link rel="manifest" href="%s">' . "\n",
			esc_url( home_url( '/' . self::MANIFEST_PATH ) )
		);
		printf(
			'<link rel="apple-touch-icon" href="%s">' . "\n",
			esc_url( OBW_BEER_TRACKER_URL . 'assets/pwa/apple-touch-icon-180.png' )
		);
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="Beer Finder">' . "\n";
	}

	/**
	 * Is the current request the finder page (by template or embedded shortcode)?
	 */
	private function is_finder_page(): bool {
		if ( is_page_template( self::FINDER_TPL ) ) {
			return true;
		}
		$post = get_post();
		return $post instanceof \WP_Post && has_shortcode( (string) $post->post_content, 'obw_beer_finder' );
	}

	/**
	 * URL of the finder page (for start_url / navigate fallback). Cached in a
	 * transient; falls back to the site root if no such page exists yet.
	 */
	private function finder_url(): string {
		$cached = get_transient( 'obw_pwa_finder_url' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$url   = home_url( '/' );
		$pages = get_posts(
			[
				'post_type'      => 'page',
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'meta_key'       => '_wp_page_template', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => self::FINDER_TPL,    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'         => 'ids',
			]
		);
		if ( ! empty( $pages ) ) {
			$permalink = get_permalink( (int) $pages[0] );
			if ( is_string( $permalink ) ) {
				$url = $permalink;
			}
		}

		set_transient( 'obw_pwa_finder_url', $url, DAY_IN_SECONDS );
		return $url;
	}
}
