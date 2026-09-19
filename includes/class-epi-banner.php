<?php
/**
 * The banner slider.
 *
 * One renderer, two ways in: the Elementor widget hands it the settings a
 * site owner has edited, and the [forum_banner] shortcode hands it the three
 * designed slides. Both print the same markup, so the design lives here once.
 *
 * @package ExternalProductImages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the banner and carries its designed defaults.
 *
 * @since 1.11.0
 */
final class EPI_Banner {

	/**
	 * Hook the shortcode and the assets.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'forum_banner', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'elementor/frontend/after_register_styles', array( __CLASS__, 'register_assets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * The three slides from the design, in order.
	 *
	 * These are the widget's defaults and the shortcode's content, so a banner
	 * dropped onto a page is finished before anyone edits it.
	 *
	 * @return array
	 */
	public static function defaults() {
		$img = EPI_PLUGIN_URL . 'assets/img/banner/';

		return array(
			'autoplay' => 'yes',
			'interval' => 6,
			'arrows'   => 'yes',
			'dots'     => 'yes',
			'slides'   => array(
				array(
					'eyebrow'       => 'LIGHTING CONTROLS · KINETIC WIRELESS SWITCHES',
					'eyebrow_color' => 'grey',
					'heading'       => 'Wireless switches that power themselves.',
					'body'          => 'Convert a simple press into wireless energy. No batteries, no wiring, self powered. Add a switch anywhere you want, retrofit or new build.',
					'button_label'  => 'Explore Kinetic controls',
					'button_url'    => '/product-category/kinetic/',
					'footnote'      => 'IN-HOUSE TECHNICAL SPECIALISTS ON HAND',
					'badge'         => '',
					'image_url'     => $img . 'kinetic.jpg',
					'image_alt'     => 'A kinetic wireless switch on a plain wall beside a glass pendant light',
					'theme'         => 'dark',
				),
				array(
					'eyebrow'       => 'DECORATIVE RANGE · BHS',
					'eyebrow_color' => 'red',
					'heading'       => 'The BHS Decorative Range has landed.',
					'body'          => 'Statement pendants, matching wall lights, floor and table lamps. Browse our extensive range to transform your home.',
					'button_label'  => 'Shop the BHS range',
					'button_url'    => '/product-category/decorative/',
					'footnote'      => 'EXPERTISE. EFFICIENCY. EXCELLENCE.',
					'badge'         => 'NEW IN',
					'image_url'     => $img . 'bhs.jpg',
					'image_alt'     => 'Four smoked-glass pendants over a dining table',
					'theme'         => 'light',
				),
				array(
					'eyebrow'       => 'FORUM LIGHTING SOLUTIONS',
					'eyebrow_color' => 'grey',
					'heading'       => 'Beautiful lighting, designed for every space.',
					'body'          => 'Kitchens, bathrooms, outdoor, indoor, LED tape, controls and worksite — designed in-house and priced to compete.',
					'button_label'  => 'See All Products',
					'button_url'    => '/shop/',
					'footnote'      => '',
					'badge'         => '',
					'image_url'     => $img . 'general.jpg',
					'image_alt'     => 'An up-and-down wall light on a white house at dusk',
					'theme'         => 'dark',
				),
			),
		);
	}

