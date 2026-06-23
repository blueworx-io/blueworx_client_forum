<?php
/**
 * BlueWorx Lab control page (Settings -> BlueWorx Lab).
 *
 * Lists every feature with an on/off switch and saves the choices to the
 * epi_feature_flags option. Switching a feature off triggers a browser
 * confirmation (sterner wording for dangerous features) via assets/js/epi-lab.js.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves the feature control page.
 *
 * @since 1.1.0
 */
final class EPI_Lab_Page {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'bwlab';

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the page under the Settings menu.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'BlueWorx Lab', 'external-product-images' ),
			__( 'BlueWorx Lab', 'external-product-images' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Load page assets only on this screen.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'epi-lab', EPI_PLUGIN_URL . 'assets/css/epi-lab.css', array(), EPI_VERSION );
		wp_enqueue_script( 'epi-lab', EPI_PLUGIN_URL . 'assets/js/epi-lab.js', array(), EPI_VERSION, true );
	}

	/**
	 * Process a submitted toggle form.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! isset( $_POST['epi_lab_save'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'external-product-images' ) );
		}

		check_admin_referer( 'epi_lab_save', 'epi_lab_nonce' );

		$enabled_ids = isset( $_POST['epi_features'] ) ? (array) wp_unslash( $_POST['epi_features'] ) : array();
		EPI_Feature_Registry::save( $enabled_ids );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'updated' => 'true',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Render the control page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$groups      = EPI_Feature_Registry::groups();
		$definitions = EPI_Feature_Registry::definitions();

		$by_group = array();
		foreach ( $definitions as $id => $definition ) {
			$by_group[ $definition['group'] ][ $id ] = $definition;
		}
		?>
		<div class="wrap epi-lab">
			<h1><?php esc_html_e( 'BlueWorx Lab | Forum Lighting', 'external-product-images' ); ?></h1>
			<p class="epi-lab__intro">
				<?php esc_html_e( 'Switch each site feature on or off. Everything is on by default. Turning a feature off stops it running until you switch it back on.', 'external-product-images' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Feature settings saved.', 'external-product-images' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'epi_lab_save', 'epi_lab_nonce' ); ?>
				<input type="hidden" name="epi_lab_save" value="1" />

				<?php
				foreach ( $groups as $group_key => $group_label ) :
					if ( empty( $by_group[ $group_key ] ) ) {
						continue;
					}
					?>
					<section class="epi-lab__group">
						<h2 class="epi-lab__group-title"><?php echo esc_html( $group_label ); ?></h2>
						<div class="epi-lab__cards">
							<?php
							foreach ( $by_group[ $group_key ] as $id => $definition ) {
								self::render_card( $id, $definition );
							}
							?>
						</div>
					</section>
				<?php endforeach; ?>

				<?php submit_button( __( 'Save changes', 'external-product-images' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a single feature card with its switch.
	 *
	 * @param string $id         Feature id.
	 * @param array  $definition Feature definition.
	 * @return void
	 */
	private static function render_card( $id, $definition ) {
		$enabled = EPI_Feature_Registry::is_enabled( $id );
		$missing = EPI_Feature_Registry::missing_dependencies( $definition['dependencies'] );
		$blocked = ! empty( $missing );

		$danger_message = '';
		if ( ! empty( $definition['dangerous'] ) && ! empty( $definition['danger_message'] ) ) {
			$danger_message = $definition['danger_message'];
		}

		$classes = 'epi-lab__card';
		if ( ! empty( $definition['dangerous'] ) ) {
			$classes .= ' epi-lab__card--danger';
		}
		if ( $blocked ) {
			$classes .= ' epi-lab__card--blocked';
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<div class="epi-lab__card-head">
				<h3 class="epi-lab__card-title"><?php echo esc_html( $definition['title'] ); ?></h3>

				<label class="epi-lab__switch">
					<input
						type="checkbox"
						name="epi_features[]"
						value="<?php echo esc_attr( $id ); ?>"
						data-epi-toggle
						data-feature-title="<?php echo esc_attr( $definition['title'] ); ?>"
						<?php if ( '' !== $danger_message ) : ?>
							data-danger-message="<?php echo esc_attr( $danger_message ); ?>"
						<?php endif; ?>
						<?php checked( $enabled ); ?>
						<?php disabled( $blocked ); ?>
					/>
					<span class="epi-lab__slider" aria-hidden="true"></span>
					<span class="screen-reader-text"><?php echo esc_html( $definition['title'] ); ?></span>
				</label>

				<?php if ( $blocked && $enabled ) : ?>
					<input type="hidden" name="epi_features[]" value="<?php echo esc_attr( $id ); ?>" />
				<?php endif; ?>
			</div>

			<p class="epi-lab__card-desc"><?php echo esc_html( $definition['description'] ); ?></p>

			<?php if ( ! empty( $definition['dangerous'] ) ) : ?>
				<p class="epi-lab__card-flag"><?php esc_html_e( 'Sensitive - affects the live store.', 'external-product-images' ); ?></p>
			<?php endif; ?>

			<?php if ( $blocked ) : ?>
				<p class="epi-lab__card-blocked">
					<?php
					printf(
						/* translators: %s: comma-separated plugin names. */
						esc_html__( 'Requires %s (not active).', 'external-product-images' ),
						esc_html( implode( ', ', $missing ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
