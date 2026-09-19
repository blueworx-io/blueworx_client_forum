<?php
/**
 * Ships the first Forum Page.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates the Kinetic page the first time the feature runs on a site.
 *
 * The values go through the library's own sanitiser and store, so what lands
 * in the database is exactly what the editor would have saved.
 *
 * @since 1.9.0
 */
final class EPI_Forum_Page_Seed {

	const OPTION = 'epi_forum_page_seeded';

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		// After EPI_Forum_Page_Type::register() at 10, so the post type exists.
		add_action( 'init', array( __CLASS__, 'maybe_seed' ), 20 );
	}

	/**
	 * Seed once per site.
	 *
	 * The stamp is written before the attempt, so a site that later deletes
	 * the page does not get it back on the next request.
	 *
	 * @return void
	 */
	public static function maybe_seed() {
		if ( get_option( self::OPTION ) ) {
			return;
		}

		update_option( self::OPTION, '1', false );
		self::seed();
	}

	/**
	 * Create the record, its images and its values.
	 *
	 * @return int New post id, or 0 when a page with that address already
	 *             exists or the editor library is not loaded.
	 */
	public static function seed() {
		if ( ! class_exists( '\Blueworx\PageEditor\v1\Editor' ) ) {
			return 0;
		}

		$page = include EPI_PLUGIN_DIR . 'includes/seed/kinetic-wireless-switches.php';

		if ( get_page_by_path( $page['slug'], OBJECT, EPI_Forum_Page_Type::POST_TYPE ) ) {
			return 0;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => EPI_Forum_Page_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $page['title'],
				'post_name'   => $page['slug'],
			),
			true
		);

		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}

		$screen = \Blueworx\PageEditor\v1\Editor::get( EPI_Forum_Page_Type::SCREEN );

		if ( $screen ) {
			$values = \Blueworx\PageEditor\v1\Sanitise::values( $screen, self::attach_images( $page['values'], $id ) );
			\Blueworx\PageEditor\v1\Store::for( $screen )->write( $values, $id );
		}

		return (int) $id;
	}

	/**
	 * Swap shipped filenames for attachment ids, one attachment per file.
	 *
	 * @param array $values  Field values, media cells holding filenames.
	 * @param int   $post_id Parent post.
	 * @return array
	 */
	private static function attach_images( array $values, $post_id ) {
		$made = array();

		foreach ( $values as &$value ) {
			if ( is_string( $value ) && self::is_asset( $value ) ) {
				$value = self::attachment( $value, $post_id, $made );
				continue;
			}

			if ( ! is_array( $value ) ) {
				continue;
			}

			foreach ( $value as &$row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				foreach ( $row as &$cell ) {
					if ( is_string( $cell ) && self::is_asset( $cell ) ) {
						$cell = self::attachment( $cell, $post_id, $made );
					}
				}
				unset( $cell );
			}
			unset( $row );
		}
		unset( $value );

		return $values;
	}

	/**
	 * Whether a value names a file shipped under assets/seed/.
	 *
	 * @param string $value Field value.
	 * @return bool
	 */
	private static function is_asset( $value ) {
		return (bool) preg_match( '/^[a-z0-9-]+\.(jpg|png)$/', $value )
			&& file_exists( EPI_PLUGIN_DIR . 'assets/seed/' . $value );
	}

	/**
	 * Copy one shipped image into the Media Library.
	 *
	 * @param string $file    Filename under assets/seed/.
	 * @param int    $post_id Parent post.
	 * @param array  $made    Attachments already made this run, by filename.
	 * @return int Attachment id, or 0 when the copy failed.
	 */
	private static function attachment( $file, $post_id, array &$made ) {
		if ( isset( $made[ $file ] ) ) {
			return $made[ $file ];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// media_handle_sideload() moves its input, so hand it a copy.
		$tmp = wp_tempnam( $file );
		copy( EPI_PLUGIN_DIR . 'assets/seed/' . $file, $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy

		$attachment = media_handle_sideload(
			array(
				'name'     => $file,
				'tmp_name' => $tmp,
			),
			$post_id
		);

		$made[ $file ] = is_wp_error( $attachment ) ? 0 : (int) $attachment;

		return $made[ $file ];
	}
}