	/**
	 * Register the assets; enqueued only when a banner renders.
	 *
	 * @return void
	 */
	public static function register_assets() {
		if ( wp_style_is( 'epi-banner', 'registered' ) ) {
			return;
		}

		wp_register_style(
			'epi-banner',
			EPI_PLUGIN_URL . 'assets/css/epi-banner.css',
			array(),
			self::asset_version( 'assets/css/epi-banner.css' )
		);

		wp_register_script(
			'epi-banner',
			EPI_PLUGIN_URL . 'assets/js/epi-banner.js',
			array(),
			self::asset_version( 'assets/js/epi-banner.js' ),
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
	 * [forum_banner] — the designed banner, as is.
	 *
	 * @return string
	 */
	public static function shortcode() {
		return self::render( self::defaults() );
	}

	/**
	 * Print the slider.
	 *
	 * @param array $settings See defaults() for the shape.
	 * @return string
	 */
	public static function render( array $settings ) {
		$slides = isset( $settings['slides'] ) && is_array( $settings['slides'] ) ? array_values( $settings['slides'] ) : array();

		if ( ! $slides ) {
			return '';
		}

		wp_enqueue_style( 'epi-banner' );
		wp_enqueue_script( 'epi-banner' );

		$autoplay = ! empty( $settings['autoplay'] ) && 'no' !== $settings['autoplay'];
		$interval = isset( $settings['interval'] ) ? max( 2, min( 30, (int) $settings['interval'] ) ) : 6;
		$arrows   = ! isset( $settings['arrows'] ) || ( ! empty( $settings['arrows'] ) && 'no' !== $settings['arrows'] );
		$dots     = ! isset( $settings['dots'] ) || ( ! empty( $settings['dots'] ) && 'no' !== $settings['dots'] );
		$total    = count( $slides );
		$first    = 'light' === self::theme( $slides[0] );

		ob_start();
		?>
		<div
			class="epi-banner<?php echo $first ? ' epi-banner--light' : ''; ?>"
			data-autoplay="<?php echo esc_attr( $autoplay ? $interval : 0 ); ?>"
			role="region"
			aria-roledescription="<?php esc_attr_e( 'carousel', 'blueworx_client_forum' ); ?>"
			aria-label="<?php esc_attr_e( 'Featured', 'blueworx_client_forum' ); ?>"
		>
			<div class="epi-banner__slides">
				<?php foreach ( $slides as $i => $slide ) : ?>
					<?php self::slide( $slide, $i, $total ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( $arrows && $total > 1 ) : ?>
				<button type="button" class="epi-banner__arrow epi-banner__arrow--prev" aria-label="<?php esc_attr_e( 'Previous slide', 'blueworx_client_forum' ); ?>">
					<svg viewBox="0 0 16 30" aria-hidden="true" focusable="false"><path d="M15 1L1 15l14 14"/></svg>
				</button>
				<button type="button" class="epi-banner__arrow epi-banner__arrow--next" aria-label="<?php esc_attr_e( 'Next slide', 'blueworx_client_forum' ); ?>">
					<svg viewBox="0 0 16 30" aria-hidden="true" focusable="false"><path d="M1 1l14 14L1 29"/></svg>
				</button>
			<?php endif; ?>

			<?php if ( $dots && $total > 1 ) : ?>
				<div class="epi-banner__dots" role="tablist" aria-label="<?php esc_attr_e( 'Choose a slide', 'blueworx_client_forum' ); ?>">
					<?php for ( $i = 0; $i < $total; $i++ ) : ?>
						<button
							type="button"
							class="epi-banner__dot"
							role="tab"
							aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: %d: slide number. */ __( 'Slide %d', 'blueworx_client_forum' ), $i + 1 ) ); ?>"
						></button>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * A slide's colour scheme, defaulting to the dark panel.
	 *
	 * @param array $slide One slide.
	 * @return string 'dark' or 'light'.
	 */
	private static function theme( array $slide ) {
		return isset( $slide['theme'] ) && 'light' === $slide['theme'] ? 'light' : 'dark';
	}

	/**
	 * Print one slide.
	 *
	 * @param array $slide One slide.
	 * @param int   $index Its position, from 0.
	 * @param int   $total How many slides there are.
	 * @return void
	 */
	private static function slide( array $slide, $index, $total ) {
		$get = static function ( $key ) use ( $slide ) {
			return isset( $slide[ $key ] ) && is_scalar( $slide[ $key ] ) ? trim( (string) $slide[ $key ] ) : '';
		};

		$theme   = self::theme( $slide );
		$eyebrow = 'red' === $get( 'eyebrow_color' ) ? 'red' : 'grey';
		?>
		<article
			class="epi-banner__slide epi-banner__slide--<?php echo esc_attr( $theme ); ?>"
			aria-hidden="<?php echo 0 === $index ? 'false' : 'true'; ?>"
			role="group"
			aria-roledescription="<?php esc_attr_e( 'slide', 'blueworx_client_forum' ); ?>"
			aria-label="<?php echo esc_attr( sprintf( /* translators: 1: slide number, 2: how many slides. */ __( '%1$d of %2$d', 'blueworx_client_forum' ), $index + 1, $total ) ); ?>"
		>
			<div class="epi-banner__panel">
				<svg class="epi-banner__arc" viewBox="0 0 949 949" aria-hidden="true" focusable="false">
					<circle cx="474.5" cy="474.5" r="382" />
					<circle cx="474.5" cy="474.5" r="474" />
				</svg>
				<div class="epi-banner__text">
					<?php if ( $get( 'eyebrow' ) ) : ?>
						<p class="epi-banner__eyebrow epi-banner__eyebrow--<?php echo esc_attr( $eyebrow ); ?>"><?php echo esc_html( $get( 'eyebrow' ) ); ?></p>
					<?php endif; ?>
					<?php if ( $get( 'heading' ) ) : ?>
						<h2 class="epi-banner__heading"><?php echo esc_html( $get( 'heading' ) ); ?></h2>
					<?php endif; ?>
					<?php if ( $get( 'body' ) ) : ?>
						<p class="epi-banner__body"><?php echo esc_html( $get( 'body' ) ); ?></p>
					<?php endif; ?>
					<?php if ( $get( 'button_label' ) && $get( 'button_url' ) ) : ?>
						<a class="epi-banner__button" href="<?php echo esc_url( $get( 'button_url' ) ); ?>"<?php echo ! empty( $slide['button_new_tab'] ) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $get( 'button_label' ) ); ?></a>
					<?php endif; ?>
					<?php if ( $get( 'footnote' ) ) : ?>
						<p class="epi-banner__footnote"><?php echo esc_html( $get( 'footnote' ) ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="epi-banner__media">
				<?php if ( $get( 'image_url' ) ) : ?>
					<img src="<?php echo esc_url( $get( 'image_url' ) ); ?>" alt="<?php echo esc_attr( $get( 'image_alt' ) ); ?>" loading="<?php echo 0 === $index ? 'eager' : 'lazy'; ?>">
				<?php endif; ?>
				<?php if ( $get( 'badge' ) ) : ?>
					<span class="epi-banner__badge"><?php echo esc_html( $get( 'badge' ) ); ?></span>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}
}
