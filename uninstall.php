<?php
/**
 * Uninstall handler.
 *
 * No-op by design (WP-0): beers are disposable and reset annually, while
 * breweries + venues are a persistent directory we must never destroy. If a
 * future WP needs teardown (e.g. custom import tables), add it here guarded by
 * an explicit opt-in — never delete brewery/venue posts.
 *
 * @package OBW\BeerTracker
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Intentionally nothing to clean up yet.
