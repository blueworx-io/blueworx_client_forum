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
				'tabs'       => array( self::tab_top() ),
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
					),
				),
			),
		);
	}
}
