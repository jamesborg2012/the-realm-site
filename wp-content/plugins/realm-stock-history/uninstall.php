<?php
/**
 * Uninstall — this is a disposable audit log, not business data.
 * Drops the table and removes the plugin's options. Deactivation (elsewhere)
 * only unschedules the cron and leaves data intact; uninstall wipes everything.
 *
 * @package Realm_Stock_History
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Defensive: also clear the cron in case the plugin was deleted without deactivating.
wp_clear_scheduled_hook( 'rsh_purge_stock_history' );

$table = $wpdb->prefix . 'rsh_stock_history';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is not user input.

delete_option( 'rsh_db_version' );
delete_option( 'rsh_tracking_started' );
