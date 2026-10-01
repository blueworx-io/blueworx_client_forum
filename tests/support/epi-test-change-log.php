<?php
/**
 * Plugin Name: Forum test support — product change log
 * Description: Test-only. Copied into the harness's mu-plugins by tests/product-change-log.spec.js.
 *              Stands in for WooCommerce's product type, boots the change log, lets a request act
 *              as ePim, and reports what the log recorded.
 *              Never shipped: tests/ is outside the release allowlist.
 */

if ( ! defined( 'EPI_TEST_EPIM_KEY' ) ) {
	define( 'EPI_TEST_EPIM_KEY', 'epim-test-key' );
}

// CI's harness has no WooCommerce. The change log only needs posts of type
// "product", so a plain post type stands in, at the same REST address
// WooCommerce gives its own, so the specs run unchanged on a harness that does
// have it. No "editor" support keeps the classic edit screen, where the
// change-log box is drawn. The fields the specs write are opened to REST either way.
add_action(
	'init',
	static function () {
		if ( ! post_type_exists( 'product' ) ) {
			register_post_type(
				'product',
				array(
					'label'        => 'Products',
					'public'       => false,
					'show_ui'      => true,
					'show_in_rest' => true,
					'rest_base'    => 'product',
					'supports'     => array( 'title', 'custom-fields' ),
				)
			);
		}

		if ( ! get_user_by( 'login', 'epi-test-viewer' ) ) {
			wp_insert_user(
				array(
					'user_login' => 'epi-test-viewer',
					'user_pass'  => wp_generate_password(),
					'role'       => 'subscriber',
				)
			);
		}

		foreach ( array( '_sku', '_regular_price', 'surerank_seo_checks_last_updated' ) as $key ) {
			register_post_meta(
				'product',
				$key,
				array(
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}
);

// ePim authenticates with an API key, not a browser session. A request carrying
// the test key is signed in as the site's first administrator, the way
// WooCommerce's key check signs in the key's owner.
//
// A second key signs in a subscriber: a real key holder who cannot edit
// products, such as a customer using the mobile app.
add_filter(
	'determine_current_user',
	static function ( $user_id ) {
		$key = isset( $_SERVER['HTTP_X_EPI_TEST_KEY'] ) ? (string) $_SERVER['HTTP_X_EPI_TEST_KEY'] : '';

		if ( 'viewer-test-key' === $key ) {
			$viewer = get_user_by( 'login', 'epi-test-viewer' );
			return $viewer ? $viewer->ID : $user_id;
		}

		if ( EPI_TEST_EPIM_KEY !== $key ) {
			return $user_id;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
			)
		);

		return $admins ? (int) $admins[0] : $user_id;
	},
	30
);

// Without WooCommerce the plugin never loads its product classes, so boot the
// change log here exactly as the plugin does when WooCommerce is active.
add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'WooCommerce' ) || ! defined( 'EPI_PLUGIN_DIR' ) || ! class_exists( 'EPI_Feature_Registry' ) ) {
			return;
		}

		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-change-log.php';
		require_once EPI_PLUGIN_DIR . 'includes/class-epi-product-meta.php';

		EPI_Product_Change_Log::maybe_upgrade();
		EPI_Product_Change_Log::register_always();

		if ( EPI_Feature_Registry::is_enabled( 'change-log' ) ) {
			EPI_Product_Change_Log::init();
		}
	},
	20
);

add_action(
	'rest_api_init',
	static function () {
		$admin_only = static function () {
			return current_user_can( 'manage_options' );
		};

		// What the log holds for a product.
		register_rest_route(
			'epi-test/v1',
			'/updates/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					return EPI_Product_Change_Log::get_updates( (int) $request['id'] );
				},
			)
		);

		// Writes one field as whoever calls it: staff when called with a session
		// and nonce, nobody when called bare. Also the way to write an array,
		// which the REST meta schema above does not allow.
		//
		// `deferred` makes the write at shutdown priority 10, where WooCommerce
		// syncs a variation's parent. `background` runs it as an Action
		// Scheduler job, which wp-admin starts with the admin's own cookies.
		register_rest_route(
			'epi-test/v1',
			'/write-meta',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$write = static function () use ( $request ) {
						update_post_meta( (int) $request['id'], (string) $request['key'], $request['value'] );
					};

					if ( $request['deferred'] ) {
						add_action( 'shutdown', $write, 10 );
						return array( 'ok' => true );
					}

					if ( $request['background'] ) {
						do_action( 'action_scheduler_before_execute', 0, 'WP Cron' );
					}

					$write();
					return array( 'ok' => true );
				},
			)
		);

		// Change stock the way WooCommerce's data store does: its hooks around a
		// plain SQL write that skips the meta hooks. Hand-rolled even when the
		// harness has WooCommerce, because wc_update_product_stock() sometimes
		// saves the product first, which would hide what this checks.
		register_rest_route(
			'epi-test/v1',
			'/set-stock',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;

					$id    = (int) $request['id'];
					$stock = (int) $request['stock'];

					$product = function_exists( 'wc_get_product' ) && wc_get_product( $id )
						? wc_get_product( $id )
						: new EPI_Test_Stock_Product( $id );
					do_action( 'woocommerce_product_before_set_stock', $product );
					$wpdb->update(
						$wpdb->postmeta,
						array( 'meta_value' => (string) $stock ),
						array(
							'post_id'  => $id,
							'meta_key' => '_stock',
						)
					);
					wp_cache_delete( $id, 'post_meta' );
					do_action( 'woocommerce_product_set_stock', $product );
					return array( 'ok' => true );
				},
			)
		);

		// Pretend the site is on an older change log, with a row in the old
		// table, and run the upgrade.
		register_rest_route(
			'epi-test/v1',
			'/upgrade-from',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					global $wpdb;

					$table = $wpdb->prefix . 'epi_product_changes';
					$wpdb->insert(
						$table,
						array(
							'product_id' => 1,
							'object_id'  => 1,
							'changed_at' => current_time( 'mysql', true ),
							'field_name' => 'surerank_seo_checks',
						)
					);
					update_option( 'epi_change_log_db_version', (string) $request['version'], false );

					EPI_Product_Change_Log::maybe_upgrade();

					return array(
						'version' => get_option( 'epi_change_log_db_version' ),
						'rows'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
					);
				},
			)
		);

		// Switch logging on or off, as the Lab screen's save does.
		register_rest_route(
			'epi-test/v1',
			'/logging',
			array(
				'methods'             => 'POST',
				'permission_callback' => $admin_only,
				'callback'            => static function ( WP_REST_Request $request ) {
					$flags               = get_option( EPI_Feature_Registry::OPTION, array() );
					$flags               = is_array( $flags ) ? $flags : array();
					$flags['change-log'] = (bool) $request['on'];
					update_option( EPI_Feature_Registry::OPTION, $flags, false );
					return $flags;
				},
			)
		);
	}
);

/**
 * The one thing WooCommerce's stock hooks need from a product, when the harness
 * has no WooCommerce to supply a real one.
 */
class EPI_Test_Stock_Product {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * @param int $id Product ID.
	 */
	public function __construct( $id ) {
		$this->id = (int) $id;
	}

	/**
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}
}
