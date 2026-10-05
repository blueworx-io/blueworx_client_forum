<?php
/**
 * Drives a pull: start, lock, batches under WP-Cron, the daily schedule.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pull is a run record moved through three stages: categories, products
 * (one API page at a time), deleted entities. Each cron batch works for a
 * time budget, then schedules the next batch. One lock stops overlap.
 */
final class EPI_Pull_Runner {

	const DAILY_HOOK = 'epi_pull_daily';
	const BATCH_HOOK = 'epi_pull_batch';
	const LOCK       = 'epi_pull_lock';
	const MAP_OPTION = 'epi_pull_category_map';

	/**
	 * Days of records to keep.
	 */
	const KEEP_DAYS = 90;

	/**
	 * Seconds a lock may go without a batch finishing before the run is
	 * treated as dead.
	 */
	const STALE = 20 * MINUTE_IN_SECONDS;

	/**
	 * The time asked for when there is no last successful run.
	 */
	const BEGINNING = '2000-01-01T00:00:00Z';

	/**
	 * Register the cron hooks and make sure the daily event exists.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::DAILY_HOOK, array( __CLASS__, 'daily' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'batch' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * Schedule the daily pull at 02:00 site time if it is not scheduled.
	 *
	 * @return void
	 */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( self::next_two_am(), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * The next 02:00 in the site's timezone, as a Unix time.
	 *
	 * @return int
	 */
	public static function next_two_am() {
		$now = new DateTimeImmutable( 'now', wp_timezone() );
		$two = $now->setTime( 2, 0, 0 );

		if ( $two <= $now ) {
			$two = $two->modify( '+1 day' );
		}

		return $two->getTimestamp();
	}

	/**
	 * The daily job: clear old records, then pull.
	 *
	 * @return void
	 */
	public static function daily() {
		// Re-arm at the next real 02:00, so a clock or timezone change is picked up.
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_schedule_event( self::next_two_am(), 'daily', self::DAILY_HOOK );

		EPI_Pull_Store::prune( self::KEEP_DAYS );

		$started = self::start( 'auto' );

		if ( is_wp_error( $started ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A daily pull that could not start must leave a trace.
			error_log( '[Forum ePim pull] Daily pull did not start: ' . $started->get_error_message() );
		}
	}

	/**
	 * Start a pull and schedule its first batch.
	 *
	 * @param string $trigger 'auto' or 'manual'.
	 * @param bool   $full    Ask ePim for everything rather than changes since the last pull.
	 * @return int|WP_Error Run ID.
	 */
	public static function start( $trigger, $full = false ) {
		if ( '' === EPI_Pull_Settings::key() ) {
			return new WP_Error( 'epi_pull_no_key', __( 'No ePim subscription key is saved.', 'blueworx_client_forum' ) );
		}

		// Clears a stale lock; the answer is not used.
		self::is_locked();

		// add_option() inserts only when the row does not exist, so two starts
		// at the same moment cannot both take the lock.
		if ( ! add_option( self::LOCK, array( 'run_id' => 0, 'time' => time() ), '', false ) ) {
			return new WP_Error( 'epi_pull_running', __( 'A pull is already running.', 'blueworx_client_forum' ) );
		}

		$last  = $full ? null : EPI_Pull_Store::last_successful_run();
		$since = $last ? gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $last->started_at . ' UTC' ) - 5 * MINUTE_IN_SECONDS ) : '';

		$run_id = EPI_Pull_Store::create_run( $trigger, $since, ! $last );

		if ( ! $run_id ) {
			delete_option( self::LOCK );
			return new WP_Error( 'epi_pull_store', __( 'The pull could not be recorded.', 'blueworx_client_forum' ) );
		}

		self::lock( $run_id );
		self::schedule_batch( $run_id, 1 );

		return $run_id;
	}

