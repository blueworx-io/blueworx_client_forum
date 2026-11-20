<?php
/**
 * Plugin Name: Forum test support — WooCommerce product stand-ins
 * Description: Test-only. Copied into the harness's mu-plugins by tests/email-picture-and-admin-price.spec.js.
 *              Gives the plugin's picture and pricing code a product to work on, and answers what it
 *              returns over REST. Uses WooCommerce's own product where it is installed, and a bare
 *              stand-in where it is not (as in CI). Never shipped: tests/ is outside the release allowlist.
 */

/**
 * Declare the bare stand-ins, only once every plugin has loaded and only where
 * WooCommerce has not. mu-plugins load first, so declaring them up front would
 * clash with WooCommerce's own classes.
 */
function epi_test_declare_woo_stand_ins() {
	if ( class_exists( 'WC_Product' ) ) {
		return;
	}

	// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine
	class WC_Product {
		private $props = array(
			'image_id'      => 0,
			'name'          => '',
			'regular_price' => '',
			'attributes'    => array(),
		);

		public function get_id() {
			return 0;
		}

		public function set_image_id( $id ) {
			$this->props['image_id'] = $id;
		}

		public function get_image_id() {
			return $this->props['image_id'];
		}

		public function set_name( $name ) {
			$this->props['name'] = $name;
		}

		public function get_title() {
			return $this->props['name'];
		}

		public function set_regular_price( $price ) {
			$this->props['regular_price'] = $price;
		}

		public function get_data() {
			return array( 'regular_price' => $this->props['regular_price'] );
		}

		public function set_attributes( $attributes ) {
			foreach ( $attributes as $attribute ) {
				$this->props['attributes'][ sanitize_title( $attribute->get_name() ) ] = $attribute;
			}
		}

		public function get_attributes() {
			return $this->props['attributes'];
		}
	}

	class WC_Product_Simple extends WC_Product {}

	class WC_Product_Attribute {
		private $name    = '';
		private $options = array();

		public function set_id( $id ) {}

		public function set_name( $name ) {
			$this->name = $name;
		}

		public function get_name() {
			return $this->name;
		}

		public function set_options( $options ) {
			$this->options = $options;
		}

		public function get_options() {
			return $this->options;
		}

		public function is_taxonomy() {
			return false;
		}
	}
	// phpcs:enable
}

add_action(
	'rest_api_init',
	static function () {
		epi_test_declare_woo_stand_ins();

		// GET epi-test/v1/product-image?width=48&height=48 returns the picture
		// markup the plugin gives a product asked for at that size.
		register_rest_route(
			'epi-test/v1',
			'/product-image',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$product = new WC_Product_Simple();
					$product->set_image_id( 777 );
					$product->set_name( 'Stand-in pendant' );

					EPI_Featured_Image::init();

					$size = $request->get_param( 'width' )
						? array( (int) $request->get_param( 'width' ), (int) $request->get_param( 'height' ) )
						: 'woocommerce_thumbnail';

					return array( 'html' => EPI_Featured_Image::filter_product_image( '', $product, $size, array() ) );
				},
			)
		);

		// GET epi-test/v1/role-price?role=administrator returns the price a
		// login with that role is shown for a 100.00 product whose ECD special
		// price is 77.
		register_rest_route(
			'epi-test/v1',
			'/role-price',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					if ( ! function_exists( 'epi_role_based_price' ) ) {
						require EPI_PLUGIN_DIR . 'includes/snippets/role-based-pricing.php';
					}

					$ecd = new WC_Product_Attribute();
					$ecd->set_id( 0 );
					$ecd->set_name( 'ECD special prices' );
					$ecd->set_options( array( '77' ) );

					$product = new WC_Product_Simple();
					$product->set_regular_price( '100' );
					$product->set_attributes( array( $ecd ) );

					// WooCommerce holds unsaved changes apart from the data the
					// pricing code reads; settle them as a saved product would.
					if ( method_exists( $product, 'apply_changes' ) ) {
						$product->apply_changes();
					}

					// Price it as a login holding only the role asked about.
					$user        = new WP_User( 0 );
					$user->ID    = 999999;
					$user->roles = array( sanitize_key( (string) $request->get_param( 'role' ) ) );
					$GLOBALS['current_user'] = $user; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

					return array( 'price' => (float) epi_role_based_price( '100', $product ) );
				},
			)
		);
	}
);
