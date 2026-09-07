<?php
/**
 * External Product Images — Elementor widget.
 *
 * Renders a self-contained product gallery (main image + clickable thumbnails)
 * that does NOT depend on the native WooCommerce gallery output.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

/**
 * Class EPI_Widget
 *
 * @since 1.0.0
 */
class EPI_Widget extends Widget_Base {

	/**
	 * Widget machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'epi-external-product-images';
	}

	/**
	 * Widget title shown in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Forum: Product Image Gallery', 'blueworx_client_forum' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-product-images';
	}

	/**
	 * Categories this widget belongs to.
	 *
	 * "woocommerce-elements-single" makes it appear in the single-product
	 * editing context alongside the native WooCommerce widgets.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'woocommerce-elements-single', 'woocommerce-elements', 'general' );
	}

	/**
	 * Search keywords for the Elementor panel.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'woocommerce', 'product', 'image', 'gallery', 'images', 'external' );
	}

	/**
	 * Style handles to enqueue only when this widget is present.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'epi-gallery' );
	}

	/**
	 * Script handles to enqueue only when this widget is present.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'epi-gallery' );
	}

	/**
	 * Register the widget controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'epi_layout_section',
			array(
				'label' => esc_html__( 'Gallery Layout', 'blueworx_client_forum' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'epi_thumb_position',
			array(
				'label'   => esc_html__( 'Thumbnail Position', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'bottom',
				'options' => array(
					'bottom' => esc_html__( 'Below main image', 'blueworx_client_forum' ),
					'left'   => esc_html__( 'Left of main image', 'blueworx_client_forum' ),
				),
				'prefix_class' => 'epi-thumbs-',
			)
		);

		$this->add_control(
			'epi_editor_notice',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Images are pulled live from ePim using the product image IDs. Products with no image IDs fall back to placeholders.', 'blueworx_client_forum' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Resolve the current WooCommerce product ID.
	 *
	 * Works on the live single product page and degrades gracefully inside the
	 * Elementor editor / preview (where there may be no real product) by
	 * falling back to the most recent published product.
	 *
	 * @return int Product ID, or 0 if none could be resolved.
	 */
	private function get_current_product_id() {
		// Standard single product page.
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null;

		if ( $product instanceof WC_Product ) {
			return $product->get_id();
		}

		// Editor / preview fallback: use a sample product so the widget renders.
		if ( \Elementor\Plugin::$instance->editor->is_edit_mode() || is_admin() ) {
			$sample = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $sample ) ) {
				return absint( $sample[0] );
			}
		}

		return 0;
	}

	/**
	 * Render the widget output on the frontend.
	 *
	 * @return void
	 */
	protected function render() {
		$product_id = $this->get_current_product_id();

		// Resolve images (placeholders now, product meta later). This helper
		// always returns at least one image, so the page never breaks.
		$images = EPI_Images_Provider::get_images( $product_id );

		if ( empty( $images ) ) {
			// Absolute last-resort guard — should not happen.
			return;
		}

		$main_image = $images[0];
		$gallery_id = 'epi-gallery-' . esc_attr( $this->get_id() );

		// Accessible alt text based on the product title where possible.
		$product   = $product_id ? get_the_title( $product_id ) : '';
		$alt_base  = $product ? $product : esc_html__( 'Product image', 'blueworx_client_forum' );

		// Fallback to a bundled placeholder if an ePim asset fails to load.
		$fallback = EPI_Images_Provider::fallback_image_url();
		$on_error = $fallback ? "this.onerror=null;this.src='" . $fallback . "';" : '';
		?>
		<div class="epi-gallery" id="<?php echo esc_attr( $gallery_id ); ?>" data-epi-gallery>

			<div class="epi-gallery__stage">
				<img
					class="epi-gallery__main-image"
					src="<?php echo esc_url( $main_image ); ?>"
					alt="<?php echo esc_attr( $alt_base ); ?>"
					data-epi-main
					loading="eager"
					decoding="async"
					onerror="<?php echo esc_attr( $on_error ); ?>"
				/>
			</div>

			<?php if ( count( $images ) > 1 ) : ?>
				<ul class="epi-gallery__thumbs" role="list" aria-label="<?php esc_attr_e( 'Product image thumbnails', 'blueworx_client_forum' ); ?>">
					<?php foreach ( $images as $index => $image_url ) : ?>
						<li class="epi-gallery__thumb-item">
							<button
								type="button"
								class="epi-gallery__thumb<?php echo 0 === $index ? ' is-active' : ''; ?>"
								data-epi-thumb
								data-epi-full="<?php echo esc_url( $image_url ); ?>"
								aria-label="<?php
									/* translators: %d: image number. */
									echo esc_attr( sprintf( __( 'View image %d', 'blueworx_client_forum' ), $index + 1 ) );
								?>"
								<?php echo 0 === $index ? 'aria-current="true"' : ''; ?>
							>
								<img
									class="epi-gallery__thumb-image"
									src="<?php echo esc_url( $image_url ); ?>"
									alt="<?php
										/* translators: 1: product name, 2: image number. */
										echo esc_attr( sprintf( __( '%1$s — thumbnail %2$d', 'blueworx_client_forum' ), $alt_base, $index + 1 ) );
									?>"
									loading="lazy"
									decoding="async"
									onerror="<?php echo esc_attr( $on_error ); ?>"
								/>
							</button>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

		</div>
		<?php
	}
}
