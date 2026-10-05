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
				)
			);
		} elseif ( 'pull' === $action || 'full' === $action ) {
			$started = EPI_Pull_Runner::start( 'manual', 'full' === $action );
			$notice  = is_wp_error( $started ) ? $started->get_error_code() : 'started';
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
		$total    = EPI_Pull_Store::count_runs();
		$pages    = max( 1, (int) ceil( $total / self::RUNS_PER_PAGE ) );
		$page_no  = min( $page_no, $pages );
		$runs     = EPI_Pull_Store::get_runs( $page_no, self::RUNS_PER_PAGE );
		$last     = EPI_Pull_Store::last_successful_run();
		$latest   = $runs && 1 === $page_no ? $runs[0] : null;
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

						<div class="bw-stats">
							<div class="bw-stat">
								<span class="bw-stat__label"><i class="bw-icon bw-icon--14" data-lucide="refresh-cw" aria-hidden="true"></i><?php esc_html_e( 'Last pull', 'blueworx_client_forum' ); ?></span>
								<div class="bw-stat__row">
									<p class="bw-stat__value"><?php echo $latest ? esc_html( self::when( $latest->started_at ) ) : esc_html__( 'Never', 'blueworx_client_forum' ); ?></p>
								</div>
								<p class="bw-stat__foot"><?php echo $latest ? esc_html( self::status_label( $latest->status ) . ', ' . self::trigger_label( $latest->trigger_type ) ) : esc_html__( 'Use Pull now to start the first one.', 'blueworx_client_forum' ); ?></p>
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
														<?php if ( $run->is_full ) : ?>
															<span class="bw-table__sub"><?php esc_html_e( 'Full import', 'blueworx_client_forum' ); ?></span>
														<?php endif; ?>
													</td>
													<td><?php echo esc_html( self::trigger_label( $run->trigger_type ) ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->added ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->updated ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->hidden ); ?></td>
													<td class="bw-table__num"><?php echo esc_html( (string) (int) $run->errors ); ?></td>
													<td><?php self::render_status_badge( $run->status ); ?></td>
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
			'saved'            => array( 'success', __( 'Settings saved.', 'blueworx_client_forum' ) ),
			'started'          => array( 'success', __( 'Pull started. It runs in the background; refresh this page to follow it.', 'blueworx_client_forum' ) ),
			'epi_pull_running' => array( 'warning', __( 'A pull is already running. Wait for it to finish, then try again.', 'blueworx_client_forum' ) ),
			'epi_pull_no_key'  => array( 'warning', __( 'No ePim subscription key is saved. Add it below, save, then pull.', 'blueworx_client_forum' ) ),
			'epi_pull_store'   => array( 'danger', __( 'The pull could not be recorded. Check the PHP error log.', 'blueworx_client_forum' ) ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		list( $tone, $text ) = $notices[ $notice ];
		$icon                = 'success' === $tone ? 'circle-check' : 'triangle-alert';
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
	 * The detail view. Filled in by Task 8.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private static function render_detail( $run_id ) {
		self::render_list( 'missing' );
	}
}
