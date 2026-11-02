<?php
/**
 * Keep ePim images in the visitor's browser.
 *
 * ePim answers every product image with "Cache-Control: private", no lifetime
 * and no validators, so browsers download the same image again on every page.
 * This adds a service worker that keeps each ePim image in the visitor's own
 * browser after the first download. Nothing is stored on the site's server.
 *
 * The worker script is always served, even with the feature off: switched off,
 * it serves a worker that clears its images and unregisters itself, so turning
 * the feature off also removes it from browsers that already have it.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves and registers the ePim image service worker.
 *
 * @since 1.19.0
 */
final class EPI_Image_Cache {

	/**
	 * Feature id in the registry.
	 */
	const FEATURE = 'epim-image-cache';

	/**
	 * admin-ajax action that serves the worker script. admin-ajax is never
	 * page-cached, so the off switch and new releases always reach browsers.
	 */
	const ACTION = 'epi_image_cache_sw';

	/**
	 * Cache name. Bump the suffix to throw every visitor's saved images away.
	 */
	const CACHE = 'epi-epim-images-v1';

	/**
	 * How long a saved set of images is kept: 7 days, in line with the weekly ePim pull.
	 */
	const MAX_AGE_MS = 604800000;

	/**
	 * Most images kept. Chrome counts each image saved without CORS as several
	 * MB against storage, whatever its real size.
	 */
	const MAX_ENTRIES = 300;

	/**
	 * Serve the worker script, whether or not the feature is on.
	 *
	 * @return void
	 */
	public static function register_worker_endpoint() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'serve_worker' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'serve_worker' ) );
	}

	/**
	 * Hook the page side. Only runs while the feature is on.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'preconnect' ), 10, 2 );
	}

	/**
	 * Register the worker on every front-end page.
	 *
	 * @return void
	 */
	public static function enqueue() {
		wp_enqueue_script(
			'epi-image-cache',
			EPI_PLUGIN_URL . 'assets/js/epi-image-cache.js',
			array(),
			EPI_VERSION,
			true
		);

		wp_localize_script(
			'epi-image-cache',
			'epiImageCache',
			array(
				'workerUrl' => self::worker_url(),
				'scope'     => (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ),
				'base'      => EPI_EPIM_IMAGE_BASE,
			)
		);
	}

	/**
	 * Open the connection to ePim early, so the first image arrives sooner.
	 *
	 * @param array  $urls          URLs to print for the hint.
	 * @param string $relation_type The hint being printed.
	 * @return array
	 */
	public static function preconnect( $urls, $relation_type ) {
		if ( 'preconnect' !== $relation_type || is_admin() ) {
			return $urls;
		}

		$parts = wp_parse_url( EPI_EPIM_IMAGE_BASE );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $urls;
		}

		$urls[] = $parts['scheme'] . '://' . $parts['host'] . ( empty( $parts['port'] ) ? '' : ':' . $parts['port'] );

		return $urls;
	}

	/**
	 * Where the worker script is served from.
	 *
	 * @return string
	 */
	public static function worker_url() {
		return admin_url( 'admin-ajax.php?action=' . self::ACTION );
	}

	/**
	 * Echo the worker script and stop.
	 *
	 * @return void
	 */
	public static function serve_worker() {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		// Browsers may keep the script for 6 hours, so returning visitors do not start
		// WordPress on every page just to check it. The off switch takes up to that long.
		header( 'Cache-Control: max-age=21600' );
		// Lets a script under /wp-admin/ look after the whole site.
		header( 'Service-Worker-Allowed: /' );

		// The version comment changes the bytes on each release, so browsers take the new worker.
		echo '/* BlueWorx Lab ' . esc_js( EPI_VERSION ) . " */\n";

		if ( EPI_Feature_Registry::is_enabled( self::FEATURE ) ) {
			$settings = array(
				'base'       => EPI_EPIM_IMAGE_BASE,
				'cache'      => self::CACHE,
				'maxAgeMs'   => self::MAX_AGE_MS,
				'maxEntries' => self::MAX_ENTRIES,
			);

			echo 'self.EPI_CACHE = ' . wp_json_encode( $settings, JSON_UNESCAPED_SLASHES ) . ";\n";
			// Plain JS shipped with the plugin, read from disk rather than echoed from user input.
			echo file_get_contents( EPI_PLUGIN_DIR . 'assets/js/epi-image-cache-sw.js' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			echo self::removal_worker(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		exit;
	}

	/**
	 * A worker that clears its saved images and unregisters itself.
	 *
	 * @return string
	 */
	private static function removal_worker() {
		$prefix = wp_json_encode( 'epi-epim-images-' );

		return <<<JS
self.addEventListener( 'install', function () {
	self.skipWaiting();
} );

self.addEventListener( 'activate', function ( event ) {
	event.waitUntil(
		caches.keys().then( function ( names ) {
			return Promise.all( names.filter( function ( name ) {
				return name.indexOf( {$prefix} ) === 0;
			} ).map( function ( name ) {
				return caches.delete( name );
			} ) );
		} ).then( function () {
			return self.registration.unregister();
		} )
	);
} );

JS;
	}
}
