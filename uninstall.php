<?php
/**
 * Uninstall handler.
 *
 * Removes only what this plugin created: its own options and its own
 * change-log table. Nothing belonging to WordPress, WooCommerce or any
 * other plugin is touched.
 *
 * @package ExternalProductImages
 */

// Exit if WordPress did not call this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Options written by the plugin and its features.
 */
$epi_options = array(
	'epi_feature_flags',
	'epi_change_log_db_version',
	'one_time_wc_product_image_purge_plan',
);

foreach ( $epi_options as $epi_option ) {
	delete_option( $epi_option );
}

global $wpdb;

// The plugin's own change-log table.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping the plugin's own table on uninstall; the name is built from $wpdb->prefix.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'epi_product_changes' );
