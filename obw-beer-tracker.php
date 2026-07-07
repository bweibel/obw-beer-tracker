<?php
/**
 * Plugin Name:       OBW Beer Tracker
 * Description:        Ohio Brew Week beer finder + data model. Owns the beer/venue/brewery CPTs, relationships, the Preact finder SPA, and the annual CSV importer.
 * Version:           1.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  advanced-custom-fields-pro
 * Author:            Ohio Brew Week
 * Text Domain:       obw-beer-tracker
 * Domain Path:       /languages
 *
 * @package OBW\BeerTracker
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Constants (contract for all WPs) --------------------------------------
define( 'OBW_BEER_TRACKER_FILE', __FILE__ );
define( 'OBW_BEER_TRACKER_PATH', plugin_dir_path( __FILE__ ) );
define( 'OBW_BEER_TRACKER_URL', plugin_dir_url( __FILE__ ) );
define( 'OBW_BEER_TRACKER_VERSION', '1.2.0' );

// --- Autoloader (PSR-4: OBW\BeerTracker\ => src/) --------------------------
require_once OBW_BEER_TRACKER_PATH . 'src/autoload.php';

// --- Activation / deactivation ---------------------------------------------
register_activation_hook( __FILE__, [ \OBW\BeerTracker\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \OBW\BeerTracker\Plugin::class, 'deactivate' ] );

// --- Boot -------------------------------------------------------------------
\OBW\BeerTracker\Plugin::instance()->init();
