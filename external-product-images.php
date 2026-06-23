<?php
/**
 * Plugin Name:       BlueWorx Lab | Forum Lighting
 * Plugin URI:        https://blueworx.io/
 * Description:        Site functionality plugin for Forum Lighting. A control centre under Settings > BlueWorx Lab switches features on or off, including the WooCommerce product gallery, metadata tools, pricing rules and more.
 * Version:           1.2.0
 * Author:            BlueWorx
 * Author URI:        https://blueworx.io/
 * Text Domain:       external-product-images
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core plugin constants.
 */
define( 'EPI_VERSION', '1.2.0' );
define( 'EPI_PLUGIN_FILE', __FILE__ );
define( 'EPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPI_EPIM_IMAGE_BASE', 'https://epim.online/webproduct/assetimage/' );

/**
 * Main plugin bootstrap class.
 *
 * Keeps everything in a single instance so we never leak globals and so
 * activation stays safe even when the dependencies (Elementor / WooCommerce)
 * are missing.
 *
 * @since 1.0.0
 */
final class EPI_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var EPI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return EPI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Hooks are registered late so we can check dependencies first.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Create plugin database tables on activation.
	 *
	 * @return void
	 */
	public static function activate() {
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-change-log.php';
		EPI_Product_Change_Log::install();
	}

	/**
	 * Initialise the plugin once all other plugins are loaded.
	 *
	 * Activation-safe: if Elementor or WooCommerce are unavailable we simply
	 * show an admin notice and bail out without fatal errors.
	 *
	 * @return void
	 */
	public function init() {
		// The feature registry and control page always load so the settings
		// page works even when a dependency (WooCommerce / Elementor) is missing.
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-feature-registry.php';
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-lab-page.php';

		EPI_Lab_Page::init();

		// Most features need WooCommerce. Load the existing product classes (so
		// their static helpers stay available regardless of which features are
		// switched on) and keep the change-log table up to date when
		// WooCommerce is active; otherwise show a gentle notice.
		if ( class_exists( 'WooCommerce' ) ) {
			require_once EPI_PLUGIN_DIR . 'includes/class-epi-images-provider.php';
			require_once EPI_PLUGIN_DIR . 'includes/class-epi-featured-image.php';
			require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-change-log.php';
			require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-meta.php';
			EPI_Product_Change_Log::maybe_upgrade();
		} else {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
		}

		// Boot every feature that is switched on and whose dependencies are met.
		EPI_Feature_Registry::boot();
	}

	/**
	 * Register the gallery frontend CSS/JS handles.
	 *
	 * These are only enqueued on demand by the Elementor widget, so the CSS/JS
	 * load only on pages where the gallery is actually rendered. Static so the
	 * feature registry can hook it without the plugin instance.
	 *
	 * @return void
	 */
	public static function register_gallery_assets() {
		wp_register_style(
			'epi-gallery',
			EPI_PLUGIN_URL . 'assets/css/epi-gallery.css',
			array(),
			EPI_VERSION
		);

		wp_register_script(
			'epi-gallery',
			EPI_PLUGIN_URL . 'assets/js/epi-gallery.js',
			array(),
			EPI_VERSION,
			true
		);
	}

	/**
	 * Admin notice: WooCommerce missing.
	 *
	 * Most features need WooCommerce; the BlueWorx Lab control page still works
	 * without it and shows which features are unavailable.
	 *
	 * @return void
	 */
	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'BlueWorx Lab | Forum Lighting works best with WooCommerce active. Most features will not run until WooCommerce is installed and active.', 'external-product-images' );
		echo '</p></div>';
	}
}

register_activation_hook( __FILE__, array( 'EPI_Plugin', 'activate' ) );

/**
 * Kick things off.
 */
EPI_Plugin::instance();