	/**
	 * Work on a run for a while, then hand over to the next batch.
	 *
	 * @param int  $run_id     Run ID.
	 * @param int  $batch_no   Which batch this is; only there to make each cron event distinct.
	 * @param bool $reschedule Whether to schedule the next batch when time runs out.
	 * @return void
	 */
	public static function batch( $run_id, $batch_no = 0, $reschedule = true ) {
		$run = EPI_Pull_Store::get_run( $run_id );

		if ( ! $run || in_array( $run->status, array( 'done', 'failed' ), true ) ) {
			return;
		}

		$lock = get_option( self::LOCK );

		if ( ! is_array( $lock ) || (int) $lock['run_id'] !== (int) $run_id ) {
			return;
		}

		self::lock( $run_id );
		EPI_Pull_Store::update_run( $run_id, array( 'status' => 'running' ) );

		add_filter( 'epi_change_source', array( __CLASS__, 'as_epim' ) );

		/**
		 * Filter how long one batch may run, in seconds.
		 *
		 * @param int $seconds Budget.
		 */
		$deadline = microtime( true ) + (int) apply_filters( 'epi_pull_batch_seconds', 20 );
		$outcome  = true;

		try {
			do {
				$run     = EPI_Pull_Store::get_run( $run_id );
				$outcome = $run ? self::step( $run ) : 'done';
			} while ( true === $outcome && microtime( true ) < $deadline );
		} catch ( Throwable $e ) {
			$outcome = new WP_Error( 'epi_pull_fatal', $e->getMessage() );
		}

		remove_filter( 'epi_change_source', array( __CLASS__, 'as_epim' ) );

		if ( is_wp_error( $outcome ) ) {
			self::finish( $run_id, 'failed', $outcome->get_error_message() );
			return;
		}

		if ( 'done' === $outcome ) {
			self::finish( $run_id, 'done' );
			return;
		}

		if ( $reschedule ) {
			$run = EPI_Pull_Store::get_run( $run_id );
			self::schedule_batch( $run_id, (int) $run->batches + 1 );
		}
	}

	/**
	 * Run a pull to the end now, without cron. For tests and WP-CLI.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null The finished run.
	 */
	public static function drain( $run_id ) {
		$guard = 0;

		do {
			self::batch( $run_id, 0, false );
			$run = EPI_Pull_Store::get_run( $run_id );
		} while ( $run && ! in_array( $run->status, array( 'done', 'failed' ), true ) && $guard++ < 1000 );

		return $run;
	}

	/**
	 * Claim writes made during a batch as ePim's.
	 *
	 * @return string
	 */
	public static function as_epim() {
		return EPI_Change_Source::EPIM;
	}

	/**
	 * Whether a pull is running. A lock left by a dead run is cleared here.
	 *
	 * @return bool
	 */
	public static function is_locked() {
		$lock = get_option( self::LOCK );

		if ( ! is_array( $lock ) || empty( $lock['run_id'] ) ) {
			return false;
		}

		if ( time() - (int) $lock['time'] > self::STALE ) {
			EPI_Pull_Store::finish_run( (int) $lock['run_id'], 'failed', __( 'Timed out: no batch finished for 20 minutes.', 'blueworx_client_forum' ) );
			delete_option( self::LOCK );
			return false;
		}

		return true;
	}

	/**
	 * The run that holds the lock.
	 *
	 * @return int Run ID, or 0.
	 */
	public static function running_run_id() {
		if ( ! self::is_locked() ) {
			return 0;
		}

		$lock = get_option( self::LOCK );

		return (int) $lock['run_id'];
	}

	/**
	 * One unit of work on a run.
	 *
	 * @param object $run The run row.
	 * @return true|string|WP_Error true for more to do, 'done', or an error.
	 */
	private static function step( $run ) {
		// Some databases hand back an ISO time as a plain datetime, so say it as ISO again.
		$since_time = '' !== (string) $run->since_utc ? strtotime( $run->since_utc . ' UTC' ) : false;
		$since      = false !== $since_time ? gmdate( 'Y-m-d\TH:i:s\Z', $since_time ) : self::BEGINNING;

		switch ( $run->stage ) {
			case 'categories':
				$categories = EPI_Pull_Client::categories();

				if ( is_wp_error( $categories ) ) {
					return $categories;
				}

				update_option( self::MAP_OPTION, EPI_Pull_Categories::sync( $categories ), false );
				EPI_Pull_Store::update_run(
					(int) $run->id,
					array(
						'stage'        => 'products',
						'cursor_start' => 0,
					)
				);
				return true;

			case 'products':
				$page = EPI_Pull_Client::variations( $since, (int) $run->cursor_start );

				if ( is_wp_error( $page ) ) {
					return $page;
				}

				foreach ( $page['results'] as $raw ) {
					self::apply( (int) $run->id, is_array( $raw ) ? $raw : array() );
				}

				$next = (int) $run->cursor_start + EPI_Pull_Settings::page_size();

				if ( empty( $page['results'] ) || $next >= $page['total'] ) {
					EPI_Pull_Store::update_run(
						(int) $run->id,
						array(
							'stage'        => 'deleted',
							'cursor_start' => 0,
							'total'        => (int) $page['total'],
						)
					);
				} else {
					EPI_Pull_Store::update_run(
						(int) $run->id,
						array(
							'cursor_start' => $next,
							'total'        => (int) $page['total'],
						)
					);
				}
				return true;

			case 'deleted':
				$page = EPI_Pull_Client::deleted( $since, (int) $run->cursor_start );

				if ( is_wp_error( $page ) ) {
					return $page;
				}

				foreach ( $page['results'] as $entry ) {
					foreach ( EPI_Pull_Writer::hide_deleted( is_array( $entry ) ? $entry : array() ) as $result ) {
						self::record( (int) $run->id, $result, $result['sku'], $result['name'], 0, $entry );
					}
				}

				$next = (int) $run->cursor_start + EPI_Pull_Settings::page_size();

				if ( empty( $page['results'] ) || $next >= $page['total'] ) {
					return 'done';
				}

				EPI_Pull_Store::update_run( (int) $run->id, array( 'cursor_start' => $next ) );
				return true;
		}

		return 'done';
	}

