<?php
/**
 * Backend: Remove Historical WooCommerce Images
 *
 * A Snippet that runs through WooCommerce images and purges them.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

/**
 * Plugin Name: One-Time WooCommerce Product Image Purge
 * Description: Safely scans WooCommerce product images, protects images used elsewhere, then deletes product-only images in batches.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * This is a one-time, admin-only maintenance tool (Tools > Purge Product Images, manage_options).
 * It intentionally runs direct, uncached, schema-level queries against core tables in batches, and
 * builds IN() lists from integer IDs already passed through absint(). Caching and prepared-statement
 * placeholders add no security value in this context, so the relevant sniffs are disabled for this file.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery, Squiz.PHP.DiscouragedFunctions.Discouraged

final class EPI_One_Time_WC_Product_Image_Purge {
	private const PAGE_SLUG    = 'one-time-wc-product-image-purge';
	private const NONCE_ACTION = 'one_time_wc_product_image_purge';
	private const PLAN_OPTION  = 'one_time_wc_product_image_purge_plan';
	private const SCAN_OPTION  = 'one_time_wc_product_image_purge_scan';
	private const BATCH_SIZE   = 250;
	private const SCAN_ROWS    = 250;

	/**
	 * Where else an image might be used, checked in this order. Each is
	 * worked through a page of rows at a time, across as many steps as it takes.
	 */
	private const STAGES = array(
		'content' => 'page and post content',
		'meta'    => 'page and product details',
		'options' => 'site settings',
	);

	/**
	 * Products the ePim pull has switched hold ePim picture numbers, not media
	 * items, even where a number matches one. SQL to leave them out, where p is
	 * the product or variation.
	 */
	private const NOT_SWITCHED_SQL = "NOT EXISTS (
		SELECT 1 FROM %s e
		WHERE e.post_id IN (p.ID, p.post_parent)
		AND e.meta_key = '_epim_pictures'
		AND e.meta_value = '1'
	)";

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
	}

	public function add_admin_page(): void {
		add_management_page(
			'Purge Product Images',
			'Purge Product Images',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Load the shared design system on this screen only.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		EPI_Lab_Page::enqueue_design_system();
		wp_enqueue_style(
			'epi-image-purge',
			EPI_PLUGIN_URL . 'assets/css/epi-image-purge.css',
			array( EPI_Lab_Page::DESIGN_HANDLE ),
			EPI_VERSION
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$result = null;

		if ( isset( $_POST['wc_product_image_purge_mode'] ) ) {
			check_admin_referer( self::NONCE_ACTION );
			$mode = sanitize_text_field( wp_unslash( $_POST['wc_product_image_purge_mode'] ) );

			if ( $mode === 'dry_run' ) {
				$result = $this->start_scan();
			} elseif ( $mode === 'scan_next' ) {
				$result = $this->continue_scan();
			} elseif ( $mode === 'delete' ) {
				$result = $this->delete_next_batch();
			} elseif ( $mode === 'clear' ) {
				delete_option( self::PLAN_OPTION );
				delete_option( self::SCAN_OPTION );
				$result = array( 'mode' => 'clear' );
			}
		}

		$plan = get_option( self::PLAN_OPTION );

		$saved_count = is_array( $plan ) && ! empty( $plan['remaining_ids'] ) ? count( $plan['remaining_ids'] ) : 0;

		if ( ! $result && is_array( get_option( self::SCAN_OPTION ) ) ) {
			$result = array( 'mode' => 'scan_stopped' );
		}
		?>
		<div class="wrap bw-wrap">
			<div class="bw-admin bw-page">
				<header class="bw-pagehead">
					<div class="bw-pagehead__titles">
						<p class="bw-pagehead__eyebrow">Maintenance</p>
						<h1 class="bw-pagehead__h1">Purge product images</h1>
						<p class="bw-pagehead__lede">Deletes image files that only the shop's products use, and leaves alone any image used anywhere else. Take a backup first. On a large shop, keep this page open while the deletion runs.</p>
					</div>
				</header>

				<div class="bw-page__body bw-page__body--single">
					<div class="bw-panels">
						<?php
						if ( $result ) {
							$this->render_result( $result );
						} elseif ( $saved_count > 0 ) {
							?>
							<div class="bw-notice bw-notice--warning" role="status">
								<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="triangle-alert" aria-hidden="true"></i>
								<div class="bw-notice__body">
									<p class="bw-notice__title">A saved scan is waiting</p>
									<p class="bw-notice__text">
										<?php echo esc_html( number_format_i18n( $saved_count ) ); ?> product-only images are still to be deleted.
									</p>
								</div>
							</div>
							<?php
						}
						?>

						<section class="bw-card">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title">Scan</h2>
								</div>
							</div>
							<div class="bw-card__body">
								<p class="bw-card__note">Counts what would be deleted and saves the result. Nothing is removed.</p>
								<form method="post">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="wc_product_image_purge_mode" value="dry_run">
									<button type="submit" class="bw-btn">
										<i class="bw-icon" data-lucide="search" aria-hidden="true"></i>
										Run dry scan
									</button>
								</form>
							</div>
						</section>

						<section class="bw-card">
							<div class="bw-card__head">
								<div class="bw-card__titles">
									<h2 class="bw-card__title">Delete</h2>
								</div>
							</div>
							<div class="bw-card__body">
								<p class="bw-card__note">Permanently removes the image files the scan found. This cannot be undone.</p>
								<form method="post" onsubmit="return confirm('This permanently deletes product-only image files. Continue?');">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="wc_product_image_purge_mode" value="delete">
									<button type="submit" class="bw-btn bw-btn--danger">
										<i class="bw-icon" data-lucide="trash-2" aria-hidden="true"></i>
										Delete product-only images
									</button>
								</form>
							</div>
						</section>

						<?php if ( is_array( $plan ) ) : ?>
							<section class="bw-card bw-card--sunken">
								<div class="bw-card__body">
									<p class="bw-card__note">Throw away the saved scan and start again.</p>
									<form method="post">
										<?php wp_nonce_field( self::NONCE_ACTION ); ?>
										<input type="hidden" name="wc_product_image_purge_mode" value="clear">
										<button type="submit" class="bw-btn bw-btn--ghost">Clear saved scan</button>
									</form>
								</div>
							</section>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * First step of a scan: find the product images, and protect those other
	 * records point at by number. Searching the site for the rest is left to
	 * later steps.
	 *
	 * The whole scan once ran in one request, which a host's gateway cut off
	 * part way on a large shop, so it now works in short steps.
	 */
	private function start_scan(): array {
		delete_option( self::SCAN_OPTION );

		$candidate_ids = array_keys( $this->collect_product_image_ids() );
		sort( $candidate_ids );

		$protected = array();

		if ( $candidate_ids ) {
			$this->protect_non_product_featured_images( $candidate_ids, $protected );
			$this->protect_taxonomy_images( $candidate_ids, $protected );
			$this->protect_attachments_owned_by_non_products( $candidate_ids, $protected );
		}

		$state = array(
			'stage'         => $candidate_ids ? (string) key( self::STAGES ) : 'done',
			'last_id'       => 0,
			'candidate_ids' => $candidate_ids,
			'protected'     => $protected,
		);

		update_option( self::SCAN_OPTION, $state, false );

		return array(
			'mode'  => 'scanning',
			'stage' => $state['stage'],
			'count' => count( $candidate_ids ),
		);
	}

	/**
	 * One step of a scan: search the site a page of rows at a time until the
	 * step's time is up, then save where it got to.
	 */
	private function continue_scan(): array {
		$state = get_option( self::SCAN_OPTION );

		if ( ! is_array( $state ) || ! isset( $state['stage'], $state['candidate_ids'] ) ) {
			return array( 'mode' => 'scan_stopped' );
		}

		$lookup   = $state['candidate_ids'] ? $this->build_candidate_lookup( $state['candidate_ids'] ) : array();
		$deadline = microtime( true ) + $this->step_seconds();

		while ( 'done' !== $state['stage'] ) {
			$this->scan_page( $state, $lookup );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( 'done' !== $state['stage'] ) {
			update_option( self::SCAN_OPTION, $state, false );

			return array(
				'mode'  => 'scanning',
				'stage' => $state['stage'],
				'count' => count( $state['candidate_ids'] ),
			);
		}

		$candidate_ids = $state['candidate_ids'];
		$protected     = $state['protected'];
		$to_delete     = array_values( array_diff( $candidate_ids, array_keys( $protected ) ) );
		sort( $to_delete );

		$result = array(
			'mode'          => 'dry_run',
			'candidate_ids' => $candidate_ids,
			'protected'     => $protected,
			'to_delete'     => $to_delete,
		);

		$this->save_plan( $result );
		delete_option( self::SCAN_OPTION );

		return $result;
	}

	/**
	 * How long one step of a scan or deletion works before handing back.
	 */
	private function step_seconds(): float {
		/**
		 * Seconds one step works for. Kept well inside a host's gateway time limit.
		 *
		 * @param float $seconds Seconds.
		 */
		return max( 0.0, (float) apply_filters( 'epi_image_purge_step_seconds', 8 ) );
	}

	/**
	 * Search one page of rows of the current stage, moving to the next stage
	 * when this one has no rows left.
	 */
	private function scan_page( array &$state, array $lookup ): void {
		global $wpdb;

		$last_id = absint( $state['last_id'] );
		$rows    = array();

		if ( 'content' === $state['stage'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_type, post_content, post_excerpt
                     FROM {$wpdb->posts}
                     WHERE ID > %d
                     AND post_type <> 'attachment'
                     AND (post_content <> '' OR post_excerpt <> '')
                     ORDER BY ID ASC
                     LIMIT %d",
					$last_id,
					self::SCAN_ROWS
				)
			);

			foreach ( $rows as $row ) {
				$last_id = absint( $row->ID );
				$value   = (string) $row->post_content . "\n" . (string) $row->post_excerpt;
				$this->protect_images_found_in_value( $value, $lookup, $state['protected'], 'used in content on ' . $row->post_type . ' #' . absint( $row->ID ) );
			}
		} elseif ( 'meta' === $state['stage'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.meta_id, pm.meta_key, pm.meta_value, p.ID AS post_id, p.post_type
                     FROM {$wpdb->postmeta} pm
                     INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_id > %d
                     AND p.post_type <> 'attachment'
                     AND pm.meta_value <> ''
                     AND pm.meta_key NOT IN ('_thumbnail_id', '_product_image_gallery')
                     ORDER BY pm.meta_id ASC
                     LIMIT %d",
					$last_id,
					self::SCAN_ROWS
				)
			);

			foreach ( $rows as $row ) {
				$last_id = absint( $row->meta_id );
				$this->protect_images_found_in_value(
					(string) $row->meta_value,
					$lookup,
					$state['protected'],
					'used in meta ' . $row->meta_key . ' on ' . $row->post_type . ' #' . absint( $row->post_id )
				);
			}
		} elseif ( 'options' === $state['stage'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value
                     FROM {$wpdb->options}
                     WHERE option_id > %d
                     AND option_value <> ''
                     AND option_name NOT LIKE '\_transient\_%'
                     AND option_name NOT LIKE '\_site\_transient\_%'
                     AND option_name NOT IN (%s, %s)
                     ORDER BY option_id ASC
                     LIMIT %d",
					$last_id,
					self::SCAN_OPTION,
					self::PLAN_OPTION,
					self::SCAN_ROWS
				)
			);

			foreach ( $rows as $row ) {
				$last_id = absint( $row->option_id );
				$this->protect_images_found_in_value(
					(string) $row->option_value,
					$lookup,
					$state['protected'],
					'used in WordPress option ' . $row->option_name
				);
			}
		}

		$state['last_id'] = $last_id;

		if ( ! $rows ) {
			$stages           = array_keys( self::STAGES );
			$next             = array_search( $state['stage'], $stages, true );
			$state['stage']   = ( false !== $next && isset( $stages[ $next + 1 ] ) ) ? $stages[ $next + 1 ] : 'done';
			$state['last_id'] = 0;
		}
	}

	private function save_plan( array $scan ): void {
		update_option(
			self::PLAN_OPTION,
			array(
				'created_at'       => time(),
				'total_candidates' => count( $scan['candidate_ids'] ),
				'total_protected'  => count( $scan['protected'] ),
				'total_to_delete'  => count( $scan['to_delete'] ),
				'remaining_ids'    => array_values( $scan['to_delete'] ),
				'deleted_ids'      => array(),
				'failed_ids'       => array(),
			),
			false
		);
	}

	private function delete_next_batch(): array {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit() warns on hosts that disable it; the purge must carry on either way.
		@set_time_limit( 600 );

		$plan = get_option( self::PLAN_OPTION );

		// Scanning takes many steps of its own, so it is never started from here.
		if ( ! is_array( $plan ) || ! isset( $plan['remaining_ids'] ) ) {
			return array( 'mode' => 'no_plan' );
		}

		$remaining = array_values( array_map( 'absint', $plan['remaining_ids'] ) );
		$batch     = array();
		$deleted   = array();
		$failed    = array();
		$deadline  = microtime( true ) + $this->step_seconds();

		foreach ( array_slice( $remaining, 0, self::BATCH_SIZE ) as $attachment_id ) {
			// At least one per step, and no more once the step's time is up.
			if ( $batch && microtime( true ) >= $deadline ) {
				break;
			}

			$batch[] = $attachment_id;

			if ( ! $attachment_id ) {
				continue;
			}

			$deleted_post = wp_delete_attachment( $attachment_id, true );

			if ( $deleted_post ) {
				$deleted[] = $attachment_id;
			} else {
				$failed[] = $attachment_id;
			}
		}

		if ( $deleted ) {
			$this->remove_deleted_product_image_references( $deleted );
		}

		$plan['remaining_ids'] = array_slice( $remaining, count( $batch ) );
		$plan['deleted_ids']   = array_values( array_unique( array_merge( $plan['deleted_ids'] ?? array(), $deleted ) ) );
		$plan['failed_ids']    = array_values( array_unique( array_merge( $plan['failed_ids'] ?? array(), $failed ) ) );
		update_option( self::PLAN_OPTION, $plan, false );

		return array(
			'mode'             => 'delete',
			'batch_count'      => count( $batch ),
			'batch_deleted'    => count( $deleted ),
			'batch_failed'     => count( $failed ),
			'total_candidates' => absint( $plan['total_candidates'] ?? 0 ),
			'total_protected'  => absint( $plan['total_protected'] ?? 0 ),
			'total_to_delete'  => absint( $plan['total_to_delete'] ?? 0 ),
			'total_deleted'    => count( $plan['deleted_ids'] ),
			'total_failed'     => count( $plan['failed_ids'] ),
			'remaining_count'  => count( $plan['remaining_ids'] ),
		);
	}

	private function collect_product_image_ids(): array {
		global $wpdb;

		$images       = array();
		$not_switched = sprintf( self::NOT_SWITCHED_SQL, $wpdb->postmeta );

		$thumbnail_rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value, p.post_type
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND p.post_type IN ('product', 'product_variation')
             AND $not_switched"
		);

		foreach ( $thumbnail_rows as $row ) {
			$label = $row->post_type === 'product_variation' ? 'variation image' : 'product featured image';
			$this->add_candidate( $images, absint( $row->meta_value ), $label . ' on post #' . absint( $row->post_id ) );
		}

		$gallery_rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
             AND p.post_type = 'product'
             AND $not_switched"
		);

		foreach ( $gallery_rows as $row ) {
			$gallery_ids = array_filter( array_map( 'absint', explode( ',', (string) $row->meta_value ) ) );

			foreach ( $gallery_ids as $attachment_id ) {
				$this->add_candidate( $images, $attachment_id, 'product gallery on post #' . absint( $row->post_id ) );
			}
		}

		$attached_rows = $wpdb->get_results(
			"SELECT a.ID AS attachment_id, p.ID AS post_id
             FROM {$wpdb->posts} a
             INNER JOIN {$wpdb->posts} p ON p.ID = a.post_parent
             WHERE a.post_type = 'attachment'
             AND p.post_type IN ('product', 'product_variation')"
		);

		foreach ( $attached_rows as $row ) {
			$this->add_candidate( $images, absint( $row->attachment_id ), 'media attached to product #' . absint( $row->post_id ) );
		}

		// Keep only numbers that are media items, asked a thousand at a time.
		$media = array();

		foreach ( array_chunk( array_keys( $images ), 1000 ) as $chunk ) {
			$ids_sql = implode( ',', array_map( 'absint', $chunk ) );
			$found   = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ($ids_sql)" );
			$media  += array_fill_keys( array_map( 'absint', $found ), true );
		}

		return array_intersect_key( $images, $media );
	}

	private function add_candidate( array &$images, int $attachment_id, string $reason ): void {
		if ( ! $attachment_id ) {
			return;
		}

		if ( ! isset( $images[ $attachment_id ] ) ) {
			$images[ $attachment_id ] = array();
		}

		if ( count( $images[ $attachment_id ] ) < 5 ) {
			$images[ $attachment_id ][] = $reason;
		}
	}

	private function build_candidate_lookup( array $candidate_ids ): array {
		$lookup = array(
			'ids'                  => array(),
			'paths'                => array(),
			'basenames'            => array(),
			'normalized_basenames' => array(),
		);

		foreach ( array_chunk( $candidate_ids, 500 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );
		}

		foreach ( $candidate_ids as $attachment_id ) {
			$lookup['ids'][ $attachment_id ] = true;
			$attached_file                   = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

			if ( $attached_file !== '' ) {
				$this->add_file_to_lookup( $lookup, $attached_file, $attachment_id );
			}

			$meta = wp_get_attachment_metadata( $attachment_id );

			if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
				$this->add_file_to_lookup( $lookup, (string) $meta['file'], $attachment_id );
				$dir = dirname( (string) $meta['file'] );
				$dir = $dir === '.' ? '' : trailingslashit( $dir );

				if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
					foreach ( $meta['sizes'] as $size ) {
						if ( ! empty( $size['file'] ) ) {
							$this->add_file_to_lookup( $lookup, $dir . $size['file'], $attachment_id );
						}
					}
				}
			}
		}

		return $lookup;
	}

	private function add_file_to_lookup( array &$lookup, string $file, int $attachment_id ): void {
		$path_key            = $this->normalize_path_key( $file );
		$basename            = basename( $path_key );
		$normalized_basename = $this->normalize_image_basename( $basename );

		if ( $path_key !== '' ) {
			$lookup['paths'][ $path_key ][ $attachment_id ] = true;
		}

		if ( $basename !== '' ) {
			$lookup['basenames'][ $basename ][ $attachment_id ] = true;
		}

		if ( $normalized_basename !== '' ) {
			$lookup['normalized_basenames'][ $normalized_basename ][ $attachment_id ] = true;
		}
	}

	private function protect_non_product_featured_images( array $candidate_ids, array &$protected ): void {
		global $wpdb;

		foreach ( array_chunk( $candidate_ids, 1000 ) as $chunk ) {
			$ids_sql = implode( ',', array_map( 'absint', $chunk ) );
			$rows    = $wpdb->get_results(
				"SELECT pm.meta_value AS attachment_id, p.ID AS post_id, p.post_type
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_thumbnail_id'
                 AND pm.meta_value IN ($ids_sql)
                 AND p.post_type NOT IN ('product', 'product_variation')"
			);

			foreach ( $rows as $row ) {
				$this->protect( $protected, absint( $row->attachment_id ), 'featured image on ' . $row->post_type . ' #' . absint( $row->post_id ) );
			}
		}
	}

	private function protect_taxonomy_images( array $candidate_ids, array &$protected ): void {
		global $wpdb;

		foreach ( array_chunk( $candidate_ids, 1000 ) as $chunk ) {
			$ids_sql = implode( ',', array_map( 'absint', $chunk ) );
			$rows    = $wpdb->get_results(
				"SELECT tm.meta_value AS attachment_id, tt.taxonomy
                 FROM {$wpdb->termmeta} tm
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
                 WHERE tm.meta_value IN ($ids_sql)"
			);

			foreach ( $rows as $row ) {
				$this->protect( $protected, absint( $row->attachment_id ), 'taxonomy image on ' . $row->taxonomy );
			}
		}
	}

	private function protect_attachments_owned_by_non_products( array $candidate_ids, array &$protected ): void {
		global $wpdb;

		foreach ( array_chunk( $candidate_ids, 1000 ) as $chunk ) {
			$ids_sql = implode( ',', array_map( 'absint', $chunk ) );
			$rows    = $wpdb->get_results(
				"SELECT a.ID AS attachment_id, parent.ID AS parent_id, parent.post_type
                 FROM {$wpdb->posts} a
                 INNER JOIN {$wpdb->posts} parent ON parent.ID = a.post_parent
                 WHERE a.ID IN ($ids_sql)
                 AND a.post_parent > 0
                 AND parent.post_type NOT IN ('product', 'product_variation')"
			);

			foreach ( $rows as $row ) {
				$this->protect( $protected, absint( $row->attachment_id ), 'attached to ' . $row->post_type . ' #' . absint( $row->parent_id ) );
			}
		}
	}

	private function protect_images_found_in_value( string $value, array $lookup, array &$protected, string $reason ): void {
		if ( $value === '' ) {
			return;
		}

		$value = $this->normalize_search_value( $value );
		$found = array();

		if ( preg_match_all( '/wp-image-(\d+)\b/i', $value, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$this->add_found_id( $found, absint( $id ), $lookup );
			}
		}

		if ( preg_match_all( '/["\'](?:id|image|image_id|media|media_id|attachment_id)["\']\s*[:=]\s*["\']?(\d+)["\']?/i', $value, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$this->add_found_id( $found, absint( $id ), $lookup );
			}
		}

		if ( preg_match_all( '/s:\d+:"(?:id|image|image_id|media|media_id|attachment_id)";(?:i:(\d+)|s:\d+:"(\d+)")/i', $value, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$this->add_found_id( $found, absint( '' !== $match[1] ? $match[1] : $match[2] ), $lookup );
			}
		}

		if ( preg_match_all( '/(?:wp-content\/uploads\/)?((?:\d{4}\/\d{2}\/)?[^"\'\s<>)]+?\.(?:jpe?g|png|gif|webp|avif|svg))\b/i', $value, $matches ) ) {
			foreach ( $matches[1] as $file ) {
				$this->add_found_file( $found, $file, $lookup );
			}
		}

		foreach ( array_keys( $found ) as $attachment_id ) {
			$this->protect( $protected, absint( $attachment_id ), $reason );
		}
	}

	private function add_found_id( array &$found, int $attachment_id, array $lookup ): void {
		if ( $attachment_id && isset( $lookup['ids'][ $attachment_id ] ) ) {
			$found[ $attachment_id ] = true;
		}
	}

	private function add_found_file( array &$found, string $file, array $lookup ): void {
		$path_key            = $this->normalize_path_key( $file );
		$basename            = basename( $path_key );
		$normalized_basename = $this->normalize_image_basename( $basename );

		foreach ( array( $lookup['paths'][ $path_key ] ?? array(), $lookup['basenames'][ $basename ] ?? array(), $lookup['normalized_basenames'][ $normalized_basename ] ?? array() ) as $ids ) {
			foreach ( array_keys( $ids ) as $attachment_id ) {
				$found[ $attachment_id ] = true;
			}
		}
	}

	private function normalize_search_value( string $value ): string {
		$value = wp_unslash( $value );
		$value = str_replace( array( '\/', '\\u002F', '\\\\' ), array( '/', '/', '/' ), $value );
		return rawurldecode( $value );
	}

	private function normalize_path_key( string $path ): string {
		$path = $this->normalize_search_value( $path );
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#/+#', '/', $path );
		$path = ltrim( $path, '/' );

		$uploads_pos = stripos( $path, 'wp-content/uploads/' );
		if ( $uploads_pos !== false ) {
			$path = substr( $path, $uploads_pos + strlen( 'wp-content/uploads/' ) );
		}

		return strtolower( $path );
	}

	private function normalize_image_basename( string $basename ): string {
		$info = pathinfo( strtolower( $basename ) );

		if ( empty( $info['filename'] ) || empty( $info['extension'] ) ) {
			return strtolower( $basename );
		}

		$filename = preg_replace( '/-\d+x\d+$/', '', $info['filename'] );
		return $filename . '.' . $info['extension'];
	}

	private function protect( array &$protected, int $attachment_id, string $reason ): void {
		if ( ! $attachment_id ) {
			return;
		}

		if ( ! isset( $protected[ $attachment_id ] ) ) {
			$protected[ $attachment_id ] = array();
		}

		if ( count( $protected[ $attachment_id ] ) < 5 ) {
			$protected[ $attachment_id ][] = $reason;
		}
	}

	private function remove_deleted_product_image_references( array $deleted_ids ): void {
		global $wpdb;

		$ids_sql      = implode( ',', array_map( 'absint', $deleted_ids ) );
		$not_switched = sprintf( self::NOT_SWITCHED_SQL, $wpdb->postmeta );

		if ( $ids_sql === '' ) {
			return;
		}

		$wpdb->query(
			"DELETE pm
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_thumbnail_id'
             AND pm.meta_value IN ($ids_sql)
             AND p.post_type IN ('product', 'product_variation')
             AND $not_switched"
		);

		$gallery_rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_product_image_gallery'
             AND p.post_type = 'product'
             AND $not_switched"
		);

		$deleted_lookup = array_flip( array_map( 'absint', $deleted_ids ) );

		foreach ( $gallery_rows as $row ) {
			$existing_ids  = array_values( array_filter( array_map( 'absint', explode( ',', (string) $row->meta_value ) ) ) );
			$remaining_ids = array_values(
				array_filter(
					$existing_ids,
					static function ( $id ) use ( $deleted_lookup ) {
						return ! isset( $deleted_lookup[ $id ] );
					}
				)
			);

			if ( $remaining_ids === $existing_ids ) {
				continue;
			}

			if ( $remaining_ids ) {
				update_post_meta( absint( $row->post_id ), '_product_image_gallery', implode( ',', $remaining_ids ) );
			} else {
				delete_post_meta( absint( $row->post_id ), '_product_image_gallery' );
			}
		}
	}

	private function render_result( array $result ): void {
		if ( $result['mode'] === 'clear' ) {
			$this->render_notice( 'success', 'circle-check', 'Saved scan cleared.' );
			return;
		}

		if ( $result['mode'] === 'no_plan' ) {
			$this->render_notice( 'warning', 'triangle-alert', 'Nothing was deleted. Run the dry scan first, then delete.' );
			return;
		}

		if ( $result['mode'] === 'scan_stopped' ) {
			$this->render_notice( 'warning', 'triangle-alert', 'A scan stopped part way. Run the dry scan again to start over.' );
			return;
		}

		if ( $result['mode'] === 'scanning' ) {
			$this->render_notice(
				'info',
				'info',
				sprintf(
					'Checking %1$s images. Now looking through %2$s. This runs in short steps so the site stays responsive. Keep this tab open; it carries on by itself.',
					number_format_i18n( (int) $result['count'] ),
					self::STAGES[ $result['stage'] ] ?? 'the results'
				)
			);
			$this->render_continue( 'scan_next', 2000 );
			return;
		}

		if ( $result['mode'] === 'dry_run' ) {
			$this->render_stats(
				'What the scan found',
				array(
					array( 'Product-linked images', count( $result['candidate_ids'] ), 'Every image any product points at.' ),
					array( 'Protected', count( $result['protected'] ), 'Used somewhere else too, so left alone.' ),
					array( 'Ready to delete', count( $result['to_delete'] ), 'Used by products and nothing else.' ),
				)
			);

			$this->render_id_preview( 'Protected images', array_keys( $result['protected'] ) );
			$this->render_id_preview( 'Product-only images', $result['to_delete'] );
			return;
		}

		if ( $result['mode'] === 'delete' ) {
			$this->render_stats(
				'This batch',
				array(
					array( 'Deleted', $result['batch_deleted'], 'Removed in this batch.' ),
					array( 'Failed', $result['batch_failed'], 'Left in place; check the media library.' ),
					array( 'Deleted so far', $result['total_deleted'], 'Of ' . number_format_i18n( (int) $result['total_to_delete'] ) . ' planned.' ),
					array( 'Remaining', $result['remaining_count'], 'Still to go.' ),
				)
			);

			if ( $result['remaining_count'] > 0 ) {
				$this->render_notice( 'info', 'info', 'Carrying on with the next batch. Keep this tab open.' );
				$this->render_continue( 'delete', 900 );
			} else {
				$this->render_notice( 'success', 'circle-check', 'Finished. Nothing is left to delete.' );
			}
		}
	}

	/**
	 * Send the next step by itself after a pause, which gives the site room
	 * to serve visitors between steps.
	 */
	private function render_continue( string $mode, int $pause_ms ): void {
		echo '<form id="wc-product-image-purge-continue" method="post">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="wc_product_image_purge_mode" value="' . esc_attr( $mode ) . '">';
		echo '</form>';
		echo '<script>setTimeout(function(){document.getElementById("wc-product-image-purge-continue").submit();}, ' . absint( $pause_ms ) . ');</script>';
	}

	/**
	 * One design system notice.
	 */
	private function render_notice( string $tone, string $icon, string $text ): void {
		?>
		<div class="bw-notice bw-notice--<?php echo esc_attr( $tone ); ?>" role="status">
			<i class="bw-icon bw-icon--18 bw-notice__icon" data-lucide="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
			<div class="bw-notice__body">
				<p class="bw-notice__text"><?php echo esc_html( $text ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * A row of figures. Each entry is [ label, value, footnote ].
	 */
	private function render_stats( string $title, array $cells ): void {
		?>
		<section class="bw-card">
			<div class="bw-card__head">
				<div class="bw-card__titles">
					<h2 class="bw-card__title"><?php echo esc_html( $title ); ?></h2>
				</div>
			</div>
			<div class="bw-card__body">
				<div class="bw-stats">
					<?php foreach ( $cells as $cell ) : ?>
						<div class="bw-stat">
							<span class="bw-stat__label"><?php echo esc_html( $cell[0] ); ?></span>
							<p class="bw-stat__value"><?php echo esc_html( number_format_i18n( (int) $cell[1] ) ); ?></p>
							<p class="bw-stat__foot"><?php echo esc_html( $cell[2] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}

	private function render_id_preview( string $title, array $ids ): void {
		if ( ! $ids ) {
			return;
		}

		$limit = 300;
		$shown = array_slice( $ids, 0, $limit );
		?>
		<section class="bw-card">
			<div class="bw-card__head">
				<div class="bw-card__titles">
					<h2 class="bw-card__title"><?php echo esc_html( $title ); ?></h2>
					<p class="bw-card__eyebrow">
						Showing <?php echo esc_html( number_format_i18n( count( $shown ) ) ); ?>
						of <?php echo esc_html( number_format_i18n( count( $ids ) ) ); ?>
					</p>
				</div>
			</div>
			<div class="bw-card__body">
				<div class="bw-scrolllist">
					<?php foreach ( $shown as $attachment_id ) : ?>
						<span>
							<code>#<?php echo esc_html( (string) absint( $attachment_id ) ); ?></code>
							<?php echo esc_html( '' !== get_the_title( $attachment_id ) ? get_the_title( $attachment_id ) : '(no title)' ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
		<?php
	}
}

new EPI_One_Time_WC_Product_Image_Purge();
