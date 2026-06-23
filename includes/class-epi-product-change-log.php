<?php
/**
 * WooCommerce product change log.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records product changes made by users, imports, and API requests.
 *
 * @since 1.0.6
 */
final class EPI_Product_Change_Log {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '1.0';

	/**
	 * Values captured immediately before a metadata change.
	 *
	 * @var array
	 */
	private static $pending_meta = array();

	/**
	 * One identifier shared by all changes in the current request.
	 *
	 * @var string
	 */
	private static $request_id = '';

	/**
	 * Create or update the change log table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
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
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY object_id (object_id),
			KEY changed_at (changed_at),
			KEY request_id (request_id)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( 'epi_change_log_db_version', self::DB_VERSION, false );
	}

	/**
	 * Upgrade the database table when required.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'epi_change_log_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * Register change tracking and admin display hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'add_post_metadata', array( __CLASS__, 'capture_meta_before_add' ), 10, 5 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'capture_meta_before_update' ), 10, 5 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'capture_meta_before_delete' ), 10, 5 );
		add_action( 'added_post_meta', array( __CLASS__, 'log_meta_added' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'log_meta_updated' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'log_meta_deleted' ), 10, 4 );
		add_action( 'post_updated', array( __CLASS__, 'log_post_updated' ), 10, 3 );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'log_post_created' ), 10, 4 );
		add_action( 'set_object_terms', array( __CLASS__, 'log_terms_updated' ), 10, 6 );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * Add the change log to product edit screens.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'epi-product-change-log',
			esc_html__( 'Product Change Log', 'external-product-images' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render recent product changes in the editor.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		?>
		<div class="epi-change-log">
			<p class="description">
				<?php esc_html_e( 'Records changes made by users, imports, scheduled tasks, and API requests from version 1.0.6 onward.', 'external-product-images' ); ?>
			</p>
			<?php self::render_table( $post->ID, 1, 10, false ); ?>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( EPI_Product_Meta::get_full_page_url( $post->ID ) . '#epi-change-log' ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'View full change log', 'external-product-images' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the complete paginated product change log.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function render_full_log( $product_id ) {
		$page = isset( $_GET['history_page'] ) ? max( 1, absint( wp_unslash( $_GET['history_page'] ) ) ) : 1;
		?>
		<section class="epi-change-log epi-change-log--page" id="epi-change-log">
			<div class="epi-section-heading">
				<div>
					<h2><?php esc_html_e( 'Product Change Log', 'external-product-images' ); ?></h2>
					<p><?php esc_html_e( 'A complete history recorded from version 1.0.6 onward.', 'external-product-images' ); ?></p>
				</div>
			</div>
			<?php self::render_table( $product_id, $page, 50, true ); ?>
		</section>
		<?php
	}

	/**
	 * Capture metadata immediately before it is added.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value New metadata value.
	 * @param bool   $unique     Whether the key must be unique.
	 * @return mixed
	 */
	public static function capture_meta_before_add( $check, $object_id, $meta_key, $meta_value, $unique ) {
		unset( $meta_value, $unique );
		self::capture_meta_state( 'add', $object_id, $meta_key );

		return $check;
	}

	/**
	 * Capture metadata immediately before it is updated.
	 *
	 * @param mixed  $check      Existing short-circuit value.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value New metadata value.
	 * @param mixed  $prev_value Previous value filter.
	 * @return mixed
	 */
	public static function capture_meta_before_update( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
		unset( $meta_value, $prev_value );
		self::capture_meta_state( 'update', $object_id, $meta_key );

		return $check;
	}

	/**
	 * Capture metadata immediately before it is deleted.
	 *
	 * @param mixed  $delete     Existing short-circuit value.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Metadata value filter.
	 * @param bool   $delete_all Whether matching keys on all objects are deleted.
	 * @return mixed
	 */
	public static function capture_meta_before_delete( $delete, $object_id, $meta_key, $meta_value, $delete_all ) {
		unset( $meta_value, $delete_all );
		self::capture_meta_state( 'delete', $object_id, $meta_key );

		return $delete;
	}

	/**
	 * Record an added metadata value.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Added value.
	 * @return void
	 */
	public static function log_meta_added( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		self::log_meta_state_change( 'add', 'added', $object_id, $meta_key );
	}

