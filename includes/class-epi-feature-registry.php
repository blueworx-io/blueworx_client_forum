<?php
/**
 * Feature registry for BlueWorx Lab | Forum Lighting.
 *
 * Declares every feature (moved Code Snippets scripts plus the plugin's own
 * gallery / meta viewer / change log) and boots the ones that are switched on
 * and whose dependencies are active. Each moved script lives verbatim under
 * includes/snippets/ and is only required when its feature is enabled.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists features and resolves their on/off state.
 *
 * @since 1.1.0
 */
final class EPI_Feature_Registry {

	/**
	 * Option holding the per-feature on/off flags.
	 */
	const OPTION = 'epi_feature_flags';

	/**
	 * Human labels for known dependencies.
	 *
	 * @var array
	 */
	private static $dependency_labels = array(
		'woocommerce' => 'WooCommerce',
		'elementor'   => 'Elementor',
		'acf'         => 'Advanced Custom Fields',
	);

	/**
	 * Ordered feature groups (key => display label).
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'pricing'         => __( 'Pricing', 'external-product-images' ),
			'product-display' => __( 'Product display', 'external-product-images' ),
			'shop'            => __( 'Shop behaviour', 'external-product-images' ),
			'account'         => __( 'Account', 'external-product-images' ),
			'admin-email'     => __( 'Admin & email', 'external-product-images' ),
			'admin-product'   => __( 'Admin product tools', 'external-product-images' ),
			'tools'           => __( 'Tools', 'external-product-images' ),
			'debug'           => __( 'Debug', 'external-product-images' ),
		);
	}

	/**
	 * All feature definitions, keyed by id.
	 *
	 * Each definition: title, description, group, dangerous (bool),
	 * danger_message (string), dependencies (array), boot (callable).
	 *
	 * @return array
	 */
	public static function definitions() {
		$price_danger = __( 'This controls live store prices. Turning it off changes what customers see and pay. Are you sure?', 'external-product-images' );

		return array(
			// --- Pricing -------------------------------------------------.
			'role-based-pricing'      => array(
				'title'          => __( 'Role-based pricing', 'external-product-images' ),
				'description'    => __( 'Adjusts product prices by user role (customer 1-4, admins/shop managers), including the EUR switch for customer_3 and the [dynamic_product_price] shortcode.', 'external-product-images' ),
				'group'          => 'pricing',
				'dangerous'      => true,
				'danger_message' => $price_danger,
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'role-based-pricing.php' ),
			),
			'hide-prices-from-guests' => array(
				'title'          => __( 'Hide prices from guests', 'external-product-images' ),
				'description'    => __( 'Logged-out visitors see no price; logged-in users see the ex-VAT price only.', 'external-product-images' ),
				'group'          => 'pricing',
				'dangerous'      => true,
				'danger_message' => $price_danger,
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'hide-prices-from-guests.php' ),
			),

