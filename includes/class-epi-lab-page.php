<?php
/**
 * BlueWorx Lab control page (Settings -> BlueWorx Lab).
 *
 * Lists every feature with an on/off switch and saves the choices to the
 * epi_feature_flags option. Switching a feature off triggers a browser
 * confirmation (sterner wording for dangerous features) via assets/js/epi-lab.js.
 *
 * The screen is built from the shared blueworx-admin-design system: the page
 * header, the section nav, the panels, the switches and the sticky save bar all
 * come from there. The only styling this plugin keeps of its own is the
 * full-bleed chrome override in assets/css/epi-lab.css.
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
	 * Handle for the shared design system stylesheet.
	 */
	const DESIGN_HANDLE = 'blueworx-admin-design';

	/**
	 * Handle for the shared Lucide icon set.
	 */
	const ICONS_HANDLE = 'blueworx-admin-icons';

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
			__( 'BlueWorx Lab', 'blueworx_client_forum' ),
			__( 'BlueWorx Lab', 'blueworx_client_forum' ),
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

		self::enqueue_design_system();

		// The only styling this plugin owns: the chrome overrides that let the
		// screen run full width inside wp-admin. Right for a screen that is
		// entirely ours, and wrong for one we are only a guest on, which is why
		// it is here and not in enqueue_design_system().
		wp_enqueue_style( 'epi-lab', EPI_PLUGIN_URL . 'assets/css/epi-lab.css', array( self::DESIGN_HANDLE ), EPI_VERSION );

		wp_enqueue_script( 'epi-lab', EPI_PLUGIN_URL . 'assets/js/epi-lab.js', array(), EPI_VERSION, true );
	}

	/**
	 * The shared design system stylesheet and icons, and nothing else.
	 *
	 * Also used by the "WooCommerce is not active" notice, which appears on
	 * somebody else's screen: a notice with the styles but not the icons draws
	 * an empty box where the icon should be.
	 *
	 * @return void
	 */
	public static function enqueue_design_system() {
		// Copied verbatim from the foundation. Never edited here: CI compares
		// this file against the foundation on every pull request.
		wp_enqueue_style( self::DESIGN_HANDLE, EPI_PLUGIN_URL . 'assets/blueworx-admin-design.css', array(), EPI_VERSION );

		// A module, because the icon file is one: it upgrades every
		// [data-lucide] element in place and watches for new ones.
		if ( function_exists( 'wp_enqueue_script_module' ) ) {
			wp_enqueue_script_module( self::ICONS_HANDLE, EPI_PLUGIN_URL . 'assets/blueworx-admin-icons.js', array(), EPI_VERSION );
			return;
		}

		// WordPress below 6.5 has no module API. A plain script tag still runs
		// it, once the type is corrected on the way out.
		wp_enqueue_script( self::ICONS_HANDLE, EPI_PLUGIN_URL . 'assets/blueworx-admin-icons.js', array(), EPI_VERSION, true );
		add_filter( 'script_loader_tag', array( __CLASS__, 'icons_as_module' ), 10, 2 );
	}

	/**
	 * Say, on every admin screen, that WooCommerce is not active.
	 *
	 * Most features need it. The Lab page still works without it and shows
	 * which features are unavailable, so this is a warning rather than a
	 * failure.
	 *
	 * @return void
	 */
	public static function warn_woocommerce_missing() {
		add_action( 'admin_notices', array( __CLASS__, 'render_woocommerce_notice' ) );
		// The notice is a guest on somebody else's screen, so it has to bring
		// the design system with it. Enqueued here rather than while the notice
		// renders, which is too late for the page head.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_design_system' ) );
	}

	/**
	 * Render the "WooCommerce is not active" notice.
	 *
	 * @return void
	 */
	public static function render_woocommerce_notice() {
		?>
		<div class="bw-admin bw-notice bw-notice--warning" role="status">
			<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="triangle-alert" aria-hidden="true"></i>
			<div class="bw-notice__body">
				<p class="bw-notice__text">
					<?php esc_html_e( 'BlueWorx Lab | Forum Lighting works best with WooCommerce active. Most features will not run until WooCommerce is installed and active.', 'blueworx_client_forum' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Mark the icon script as a module on WordPress versions without the module API.
	 *
	 * @param mixed $tag    The script tag WordPress built.
	 * @param mixed $handle The handle it is for.
	 * @return string
	 */
	public static function icons_as_module( $tag, $handle = '' ) {
		return self::ICONS_HANDLE === $handle
			? str_replace( '<script ', '<script type="module" ', (string) $tag )
			: (string) $tag;
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
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'blueworx_client_forum' ) );
		}

		check_admin_referer( 'epi_lab_save', 'epi_lab_nonce' );

		$enabled_ids = isset( $_POST['epi_features'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['epi_features'] ) )
			: array();
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

		// Only groups that actually have features. An empty section in the nav
		// is a promise the screen cannot keep.
		$sections = array();
		foreach ( $groups as $group_key => $group_label ) {
			if ( ! empty( $by_group[ $group_key ] ) ) {
				$sections[ $group_key ] = $group_label;
			}
		}

		if ( empty( $sections ) ) {
			return;
		}

		$section_keys  = array_keys( $sections );
		$first_section = $section_keys[0];
		$saved         = isset( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap bw-wrap">
			<div class="bw-admin bw-page">
				<header class="bw-pagehead">
					<div class="bw-pagehead__titles">
						<p class="bw-pagehead__eyebrow"><?php esc_html_e( 'BlueWorx Lab', 'blueworx_client_forum' ); ?></p>
						<h1 class="bw-pagehead__h1"><?php esc_html_e( 'Forum Lighting', 'blueworx_client_forum' ); ?></h1>
						<p class="bw-pagehead__lede">
							<?php esc_html_e( 'Switch each site feature on or off. Everything is on by default. Turning a feature off stops it running until you switch it back on.', 'blueworx_client_forum' ); ?>
						</p>
					</div>
				</header>

				<form id="epi-lab-form" class="bw-page__body" method="post" action="">
					<?php wp_nonce_field( 'epi_lab_save', 'epi_lab_nonce' ); ?>
					<input type="hidden" name="epi_lab_save" value="1" />

					<nav class="bw-secnav" aria-label="<?php esc_attr_e( 'Sections', 'blueworx_client_forum' ); ?>">
						<?php foreach ( $sections as $group_key => $group_label ) : ?>
							<button
								type="button"
								class="bw-secnav__item<?php echo $group_key === $first_section ? ' is-active' : ''; ?>"
								data-epi-section-link="<?php echo esc_attr( $group_key ); ?>"
								<?php echo $group_key === $first_section ? ' aria-current="true"' : ''; ?>
							>
								<span><?php echo esc_html( $group_label ); ?></span>
								<span class="bw-secnav__meta"><?php echo esc_html( (string) count( $by_group[ $group_key ] ) ); ?></span>
							</button>
						<?php endforeach; ?>
					</nav>

					<div class="bw-panels">
						<?php if ( $saved ) : ?>
							<div class="bw-notice bw-notice--success" role="status">
								<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="circle-check" aria-hidden="true"></i>
								<div class="bw-notice__body">
									<p class="bw-notice__text"><?php esc_html_e( 'Feature settings saved.', 'blueworx_client_forum' ); ?></p>
								</div>
							</div>
						<?php endif; ?>

						<?php
						foreach ( $sections as $group_key => $group_label ) {
							self::render_section( $group_key, $group_label, $by_group[ $group_key ], $group_key === $first_section );
						}
						?>
					</div>
				</form>

				<div class="bw-savebar">
					<p class="bw-savebar__hint">
						<i class="bw-icon bw-icon--18" data-lucide="info" aria-hidden="true"></i>
						<?php esc_html_e( 'Changes apply to the live site as soon as you save.', 'blueworx_client_forum' ); ?>
					</p>
					<button type="submit" form="epi-lab-form" class="bw-btn bw-btn--primary">
						<?php esc_html_e( 'Save changes', 'blueworx_client_forum' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one group of features as a panel.
	 *
	 * Every panel is in the page at once — hidden panels still post their
	 * switches, so one Save covers the whole screen however the nav is left.
	 *
	 * @param string $group_key   Group id.
	 * @param string $group_label Group heading.
	 * @param array  $definitions Feature definitions in this group.
	 * @param bool   $is_first    Whether this is the section shown on load.
	 * @return void
	 */
	private static function render_section( $group_key, $group_label, $definitions, $is_first ) {
		?>
		<section
			class="bw-card"
			data-epi-section="<?php echo esc_attr( $group_key ); ?>"
			<?php echo $is_first ? '' : ' hidden'; ?>
		>
			<div class="bw-card__head">
				<div class="bw-card__titles">
					<h2 class="bw-card__title"><?php echo esc_html( $group_label ); ?></h2>
				</div>
			</div>
			<div class="bw-card__body">
				<?php if ( 'debug' === $group_key ) : ?>
					<p class="bw-card__note">
						<?php esc_html_e( 'These print to every visitor\'s browser console while switched on, so turn them off once you are done.', 'blueworx_client_forum' ); ?>
					</p>
				<?php endif; ?>

				<div class="bw-fields bw-fields--single">
					<?php
					foreach ( $definitions as $id => $definition ) {
						self::render_feature( $id, $definition );
					}
					?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Render a single feature switch.
	 *
	 * @param string $id         Feature id.
	 * @param array  $definition Feature definition.
	 * @return void
	 */
	private static function render_feature( $id, $definition ) {
		$enabled = EPI_Feature_Registry::is_enabled( $id );
		$missing = EPI_Feature_Registry::missing_dependencies( $definition['dependencies'] );
		$blocked = ! empty( $missing );

		$danger_message = '';
		if ( ! empty( $definition['dangerous'] ) && ! empty( $definition['danger_message'] ) ) {
			$danger_message = $definition['danger_message'];
		}
		?>
		<div>
			<label class="bw-switch bw-switch--bare">
				<input
					type="checkbox"
					role="switch"
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
				<span class="bw-switch__track"><span class="bw-switch__thumb"></span></span>
				<span class="bw-switch__label">
					<?php echo esc_html( $definition['title'] ); ?>
					<?php if ( ! empty( $definition['dangerous'] ) ) : ?>
						<span class="bw-badge bw-badge--warning"><?php esc_html_e( 'Sensitive', 'blueworx_client_forum' ); ?></span>
					<?php endif; ?>
					<small><?php echo esc_html( $definition['description'] ); ?></small>
				</span>
			</label>

			<?php if ( $blocked && $enabled ) : ?>
				<?php // A blocked switch is disabled, so it posts nothing. This keeps the feature on rather than silently switching it off on the next save. ?>
				<input type="hidden" name="epi_features[]" value="<?php echo esc_attr( $id ); ?>" />
			<?php endif; ?>

			<?php if ( $blocked ) : ?>
				<p class="bw-fieldnote">
					<i class="bw-icon bw-icon--14" data-lucide="triangle-alert" aria-hidden="true"></i>
					<?php
					printf(
						/* translators: %s: comma-separated plugin names. */
						esc_html__( 'Requires %s (not active).', 'blueworx_client_forum' ),
						esc_html( implode( ', ', $missing ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
