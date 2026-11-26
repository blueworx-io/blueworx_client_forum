<?php
/**
 * Automatic updates for this plugin only.
 *
 * The update checker in the main file finds new releases on GitHub. This file
 * lets WordPress install them in the background, so a release does not sit
 * waiting for somebody to press Update.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Switch automatic updates on for this plugin, and leave every other plugin's
 * setting alone.
 *
 * Runs on `auto_update_plugin`, which WordPress asks for every plugin, both
 * when it decides what to install and when the Plugins screen draws its
 * "Auto-updates" column. For any plugin that is not this one the answer
 * WordPress arrived at is handed straight back.
 *
 * @param bool|null $update Whether to update, as decided so far. Null means nobody has decided.
 * @param object    $item   The pending update. Its `plugin` is the plugin's file, like `folder/file.php`.
 * @return bool|null
 */
function epi_auto_update_own_plugin( $update, $item ) {
	if ( is_object( $item ) && isset( $item->plugin ) && plugin_basename( EPI_PLUGIN_FILE ) === $item->plugin ) {
		return true;
	}

	return $update;
}
add_filter( 'auto_update_plugin', 'epi_auto_update_own_plugin', 10, 2 );