			// --- Product display ----------------------------------------.
			'gallery'                 => array(
				'title'          => __( 'External image gallery', 'external-product-images' ),
				'description'    => __( 'The Elementor product gallery widget (main image plus clickable thumbnails).', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce', 'elementor' ),
				'boot'           => array( __CLASS__, 'boot_gallery' ),
			),
			'epim-featured-image'     => array(
				'title'          => __( 'ePim featured image', 'external-product-images' ),
				'description'    => __( 'Builds the WooCommerce featured image (shop, category, related, cart, checkout, search) from the product image ID using the external ePim asset URL.', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => array( 'EPI_Featured_Image', 'init' ),
			),
			'hide-internal-attributes' => array(
				'title'          => __( 'Hide internal attributes', 'external-product-images' ),
				'description'    => __( 'Hides the internal pa_epim-*, pa_bullet-*, pa_ecd-* and "bullet" attributes from the product page.', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'hide-internal-attributes.php' ),
			),
			'hide-na-attributes'      => array(
				'title'          => __( 'Hide empty / N/A attributes', 'external-product-images' ),
				'description'    => __( 'Removes attributes that have no value or are set to "N/A".', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'hide-na-attributes.php' ),
			),
			'product-bullets'         => array(
				'title'          => __( 'Product bullets shortcode', 'external-product-images' ),
				'description'    => __( '[product_bullets] -renders the bullet attributes as a list.', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'product-bullets.php' ),
			),
			'fitting-instructions-button' => array(
				'title'          => __( 'Fitting instructions button', 'external-product-images' ),
				'description'    => __( '[fitting_instructions_button] -builds the fitting-sheet PDF link from the SKU (only if the file exists).', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'fitting-instructions-button.php' ),
			),
			'datasheet-button'        => array(
				'title'          => __( 'Datasheet (spec sheet) button', 'external-product-images' ),
				'description'    => __( '[datasheet_button_alt] -builds the spec-sheet link from the SKU.', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'datasheet-button.php' ),
			),
			'product-video-button'    => array(
				'title'          => __( 'Product video button', 'external-product-images' ),
				'description'    => __( '[product_video_button] -shows a Video button from the product video attribute, and hides that attribute from the list.', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'product-video-button.php' ),
			),
			'product-category-filter' => array(
				'title'          => __( 'Product filter widget', 'external-product-images' ),
				'description'    => __( '[product_category_filter] -the categories + lamp type / fitting class / IP rating / colour filter.', 'external-product-images' ),
				'group'          => 'product-display',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'product-category-filter.php' ),
			),

			// --- Shop behaviour -----------------------------------------.
			'hide-cart-for-guests'    => array(
				'title'          => __( 'Hide cart & purchasing for guests', 'external-product-images' ),
				'description'    => __( 'Makes products non-purchasable and removes Add-to-cart buttons for logged-out visitors.', 'external-product-images' ),
				'group'          => 'shop',
				'dangerous'      => true,
				'danger_message' => __( 'This currently blocks logged-out visitors from purchasing. Turning it off lets guests add products to the cart. Are you sure?', 'external-product-images' ),
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'hide-cart-for-guests.php' ),
			),
			'restrict-search'         => array(
				'title'          => __( 'Restrict search to products', 'external-product-images' ),
				'description'    => __( 'Site search returns only WooCommerce products.', 'external-product-images' ),
				'group'          => 'shop',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array(),
				'boot'           => self::snippet( 'restrict-search.php' ),
			),

