<?php
/**
 * Change log storage: stored copies and recorded updates.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's two change-log tables.
 *
 * Every failed write goes to the PHP error log, so a lost entry is never silent.
 */
final class EPI_Change_Store {

	/**
	 * Updates kept per product.
	 */
	const KEEP_UPDATES = 2;

	/**
	 * Recorded updates, one row per changed field.
	 *
	 * @return string
	 */
	public static function changes_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_product_changes';
	}

	/**
	 * One stored copy per product or variation.
	 *
	 * @return string
	 */
	public static function snapshots_table() {
		global $wpdb;

		return $wpdb->prefix . 'epi_product_snapshots';
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
		$changes         = self::changes_table();
		$snapshots       = self::snapshots_table();

		dbDelta(
			"CREATE TABLE {$changes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(30) NOT NULL DEFAULT 'product',
			changed_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor varchar(191) NOT NULL DEFAULT '',
			source varchar(50) NOT NULL DEFAULT '',
			field_type varchar(30) NOT NULL DEFAULT '',
			field_name varchar(191) NOT NULL DEFAULT '',
			action varchar(30) NOT NULL DEFAULT 'updated',
			old_value longtext NULL,
			new_value longtext NULL,
			request_id varchar(64) NOT NULL DEFAULT '',
			gap tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY object_id (object_id),
			KEY changed_at (changed_at),
			KEY request_id (request_id)
		) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$snapshots} (
			object_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			data longtext NOT NULL,
			taken_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (object_id),
			KEY product_id (product_id)
		) {$charset_collate};"
		);
	}

	/**
	 * Drop the recorded updates table; install() makes it again.
	 *
	 * @return void
	 */
	public static function drop_changes() {
		global $wpdb;

		$table = self::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The plugin's own table; name built from $wpdb->prefix.
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS {$table}" ) ) {
			self::report( 'clearing old history', 0 );
		}
	}

	/**
	 * Milliseconds since the epoch. Fine enough that a copy taken in the same
	 * second as logging resumed still sorts before or after it.
	 *
	 * @return int
	 */
	public static function now_ms() {
		return (int) round( microtime( true ) * 1000 );
	}

	/**
	 * An object's stored copy.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return array|null array( 'data' => array, 'taken_at' => int ), or null if none.
	 */
	public static function get_snapshot( $object_id ) {
		global $wpdb;

		$table = self::snapshots_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, read once per touched product per request.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT data, taken_at FROM {$table} WHERE object_id = %d",
				$object_id
			)
		);

		if ( ! $row ) {
			return null;
		}

		$data = json_decode( $row->data, true );

		return array(
			'data'     => is_array( $data ) ? $data : array(),
			'taken_at' => (int) $row->taken_at,
		);
	}

	/**
	 * Replace an object's stored copy.
	 *
	 * Insert-or-update by hand: REPLACE INTO does not survive the test
	 * harness's SQLite translation.
	 *
	 * @param int   $object_id  Product or variation ID.
	 * @param int   $product_id Product it belongs to.
	 * @param array $data       Tracked data.
	 * @return void
	 */
	public static function put_snapshot( $object_id, $product_id, array $data ) {
		global $wpdb;

		$table = self::snapshots_table();
		$json  = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		$values = array(
			'product_id' => absint( $product_id ),
			'data'       => false === $json ? '{}' : $json,
			'taken_at'   => self::now_ms(),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT object_id FROM {$table} WHERE object_id = %d",
				$object_id
			)
		);

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$result = $wpdb->update( $table, $values, array( 'object_id' => absint( $object_id ) ), array( '%d', '%s', '%d' ), array( '%d' ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table.
			$result = $wpdb->insert( $table, array( 'object_id' => absint( $object_id ) ) + $values, array( '%d', '%d', '%s', '%d' ) );
		}

		if ( false === $result ) {
			self::report( 'saving the stored copy', $object_id );
		}
	}

	/**
	 * Write one update: a row per changed field, sharing a request id.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $rows       Each: object_id, object_type, field_type, field_name, before, after.
	 * @param string $request_id This request's id.
	 * @param string $source     EPI_Change_Source constant.
	 * @param string $actor      Name shown for who made it.
	 * @param int    $user_id    Signed-in user.
	 * @param bool   $gap        Whether the stored copy predates logging being switched back on.
	 * @return void
	 */
	public static function insert_update( $product_id, array $rows, $request_id, $source, $actor, $user_id, $gap ) {
		global $wpdb;

		$table      = self::changes_table();
		$changed_at = current_time( 'mysql', true );

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The plugin's own table, via insert() with a format map.
			$result = $wpdb->insert(
				$table,
				array(
					'product_id'  => absint( $product_id ),
					'object_id'   => absint( $row['object_id'] ),
					'object_type' => sanitize_key( $row['object_type'] ),
					'changed_at'  => $changed_at,
					'user_id'     => absint( $user_id ),
					'actor'       => sanitize_text_field( $actor ),
					'source'      => sanitize_key( $source ),
					'field_type'  => sanitize_key( $row['field_type'] ),
					'field_name'  => sanitize_text_field( $row['field_name'] ),
					'action'      => 'updated',
					'old_value'   => EPI_Change_Fields::encode( $row['before'] ),
					'new_value'   => EPI_Change_Fields::encode( $row['after'] ),
					'request_id'  => $request_id,
					'gap'         => $gap ? 1 : 0,
				),
				array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
			);

			if ( false === $result ) {
				self::report( 'saving an update', $product_id );
			}
		}
	}

	/**
	 * Delete all but the product's newest updates.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function prune( $product_id ) {
		global $wpdb;

		$table = self::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$request_ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT request_id FROM {$table} WHERE product_id = %d GROUP BY request_id ORDER BY MAX(id) DESC",
				$product_id
			)
		);

		foreach ( array_slice( $request_ids, self::KEEP_UPDATES ) as $request_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
			$result = $wpdb->delete(
				$table,
				array(
					'product_id' => absint( $product_id ),
					'request_id' => $request_id,
				),
				array( '%d', '%s' )
			);

			if ( false === $result ) {
				self::report( 'removing an old update', $product_id );
			}
		}
	}

	/**
	 * Forget a deleted product or variation.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	public static function delete_object( $object_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->delete( self::snapshots_table(), array( 'object_id' => absint( $object_id ) ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table.
		$wpdb->delete( self::changes_table(), array( 'product_id' => absint( $object_id ) ), array( '%d' ) );
	}

	/**
	 * A product's recorded updates, newest first.
	 *
	 * @param int $product_id Product ID.
	 * @return array See EPI_Product_Change_Log::get_updates().
	 */
	public static function get_updates( $product_id ) {
		global $wpdb;

		$table = self::changes_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own table, for an admin-only display.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; the id is bound.
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY id DESC",
				$product_id
			)
		);

		$updates = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $updates[ $row->request_id ] ) ) {
				$updates[ $row->request_id ] = array(
					'request_id' => $row->request_id,
					'changed_at' => $row->changed_at,
					'actor'      => $row->actor,
					'source'     => $row->source,
					'gap'        => (bool) (int) $row->gap,
					'fields'     => array(),
				);
			}

			$updates[ $row->request_id ]['fields'][] = array(
				'object_id'   => (int) $row->object_id,
				'object_type' => $row->object_type,
				'field_type'  => $row->field_type,
				'field_name'  => $row->field_name,
				'label'       => EPI_Change_Fields::label( $row->field_type, $row->field_name ),
				'before'      => json_decode( (string) $row->old_value, true ),
				'after'       => json_decode( (string) $row->new_value, true ),
			);
		}

		foreach ( $updates as $request_id => $update ) {
			$updates[ $request_id ]['fields'] = array_reverse( $update['fields'] );
		}

		return array_values( $updates );
	}

	/**
	 * Leave a trace of a failed write.
	 *
	 * @param string $what What was being written.
	 * @param int    $id   Product or object ID.
	 * @return void
	 */
	private static function report( $what, $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A failed write must leave a trace; the error log is the one place it can.
		error_log( sprintf( '[Forum change log] Failed %1$s for #%2$d: %3$s', $what, $id, $wpdb->last_error ) );
	}
}
