<?php
/**
 * Storage for pull runs and the products each one touched.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the two pull tables.
 *
 * Every failed write goes to the PHP error log, so a lost record is never silent.
 */
final class EPI_Pull_Store {

	/**
	 * Counters a run keeps, by the action name the writer reports.
	 *
	 * @var array
	 */
	private static $counters = array(
		'added'     => 'added',
		'updated'   => 'updated',
		'hidden'    => 'hidden',
		'unchanged' => 'unchanged',
		'skipped'   => 'skipped',
		'error'     => 'errors',
	);

	/**
	 * One row per pull.
	 *
	 * @return string
	 */
	public static function runs_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_pull_runs';
	}

	/**
	 * One row per product a pull added, updated, hid or failed on.
	 *
	 * @return string
	 */
	public static function items_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_pull_items';
	}

	/**
	 * Create or update both tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$runs            = self::runs_table();
		$items           = self::items_table();

		// "trigger" and "cursor" are reserved words in MySQL, hence the longer names.
		dbDelta(
			"CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trigger_type varchar(10) NOT NULL DEFAULT 'auto',
			status varchar(12) NOT NULL DEFAULT 'queued',
			is_full tinyint(1) NOT NULL DEFAULT 0,
			since_utc varchar(25) NOT NULL DEFAULT '',
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			stage varchar(20) NOT NULL DEFAULT 'categories',
			cursor_start int(11) NOT NULL DEFAULT 0,
			total int(11) NOT NULL DEFAULT 0,
			batches int(11) NOT NULL DEFAULT 0,
			added int(11) NOT NULL DEFAULT 0,
			updated int(11) NOT NULL DEFAULT 0,
			hidden int(11) NOT NULL DEFAULT 0,
			unchanged int(11) NOT NULL DEFAULT 0,
			skipped int(11) NOT NULL DEFAULT 0,
			errors int(11) NOT NULL DEFAULT 0,
			message text NULL,
			PRIMARY KEY  (id),
			KEY started_at (started_at),
			KEY status (status)
		) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			epim_id bigint(20) unsigned NOT NULL DEFAULT 0,
			sku varchar(100) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			action varchar(12) NOT NULL DEFAULT '',
			changes longtext NULL,
			raw longtext NULL,
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id)
		) {$charset_collate};"
		);
	}

	/**
	 * Start a run record.
	 *
	 * @param string $trigger   'auto' or 'manual'.
	 * @param string $since_utc ISO time the pull asks for changes since, or '' for everything.
	 * @param bool   $is_full   Whether this is a full import.
	 * @return int Run ID, or 0 when the insert failed.
	 */
	public static function create_run( $trigger, $since_utc, $is_full ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$result = $wpdb->insert(
			self::runs_table(),
			array(
				'trigger_type' => 'manual' === $trigger ? 'manual' : 'auto',
				'status'       => 'queued',
				'is_full'      => $is_full ? 1 : 0,
				'since_utc'    => (string) $since_utc,
				'started_at'   => current_time( 'mysql', true ),
				'stage'        => 'categories',
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			self::report( 'starting a run', 0 );
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One run.
	 *
	 * @param int $run_id Run ID.
	 * @return object|null
	 */
	public static function get_run( $run_id ) {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table; the row changes every batch.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT * FROM {$table} WHERE id = %d",
				$run_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Change some columns of a run.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $fields Column => value.
	 * @return void
	 */
	public static function update_run( $run_id, array $fields ) {
		global $wpdb;

		$formats = array();

		foreach ( $fields as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$result = $wpdb->update( self::runs_table(), $fields, array( 'id' => absint( $run_id ) ), $formats, array( '%d' ) );

		if ( false === $result ) {
			self::report( 'updating a run', $run_id );
		}
	}

	/**
	 * Add one to a run's counter for an action.
	 *
	 * @param int    $run_id Run ID.
	 * @param string $action added, updated, hidden, unchanged, skipped or error.
	 * @return void
	 */
	public static function bump( $run_id, $action ) {
		global $wpdb;

		if ( ! isset( self::$counters[ $action ] ) ) {
			return;
		}

		$column = self::$counters[ $action ];
		$table  = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column names come from this class's own lists; the id is bound.
				"UPDATE {$table} SET {$column} = {$column} + 1 WHERE id = %d",
				$run_id
			)
		);

		if ( false === $result ) {
			self::report( 'counting ' . $action, $run_id );
		}
	}

	/**
	 * Close a run.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $status  'done' or 'failed'.
	 * @param string $message Why, when it failed.
	 * @return void
	 */
	public static function finish_run( $run_id, $status, $message = '' ) {
		self::update_run(
			$run_id,
			array(
				'status'      => 'failed' === $status ? 'failed' : 'done',
				'stage'       => 'done',
				'finished_at' => current_time( 'mysql', true ),
				'message'     => (string) $message,
			)
		);
	}

	/**
	 * Record one product a run touched.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $item   Keys: product_id, epim_id, sku, name, action, changes (array), raw (array), message.
	 * @return void
	 */
	public static function add_item( $run_id, array $item ) {
		global $wpdb;

		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
		$result = $wpdb->insert(
			self::items_table(),
			array(
				'run_id'     => absint( $run_id ),
				'product_id' => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0,
				'epim_id'    => isset( $item['epim_id'] ) ? absint( $item['epim_id'] ) : 0,
				'sku'        => isset( $item['sku'] ) ? sanitize_text_field( $item['sku'] ) : '',
				'name'       => isset( $item['name'] ) ? sanitize_text_field( $item['name'] ) : '',
				'action'     => isset( $item['action'] ) ? sanitize_key( $item['action'] ) : '',
				'changes'    => (string) wp_json_encode( isset( $item['changes'] ) ? $item['changes'] : array(), $flags ),
				'raw'        => (string) wp_json_encode( isset( $item['raw'] ) ? $item['raw'] : array(), $flags ),
				'message'    => isset( $item['message'] ) ? sanitize_text_field( $item['message'] ) : '',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $result ) {
			self::report( 'recording a product', $run_id );
		}
	}

	/**
	 * Runs, newest first.
	 *
	 * @param int $page     Page number from 1.
	 * @param int $per_page Rows per page.
	 * @return object[]
	 */
	public static function get_runs( $page, $per_page ) {
		global $wpdb;

		$table  = self::runs_table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for an admin-only screen.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the numbers are bound.
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
				(int) $per_page,
				$offset
			)
		);
	}

	/**
	 * How many runs there are.
	 *
	 * @return int
	 */
	public static function count_runs() {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table; name from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * A run's recorded products, in the order they were recorded.
	 *
	 * @param int $run_id   Run ID.
	 * @param int $page     Page number from 1.
	 * @param int $per_page Rows per page.
	 * @return array Each: id, product_id, epim_id, sku, name, action, changes (array), raw (array), message, created_at.
	 */
	public static function get_items( $run_id, $page, $per_page ) {
		global $wpdb;

		$table  = self::items_table();
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for an admin-only screen.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the numbers are bound.
				"SELECT * FROM {$table} WHERE run_id = %d ORDER BY id ASC LIMIT %d OFFSET %d",
				$run_id,
				(int) $per_page,
				$offset
			),
			ARRAY_A
		);

		foreach ( $rows as &$row ) {
			$changes        = json_decode( (string) $row['changes'], true );
			$raw            = json_decode( (string) $row['raw'], true );
			$row['changes'] = is_array( $changes ) ? $changes : array();
			$row['raw']     = is_array( $raw ) ? $raw : array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * How many products a run recorded.
	 *
	 * @param int $run_id Run ID.
	 * @return int
	 */
	public static function count_items( $run_id ) {
		global $wpdb;

		$table = self::items_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT COUNT(*) FROM {$table} WHERE run_id = %d",
				$run_id
			)
		);
	}

	/**
	 * The newest run that finished properly.
	 *
	 * @return object|null
	 */
	public static function last_successful_run() {
		global $wpdb;

		$table = self::runs_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table; name from $wpdb->prefix.
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE status = 'done' ORDER BY id DESC LIMIT 1" );

		return $row ? $row : null;
	}

	/**
	 * Remove runs older than a number of days, with their products.
	 *
	 * @param int $days Age in days.
	 * @return int Runs removed.
	 */
	public static function prune( $days ) {
		global $wpdb;

		$runs   = self::runs_table();
		$items  = self::items_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - absint( $days ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own tables.
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from $wpdb->prefix; the date is bound.
				"DELETE FROM {$items} WHERE run_id IN (SELECT id FROM {$runs} WHERE started_at < %s)",
				$cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$removed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the date is bound.
				"DELETE FROM {$runs} WHERE started_at < %s",
				$cutoff
			)
		);

		return false === $removed ? 0 : (int) $removed;
	}

	/**
	 * Leave a trace of a failed write.
	 *
	 * @param string $what What was being written.
	 * @param int    $id   Run ID.
	 * @return void
	 */
	private static function report( $what, $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed write must leave a trace; the error log is the one place it can.
		error_log( sprintf( '[Forum ePim pull] Failed %1$s for run #%2$d: %3$s', $what, $id, $wpdb->last_error ) );
	}
}
