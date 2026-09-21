<?php
/**
 * The product image gallery.
 *
 * One renderer, two ways in: the Elementor widget hands it the settings a
 * site owner has chosen, and the [forum_product_gallery] shortcode reads the
 * same choices from its attributes. Both print the same markup, so the
 * gallery lives here once.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the gallery: the featured image on show, the thumbnails beneath,
 * and arrows that loop through the lot.
 *
 * @since 1.12.0
 */
final class EPI_Gallery {

	/**
	 * Hook the shortcode and the assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'forum_product_gallery', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( 'EPI_Plugin', 'register_gallery_assets' ) );
		add_action( 'elementor/frontend/after_register_styles', array( 'EPI_Plugin', 'register_gallery_assets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( 'EPI_Plugin', 'register_gallery_assets' ) );
	}

	/**
	 * The renderer's defaults; also the widget's.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'source'         => 'epim',
			'thumb_position' => 'bottom',
		);
	}

	/**
	 * [forum_product_gallery source="epim|woocommerce" thumbs="bottom|left" id="123"]
	 *
	 * `id` defaults to the product (or post) the shortcode is printed on.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'source' => 'epim',
				'thumbs' => 'bottom',
				'id'     => 0,
			),
			$atts,
			'forum_product_gallery'
		);

		$post_id = absint( $atts['id'] );
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		return self::render(
			$post_id,
			array(
				'source'         => $atts['source'],
				'thumb_position' => $atts['thumbs'],
			)
		);
	}

	/**
	 * Print the gallery.
	 *
	 * @param int   $product_id The product (or post) whose images to show.
	 * @param array $settings   See defaults() for the shape.
	 * @return string
	 */
	public static function render( $product_id, array $settings = array() ) {
		$settings = wp_parse_args( $settings, self::defaults() );
		$source   = 'woocommerce' === $settings['source'] ? 'woocommerce' : 'epim';
		$thumbs   = 'left' === $settings['thumb_position'] ? 'left' : 'bottom';

		// Gallery first, featured last — and the featured one is what opens.
		$images = EPI_Images_Provider::get_images( $product_id, $source );

		if ( empty( $images ) ) {
			return '';
		}

		wp_enqueue_style( 'epi-gallery' );
		wp_enqueue_script( 'epi-gallery' );

		$start      = count( $images ) - 1;
		$main_image = $images[ $start ];
		$gallery_id = 'epi-gallery-' . wp_unique_id();

		// Accessible alt text based on the product title where possible.
		$title    = $product_id ? get_the_title( $product_id ) : '';
		$alt_base = $title ? $title : esc_html__( 'Product image', 'blueworx_client_forum' );

		// Fall back to a bundled placeholder if an image fails to load.
		$fallback = EPI_Images_Provider::fallback_image_url();
		$on_error = $fallback ? "this.onerror=null;this.src='" . $fallback . "';" : '';

		ob_start();
		?>
		<div class="epi-gallery epi-thumbs-<?php echo esc_attr( $thumbs ); ?>" id="<?php echo esc_attr( $gallery_id ); ?>" data-epi-gallery>

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

				<?php if ( count( $images ) > 1 ) : ?>
					<button type="button" class="epi-gallery__arrow epi-gallery__arrow--prev" data-epi-prev aria-label="<?php esc_attr_e( 'Previous image', 'blueworx_client_forum' ); ?>">
						<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
					</button>
					<button type="button" class="epi-gallery__arrow epi-gallery__arrow--next" data-epi-next aria-label="<?php esc_attr_e( 'Next image', 'blueworx_client_forum' ); ?>">
						<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( count( $images ) > 1 ) : ?>
				<ul class="epi-gallery__thumbs" role="list" aria-label="<?php esc_attr_e( 'Product image thumbnails', 'blueworx_client_forum' ); ?>">
					<?php foreach ( $images as $index => $image_url ) : ?>
						<li class="epi-gallery__thumb-item">
							<button
								type="button"
								class="epi-gallery__thumb<?php echo $start === $index ? ' is-active' : ''; ?>"
								data-epi-thumb
								data-epi-full="<?php echo esc_url( $image_url ); ?>"
								aria-label="
								<?php
									/* translators: %d: image number. */
									echo esc_attr( sprintf( __( 'View image %d', 'blueworx_client_forum' ), $index + 1 ) );
								?>
								"
								<?php echo $start === $index ? 'aria-current="true"' : ''; ?>
							>
								<img
									class="epi-gallery__thumb-image"
									src="<?php echo esc_url( $image_url ); ?>"
									alt="
									<?php
										/* translators: 1: product name, 2: image number. */
										echo esc_attr( sprintf( __( '%1$s — thumbnail %2$d', 'blueworx_client_forum' ), $alt_base, $index + 1 ) );
									?>
									"
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
		return ob_get_clean();
	}
}
