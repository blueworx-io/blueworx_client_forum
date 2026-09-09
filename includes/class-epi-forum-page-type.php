<?php
/**
 * The Forum Pages record type.
 *
 * The page editor library edits records; it never creates them. So the post
 * type is registered with show_ui, WordPress draws its own list and Add New,
 * and the row action below is the way from that list into the editor.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the forum_page post type and the link into its editor.
 *
 * @since 1.8.0
 */
final class EPI_Forum_Page_Type {

	const POST_TYPE = 'forum_page';
	const SCREEN    = 'forum-page';

	/**
	 * Marks the rewrite rules as having been rebuilt for this post type.
	 *
	 * Registering a post type does not teach WordPress the addresses that go
	 * with it — until the rules are rebuilt, every Forum Page is a 404. The
	 * plugin cannot do that on activation alone, because the feature can be
	 * switched on long afterwards, so the rebuild is stamped and happens once
	 * however the post type first appears.
	 */
	const REWRITE_OPTION  = 'epi_forum_page_rewrites';
	const REWRITE_VERSION = '1';

	/**
	 * Hook the post type and its list-table row action.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	/**
	 * Register the post type.
	 *
	 * Public, because a Forum Page is a page on the site with its own address.
	 *
	 * @return void
	 */
	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Forum Pages', 'blueworx_client_forum' ),
					'singular_name' => __( 'Forum Page', 'blueworx_client_forum' ),
					'add_new_item'  => __( 'Add Forum Page', 'blueworx_client_forum' ),
					'edit_item'     => __( 'Edit Forum Page', 'blueworx_client_forum' ),
				),
				'public'       => true,
				'show_ui'      => true,
				'menu_icon'    => 'dashicons-media-document',
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'forum-pages' ),
				'supports'     => array( 'title', 'revisions', 'author' ),
				'show_in_rest' => true,
			)
		);

		self::maybe_flush_rewrites();
	}

	/**
	 * Rebuild the rewrite rules the first time this post type is registered.
	 *
	 * Guarded by a stored stamp so it runs once rather than on every request —
	 * flushing on every load is expensive and is a well-known way to make a
	 * site crawl.
	 *
	 * @return void
	 */
	private static function maybe_flush_rewrites() {
		if ( self::REWRITE_VERSION === get_option( self::REWRITE_OPTION ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_VERSION );
	}

	/**
	 * Point the list table's Edit action at the BlueWorx editor.
	 *
	 * Without this the list edits the record in Gutenberg, which knows nothing
	 * about any of these fields.
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    The row's post.
	 * @return array
	 */
	public static function row_action( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$actions['edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::editor_url( $post->ID ) ),
			esc_html__( 'Edit', 'blueworx_client_forum' )
		);

		return $actions;
	}

	/**
	 * The editor address for one record.
	 *
	 * @param int $id Post id.
	 * @return string
	 */
	public static function editor_url( $id ) {
		return admin_url( 'admin.php?page=' . self::SCREEN . '&id=' . (int) $id );
	}
}
