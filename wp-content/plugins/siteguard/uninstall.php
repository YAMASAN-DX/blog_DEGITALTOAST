<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

function remove_directory( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return true;
	}
	$entries = @scandir( $dir );
	if ( ! is_array( $entries ) ) {
		return false;
	}
	$files = array_diff( $entries, array( '.', '..' ) );
	foreach ( $files as $file ) {
		if ( is_dir( "$dir/$file" ) ) {
			remove_directory( "$dir/$file" );
		} else {
			@unlink( "$dir/$file" );
		}
	}
	return @rmdir( $dir );
}

function delete_siteguard_plugin() {
	global $wpdb;

	delete_option( 'siteguard_config' );

	// Deleting the plugin deactivates it first, which already clears this event,
	// but an uninstall that reaches this point with the event still scheduled
	// would leave it in the cron table with no handler and no settings behind it.
	// The name is duplicated from SiteGuard_UpdatesNotify::CRON_NAME because the
	// plugin classes are not loaded during uninstall.
	wp_clear_scheduled_hook( 'siteguard_update_check' );

	$table_name = $wpdb->prefix . 'siteguard_login';
	$wpdb->query( "DROP TABLE IF EXISTS $table_name;" );

	$table_name = $wpdb->prefix . 'siteguard_history';
	$wpdb->query( "DROP TABLE IF EXISTS $table_name;" );

	remove_directory( WP_CONTENT_DIR . '/siteguard' );
}

delete_siteguard_plugin();