	/**
	 * Map and write one record, and record what happened.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $raw    The ePim record.
	 * @return void
	 */
	private static function apply( $run_id, array $raw ) {
		$product = EPI_Pull_Mapper::map( $raw );

		if ( '' === $product['sku'] ) {
			$result = array(
				'action'     => 'error',
				'product_id' => 0,
				'changes'    => array(),
				'message'    => __( 'No SKU, so it cannot be matched to a product.', 'blueworx_client_forum' ),
			);
		} else {
			$map    = get_option( self::MAP_OPTION, array() );
			$result = EPI_Pull_Writer::apply( $product, is_array( $map ) ? $map : array(), EPI_Pull_Settings::images_from_epim() );
		}

		self::record( $run_id, $result, $product['sku'], $product['name'], $product['epim_id'], $raw );
	}

	/**
	 * Count a result on the run, and keep the product when something happened.
	 *
	 * @param int    $run_id  Run ID.
	 * @param array  $result  Writer result.
	 * @param string $sku     SKU.
	 * @param string $name    Product name.
	 * @param int    $epim_id ePim variation ID.
	 * @param array  $raw     What ePim sent.
	 * @return void
	 */
	private static function record( $run_id, array $result, $sku, $name, $epim_id, array $raw ) {
		EPI_Pull_Store::bump( $run_id, $result['action'] );

		if ( ! in_array( $result['action'], array( 'added', 'updated', 'hidden', 'error' ), true ) ) {
			return;
		}

		EPI_Pull_Store::add_item(
			$run_id,
			array(
				'product_id' => $result['product_id'],
				'epim_id'    => $epim_id,
				'sku'        => $sku,
				'name'       => $name,
				'action'     => $result['action'],
				'changes'    => $result['changes'],
				'raw'        => $raw,
				'message'    => $result['message'],
			)
		);
	}

	/**
	 * Close the run and release the lock.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $status  'done' or 'failed'.
	 * @param string $message Why, when failed.
	 * @return void
	 */
	private static function finish( $run_id, $status, $message = '' ) {
		EPI_Pull_Store::finish_run( $run_id, $status, $message );
		delete_option( self::LOCK );
	}

	/**
	 * Hold the lock for a run, with the time of the last sign of life.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private static function lock( $run_id ) {
		update_option(
			self::LOCK,
			array(
				'run_id' => (int) $run_id,
				'time'   => time(),
			),
			false
		);
	}

	/**
	 * Queue the next batch. The batch number keeps each event distinct, or
	 * WordPress would drop it as a duplicate of the one just run. Batches chain
	 * through kick(), which starts the next cron run at once.
	 *
	 * @param int $run_id   Run ID.
	 * @param int $batch_no Batch number.
	 * @return void
	 */
	private static function schedule_batch( $run_id, $batch_no ) {
		EPI_Pull_Store::update_run( $run_id, array( 'batches' => (int) $batch_no ) );
		wp_schedule_single_event( time(), self::BATCH_HOOK, array( (int) $run_id, (int) $batch_no ) );
		self::kick();
	}

	/**
	 * Start the next cron run now. spawn_cron() refuses to from inside a cron
	 * run, so the same non-blocking request is made directly, at shutdown,
	 * when wp-cron.php has released its lock. Outside cron spawn_cron() is
	 * enough.
	 *
	 * @return void
	 */
	private static function kick() {
		if ( ! wp_doing_cron() ) {
			spawn_cron();
			return;
		}

		add_action( 'shutdown', array( __CLASS__, 'loopback' ), 200 );
	}

	/**
	 * The request spawn_cron() would make.
	 *
	 * @return void
	 */
	public static function loopback() {
		$doing_wp_cron = sprintf( '%.22F', microtime( true ) );

		wp_remote_post(
			add_query_arg( 'doing_wp_cron', $doing_wp_cron, site_url( 'wp-cron.php' ) ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, as spawn_cron() uses it.
			)
		);
	}
}
