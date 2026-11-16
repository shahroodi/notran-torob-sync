<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/auth.php';

function notran_torob_uninstall_site(): void {
	global $wpdb;
	wp_clear_scheduled_hook( 'nttc_cron' );
	wp_clear_scheduled_hook( 'nttc_cron_once' );
	foreach ( array( 'nttc_catalog', 'nttc_dirty', 'nttc_outbox' ) as $suffix ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the plugin's own tables.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $suffix ) );
	}
	foreach ( array( 'nttc_schema', 'nttc_settings', 'nttc_rebuild_token', 'nttc_build_state', 'nttc_last_feed_request',
		'nttc_last_run', 'nttc_last_error', 'nttc_last_webhook' ) as $option ) {
		delete_option( $option );
	}
	foreach ( Notran\Torob\Auth::RESULTS as $result ) {
		delete_option( Notran\Torob\Auth::STATUS_PREFIX . $result );
	}
	delete_post_meta_by_key( '_nttc_title' );
	delete_post_meta_by_key( '_nttc_exclude' );
}

function notran_torob_uninstall(): void {
	if ( ! is_multisite() ) {
		notran_torob_uninstall_site();
		return;
	}

	$offset = 0;
	do {
		$ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
		foreach ( $ids as $id ) {
			switch_to_blog( $id );
			try {
				notran_torob_uninstall_site();
			} finally {
				restore_current_blog();
			}
		}
		$offset += count( $ids );
	} while ( count( $ids ) === 100 );
}

notran_torob_uninstall();
