<?php
/**
 * Renders a Forum Page on the front end.
 *
 * The section order is fixed and lives here. Which sections a page shows is a
 * per-record switch; what order they come in is part of the product.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * The [forum_page] shortcode and the section order behind it.
 *
 * @since 1.8.0
 */
final class EPI_Forum_Page_Renderer {

	/**
	 * Hook the shortcode and its assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'forum_page', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'fallback_content' ) );
	}

	/**
	 * Every section, in the order the page shows them.
	 *
	 * 'numbered' means the section carries a number on the page and a link in
	 * the on-this-page bar. Both are worked out from the sections actually
	 * shown, so switching one off closes the numbering up behind it.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'hero'       => array( 'numbered' => false ),
			'sectionbar' => array( 'numbered' => false ),
			'what'       => array( 'numbered' => true ),
			'how'        => array( 'numbered' => true ),
			'advantages' => array( 'numbered' => true ),
			'where'      => array( 'numbered' => true ),
			'comparison' => array( 'numbered' => true ),
			'range'      => array( 'numbered' => true ),
			'specifying' => array( 'numbered' => true ),
			'faqs'       => array( 'numbered' => true ),
			'cta'        => array( 'numbered' => false ),
		);
	}

	/**
	 * Whether a panel is switched on for this record.
	 *
	 * The hero is not hideable, and a record saved before a section existed
	 * has no value for its switch — both default to shown.
	 *
	 * @param int    $id    Post id.
	 * @param string $panel Panel id.
	 * @return bool
	 */
	public static function shown( $id, $panel ) {
		if ( 'hero' === $panel ) {
			return true;
		}

		$key = EPI_Forum_Page_Type::POST_TYPE . '_' . $panel . '__shown';

		if ( ! metadata_exists( 'post', $id, $key ) ) {
			return true;
		}

		return (bool) get_post_meta( $id, $key, true );
	}

	/**
	 * One field's value.
	 *
	 * @param int    $id      Post id.
	 * @param string $key     Field id.
	 * @param string $default Fallback.
	 * @return mixed
	 */
	public static function value( $id, $key, $default = '' ) {
		$value = get_post_meta( $id, EPI_Forum_Page_Type::POST_TYPE . '_' . $key, true );

		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * One repeater's rows.
	 *
	 * @param int    $id  Post id.
	 * @param string $key Field id.
	 * @return array
	 */
	public static function rows( $id, $key ) {
		$rows = get_post_meta( $id, EPI_Forum_Page_Type::POST_TYPE . '_' . $key, true );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Where a section's markup lives.
	 *
	 * @param string $panel Panel id.
	 * @return string
	 */
	public static function partial( $panel ) {
		return EPI_PLUGIN_DIR . 'includes/forum-page/section-' . $panel . '.php';
	}

	/**
	 * The numbered sections this record actually shows, in order.
	 *
	 * Feeds both the numbering and the on-this-page bar, so the two can never
	 * disagree.
	 *
	 * A section with no partial is not counted. The section list names every
	 * section the design has, and one of them can be declared before its markup
	 * exists — counting it anyway numbered the page 05, 07, 08 and put a link
	 * in the bar pointing at nothing.
	 *
	 * @param int $id Post id.
	 * @return array List of panel ids.
	 */
	public static function visible_sections( $id ) {
		$out = array();

		foreach ( self::sections() as $panel => $section ) {
			if ( ! $section['numbered'] || ! self::shown( $id, $panel ) ) {
				continue;
			}

			if ( ! file_exists( self::partial( $panel ) ) ) {
				continue;
			}

			$out[] = $panel;
		}

		return $out;
	}

	/**
	 * Register the front-end assets. Enqueued only when the shortcode runs.
	 *
	 * The version carries the file's modification time, so a changed stylesheet
	 * is never served from a browser's or a host's cache under the old name.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_style(
			'epi-forum-page',
			EPI_PLUGIN_URL . 'assets/css/epi-forum-page.css',
			array(),
			self::asset_version( 'assets/css/epi-forum-page.css' )
		);

		wp_register_script(
			'epi-forum-page',
			EPI_PLUGIN_URL . 'assets/js/epi-forum-page.js',
			array(),
			self::asset_version( 'assets/js/epi-forum-page.js' ),
			true
		);
	}

	/**
	 * A cache-busting version for one plugin file.
	 *
	 * @param string $file Path under the plugin directory.
	 * @return string
	 */
	private static function asset_version( $file ) {
		$time = file_exists( EPI_PLUGIN_DIR . $file ) ? filemtime( EPI_PLUGIN_DIR . $file ) : 0;

		return EPI_VERSION . ( $time ? '.' . $time : '' );
	}

	/**
	 * Render the sections on a Forum Page that no template has claimed.
	 *
	 * The site's Elementor single template is the intended home for the
	 * shortcode. Until it exists — and on any site that never builds one — a
	 * Forum Page would otherwise be a blank screen.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function fallback_content( $content ) {
		if ( ! is_singular( EPI_Forum_Page_Type::POST_TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		if ( false !== strpos( (string) $content, '[forum_page]' ) ) {
			return $content;
		}

		// The theme's content column would box the design in; the breakout
		// class lets it run the full width of the window, as designed.
		return $content . str_replace(
			'<div class="epi-forum-page">',
			'<div class="epi-forum-page epi-forum-page--breakout alignfull">',
			self::shortcode()
		);
	}

	/**
	 * Render a Forum Page: the one named by `id`, or the one being viewed.
	 *
	 * `[forum_page]` belongs in the Forum Pages template; `[forum_page id="12"]`
	 * puts that page inside any other page on the site.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ) {
		$atts   = shortcode_atts( array( 'id' => 0 ), $atts, 'forum_page' );
		$picked = (int) $atts['id'];
		$id     = $picked > 0 ? $picked : get_the_ID();

		// A page placed by id only shows once it is published; the page being
		// viewed is already whatever WordPress let the visitor see.
		$hidden = $picked > 0 && 'publish' !== get_post_status( $picked );

		if ( ! $id || $hidden || EPI_Forum_Page_Type::POST_TYPE !== get_post_type( $id ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p>' . esc_html__( 'The [forum_page] shortcode needs a published Forum Page: use it in the Forum Pages template, or give it an id from the Forum Pages list.', 'blueworx_client_forum' ) . '</p>';
			}

			return '';
		}

		wp_enqueue_style( 'epi-forum-page' );
		wp_enqueue_script( 'epi-forum-page' );

		$numbers = array_flip( self::visible_sections( $id ) );

		ob_start();
		echo '<div class="epi-forum-page">';

		foreach ( self::sections() as $panel => $section ) {
			if ( ! self::shown( $id, $panel ) ) {
				continue;
			}

			$partial = self::partial( $panel );

			if ( ! file_exists( $partial ) ) {
				continue;
			}

			// Available to the partial: the record, and its number on the page.
			$post_id = $id;
			$number  = isset( $numbers[ $panel ] ) ? sprintf( '%02d', $numbers[ $panel ] + 1 ) : '';

			include $partial;
		}

		echo '</div>';

		return ob_get_clean();
	}
}