	/**
	 * Record an updated metadata value.
	 *
	 * @param int    $meta_id    Metadata row ID.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Updated value.
	 * @return void
	 */
	public static function log_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );
		self::log_meta_state_change( 'update', 'updated', $object_id, $meta_key );
	}

	/**
	 * Record a deleted metadata value.
	 *
	 * @param array  $meta_ids   Deleted metadata row IDs.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @param mixed  $meta_value Deleted value filter.
	 * @return void
	 */
	public static function log_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ) {
		unset( $meta_ids, $meta_value );
		self::log_meta_state_change( 'delete', 'deleted', $object_id, $meta_key );
	}

	/**
	 * Record changed product post fields.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after the update.
	 * @param WP_Post $post_before Post before the update.
	 * @return void
	 */
	public static function log_post_updated( $post_id, $post_after, $post_before ) {
		$product_id = self::resolve_product_id( $post_id );

		if ( ! $product_id ) {
			return;
		}

		$fields = array(
			'post_title'   => 'Product name',
			'post_content' => 'Description',
			'post_excerpt' => 'Short description',
			'post_status'  => 'Status',
			'post_name'    => 'Slug',
			'menu_order'   => 'Menu order',
			'post_parent'  => 'Parent product',
		);

		foreach ( $fields as $field => $label ) {
			if ( $post_before->$field === $post_after->$field ) {
				continue;
			}

			self::insert_change(
				$product_id,
				$post_id,
				$post_after->post_type,
				'product',
				$label,
				'updated',
				$post_before->$field,
				$post_after->$field
			);
		}
	}

	/**
	 * Record the creation of a product or variation.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Inserted post.
	 * @param bool         $update      Whether this was an update.
	 * @param WP_Post|null $post_before Post before the update.
	 * @return void
	 */
	public static function log_post_created( $post_id, $post, $update, $post_before ) {
		unset( $post_before );

		if ( $update || ! $post instanceof WP_Post ) {
			return;
		}

		$product_id = self::resolve_product_id( $post_id );

		if ( ! $product_id ) {
			return;
		}

		self::insert_change(
			$product_id,
			$post_id,
			$post->post_type,
			'product',
			'Product',
			'created',
			null,
			$post->post_title
		);
	}

	/**
	 * Record changes to categories, tags, types, and product attributes.
	 *
	 * @param int    $object_id   Object ID.
	 * @param array  $terms       Submitted terms.
	 * @param array  $tt_ids      New term taxonomy IDs.
	 * @param string $taxonomy    Taxonomy name.
	 * @param bool   $append      Whether terms were appended.
	 * @param array  $old_tt_ids  Previous term taxonomy IDs.
	 * @return void
	 */
	public static function log_terms_updated( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		unset( $terms, $append );

		$product_id = self::resolve_product_id( $object_id );

		if ( ! $product_id || ! self::is_product_taxonomy( $taxonomy ) ) {
			return;
		}

		$old_terms = self::get_term_names( $old_tt_ids );
		$new_terms = self::get_term_names( $tt_ids );

		if ( $old_terms === $new_terms ) {
			return;
		}

		self::insert_change(
			$product_id,
			$object_id,
			get_post_type( $object_id ),
			'taxonomy',
			$taxonomy,
			'updated',
			$old_terms,
			$new_terms
		);
	}

	/**
	 * Render change rows and optional pagination.
	 *
	 * @param int  $product_id Product ID.
	 * @param int  $page       Current page.
	 * @param int  $per_page   Rows per page.
	 * @param bool $paginate   Whether to show pagination.
	 * @return void
	 */
	private static function render_table( $product_id, $page, $per_page, $paginate ) {
		$total   = self::count_changes( $product_id );
		$changes = self::get_changes( $product_id, $page, $per_page );

		if ( empty( $changes ) ) {
			?>
			<div class="epi-meta-empty"><?php esc_html_e( 'No changes have been recorded yet.', 'external-product-images' ); ?></div>
			<?php
			return;
		}

		?>
		<div class="epi-change-table-wrap">
			<table class="widefat striped epi-change-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Date', 'external-product-images' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Changed by', 'external-product-images' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Field', 'external-product-images' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Before', 'external-product-images' ); ?></th>
						<th scope="col"><?php esc_html_e( 'After', 'external-product-images' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $changes as $change ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( self::format_date( $change->changed_at ) ); ?></strong>
								<span class="epi-change-action"><?php echo esc_html( ucfirst( $change->action ) ); ?></span>
							</td>
							<td>
								<strong><?php echo esc_html( $change->actor ); ?></strong>
								<span class="epi-change-source"><?php echo esc_html( $change->source ); ?></span>
							</td>
							<td>
								<?php if ( 'product_variation' === $change->object_type ) : ?>
									<span class="epi-change-object"><?php echo esc_html( sprintf( __( 'Variation #%d', 'external-product-images' ), $change->object_id ) ); ?></span>
								<?php endif; ?>
								<code><?php echo esc_html( self::get_field_label( $change ) ); ?></code>
							</td>
							<td><?php self::render_logged_value( $change->old_value ); ?></td>
							<td><?php self::render_logged_value( $change->new_value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php

		if ( $paginate && $total > $per_page ) {
			$total_pages = (int) ceil( $total / $per_page );
			$base_url    = EPI_Product_Meta::get_full_page_url( $product_id ) . '%_%#epi-change-log';

			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base_url,
						'format'    => '&history_page=%#%',
						'current'   => $page,
						'total'     => $total_pages,
						'prev_text' => __( '&laquo; Previous', 'external-product-images' ),
						'next_text' => __( 'Next &raquo;', 'external-product-images' ),
					)
				)
			);
			echo '</div></div>';
		}
	}

	/**
	 * Save metadata before a change.
	 *
	 * @param string $operation  Add, update, or delete.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @return void
	 */
	private static function capture_meta_state( $operation, $object_id, $meta_key ) {
		if ( ! self::should_track_meta( $object_id, $meta_key ) ) {
			return;
		}

		self::$pending_meta[ self::get_pending_key( $operation, $object_id, $meta_key ) ] = get_post_meta( $object_id, $meta_key, false );
	}

	/**
	 * Save one metadata state change.
	 *
	 * @param string $operation  Add, update, or delete.
	 * @param string $action     Display action.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Metadata key.
	 * @return void
	 */
	private static function log_meta_state_change( $operation, $action, $object_id, $meta_key ) {
		if ( ! self::should_track_meta( $object_id, $meta_key ) ) {
			return;
		}

		$pending_key = self::get_pending_key( $operation, $object_id, $meta_key );
		$old_value   = isset( self::$pending_meta[ $pending_key ] ) ? self::$pending_meta[ $pending_key ] : array();
		$new_value   = get_post_meta( $object_id, $meta_key, false );

		unset( self::$pending_meta[ $pending_key ] );

		$product_id = self::resolve_product_id( $object_id );

		if ( ! $product_id ) {
			return;
		}

		self::insert_change(
			$product_id,
			$object_id,
			get_post_type( $object_id ),
			'meta',
			$meta_key,
			$action,
			self::normalise_meta_values( $old_value ),
			self::normalise_meta_values( $new_value )
		);
	}

	/**
	 * Check whether a metadata field belongs to a product.
	 *
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Metadata key.
	 * @return bool
	 */
	private static function should_track_meta( $object_id, $meta_key ) {
		return self::resolve_product_id( $object_id )
			&& EPI_Product_Meta::is_product_meta_key( $meta_key, $object_id );
	}

	/**
	 * Insert one audit row.
	 *
	 * @param int    $product_id Product ID.
	 * @param int    $object_id  Changed object ID.
	 * @param string $object_type Object type.
	 * @param string $field_type Field type.
	 * @param string $field_name Field name.
	 * @param string $action     Change action.
	 * @param mixed  $old_value  Previous value.
	 * @param mixed  $new_value  New value.
	 * @return void
	 */
	private static function insert_change( $product_id, $object_id, $object_type, $field_type, $field_name, $action, $old_value, $new_value ) {
		global $wpdb;

		$old_json = self::encode_value( $old_value );
		$new_json = self::encode_value( $new_value );

		if ( $old_json === $new_json ) {
			return;
		}

		$actor = self::get_actor();

		$wpdb->insert(
			self::get_table_name(),
			array(
				'product_id' => absint( $product_id ),
				'object_id'  => absint( $object_id ),
				'object_type' => sanitize_key( $object_type ),
				'changed_at' => current_time( 'mysql', true ),
				'user_id'    => absint( $actor['user_id'] ),
				'actor'      => sanitize_text_field( $actor['actor'] ),
				'source'     => sanitize_text_field( $actor['source'] ),
				'field_type' => sanitize_key( $field_type ),
				'field_name' => sanitize_text_field( $field_name ),
				'action'     => sanitize_key( $action ),
				'old_value'  => $old_json,
				'new_value'  => $new_json,
				'request_id' => self::get_request_id(),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch product changes.
	 *
	 * @param int $product_id Product ID.
	 * @param int $page       Current page.
	 * @param int $per_page   Rows per page.
	 * @return array
	 */
	private static function get_changes( $product_id, $page, $per_page ) {
		global $wpdb;

		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$table  = self::get_table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY changed_at DESC, id DESC LIMIT %d OFFSET %d",
				$product_id,
				$per_page,
				$offset
			)
		);
	}

	/**
	 * Count product changes.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private static function count_changes( $product_id ) {
		global $wpdb;

		$table = self::get_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE product_id = %d",
				$product_id
			)
		);
	}

	/**
	 * Resolve a product or variation to its parent product.
	 *
	 * @param int $object_id Object ID.
	 * @return int
	 */
	private static function resolve_product_id( $object_id ) {
		$post_type = get_post_type( $object_id );

		if ( 'product' === $post_type ) {
			return absint( $object_id );
		}

		if ( 'product_variation' === $post_type ) {
			return absint( wp_get_post_parent_id( $object_id ) );
		}

		return 0;
	}

	/**
	 * Identify product-related taxonomies.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	private static function is_product_taxonomy( $taxonomy ) {
		return in_array(
			$taxonomy,
			array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' ),
			true
		) || 0 === strpos( $taxonomy, 'pa_' );
	}

	/**
	 * Convert term taxonomy IDs to sorted names.
	 *
	 * @param array $tt_ids Term taxonomy IDs.
	 * @return array
	 */
	private static function get_term_names( $tt_ids ) {
		$names = array();

		foreach ( (array) $tt_ids as $tt_id ) {
			$term = get_term_by( 'term_taxonomy_id', absint( $tt_id ) );

			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}

		natcasesort( $names );

		return array_values( $names );
	}

	/**
	 * Get the current user and request source.
	 *
	 * @return array
	 */
	private static function get_actor() {
		$user      = wp_get_current_user();
		$user_id   = $user instanceof WP_User ? $user->ID : 0;
		$user_name = $user_id ? $user->display_name : '';
		$source    = __( 'WordPress admin', 'external-product-images' );

		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ) ) {
			$source = __( 'External API', 'external-product-images' );
		} elseif ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			$source = __( 'External API', 'external-product-images' );
		} elseif ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			$source = __( 'Scheduled task', 'external-product-images' );
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			$source = __( 'Command line', 'external-product-images' );
		} elseif ( ! is_admin() ) {
			$source = __( 'Website / import', 'external-product-images' );
		}

		if ( ! $user_name ) {
			$user_name = __( 'System', 'external-product-images' );
		}

		return array(
			'user_id' => $user_id,
			'actor'   => $user_name,
			'source'  => $source,
		);
	}

	/**
	 * Normalise metadata values for storage.
	 *
	 * @param array $values Raw values.
	 * @return mixed
	 */
	private static function normalise_meta_values( $values ) {
		$values = array_map( 'maybe_unserialize', (array) $values );

		if ( empty( $values ) ) {
			return null;
		}

		if ( 1 === count( $values ) ) {
			return reset( $values );
		}

		return array_values( $values );
	}

	/**
	 * Encode a value for the database.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	private static function encode_value( $value ) {
		$json = wp_json_encode(
			$value,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);

		return false === $json ? wp_json_encode( (string) $value ) : $json;
	}

	/**
	 * Render a stored value.
	 *
	 * @param string $json Stored JSON.
	 * @return void
	 */
	private static function render_logged_value( $json ) {
		$value = json_decode( $json, true );

		if ( null === $value && 'null' === $json ) {
			echo '<span class="epi-meta-empty-value">' . esc_html__( 'Empty', 'external-product-images' ) . '</span>';
			return;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$display = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} elseif ( is_bool( $value ) ) {
			$display = $value ? 'true' : 'false';
		} else {
			$display = (string) $value;
		}

		if ( '' === $display ) {
			echo '<span class="epi-meta-empty-value">' . esc_html__( 'Empty', 'external-product-images' ) . '</span>';
			return;
		}

		if ( strlen( $display ) > 180 || false !== strpos( $display, "\n" ) ) {
			?>
			<details>
				<summary><?php esc_html_e( 'View value', 'external-product-images' ); ?></summary>
				<pre><?php echo esc_html( $display ); ?></pre>
			</details>
			<?php
			return;
		}

		echo '<pre>' . esc_html( $display ) . '</pre>';
	}

	/**
	 * Get a readable field label.
	 *
	 * @param object $change Change row.
	 * @return string
	 */
	private static function get_field_label( $change ) {
		if ( 'taxonomy' === $change->field_type ) {
			$taxonomy = get_taxonomy( $change->field_name );

			if ( $taxonomy && isset( $taxonomy->labels->singular_name ) ) {
				return $taxonomy->labels->singular_name;
			}
		}

		return $change->field_name;
	}

	/**
	 * Format a UTC database date in the site's timezone.
	 *
	 * @param string $date_gmt UTC date.
	 * @return string
	 */
	private static function format_date( $date_gmt ) {
		return get_date_from_gmt(
			$date_gmt,
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
		);
	}

	/**
	 * Build a pending metadata key.
	 *
	 * @param string $operation Operation.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Metadata key.
	 * @return string
	 */
	private static function get_pending_key( $operation, $object_id, $meta_key ) {
		return $operation . ':' . absint( $object_id ) . ':' . $meta_key;
	}

	/**
	 * Return one request identifier.
	 *
	 * @return string
	 */
	private static function get_request_id() {
		if ( ! self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}

		return self::$request_id;
	}

	/**
	 * Return the audit table name.
	 *
	 * @return string
	 */
	private static function get_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'epi_product_changes';
	}
}