			// --- Account ------------------------------------------------.
			'acf-account-details'     => array(
				'title'          => __( 'Account details (ACF)', 'external-product-images' ),
				'description'    => __( '[show_user_acf_fields] -shows the logged-in user account number, rep, manager and contact details.', 'external-product-images' ),
				'group'          => 'account',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'acf' ),
				'boot'           => self::snippet( 'acf-account-details.php' ),
			),

			// --- Admin & email ------------------------------------------.
			'email-footer'            => array(
				'title'          => __( 'Clean WooCommerce email footer', 'external-product-images' ),
				'description'    => __( 'Removes the "Process your orders on the go. Get the app." text from WooCommerce order emails.', 'external-product-images' ),
				'group'          => 'admin-email',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'email-footer.php' ),
			),
			'catalogue-ordering'      => array(
				'title'          => __( 'Catalogue drag-ordering', 'external-product-images' ),
				'description'    => __( 'Drag-and-drop manual ordering for the "catalogue" post type in the admin list.', 'external-product-images' ),
				'group'          => 'admin-email',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array(),
				'boot'           => self::snippet( 'catalogue-ordering.php' ),
			),

			// --- Admin product tools (existing plugin features) ---------.
			'meta-viewer'             => array(
				'title'          => __( 'Product metadata viewer', 'external-product-images' ),
				'description'    => __( 'The searchable product metadata viewer plus the "Last updated" product list column.', 'external-product-images' ),
				'group'          => 'admin-product',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => array( 'EPI_Product_Meta', 'init' ),
			),
			'change-log'              => array(
				'title'          => __( 'Product change log', 'external-product-images' ),
				'description'    => __( 'Records and displays an audit trail of product changes (users, imports, APIs).', 'external-product-images' ),
				'group'          => 'admin-product',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => array( 'EPI_Product_Change_Log', 'init' ),
			),

			// --- Tools --------------------------------------------------.
			'image-purge'             => array(
				'title'          => __( 'Product image purge tool', 'external-product-images' ),
				'description'    => __( 'Adds Tools > Purge Product Images: scan, then batch-delete product-only media (with confirmations). Turning this off only hides the tool - it deletes nothing.', 'external-product-images' ),
				'group'          => 'tools',
				'dangerous'      => true,
				'danger_message' => __( 'This hides the permanent image-deletion tool from the Tools menu. Turning it off will not delete anything. Are you sure?', 'external-product-images' ),
				'dependencies'   => array( 'woocommerce' ),
				'boot'           => self::snippet( 'image-purge.php' ),
			),

			// --- Debug --------------------------------------------------.
			'debug-log-user-role'     => array(
				'title'          => __( 'Log user role (debug)', 'external-product-images' ),
				'description'    => __( 'Prints the current user role to the browser console. Note: visible to every visitor when on.', 'external-product-images' ),
				'group'          => 'debug',
				'dangerous'      => false,
				'danger_message' => '',
				'dependencies'   => array(),
				'boot'           => self::snippet( 'debug-log-user-role.php' ),
			),
		);
	}

	/**
	 * Build a boot callback that requires a verbatim snippet file.
	 *
	 * @param string $file Filename within includes/snippets/.
	 * @return callable
	 */
	private static function snippet( $file ) {
		return function () use ( $file ) {
			require EPI_PLUGIN_DIR . 'includes/snippets/' . $file;
		};
	}

	/**
	 * Boot the existing Elementor gallery widget feature.
	 *
	 * @return void
	 */
	public static function boot_gallery() {
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-images-provider.php';

		add_action(
			'elementor/widgets/register',
			static function ( $widgets_manager ) {
				require_once EPI_PLUGIN_DIR . 'includes/class-epi-widget.php';
				$widgets_manager->register( new \EPI_Widget() );
			}
		);

		add_action( 'elementor/frontend/after_register_styles', array( 'EPI_Plugin', 'register_gallery_assets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( 'EPI_Plugin', 'register_gallery_assets' ) );
	}

	/**
	 * Whether a feature is switched on. Missing flag = on (default).
	 *
	 * @param string $id Feature id.
	 * @return bool
	 */
	public static function is_enabled( $id ) {
		$flags = get_option( self::OPTION, array() );

		if ( ! is_array( $flags ) || ! array_key_exists( $id, $flags ) ) {
			return true;
		}

		return ! empty( $flags[ $id ] );
	}

	/**
	 * Whether a single dependency is active.
	 *
	 * @param string $dependency Dependency key.
	 * @return bool
	 */
	public static function dependency_active( $dependency ) {
		switch ( $dependency ) {
			case 'woocommerce':
				return class_exists( 'WooCommerce' );
			case 'elementor':
				return (bool) did_action( 'elementor/loaded' );
			case 'acf':
				return function_exists( 'get_field' );
		}

		return true;
	}

	/**
	 * Labels of any inactive dependencies for a feature.
	 *
	 * @param array $dependencies Dependency keys.
	 * @return string[]
	 */
	public static function missing_dependencies( $dependencies ) {
		$missing = array();

		foreach ( (array) $dependencies as $dependency ) {
			if ( ! self::dependency_active( $dependency ) ) {
				$missing[] = isset( self::$dependency_labels[ $dependency ] )
					? self::$dependency_labels[ $dependency ]
					: $dependency;
			}
		}

		return $missing;
	}

	/**
	 * Boot every enabled, dependency-satisfied feature.
	 *
	 * @return void
	 */
	public static function boot() {
		foreach ( self::definitions() as $id => $definition ) {
			if ( ! self::is_enabled( $id ) ) {
				continue;
			}

			if ( self::missing_dependencies( $definition['dependencies'] ) ) {
				continue;
			}

			if ( is_callable( $definition['boot'] ) ) {
				call_user_func( $definition['boot'] );
			}
		}
	}

	/**
	 * Persist the on/off flags from submitted feature ids.
	 *
	 * Every known feature is written explicitly: present in the list = on,
	 * absent = off.
	 *
	 * @param array $enabled_ids Feature ids that were left switched on.
	 * @return void
	 */
	public static function save( $enabled_ids ) {
		$enabled_ids = is_array( $enabled_ids ) ? array_map( 'sanitize_key', $enabled_ids ) : array();
		$flags       = array();

		foreach ( array_keys( self::definitions() ) as $id ) {
			$flags[ $id ] = in_array( $id, $enabled_ids, true );
		}

		update_option( self::OPTION, $flags, false );
	}
}
