<?php
/**
 * Settings for the ePim pull: the key, the pictures switch, the API address.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the pull's settings.
 */
final class EPI_Pull_Settings {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'epi_pull_settings';

	/**
	 * ePim's read API for the Forum website channel.
	 */
	const DEFAULT_BASE = 'https://epim.azure-api.net/forum-website-categorised-channel/api/';

	/**
	 * The saved settings, with defaults filled in.
	 *
	 * @return array{key: string, images: bool}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'key'    => isset( $saved['key'] ) ? (string) $saved['key'] : '',
			'images' => ! empty( $saved['images'] ),
		);
	}

	/**
	 * Save some or all of the settings.
	 *
	 * @param array $values Any of: key (string), images (bool).
	 * @return void
	 */
	public static function save( array $values ) {
		$current = self::get();

		if ( array_key_exists( 'key', $values ) ) {
			$current['key'] = sanitize_text_field( (string) $values['key'] );
		}

		if ( array_key_exists( 'images', $values ) ) {
			$current['images'] = ! empty( $values['images'] );
		}

		update_option( self::OPTION, $current, false );
	}

	/**
	 * The subscription key. A constant in wp-config.php wins over the saved one.
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'EPI_EPIM_SUBSCRIPTION_KEY' ) && EPI_EPIM_SUBSCRIPTION_KEY ) {
			return (string) EPI_EPIM_SUBSCRIPTION_KEY;
		}

		$settings = self::get();

		return $settings['key'];
	}

	/**
	 * Whether the pull sets product pictures. Off while ePim still pushes.
	 *
	 * @return bool
	 */
	public static function images_from_epim() {
		$settings = self::get();

		return $settings['images'];
	}

	/**
	 * The API address, with a trailing slash.
	 *
	 * @return string
	 */
	public static function base_url() {
		/**
		 * Filter the ePim API address. The test harness points it at a fake.
		 *
		 * @param string $base_url Address ending in /api/.
		 */
		return trailingslashit( (string) apply_filters( 'epi_pull_api_base', self::DEFAULT_BASE ) );
	}

	/**
	 * How many records one API page asks for.
	 *
	 * @return int
	 */
	public static function page_size() {
		/**
		 * Filter the page size. Tests shrink it to exercise paging.
		 *
		 * @param int $size Records per page.
		 */
		return max( 1, (int) apply_filters( 'epi_pull_page_size', 50 ) );
	}
}
