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

// Beers/breweries/venues are intentionally left untouched (see file header).
//
// The one thing we DO remove is the anonymous trending table + its options: it
// holds only random device UUIDs and per-beer flag counts (no PII, no directory
// data), and is disposable. Inlined here rather than via TrackStore because the
// plugin's autoloader is not active in the uninstall context.
global $wpdb;
$obw_track_table = $wpdb->prefix . 'obw_beer_track';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$obw_track_table}" );
delete_option( 'obw_beer_track_db_version' );
delete_option( 'obw_beer_tracker_trend_disabled' );
