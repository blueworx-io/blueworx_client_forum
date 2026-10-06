<?php
/**
 * Product import page (Products -> Product import).
 *
 * The record of every ePim pull: a settings card, the last pull at a glance,
 * and a table of runs, each opening to a product-by-product view. Built from
 * the shared blueworx-admin-design system; this plugin's own CSS is only the
 * full-bleed chrome override.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the page and handles its forms.
 */
final class EPI_Product_Import_Page {

	/**
	 * Admin page slug.
	 */
	const SLUG = 'epi-product-import';

	/**
	 * Runs per page of the table.
	 */
	const RUNS_PER_PAGE = 20;

	/**
	 * Products per page of the detail view.
	 */
	const ITEMS_PER_PAGE = 50;

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add the page under Products. Administrators only.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'Product import', 'blueworx_client_forum' ),
			__( 'Product import', 'blueworx_client_forum' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The page's address, with extra query values.
	 *
	 * @param array $args Query values.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'post_type' => 'product',
					'page'      => self::SLUG,
				),
				$args
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Load assets on this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'product_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		EPI_Lab_Page::enqueue_design_system();
		wp_enqueue_style( 'epi-product-import', EPI_PLUGIN_URL . 'assets/css/epi-product-import.css', array( EPI_Lab_Page::DESIGN_HANDLE ), EPI_VERSION );
		wp_enqueue_script( 'epi-product-import', EPI_PLUGIN_URL . 'assets/js/epi-product-import.js', array(), EPI_VERSION, true );
	}

	/**
	 * Save settings, or start a pull, then come back with a notice.
	 *
	 * @return void
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['epi_pull_action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage the product import.', 'blueworx_client_forum' ) );
		}

		$action = sanitize_key( wp_unslash( $_POST['epi_pull_action'] ) );
		check_admin_referer( 'epi_pull_' . $action, 'epi_pull_nonce' );

		$notice = 'saved';

		if ( 'settings' === $action ) {
			EPI_Pull_Settings::save(
				array(
					'key'    => isset( $_POST['epi_pull_key'] ) ? sanitize_text_field( wp_unslash( $_POST['epi_pull_key'] ) ) : '',
					'images' => ! empty( $_POST['epi_pull_images'] ),
					'test'   => ! empty( $_POST['epi_pull_test'] ),
				)
			);
		} elseif ( 'pull' === $action || 'full' === $action ) {
			$started = EPI_Pull_Runner::start( 'manual', 'full' === $action );
			$notice  = is_wp_error( $started ) ? $started->get_error_code() : 'started';
		} elseif ( 'refresh' === $action ) {
			$count = EPI_Pull_Runner::refresh_categories();

			if ( is_wp_error( $count ) ) {
				set_transient( 'epi_pull_last_error', $count->get_error_message(), MINUTE_IN_SECONDS );
				$notice = 'error';
			} else {
				$notice = 'categories';
			}
		} elseif ( 'category' === $action ) {
			$category_id = isset( $_POST['epi_pull_category'] ) ? absint( wp_unslash( $_POST['epi_pull_category'] ) ) : 0;

			if ( ! $category_id ) {
				$notice = 'nocategory';
			} else {
				$started = EPI_Pull_Runner::start( 'manual', true, array( 'category_id' => $category_id ) );
				$notice  = is_wp_error( $started ) ? $started->get_error_code() : 'started';
			}
		}

		wp_safe_redirect( self::url( array( 'notice' => $notice ) ) );
		exit;
	}

	/**
	 * Render the page: the detail view when a run is asked for, else the list.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view choice for an admin screen; sanitised with absint().
		$run_id = isset( $_GET['run'] ) ? absint( wp_unslash( $_GET['run'] ) ) : 0;

		if ( $run_id ) {
			self::render_detail( $run_id );
			return;
		}

		self::render_list();
	}

	/**
	 * The list view: settings, last pull, every run.
	 *
	 * The page callback runs after wp-admin has sent its header, so a view
	 * that cannot be shown falls back to this one with a notice rather than
	 * redirecting.
	 *
	 * @param string $forced_notice A notice to show instead of the one in the URL.
	 * @return void
	 */
	private static function render_list( $forced_notice = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging and notice for an admin screen; sanitised below.
		$page_no = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$notice = '' !== $forced_notice ? $forced_notice : ( isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '' );

		$settings = EPI_Pull_Settings::get();
		$choices  = EPI_Pull_Categories::choices();
		$total   = EPI_Pull_Store::count_runs();
		$pages    = max( 1, (int) ceil( $total / self::RUNS_PER_PAGE ) );
		$page_no  = min( $page_no, $pages );
		$runs     = EPI_Pull_Store::get_runs( $page_no, self::RUNS_PER_PAGE );
		$last     = EPI_Pull_Store::last_successful_run();
		$newest   = EPI_Pull_Store::get_runs( 1, 1 );
		$latest   = $newest ? $newest[0] : null;
		$next     = (int) wp_next_scheduled( EPI_Pull_Runner::DAILY_HOOK );
		?>
		<div class="wrap bw-wrap">
			<div class="bw-admin bw-page">
				<header class="bw-pagehead">
					<div class="bw-pagehead__titles">
						<p class="bw-pagehead__eyebrow"><?php esc_html_e( 'ePim', 'blueworx_client_forum' ); ?></p>
						<h1 class="bw-pagehead__h1"><?php esc_html_e( 'Product import', 'blueworx_client_forum' ); ?></h1>
						<p class="bw-pagehead__lede">
							<?php esc_html_e( 'Products are pulled from ePim once a day at 02:00 and whenever you ask. Every pull is listed here, product by product, so you can see exactly what ePim sent.', 'blueworx_client_forum' ); ?>
						</p>
					</div>
					<div class="bw-pagehead__actions">
						<form method="post" action="">
							<?php wp_nonce_field( 'epi_pull_full', 'epi_pull_nonce' ); ?>
							<input type="hidden" name="epi_pull_action" value="full" />
							<button type="submit" class="bw-btn" data-epi-confirm="<?php esc_attr_e( 'This fetches every product from ePim again, not just the changes. Carry on?', 'blueworx_client_forum' ); ?>">
								<?php esc_html_e( 'Re-import everything', 'blueworx_client_forum' ); ?>
							</button>
						</form>
						<form method="post" action="">
							<?php wp_nonce_field( 'epi_pull_pull', 'epi_pull_nonce' ); ?>
							<input type="hidden" name="epi_pull_action" value="pull" />
							<button type="submit" class="bw-btn bw-btn--primary">
								<i class="bw-icon" data-lucide="refresh-cw" aria-hidden="true"></i>
								<?php esc_html_e( 'Pull now', 'blueworx_client_forum' ); ?>
							</button>
						</form>
					</div>
				</header>

				<div class="bw-page__body bw-page__body--single">
					<div class="bw-panels">
						<?php self::render_notice( $notice ); ?>

						<?php if ( $settings['test'] ) : ?>
							<div class="bw-notice bw-notice--info" role="status">
								<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="info" aria-hidden="true"></i>
								<div class="bw-notice__body">
									<p class="bw-notice__title"><?php esc_html_e( 'Test mode is on', 'blueworx_client_forum' ); ?></p>
									<p class="bw-notice__text"><?php esc_html_e( 'Pulls record what they would add, update or hide, and change nothing. Switch it off in Settings when you are happy with what you see.', 'blueworx_client_forum' ); ?></p>
								</div>
							</div>
						<?php endif; ?>

						<form method="post" action="">
							<?php wp_nonce_field( 'epi_pull_settings', 'epi_pull_nonce' ); ?>
							<input type="hidden" name="epi_pull_action" value="settings" />
							<section class="bw-card">
								<div class="bw-card__head">
									<div class="bw-card__titles">
										<h2 class="bw-card__title"><?php esc_html_e( 'Settings', 'blueworx_client_forum' ); ?></h2>
									</div>
								</div>
								<div class="bw-card__body">
									<div class="bw-fields bw-fields--single">
										<div class="bw-field bw-field--wide">
											<label class="bw-field__label" for="epi_pull_key"><?php esc_html_e( 'Subscription key', 'blueworx_client_forum' ); ?></label>
											<input class="bw-input bw-input--mono" id="epi_pull_key" name="epi_pull_key" type="text" value="<?php echo esc_attr( $settings['key'] ); ?>" autocomplete="off" spellcheck="false" />
											<p class="bw-field__help"><?php esc_html_e( 'From the ePim team. It is sent with every request and stored only on this site.', 'blueworx_client_forum' ); ?></p>
										</div>
										<div>
											<label class="bw-switch bw-switch--bare">
												<input type="checkbox" role="switch" name="epi_pull_test" value="1" <?php checked( $settings['test'] ); ?> />
												<span class="bw-switch__track"><span class="bw-switch__thumb"></span></span>
												<span class="bw-switch__label">
													<?php esc_html_e( 'Test mode', 'blueworx_client_forum' ); ?>
													<small><?php esc_html_e( 'Pulls report what they would change and change nothing. Start here, then switch off once a test pull looks right.', 'blueworx_client_forum' ); ?></small>
												</span>
											</label>
										</div>
										<div>
											<label class="bw-switch bw-switch--bare">
												<input type="checkbox" role="switch" name="epi_pull_images" value="1" <?php checked( $settings['images'] ); ?> />
												<span class="bw-switch__track"><span class="bw-switch__thumb"></span></span>
												<span class="bw-switch__label">
													<?php esc_html_e( 'Set product pictures from ePim', 'blueworx_client_forum' ); ?>
													<small><?php esc_html_e( 'Leave off while ePim is still pushing products to this site, or the two will keep changing each other\'s pictures. Switch on once the push is off.', 'blueworx_client_forum' ); ?></small>
												</span>
											</label>
										</div>
									</div>
								</div>
								<div class="bw-card__foot">
									<button type="submit" class="bw-btn bw-btn--primary"><?php esc_html_e( 'Save settings', 'blueworx_client_forum' ); ?></button>
								</div>
							</section>
						</form>

						<section class="bw-card">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Pull one category', 'blueworx_client_forum' ); ?></h2>
								</div>
								<div class="bw-card__actions">
									<form method="post" action="">
										<?php wp_nonce_field( 'epi_pull_refresh', 'epi_pull_nonce' ); ?>
										<input type="hidden" name="epi_pull_action" value="refresh" />
										<button type="submit" class="bw-btn bw-btn--sm">
											<i class="bw-icon bw-icon--14" data-lucide="refresh-cw" aria-hidden="true"></i>
											<?php esc_html_e( 'Refresh categories from ePim', 'blueworx_client_forum' ); ?>
										</button>
									</form>
								</div>
							</div>
							<form method="post" action="">
								<?php wp_nonce_field( 'epi_pull_category', 'epi_pull_nonce' ); ?>
								<input type="hidden" name="epi_pull_action" value="category" />
								<div class="bw-card__body">
									<div class="bw-fields">
										<div class="bw-field">
											<label class="bw-field__label" for="epi_pull_category"><?php esc_html_e( 'Category', 'blueworx_client_forum' ); ?></label>
											<span class="bw-select">
												<select class="bw-select__el" id="epi_pull_category" name="epi_pull_category">
													<option value=""><?php esc_html_e( 'Choose a category', 'blueworx_client_forum' ); ?></option>
													<?php foreach ( $choices as $epim_id => $label ) : ?>
														<option value="<?php echo esc_attr( (string) $epim_id ); ?>"><?php echo esc_html( $label ); ?></option>
													<?php endforeach; ?>
												</select>
												<i class="bw-icon bw-icon--14 bw-select__arrow" data-lucide="chevron-down" aria-hidden="true"></i>
											</span>
											<p class="bw-field__help">
													<?php
													if ( empty( $choices ) ) {
														esc_html_e( 'No ePim categories yet. Use Refresh categories from ePim first.', 'blueworx_client_forum' );
													} else {
														esc_html_e( 'Covers the category and everything under it. Refresh first so new ePim categories appear here.', 'blueworx_client_forum' );
														if ( $settings['test'] ) {
															esc_html_e( ' Refreshing updates the shop\'s product categories to match ePim, even in test mode.', 'blueworx_client_forum' );
														}
													}
													?>
												</p>
										</div>
									</div>
								</div>
								<div class="bw-card__foot">
									<button type="submit" class="bw-btn bw-btn--primary" <?php disabled( empty( $choices ) ); ?>><?php esc_html_e( 'Pull this category', 'blueworx_client_forum' ); ?></button>
								</div>
							</form>
						</section>

						<div class="bw-stats">
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="refresh-cw" aria-hidden="true"></i><?php esc_html_e( 'Last pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value"><?php echo $latest ? esc_html( self::when( $latest->started_at ) ) : esc_html__( 'Never', 'blueworx_client_forum' ); ?></p>
								</div>
								<p class="bw-stat__foot"><?php echo $latest ? esc_html( self::status_label( $latest->status ) . ', ' . self::trigger_label( $latest->trigger_type ) . ( $latest->is_test ? ', ' . __( 'test', 'blueworx_client_forum' ) : '' ) ) : esc_html__( 'Use Pull now to start the first one.', 'blueworx_client_forum' ); ?></p>
							</div>
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="calendar" aria-hidden="true"></i><?php esc_html_e( 'Next automatic pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value"><?php echo $next ? esc_html( wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $next ) ) : esc_html__( 'Not scheduled', 'blueworx_client_forum' ); ?></p>
								</div>
								<p class="bw-stat__foot"><?php esc_html_e( 'Fetches only what changed since the last successful pull.', 'blueworx_client_forum' ); ?></p>
							</div>
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="circle-check" aria-hidden="true"></i><?php esc_html_e( 'Last successful pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value">
										<?php
										if ( $last ) {
											printf(
												/* translators: 1: added, 2: updated, 3: hidden. */
												esc_html__( '%1$d added, %2$d updated, %3$d hidden', 'blueworx_client_forum' ),
												(int) $last->added,
												(int) $last->updated,
												(int) $last->hidden
											);
										} else {
											esc_html_e( 'None yet', 'blueworx_client_forum' );
										}
										?>
									</p>
								</div>
								<p class="bw-stat__foot"><?php echo $last ? esc_html( self::when( $last->started_at ) ) : ''; ?></p>
							</div>
						</div>

						<section class="bw-card bw-card--flush">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Pulls', 'blueworx_client_forum' ); ?></h2>
								</div>
							</div>
							<?php if ( ! $runs ) : ?>
								<div class="bw-empty">
									<i class="bw-icon bw-icon--28 bw-empty__icon" data-lucide="refresh-cw" aria-hidden="true"></i>
									<h3 class="bw-empty__title"><?php esc_html_e( 'No pulls yet', 'blueworx_client_forum' ); ?></h3>
									<p class="bw-empty__text"><?php esc_html_e( 'The first pull fetches every product from ePim. Save the subscription key, then use Pull now.', 'blueworx_client_forum' ); ?></p>
								</div>
							<?php else : ?>
								<div class="bw-tablescroll">
									<table class="bw-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'When', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Trigger', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Added', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Updated', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Hidden', 'blueworx_client_forum' ); ?></th>
												<th scope="col" class="bw-table__num"><?php esc_html_e( 'Errors', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Status', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'View', 'blueworx_client_forum' ); ?></span></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $runs as $run ) : ?>
												<tr>
													<td>
														<span class="bw-table__primary"><?php echo esc_html( self::when( $run->started_at ) ); ?></span>
														<?php if ( $run->is_full && ! $run->category_id ) : ?>
															<span class="bw-table__sub"><?php esc_html_e( 'Full import', 'blueworx_client_forum' ); ?></span>
														<?php endif; ?>
													</td>
													<td>
														<?php echo esc_html( self::trigger_label( $run->trigger_type ) ); ?>
														<?php if ( '' !== (string) $run->category_name ) : ?>
															<span class="bw-table__sub"><?php echo esc_html( $run->category_name ); ?></span>
														<?php endif; ?>
													</td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->added ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->updated ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->hidden ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->errors ); ?></td>
													<td>
														<?php self::render_status_badge( $run->status ); ?>
														<?php if ( $run->is_test ) : ?>
															<span class="bw-badge bw-badge--neutral"><?php esc_html_e( 'Test', 'blueworx_client_forum' ); ?></span>
														<?php endif; ?>
													</td>
													<td class="bw-table__actions">
														<a class="bw-btn bw-btn--sm" href="<?php echo esc_url( self::url( array( 'run' => (int) $run->id ) ) ); ?>"><?php esc_html_e( 'View', 'blueworx_client_forum' ); ?></a>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<?php self::render_pager( $page_no, $pages, $total, array() ); ?>
							<?php endif; ?>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The notice for the last action, if any.
	 *
	 * @param string $notice Notice code from the redirect.
	 * @return void
	 */
	private static function render_notice( $notice ) {
		$notices = array(
			'saved'                   => array( 'success', __( 'Settings saved.', 'blueworx_client_forum' ) ),
			'started'                 => array( 'success', __( 'Pull started. It runs in the background; refresh this page to follow it.', 'blueworx_client_forum' ) ),
			'epi_pull_running'        => array( 'warning', __( 'A pull is already running. Wait for it to finish, then try again.', 'blueworx_client_forum' ) ),
			'epi_pull_no_key'         => array( 'warning', __( 'No ePim subscription key is saved. Add it in Settings, save, then pull.', 'blueworx_client_forum' ) ),
			'missing'                 => array( 'warning', __( 'That pull could not be found. It may have been older than 90 days and removed.', 'blueworx_client_forum' ) ),
			'epi_pull_store'          => array( 'danger', __( 'The pull could not be recorded. Check the PHP error log.', 'blueworx_client_forum' ) ),
			'epi_pull_no_woocommerce' => array( 'warning', __( 'WooCommerce is not active, so products cannot be pulled.', 'blueworx_client_forum' ) ),
			'categories'              => array( 'success', __( 'Categories refreshed from ePim.', 'blueworx_client_forum' ) ),
			'nocategory'              => array( 'warning', __( 'Choose a category first.', 'blueworx_client_forum' ) ),
			'error'                   => array( 'danger', (string) get_transient( 'epi_pull_last_error' ) ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		list( $tone, $text ) = $notices[ $notice ];

		if ( 'error' === $notice ) {
			delete_transient( 'epi_pull_last_error' );
		}

		if ( '' === $text ) {
			return;
		}

		$icon = 'success' === $tone ? 'circle-check' : 'triangle-alert';
		?>
		<div class="bw-notice bw-notice--<?php echo esc_attr( $tone ); ?>" role="<?php echo 'danger' === $tone ? 'alert' : 'status'; ?>">
			<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
			<div class="bw-notice__body">
				<p class="bw-notice__text"><?php echo esc_html( $text ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * A run's status as a badge.
	 *
	 * @param string $status Run status.
	 * @return void
	 */
	private static function render_status_badge( $status ) {
		$tones = array(
			'queued'  => 'neutral',
			'running' => 'warning',
			'done'    => 'success',
			'failed'  => 'danger',
		);
		$tone  = isset( $tones[ $status ] ) ? $tones[ $status ] : 'neutral';
		?>
		<span class="bw-badge bw-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span>
		<?php
	}

	/**
	 * Previous / next links under a table.
	 *
	 * @param int   $page_no Current page.
	 * @param int   $pages   Page count.
	 * @param int   $total   Row count.
	 * @param array $args    Extra query values to keep (the run, on the detail view).
	 * @return void
	 */
	private static function render_pager( $page_no, $pages, $total, array $args ) {
		?>
		<div class="bw-tablefoot">
			<span>
				<?php
				printf(
					/* translators: 1: current page, 2: page count, 3: row count. */
					esc_html__( 'Page %1$d of %2$d, %3$d in all', 'blueworx_client_forum' ),
					(int) $page_no,
					(int) $pages,
					(int) $total
				);
				?>
			</span>
			<?php if ( $pages > 1 ) : ?>
				<nav class="bw-pager" aria-label="<?php esc_attr_e( 'Pages', 'blueworx_client_forum' ); ?>">
					<div class="bw-pager__btns">
						<?php if ( $page_no > 1 ) : ?>
							<a class="bw-pager__btn" href="<?php echo esc_url( self::url( $args + array( 'paged' => $page_no - 1 ) ) ); ?>"><?php esc_html_e( 'Previous', 'blueworx_client_forum' ); ?></a>
						<?php endif; ?>
						<?php if ( $page_no < $pages ) : ?>
							<a class="bw-pager__btn" href="<?php echo esc_url( self::url( $args + array( 'paged' => $page_no + 1 ) ) ); ?>"><?php esc_html_e( 'Next', 'blueworx_client_forum' ); ?></a>
						<?php endif; ?>
					</div>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * A stored UTC time in the site's date and time format.
	 *
	 * @param string $utc 'Y-m-d H:i:s' in UTC.
	 * @return string
	 */
	private static function when( $utc ) {
		$timestamp = strtotime( (string) $utc . ' UTC' );

		return $timestamp ? wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $timestamp ) : '';
	}

	/**
	 * A status in words.
	 *
	 * @param string $status Run status.
	 * @return string
	 */
	private static function status_label( $status ) {
		$labels = array(
			'queued'  => __( 'Queued', 'blueworx_client_forum' ),
			'running' => __( 'Running', 'blueworx_client_forum' ),
			'done'    => __( 'Done', 'blueworx_client_forum' ),
			'failed'  => __( 'Failed', 'blueworx_client_forum' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : (string) $status;
	}

	/**
	 * A trigger in words.
	 *
	 * @param string $trigger 'auto' or 'manual'.
	 * @return string
	 */
	private static function trigger_label( $trigger ) {
		return 'manual' === $trigger ? __( 'Manual', 'blueworx_client_forum' ) : __( 'Auto', 'blueworx_client_forum' );
	}

	/**
	 * The detail view: one run, product by product.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private static function render_detail( $run_id ) {
		$run = EPI_Pull_Store::get_run( $run_id );

		if ( ! $run ) {
			self::render_list( 'missing' );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only paging for an admin screen; sanitised with absint().
		$page_no = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$total   = EPI_Pull_Store::count_items( $run_id );
		$pages   = max( 1, (int) ceil( $total / self::ITEMS_PER_PAGE ) );
		$page_no = min( $page_no, $pages );
		$items   = EPI_Pull_Store::get_items( $run_id, $page_no, self::ITEMS_PER_PAGE );
		$json    = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		?>
		<div class="wrap bw-wrap">
			<div class="bw-admin bw-page">
				<header class="bw-pagehead">
					<div class="bw-pagehead__titles">
						<p class="bw-pagehead__eyebrow"><?php esc_html_e( 'Product import', 'blueworx_client_forum' ); ?></p>
						<h1 class="bw-pagehead__h1">
							<?php
							/* translators: %s: date and time. */
							printf( esc_html__( 'Pull on %s', 'blueworx_client_forum' ), esc_html( self::when( $run->started_at ) ) );
							?>
						</h1>
						<p class="bw-pagehead__lede"><?php esc_html_e( 'Every product this pull added, updated or hid, with each changed field before and after, and the record exactly as ePim sent it.', 'blueworx_client_forum' ); ?></p>
					</div>
					<div class="bw-pagehead__actions">
						<a class="bw-btn" href="<?php echo esc_url( self::url() ); ?>">
							<i class="bw-icon" data-lucide="arrow-left" aria-hidden="true"></i>
							<?php esc_html_e( 'Back to pulls', 'blueworx_client_forum' ); ?>
						</a>
					</div>
				</header>

				<div class="bw-page__body bw-page__body--single">
					<div class="bw-panels">
						<?php if ( $run->is_test ) : ?>
							<div class="bw-notice bw-notice--info" role="status">
								<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="info" aria-hidden="true"></i>
								<div class="bw-notice__body">
									<p class="bw-notice__title"><?php esc_html_e( 'Test pull', 'blueworx_client_forum' ); ?></p>
									<p class="bw-notice__text"><?php esc_html_e( 'This was a test pull: nothing on the site was changed. Each row shows what a real pull would do. New ePim categories are only shown once they have been refreshed.', 'blueworx_client_forum' ); ?></p>
								</div>
							</div>
						<?php endif; ?>
						<section class="bw-card">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Summary', 'blueworx_client_forum' ); ?></h2>
								</div>
							</div>
							<div class="bw-card__body">
								<dl class="bw-dl">
									<dt><?php esc_html_e( 'Trigger', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo esc_html( self::trigger_label( $run->trigger_type ) ); ?></dd>
									<?php if ( '' !== (string) $run->category_name ) : ?>
										<dt><?php esc_html_e( 'Category', 'blueworx_client_forum' ); ?></dt>
										<dd><?php echo esc_html( $run->category_name ); ?></dd>
									<?php endif; ?>
									<dt><?php esc_html_e( 'Status', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo esc_html( self::status_label( $run->status ) ); ?></dd>
									<dt><?php esc_html_e( 'Mode', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo $run->is_test ? esc_html__( 'Test (nothing changed)', 'blueworx_client_forum' ) : esc_html__( 'Live', 'blueworx_client_forum' ); ?></dd>
									<dt><?php esc_html_e( 'Started', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo esc_html( self::when( $run->started_at ) ); ?></dd>
									<dt><?php esc_html_e( 'Finished', 'blueworx_client_forum' ); ?></dt>
									<dd><?php echo $run->finished_at ? esc_html( self::when( $run->finished_at ) ) : esc_html__( 'Not yet', 'blueworx_client_forum' ); ?></dd>
									<dt><?php esc_html_e( 'Asked ePim for', 'blueworx_client_forum' ); ?></dt>
									<dd>
										<?php
										if ( '' === (string) $run->since_utc ) {
											esc_html_e( 'Every product', 'blueworx_client_forum' );
										} else {
											/* translators: %s: date and time. */
											printf( esc_html__( 'Changes since %s', 'blueworx_client_forum' ), esc_html( self::when( str_replace( array( 'T', 'Z' ), array( ' ', '' ), $run->since_utc ) ) ) );
										}
										?>
									</dd>
									<dt><?php esc_html_e( 'Counts', 'blueworx_client_forum' ); ?></dt>
									<dd>
										<?php
										printf(
											/* translators: 1: added, 2: updated, 3: hidden, 4: unchanged, 5: skipped, 6: errors. */
											esc_html__( '%1$d added, %2$d updated, %3$d hidden, %4$d unchanged, %5$d skipped, %6$d errors', 'blueworx_client_forum' ),
											(int) $run->added,
											(int) $run->updated,
											(int) $run->hidden,
											(int) $run->unchanged,
											(int) $run->skipped,
											(int) $run->errors
										);
										?>
									</dd>
									<?php if ( '' !== (string) $run->message ) : ?>
										<dt><?php esc_html_e( 'Message', 'blueworx_client_forum' ); ?></dt>
										<dd><?php echo esc_html( $run->message ); ?></dd>
									<?php endif; ?>
								</dl>
							</div>
						</section>

						<section class="bw-card bw-card--flush">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title"><?php esc_html_e( 'Products', 'blueworx_client_forum' ); ?></h2>
								</div>
							</div>
							<?php if ( ! $items ) : ?>
								<div class="bw-empty">
									<i class="bw-icon bw-icon--28 bw-empty__icon" data-lucide="circle-check" aria-hidden="true"></i>
									<h3 class="bw-empty__title"><?php esc_html_e( 'Nothing changed', 'blueworx_client_forum' ); ?></h3>
									<p class="bw-empty__text"><?php esc_html_e( 'ePim sent nothing this pull needed to add, update or hide.', 'blueworx_client_forum' ); ?></p>
								</div>
							<?php else : ?>
								<div class="bw-tablescroll">
									<table class="bw-table">
										<thead>
											<tr>
												<th scope="col"><?php esc_html_e( 'Product', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Result', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'Changes', 'blueworx_client_forum' ); ?></th>
												<th scope="col"><?php esc_html_e( 'From ePim', 'blueworx_client_forum' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $items as $item ) : ?>
												<tr>
													<td>
														<span class="bw-table__primary"><?php echo esc_html( '' !== $item['name'] ? $item['name'] : __( '(no name)', 'blueworx_client_forum' ) ); ?></span>
														<span class="bw-table__sub"><?php echo esc_html( '' !== $item['sku'] ? $item['sku'] : __( 'No SKU', 'blueworx_client_forum' ) ); ?></span>
														<?php if ( $item['product_id'] ) : ?>
															<span class="bw-table__sub"><a href="<?php echo esc_url( get_edit_post_link( (int) $item['product_id'], 'raw' ) ); ?>"><?php esc_html_e( 'Open product', 'blueworx_client_forum' ); ?></a></span>
														<?php endif; ?>
													</td>
													<td>
														<?php self::render_action_badge( $item['action'] ); ?>
														<?php if ( '' !== (string) $item['message'] ) : ?>
															<span class="bw-table__sub"><?php echo esc_html( $item['message'] ); ?></span>
														<?php endif; ?>
													</td>
													<td><?php self::render_changes( $item['changes'] ); ?></td>
													<td>
														<section class="bw-accordion" data-epi-accordion>
															<button type="button" class="bw-accordion__head" aria-expanded="false">
																<span class="bw-accordion__title"><?php esc_html_e( 'Raw ePim data', 'blueworx_client_forum' ); ?></span>
																<i class="bw-icon bw-accordion__chev" data-lucide="chevron-down" aria-hidden="true"></i>
															</button>
															<div class="bw-accordion__body" hidden>
																<pre><?php echo esc_html( (string) wp_json_encode( $item['raw'], $json ) ); ?></pre>
															</div>
														</section>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
								<?php self::render_pager( $page_no, $pages, $total, array( 'run' => (int) $run_id ) ); ?>
							<?php endif; ?>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * An item's action as a badge.
	 *
	 * @param string $action added, updated, hidden or error.
	 * @return void
	 */
	private static function render_action_badge( $action ) {
		$badges = array(
			'added'   => array( 'success', __( 'Added', 'blueworx_client_forum' ) ),
			'updated' => array( 'info', __( 'Updated', 'blueworx_client_forum' ) ),
			'hidden'  => array( 'warning', __( 'Hidden', 'blueworx_client_forum' ) ),
			'error'   => array( 'danger', __( 'Error', 'blueworx_client_forum' ) ),
		);

		list( $tone, $label ) = isset( $badges[ $action ] ) ? $badges[ $action ] : array( 'neutral', $action );
		?>
		<span class="bw-badge bw-badge--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $label ); ?></span>
		<?php
	}

	/**
	 * Field changes as "label: before, arrow, after", one per line.
	 *
	 * @param array $changes Each: field, label, before, after.
	 * @return void
	 */
	private static function render_changes( array $changes ) {
		if ( ! $changes ) {
			?>
			<span class="bw-table__sub"><?php esc_html_e( 'None', 'blueworx_client_forum' ); ?></span>
			<?php
			return;
		}

		$empty = __( '(empty)', 'blueworx_client_forum' );
		?>
		<dl class="bw-dl bw-dl--stack">
			<?php foreach ( $changes as $change ) : ?>
				<dt><?php echo esc_html( isset( $change['label'] ) ? $change['label'] : $change['field'] ); ?></dt>
				<dd>
					<?php echo esc_html( isset( $change['before'] ) && '' !== (string) $change['before'] && null !== $change['before'] ? (string) $change['before'] : $empty ); ?>
					<i class="bw-icon bw-icon--14" data-lucide="arrow-right" aria-hidden="true"></i>
					<?php echo esc_html( isset( $change['after'] ) && '' !== (string) $change['after'] && null !== $change['after'] ? (string) $change['after'] : $empty ); ?>
				</dd>
			<?php endforeach; ?>
		</dl>
		<?php
	}
}
