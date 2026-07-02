<?php
/**
 * Minimal PSR-4 autoloader for the OBW\BeerTracker\ namespace.
 *
 * Maps OBW\BeerTracker\Foo\Bar => src/Foo/Bar.php. Kept dependency-free so the
 * plugin runs without a `composer install` step; a Composer autoloader can be
 * dropped in later without changing the class layout.
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'OBW\\BeerTracker\\';
		$base_dir = __DIR__ . '/';

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
