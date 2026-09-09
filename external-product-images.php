<?php
/**
 * Plugin Name:       BlueWorx Lab | Forum Lighting
 * Plugin URI:        https://blueworx.io/
 * Description:        Site functionality plugin for Forum Lighting. A control centre under Settings > BlueWorx Lab switches features on or off, including the WooCommerce product gallery, metadata tools, pricing rules and more.
 * Version:           1.8.0
 * Author:            BlueWorx
 * Author URI:        https://blueworx.io/
 * Text Domain:       blueworx_client_forum
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
define( 'EPI_VERSION', '1.8.0' );
define( 'EPI_PLUGIN_FILE', __FILE__ );
define( 'EPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EPI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EPI_EPIM_IMAGE_BASE', 'https://epim.online/webproduct/assetimage/' );

/*
 * The shared admin design system, then the page editor library that enqueues
 * against it. This order matters: the design system's registrar decides which
 * copy on the site wins, and that has to be settled before anything enqueues.
 */
require_once EPI_PLUGIN_DIR . 'assets/blueworx-admin-design.php';
require_once EPI_PLUGIN_DIR . 'blueworx-page-editor/blueworx-page-editor.php';

/*
 * Self-updating from GitHub Releases. The site checks this repo's releases and
 * installs the zip attached to one, exactly like a wordpress.org update, so
 * nobody uploads a zip to update the plugin.
 *
 * This block must stay at file scope — the "use" import below cannot live
 * inside a function, closure or conditional.
 */
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$blueworx_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/blueworx-io/blueworx_client_forum/',
	__FILE__,
	// Must equal the plugin's folder name on the site, and the release
	// workflow's `plugin_slug` input. If the three disagree, WordPress installs
	// the update as a second copy and deactivates the original.
	'blueworx_client_forum'
);

/*
 * The repo is private, so the site needs a read-only token to see releases at
 * all. It lives in wp-config.php, never in the plugin and never in the repo:
 *
 *     define( 'BLUEWORX_PLUGIN_UPDATE_TOKEN', 'github_pat_...' );
 */
if ( defined( 'BLUEWORX_PLUGIN_UPDATE_TOKEN' ) && BLUEWORX_PLUGIN_UPDATE_TOKEN ) {
	$blueworx_update_checker->setAuthentication( BLUEWORX_PLUGIN_UPDATE_TOKEN );
}

/*
 * Install the zip attached to the Release, not GitHub's auto-generated source
 * tarball — the tarball extracts to a differently named folder, which
 * WordPress treats as a different plugin.
 */
$blueworx_update_checker->getVcsApi()->enableReleaseAssets();

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
			// The notice and the design system it needs are both registered by
			// the Lab page. Keeping every admin hook in that one file is what
			// lets the tooling tell this plugin's admin assets apart from its
			// shop-front ones.
			EPI_Lab_Page::warn_woocommerce_missing();
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
}

register_activation_hook( __FILE__, array( 'EPI_Plugin', 'activate' ) );

/**
 * Kick things off.
 */
EPI_Plugin::instance();
