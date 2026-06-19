<?php
/**
 * Plugin Name:       External Product Images
 * Plugin URI:        https://blueworx.io/
 * Description:        Custom Elementor widget that replaces the WooCommerce Product Images widget on single product pages with an external, self-contained product gallery (placeholder images now, product meta later).
 * Version:           1.0.3
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
define( 'EPI_VERSION', '1.0.3' );
define( 'EPI_PLUGIN_FILE', __FILE__ );
define( 'EPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
	 * Initialise the plugin once all other plugins are loaded.
	 *
	 * Activation-safe: if Elementor or WooCommerce are unavailable we simply
	 * show an admin notice and bail out without fatal errors.
	 *
	 * @return void
	 */
	public function init() {
		// Bail (gracefully) if WooCommerce is not active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_woocommerce' ) );
			return;
		}

		// Bail (gracefully) if Elementor is not active.
		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_elementor' ) );
			return;
		}

		// Load the data helper (placeholder now, product meta later).
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-images-provider.php';

		// Register the widget with Elementor.
		add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

		// Register (but do NOT enqueue) assets. Enqueueing is handled by the
		// widget itself via get_style_depends() / get_script_depends() so the
		// CSS/JS only load on pages where the widget is actually rendered.
		add_action( 'elementor/frontend/after_register_styles', array( $this, 'register_assets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register the custom Elementor widget(s).
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_widgets( $widgets_manager ) {
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-widget.php';

		$widgets_manager->register( new \EPI_Widget() );
	}

	/**
	 * Register frontend CSS/JS handles.
	 *
	 * These are only enqueued on demand by the widget, satisfying the
	 * "load assets only where needed" requirement.
	 *
	 * @return void
	 */
	public function register_assets() {
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
	 * @return void
	 */
	public function notice_missing_woocommerce() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'External Product Images requires WooCommerce to be installed and active.', 'external-product-images' );
		echo '</p></div>';
	}

	/**
	 * Admin notice: Elementor missing.
	 *
	 * @return void
	 */
	public function notice_missing_elementor() {
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'External Product Images requires Elementor to be installed and active.', 'external-product-images' );
		echo '</p></div>';
	}
}

/**
 * Kick things off.
 */
EPI_Plugin::instance();
