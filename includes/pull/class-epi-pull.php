<?php
/**
 * The ePim pull feature: loads its classes, keeps its tables, boots its parts.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entry point for the pull.
 */
final class EPI_Pull {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '1.0';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'epi_pull_db_version';

	/**
	 * Load every pull class. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function load() {
		require_once __DIR__ . '/class-epi-pull-settings.php';
		require_once __DIR__ . '/class-epi-pull-store.php';
		require_once __DIR__ . '/class-epi-pull-client.php';
		require_once __DIR__ . '/class-epi-pull-mapper.php';
	}

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		self::load();
		update_option( self::DB_OPTION, self::DB_VERSION, false );
		EPI_Pull_Store::install();
	}

	/**
	 * Boot the feature. Called by the feature registry when it is switched on.
	 *
	 * @return void
	 */
	public static function boot() {
		self::load();

		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}
}
