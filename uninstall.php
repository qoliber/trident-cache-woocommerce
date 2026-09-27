<?php
/**
 * Uninstall: remove the outbox table, the settings, the token and the cron job.
 *
 * Deactivation keeps all of it (a reactivated plugin still delivers what it
 * owes); only deleting the plugin removes it.
 *
 * @package Qoliber\TridentWoo
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The outbox store implements the library's OutboxStore interface, which the
// release zip ships prefixed in vendor-prefixed/ — load it like the plugin does.
if ( is_file( __DIR__ . '/vendor-prefixed/autoload.php' ) ) {
	require_once __DIR__ . '/vendor-prefixed/autoload.php';
} elseif ( is_file( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/includes/Settings.php';
require_once __DIR__ . '/includes/Purge/WpdbOutboxStore.php';

/**
 * Remove this site's data.
 *
 * @return void
 */
function trident_woo_uninstall_site(): void {
	global $wpdb;
	( new \Qoliber\TridentWoo\Purge\WpdbOutboxStore( $wpdb ) )->uninstall();
	\Qoliber\TridentWoo\Settings::delete_all();
	wp_clear_scheduled_hook( 'trident_wc_purge_drain' );
	wp_clear_scheduled_hook( 'trident_purge_drain' ); // Pre-1.0 name.
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $trident_woo_site ) {
		switch_to_blog( (int) $trident_woo_site );
		trident_woo_uninstall_site();
		restore_current_blog();
	}
} else {
	trident_woo_uninstall_site();
}
