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
 * Whether a download address is the plugin zip attached to a GitHub release.
 *
 * The update checker falls back to GitHub's raw source archive when a release
 * has no zip attached. That archive holds files the release build leaves out on
 * purpose, so it must never be installed without somebody choosing to.
 *
 * @param string $package The update's download address.
 * @return bool
 */
function epi_is_release_zip( $package ) {
	$package = (string) $package;

	// A private repo read with a token: the asset's address on the API. It
	// carries no file name; the update checker already picked it by name.
	if ( 0 === strpos( $package, 'https://api.github.com/repos/blueworx-io/blueworx_client_forum/releases/assets/' ) ) {
		return true;
	}

	// A public repo: the file's own address, which must be the versioned plugin
	// zip and not some other file attached to the release.
	$prefix = 'https://github.com/blueworx-io/blueworx_client_forum/releases/download/';
	if ( 0 !== strpos( $package, $prefix ) ) {
		return false;
	}

	return 1 === preg_match( EPI_RELEASE_ZIP_PATTERN, basename( $package ) );
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
 * A newer version whose download is not the release's attached zip is not
 * installed automatically.
 *
 * @param bool|null $update Whether to update, as decided so far. Null means nobody has decided.
 * @param object    $item   The pending update. Its `plugin` is the plugin's file, like `folder/file.php`.
 * @return bool|null
 */
function epi_auto_update_own_plugin( $update, $item ) {
	if ( ! is_object( $item ) || ! isset( $item->plugin ) || plugin_basename( EPI_PLUGIN_FILE ) !== $item->plugin ) {
		return $update;
	}

	$is_newer = isset( $item->new_version ) && version_compare( (string) $item->new_version, EPI_VERSION, '>' );

	if ( $is_newer && ! empty( $item->package ) && ! epi_is_release_zip( $item->package ) ) {
		return false;
	}

	return true;
}
add_filter( 'auto_update_plugin', 'epi_auto_update_own_plugin', 10, 2 );
