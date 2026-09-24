<?php
/**
 * External Product Images — Elementor widget.
 *
 * The Elementor way into the product gallery. The markup itself is printed by
 * EPI_Gallery, which the [forum_product_gallery] shortcode shares.
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
			'epi_image_source',
			array(
				'label'       => esc_html__( 'Image source', 'blueworx_client_forum' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'epim',
				'options'     => array(
					'epim'        => esc_html__( 'ePim (live, by image ID)', 'blueworx_client_forum' ),
					'woocommerce' => esc_html__( 'WooCommerce gallery (media library)', 'blueworx_client_forum' ),
				),
				'description' => esc_html__( 'Either way the featured image opens the gallery and sits first in the thumbnails, followed by the gallery in its own order. The arrows loop through every image.', 'blueworx_client_forum' ),
			)
		);

		$this->add_control(
			'epi_thumb_position',
			array(
				'label'        => esc_html__( 'Thumbnail Position', 'blueworx_client_forum' ),
				'type'         => Controls_Manager::SELECT,
				'default'      => 'bottom',
				'options'      => array(
					'bottom' => esc_html__( 'Below main image', 'blueworx_client_forum' ),
					'left'   => esc_html__( 'Left of main image', 'blueworx_client_forum' ),
				),
				'prefix_class' => 'epi-thumbs-',
			)
		);

		$this->add_control(
			'epi_editor_notice_epim',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Images are pulled live from ePim using the product image IDs. Products with no image IDs fall back to placeholders.', 'blueworx_client_forum' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				'condition'       => array( 'epi_image_source' => 'epim' ),
			)
		);

		$this->add_control(
			'epi_editor_notice_woocommerce',
			array(
				'type'            => Controls_Manager::RAW_HTML,
				'raw'             => esc_html__( 'Images come from the product’s featured image and gallery in the media library. Products with no images fall back to placeholders.', 'blueworx_client_forum' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
				'condition'       => array( 'epi_image_source' => 'woocommerce' ),
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
		$settings = $this->get_settings_for_display();

		$html = EPI_Gallery::render(
			$this->get_current_product_id(),
			array(
				'source'         => isset( $settings['epi_image_source'] ) ? $settings['epi_image_source'] : 'epim',
				'thumb_position' => isset( $settings['epi_thumb_position'] ) ? $settings['epi_thumb_position'] : 'bottom',
			)
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
	}
}
