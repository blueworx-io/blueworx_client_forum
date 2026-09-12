<?php
/**
 * The banner slider as an Elementor widget.
 *
 * Every word, button, photo and colour is a control; the three designed
 * slides are the defaults, so the widget is finished the moment it is dropped
 * onto a page. The drawing itself is EPI_Banner's — this only collects the
 * settings and hands them over.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Repeater;

/**
 * Class EPI_Banner_Widget
 *
 * @since 1.11.0
 */
class EPI_Banner_Widget extends Widget_Base {

	/**
	 * Widget machine name.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'epi-forum-banner';
	}

	/**
	 * Widget title shown in the Elementor panel.
	 *
	 * @return string
	 */
	public function get_title() {
		return esc_html__( 'Forum: Banner slider', 'blueworx_client_forum' );
	}

	/**
	 * Widget icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-slider-push';
	}

	/**
	 * Categories this widget belongs to.
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Search keywords for the Elementor panel.
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( 'banner', 'slider', 'hero', 'carousel', 'forum' );
	}

	/**
	 * Style handles to enqueue only when this widget is present.
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( 'epi-banner' );
	}

	/**
	 * Script handles to enqueue only when this widget is present.
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( 'epi-banner' );
	}

	/**
	 * Register the controls.
	 *
	 * @return void
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'slides_section',
			array(
				'label' => esc_html__( 'Slides', 'blueworx_client_forum' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'theme',
			array(
				'label'   => esc_html__( 'Panel', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'dark',
				'options' => array(
					'dark'  => esc_html__( 'Navy with white text', 'blueworx_client_forum' ),
					'light' => esc_html__( 'White with navy text', 'blueworx_client_forum' ),
				),
			)
		);

		$repeater->add_control(
			'eyebrow',
			array(
				'label'       => esc_html__( 'Small line above the heading', 'blueworx_client_forum' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'eyebrow_color',
			array(
				'label'   => esc_html__( 'Small line colour', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'grey',
				'options' => array(
					'grey' => esc_html__( 'Grey', 'blueworx_client_forum' ),
					'red'  => esc_html__( 'Red', 'blueworx_client_forum' ),
				),
			)
		);

		$repeater->add_control(
			'heading',
			array(
				'label'   => esc_html__( 'Heading', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 2,
				'dynamic' => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'body',
			array(
				'label'   => esc_html__( 'Text', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::TEXTAREA,
				'rows'    => 3,
				'dynamic' => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'button_label',
			array(
				'label'   => esc_html__( 'Button', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::TEXT,
				'dynamic' => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'button_url',
			array(
				'label'   => esc_html__( 'Button link', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::URL,
				'dynamic' => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'footnote',
			array(
				'label'       => esc_html__( 'Small line under the button', 'blueworx_client_forum' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'dynamic'     => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'image',
			array(
				'label'   => esc_html__( 'Photo', 'blueworx_client_forum' ),
				'type'    => Controls_Manager::MEDIA,
				'dynamic' => array( 'active' => true ),
			)
		);

		$repeater->add_control(
			'badge',
			array(
				'label'       => esc_html__( 'Badge on the photo', 'blueworx_client_forum' ),
				'type'        => Controls_Manager::TEXT,
				'description' => esc_html__( 'e.g. NEW IN. Leave empty for none.', 'blueworx_client_forum' ),
			)
		);

		$this->add_control(
			'slides',
			array(
				'label'       => esc_html__( 'Slides', 'blueworx_client_forum' ),
				'type'        => Controls_Manager::REPEATER,
				'fields'      => $repeater->get_controls(),
				'default'     => self::default_slides(),
				'title_field' => '{{{ heading }}}',
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'slider_section',
			array(
				'label' => esc_html__( 'Slider', 'blueworx_client_forum' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'autoplay',
			array(
				'label'        => esc_html__( 'Move on by itself', 'blueworx_client_forum' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'interval',
			array(
				'label'       => esc_html__( 'Seconds per slide', 'blueworx_client_forum' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 2,
				'max'         => 30,
				'step'        => 1,
				'default'     => 6,
				'description' => esc_html__( 'Pauses while the pointer is over the banner. Arrows and dots always work.', 'blueworx_client_forum' ),
				'condition'   => array( 'autoplay' => 'yes' ),
			)
		);

		$this->add_control(
			'arrows',
			array(
				'label'        => esc_html__( 'Arrows', 'blueworx_client_forum' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'dots',
			array(
				'label'        => esc_html__( 'Dots', 'blueworx_client_forum' ),
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * The designed slides in the shape the repeater stores.
	 *
	 * @return array
	 */
	private static function default_slides() {
		$out = array();

		foreach ( EPI_Banner::defaults()['slides'] as $slide ) {
			$out[] = array(
				'theme'         => $slide['theme'],
				'eyebrow'       => $slide['eyebrow'],
				'eyebrow_color' => $slide['eyebrow_color'],
				'heading'       => $slide['heading'],
				'body'          => $slide['body'],
				'button_label'  => $slide['button_label'],
				'button_url'    => array(
					'url'         => $slide['button_url'],
					'is_external' => '',
					'nofollow'    => '',
				),
				'footnote'      => $slide['footnote'],
				'image'         => array(
					'url' => $slide['image_url'],
					'id'  => '',
				),
				'badge'         => $slide['badge'],
			);
		}

		return $out;
	}

	/**
	 * Print the banner.
	 *
	 * @return void
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();
		$slides   = array();

		foreach ( (array) ( isset( $settings['slides'] ) ? $settings['slides'] : array() ) as $row ) {
			$image = isset( $row['image'] ) && is_array( $row['image'] ) ? $row['image'] : array();
			$link  = isset( $row['button_url'] ) && is_array( $row['button_url'] ) ? $row['button_url'] : array();
			$alt   = ! empty( $image['id'] ) ? get_post_meta( (int) $image['id'], '_wp_attachment_image_alt', true ) : '';

			$slides[] = array(
				'theme'          => isset( $row['theme'] ) ? $row['theme'] : 'dark',
				'eyebrow'        => isset( $row['eyebrow'] ) ? $row['eyebrow'] : '',
				'eyebrow_color'  => isset( $row['eyebrow_color'] ) ? $row['eyebrow_color'] : 'grey',
				'heading'        => isset( $row['heading'] ) ? $row['heading'] : '',
				'body'           => isset( $row['body'] ) ? $row['body'] : '',
				'button_label'   => isset( $row['button_label'] ) ? $row['button_label'] : '',
				'button_url'     => isset( $link['url'] ) ? $link['url'] : '',
				'button_new_tab' => ! empty( $link['is_external'] ),
				'footnote'       => isset( $row['footnote'] ) ? $row['footnote'] : '',
				'badge'          => isset( $row['badge'] ) ? $row['badge'] : '',
				'image_url'      => isset( $image['url'] ) ? $image['url'] : '',
				'image_alt'      => is_string( $alt ) ? $alt : '',
			);
		}

		$html = EPI_Banner::render(
			array(
				'slides'   => $slides,
				'autoplay' => isset( $settings['autoplay'] ) ? $settings['autoplay'] : '',
				'interval' => isset( $settings['interval'] ) ? $settings['interval'] : 6,
				'arrows'   => isset( $settings['arrows'] ) ? $settings['arrows'] : '',
				'dots'     => isset( $settings['dots'] ) ? $settings['dots'] : '',
			)
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped as it is built.
	}
}
