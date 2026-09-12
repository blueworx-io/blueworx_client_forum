<?php
/**
 * The Forum Page editor screen.
 *
 * This file declares a schema and nothing else. The shell — page header, tabs,
 * panels, the one save bar — belongs to the shared page editor library.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Declares the Forum Page editor screen.
 *
 * @since 1.8.0
 */
final class EPI_Forum_Page_Editor {

	/**
	 * Register the screen once every plugin is loaded.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Hand the screen to the library.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! class_exists( '\Blueworx\PageEditor\v1\Editor' ) ) {
			return;
		}

		\Blueworx\PageEditor\v1\Editor::register(
			array(
				'slug'       => EPI_Forum_Page_Type::SCREEN,
				'title'      => __( 'Edit Forum Page', 'blueworx_client_forum' ),
				'eyebrow'    => __( 'Forum Pages', 'blueworx_client_forum' ),
				'lede'       => __( 'Everything on the live page. Nothing changes on the site until you save.', 'blueworx_client_forum' ),
				'post_type'  => EPI_Forum_Page_Type::POST_TYPE,
				'parent'     => 'edit.php?post_type=' . EPI_Forum_Page_Type::POST_TYPE,
				'capability' => 'edit_posts',
				'tabs'       => array(
					self::tab_top(),
					self::tab_explainer(),
					self::tab_evidence(),
					self::tab_products(),
					self::tab_close(),
				),
			)
		);
	}

	/**
	 * The Page top tab.
	 *
	 * @return array
	 */
	private static function tab_top() {
		return array(
			'id'     => 'top',
			'label'  => __( 'Page top', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'      => 'hero',
					'eyebrow' => __( 'Forum page · Hero', 'blueworx_client_forum' ),
					'title'   => __( 'Hero', 'blueworx_client_forum' ),
					'note'    => __( 'The top of the page. Every Forum Page has one, so it cannot be switched off.', 'blueworx_client_forum' ),
					'fields'  => array(
						array(
							'id'       => 'post_title',
							'kind'     => 'title',
							'label'    => __( 'Page name', 'blueworx_client_forum' ),
							'required' => true,
							'help'     => __( 'How this page is listed in wp-admin. Not shown on the page itself.', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_eyebrow',
							'kind'  => 'text',
							'label' => __( 'Eyebrow', 'blueworx_client_forum' ),
							'help'  => __( 'The small word above the heading, e.g. KINETIC.', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_breadcrumb',
							'kind'  => 'text',
							'label' => __( 'Breadcrumb', 'blueworx_client_forum' ),
							'help'  => __( 'The last crumb, e.g. Kinetic wireless switches.', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_intro',
							'kind'  => 'textarea',
							'label' => __( 'Introduction', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_image',
							'kind'  => 'media',
							'label' => __( 'Hero image', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_cta1_label',
							'kind'  => 'text',
							'label' => __( 'First button', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'hero_cta1_url',
							'kind'   => 'text',
							'label'  => __( 'First button address', 'blueworx_client_forum' ),
							'format' => 'url',
						),
						array(
							'id'    => 'hero_cta2_label',
							'kind'  => 'text',
							'label' => __( 'Second button', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'hero_cta2_url',
							'kind'   => 'text',
							'label'  => __( 'Second button address', 'blueworx_client_forum' ),
							'format' => 'url',
						),
						array(
							'id'    => 'hero_meta_category',
							'kind'  => 'text',
							'label' => __( 'Category chip', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_meta_read',
							'kind'  => 'text',
							'label' => __( 'Read time chip', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'hero_meta_updated',
							'kind'  => 'text',
							'label' => __( 'Updated chip', 'blueworx_client_forum' ),
						),
					),
				),
				array(
					'id'       => 'sectionbar',
					'eyebrow'  => __( 'Forum page · On this page', 'blueworx_client_forum' ),
					'title'    => __( 'On this page', 'blueworx_client_forum' ),
					'note'     => __( 'The links are built from the sections that are switched on, using each section\'s own short name.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'      => 'sectionbar_label',
							'kind'    => 'text',
							'label'   => __( 'Bar label', 'blueworx_client_forum' ),
							'default' => 'ON THIS PAGE',
						),
						array(
							'id'    => 'sectionbar_phone',
							'kind'  => 'text',
							'label' => __( 'Phone number', 'blueworx_client_forum' ),
						),
					),
				),
			),
		);
	}

	/**
	 * The Explainer tab.
	 *
	 * @return array
	 */
	private static function tab_explainer() {
		return array(
			'id'     => 'explainer',
			'label'  => __( 'Explainer', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'what',
					'eyebrow'  => __( 'Forum page · What they are', 'blueworx_client_forum' ),
					'title'    => __( 'What they are', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'what_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
							'help'  => __( 'Used in the on-this-page bar.', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'what_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'what_body',
							'kind'  => 'richtext',
							'label' => __( 'Body', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'what_pullquote',
							'kind'  => 'textarea',
							'label' => __( 'Pull quote', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'what_parts',
							'kind'   => 'repeater',
							'label'  => __( 'Parts', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'title',
									'kind'  => 'text',
									'label' => __( 'Name', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'desc',
									'kind'  => 'text',
									'label' => __( 'What it does', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
				array(
					'id'       => 'how',
					'eyebrow'  => __( 'Forum page · How they work', 'blueworx_client_forum' ),
					'title'    => __( 'How they work', 'blueworx_client_forum' ),
					'note'     => __( 'One row per step. The page shows them one at a time.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'how_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'how_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'how_intro',
							'kind'  => 'textarea',
							'label' => __( 'Introduction', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'how_steps',
							'kind'   => 'repeater',
							'label'  => __( 'Steps', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'title',
									'kind'  => 'text',
									'label' => __( 'Step', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'body',
									'kind'  => 'textarea',
									'label' => __( 'What happens', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'image',
									'kind'  => 'media',
									'label' => __( 'Diagram', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The Evidence tab.
	 *
	 * @return array
	 */
	private static function tab_evidence() {
		return array(
			'id'     => 'evidence',
			'label'  => __( 'Evidence', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'advantages',
					'eyebrow'  => __( 'Forum page · Advantages', 'blueworx_client_forum' ),
					'title'    => __( 'Advantages', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'advantages_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'advantages_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'advantages_wins_label',
							'kind'  => 'text',
							'label' => __( 'First list heading', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'advantages_wins',
							'kind'   => 'repeater',
							'label'  => __( 'First list', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'item',
									'kind'  => 'textarea',
									'label' => __( 'Point', 'blueworx_client_forum' ),
								),
							),
						),
						array(
							'id'    => 'advantages_allow_label',
							'kind'  => 'text',
							'label' => __( 'Second list heading', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'advantages_allow',
							'kind'   => 'repeater',
							'label'  => __( 'Second list', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'item',
									'kind'  => 'textarea',
									'label' => __( 'Point', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
				array(
					'id'       => 'where',
					'eyebrow'  => __( 'Forum page · Where they work', 'blueworx_client_forum' ),
					'title'    => __( 'Where they work', 'blueworx_client_forum' ),
					'note'     => __( 'The photo cards. Any number of them.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'where_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'where_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'where_intro',
							'kind'  => 'textarea',
							'label' => __( 'Introduction', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'where_cards',
							'kind'   => 'repeater',
							'label'  => __( 'Cards', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'image',
									'kind'  => 'media',
									'label' => __( 'Photograph', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'title',
									'kind'  => 'text',
									'label' => __( 'Heading', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'body',
									'kind'  => 'textarea',
									'label' => __( 'Description', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
				array(
					'id'       => 'comparison',
					'eyebrow'  => __( 'Forum page · Comparison', 'blueworx_client_forum' ),
					'title'    => __( 'Comparison', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'comparison_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'comparison_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'comparison_col1',
							'kind'  => 'text',
							'label' => __( 'First column', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'comparison_col2',
							'kind'  => 'text',
							'label' => __( 'Second column', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'comparison_col3',
							'kind'  => 'text',
							'label' => __( 'Third column', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'comparison_rows',
							'kind'   => 'repeater',
							'label'  => __( 'Rows', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'label',
									'kind'  => 'text',
									'label' => __( 'Row', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'col1',
									'kind'  => 'text',
									'label' => __( 'First column', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'col2',
									'kind'  => 'text',
									'label' => __( 'Second column', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'col3',
									'kind'  => 'text',
									'label' => __( 'Third column', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The Products tab.
	 *
	 * The range panel joins this tab once the shared design system can pick a
	 * product inside a repeating list.
	 *
	 * @return array
	 */
	private static function tab_products() {
		return array(
			'id'     => 'products',
			'label'  => __( 'Products', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'range',
					'eyebrow'  => __( 'Forum page · The range', 'blueworx_client_forum' ),
					'title'    => __( 'The range', 'blueworx_client_forum' ),
					'note'     => __( 'Pick each product by its SKU. The name, photo and link come from the product itself; only the one-line description is typed here.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'range_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'range_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'range_intro',
							'kind'  => 'textarea',
							'label' => __( 'Intro', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'range_products',
							'kind'   => 'repeater',
							'label'  => __( 'Products', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'sku',
									'kind'  => 'text',
									'label' => __( 'SKU', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'blurb',
									'kind'  => 'text',
									'label' => __( 'One-line description', 'blueworx_client_forum' ),
								),
							),
						),
						array(
							'id'    => 'range_more_label',
							'kind'  => 'text',
							'label' => __( 'See-all button', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'range_more_url',
							'kind'   => 'text',
							'format' => 'url',
							'label'  => __( 'See-all link', 'blueworx_client_forum' ),
						),
					),
				),
				array(
					'id'       => 'specifying',
					'eyebrow'  => __( 'Forum page · Specifying', 'blueworx_client_forum' ),
					'title'    => __( 'Specifying', 'blueworx_client_forum' ),
					'note'     => __( 'The points are numbered on the page, in this order.', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'specifying_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'specifying_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'specifying_points',
							'kind'   => 'repeater',
							'label'  => __( 'Points', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'title',
									'kind'  => 'text',
									'label' => __( 'Point', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'body',
									'kind'  => 'textarea',
									'label' => __( 'Detail', 'blueworx_client_forum' ),
								),
							),
						),
						array(
							'id'    => 'specifying_trouble_label',
							'kind'  => 'text',
							'label' => __( 'Troubleshooting heading', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'specifying_trouble',
							'kind'   => 'repeater',
							'label'  => __( 'Troubleshooting', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'title',
									'kind'  => 'text',
									'label' => __( 'Symptom', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'body',
									'kind'  => 'textarea',
									'label' => __( 'What to do', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * The Close tab.
	 *
	 * @return array
	 */
	private static function tab_close() {
		return array(
			'id'     => 'close',
			'label'  => __( 'Close', 'blueworx_client_forum' ),
			'panels' => array(
				array(
					'id'       => 'faqs',
					'eyebrow'  => __( 'Forum page · FAQs', 'blueworx_client_forum' ),
					'title'    => __( 'FAQs', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'faqs_nav_label',
							'kind'  => 'text',
							'label' => __( 'Short name', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'faqs_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'faqs_items',
							'kind'   => 'repeater',
							'label'  => __( 'Questions', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'question',
									'kind'  => 'text',
									'label' => __( 'Question', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'answer',
									'kind'  => 'textarea',
									'label' => __( 'Answer', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
				array(
					'id'       => 'cta',
					'eyebrow'  => __( 'Forum page · Closing call to action', 'blueworx_client_forum' ),
					'title'    => __( 'Closing call to action', 'blueworx_client_forum' ),
					'hideable' => true,
					'fields'   => array(
						array(
							'id'    => 'cta_eyebrow',
							'kind'  => 'text',
							'label' => __( 'Eyebrow', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'cta_heading',
							'kind'  => 'text',
							'label' => __( 'Heading', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'cta_body',
							'kind'  => 'textarea',
							'label' => __( 'Body', 'blueworx_client_forum' ),
						),
						array(
							'id'    => 'cta_cta1_label',
							'kind'  => 'text',
							'label' => __( 'First button', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'cta_cta1_url',
							'kind'   => 'text',
							'label'  => __( 'First button address', 'blueworx_client_forum' ),
							'format' => 'url',
						),
						array(
							'id'    => 'cta_cta2_label',
							'kind'  => 'text',
							'label' => __( 'Second button', 'blueworx_client_forum' ),
						),
						array(
							'id'     => 'cta_cta2_url',
							'kind'   => 'text',
							'label'  => __( 'Second button address', 'blueworx_client_forum' ),
							'format' => 'url',
						),
						array(
							'id'     => 'cta_stats',
							'kind'   => 'repeater',
							'label'  => __( 'Trust figures', 'blueworx_client_forum' ),
							'fields' => array(
								array(
									'id'    => 'value',
									'kind'  => 'text',
									'label' => __( 'Figure', 'blueworx_client_forum' ),
								),
								array(
									'id'    => 'label',
									'kind'  => 'text',
									'label' => __( 'Caption', 'blueworx_client_forum' ),
								),
							),
						),
					),
				),
			),
		);
	}
}
