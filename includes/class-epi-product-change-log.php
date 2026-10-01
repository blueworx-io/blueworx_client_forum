<?php
/**
 * WooCommerce product change log.
 *
 * Keeps each product's last two updates from ePim or from staff, field by
 * field, with the value before and after.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/change-log/class-epi-change-fields.php';
require_once __DIR__ . '/change-log/class-epi-change-source.php';
require_once __DIR__ . '/change-log/class-epi-change-store.php';

/**
 * Notices product writes, diffs them against a stored copy at the end of the
 * request, and records ePim and staff updates.
 *
 * @since 1.0.6
 */
final class EPI_Product_Change_Log {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '2.0';

	/**
	 * Option holding when logging was last switched back on, in milliseconds.
	 */
	const RESUMED_OPTION = 'epi_change_log_resumed_at';

	/**
	 * Products touched in this request.
	 *
	 * @var array Object ID => array( 'before' => array, 'taken_at' => int ). taken_at 0 means no stored copy.
	 */
	private static $touched = array();

	/**
	 * One identifier shared by all changes in the current request.
	 *
	 * @var string
	 */
	private static $request_id = '';

	/**
	 * Create or update the tables. Versions before 2.0 kept every change from
	 * every source, mostly SEO noise; that history is cleared.
	 *
	 * @return void
	 */
	public static function install() {
		$installed = (string) get_option( 'epi_change_log_db_version', '' );

		EPI_Change_Store::install();

		if ( '' !== $installed && version_compare( $installed, '2.0', '<' ) ) {
			EPI_Change_Store::clear_changes();
		}

		update_option( 'epi_change_log_db_version', self::DB_VERSION, false );
	}

	/**
	 * Upgrade the tables when required.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'epi_change_log_db_version' ) ) {
			self::install();
		}
	}

	/**
	 * Hooks that run whether logging is on or off.
	 *
	 * @return void
	 */
	public static function register_always() {
		add_action( 'deleted_post', array( __CLASS__, 'forget' ), 10, 2 );
		add_action( 'update_option_' . EPI_Feature_Registry::OPTION, array( __CLASS__, 'note_flags_saved' ), 10, 2 );
	}

	/**
	 * Register change tracking and the edit-screen box.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'add_post_metadata', array( __CLASS__, 'before_meta_write' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'before_meta_write' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( __CLASS__, 'before_meta_write' ), 10, 3 );
		add_action( 'pre_post_update', array( __CLASS__, 'before_post_write' ) );
		add_action( 'wp_insert_post', array( __CLASS__, 'after_post_insert' ), 10, 3 );
		add_action( 'set_object_terms', array( __CLASS__, 'after_terms_set' ), 10, 6 );
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'after_stock_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'after_stock_set' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
	}

	/**
	 * A product's recorded updates, newest first.
	 *
	 * @param int $product_id Product ID.
	 * @return array Each: request_id, changed_at, actor, source, gap, fields
	 *               (object_id, object_type, field_type, field_name, label, before, after).
	 */
	public static function get_updates( $product_id ) {
		return EPI_Change_Store::get_updates( absint( $product_id ) );
	}

	/**
	 * Mark a product touched before a tracked field is written.
	 *
	 * @param mixed  $check     Short-circuit value, returned untouched.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public static function before_meta_write( $check, $object_id, $meta_key ) {
		if ( EPI_Change_Fields::is_tracked_meta( $meta_key ) ) {
			self::touch( $object_id );
		}

		return $check;
	}

	/**
	 * Mark a product touched before its post fields are written.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function before_post_write( $post_id ) {
		self::touch( $post_id );
	}

	/**
	 * A brand-new product starts from nothing, whatever was read while it was
	 * being inserted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Whether this was an update.
	 * @return void
	 */
	public static function after_post_insert( $post_id, $post, $update ) {
		if ( ! $update && EPI_Change_Fields::product_id( $post_id ) ) {
			self::$touched[ absint( $post_id ) ] = array(
				'before'   => array(),
				'taken_at' => 0,
			);
		}
	}

	/**
	 * Mark a product touched when its terms change. Terms have no "before"
	 * hook, so with no stored copy the old terms WordPress passes in stand in.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Submitted terms.
	 * @param array  $tt_ids     New term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Whether terms were appended.
	 * @param array  $old_tt_ids Previous term taxonomy IDs.
	 * @return void
	 */
	public static function after_terms_set( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
		$object_id = absint( $object_id );

		if ( isset( self::$touched[ $object_id ] ) || ! EPI_Change_Fields::is_tracked_taxonomy( $taxonomy ) ) {
			return;
		}

		self::touch( $object_id );

		if ( ! isset( self::$touched[ $object_id ] ) || self::$touched[ $object_id ]['taken_at'] ) {
			return;
		}

		$old_terms = self::term_names( $old_tt_ids );

		if ( $old_terms ) {
			self::$touched[ $object_id ]['before'][ 'taxonomy:' . $taxonomy ] = $old_terms;
		} else {
			unset( self::$touched[ $object_id ]['before'][ 'taxonomy:' . $taxonomy ] );
		}
	}

	/**
	 * WooCommerce writes stock with plain SQL, past the meta hooks.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return void
	 */
	public static function after_stock_set( $product ) {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			self::touch( $product->get_id() );
		}
	}

	/**
	 * Remove a deleted product's copy and log.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post.
	 * @return void
	 */
	public static function forget( $post_id, $post = null ) {
		if ( $post instanceof WP_Post && in_array( $post->post_type, array( 'product', 'product_variation' ), true ) ) {
			EPI_Change_Store::delete_object( $post_id );
		}
	}

	/**
	 * Note when logging is switched back on, so copies taken before then are
	 * known to be possibly out of date.
	 *
	 * @param mixed $old_value Flags before.
	 * @param mixed $value     Flags after.
	 * @return void
	 */
	public static function note_flags_saved( $old_value, $value ) {
		$was_on = ! is_array( $old_value ) || ! array_key_exists( 'change-log', $old_value ) || ! empty( $old_value['change-log'] );
		$is_on  = ! is_array( $value ) || ! array_key_exists( 'change-log', $value ) || ! empty( $value['change-log'] );

		if ( ! $was_on && $is_on ) {
			update_option( self::RESUMED_OPTION, EPI_Change_Store::now_ms(), false );
		}
	}

	/**
	 * Compare every touched product with its stored copy and record what
	 * ePim or staff changed. Runs once, at the end of the request.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$touched ) ) {
			return;
		}

		$touched       = self::$touched;
		self::$touched = array();
		$in_order      = self::in_order_request();
		$resumed_at    = (int) get_option( self::RESUMED_OPTION, 0 );
		$updates       = array();

		foreach ( $touched as $object_id => $state ) {
			$product_id = EPI_Change_Fields::product_id( $object_id );
			$after      = EPI_Change_Fields::read( $object_id );

			if ( ! $product_id || empty( $after ) ) {
				EPI_Change_Store::delete_object( $object_id );
				continue;
			}

			EPI_Change_Store::put_snapshot( $object_id, $product_id, $after );

			// "Add New" saves an empty draft first; the real save comes next.
			if ( 'auto-draft' === $after['post:post_status'] ) {
				continue;
			}

			$source = EPI_Change_Source::current( $object_id );

			if ( EPI_Change_Source::IGNORED === $source ) {
				continue;
			}

			$changes = EPI_Change_Fields::diff( $state['before'], $after );

			if ( $in_order ) {
				foreach ( EPI_Change_Fields::stock_fields() as $field_id ) {
					unset( $changes[ $field_id ] );
				}
			}

			if ( empty( $changes ) ) {
				continue;
			}

			if ( ! isset( $updates[ $product_id ] ) ) {
				$updates[ $product_id ] = array(
					'source' => $source,
					'gap'    => false,
					'rows'   => array(),
				);
			}

			if ( $state['taken_at'] && $state['taken_at'] < $resumed_at ) {
				$updates[ $product_id ]['gap'] = true;
			}

			foreach ( $changes as $field_id => $pair ) {
				list( $field_type, $field_name ) = explode( ':', $field_id, 2 );

				$updates[ $product_id ]['rows'][] = array(
					'object_id'   => $object_id,
					'object_type' => get_post_type( $object_id ),
					'field_type'  => $field_type,
					'field_name'  => $field_name,
					'before'      => $pair[0],
					'after'       => $pair[1],
				);
			}
		}

		foreach ( $updates as $product_id => $update ) {
			EPI_Change_Store::insert_update(
				$product_id,
				$update['rows'],
				self::get_request_id(),
				$update['source'],
				EPI_Change_Source::actor( $update['source'] ),
				get_current_user_id(),
				$update['gap']
			);
			EPI_Change_Store::prune( $product_id );
		}
	}

	/**
	 * Add the change log to product edit screens.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'epi-product-change-log',
			esc_html__( 'Product Change Log', 'blueworx_client_forum' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Render the product's last updates in the editor.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		?>
		<div class="bw-admin">
			<p class="bw-card__note">
				<?php esc_html_e( 'The last two updates to this product from ePim or from staff.', 'blueworx_client_forum' ); ?>
			</p>

			<?php self::render_updates( $post->ID ); ?>

			<div class="bw-tablefoot">
				<span class="bw-toolbar__spacer"></span>
				<a class="bw-btn bw-btn--sm" href="<?php echo esc_url( EPI_Product_Meta::get_full_page_url( $post->ID ) . '#epi-change-log' ); ?>" target="_blank" rel="noopener noreferrer">
					<i class="bw-icon bw-icon--14" data-lucide="external-link" aria-hidden="true"></i>
					<?php esc_html_e( 'View product data', 'blueworx_client_forum' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the change log section on the full product data page.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public static function render_full_log( $product_id ) {
		?>
		<section class="bw-card bw-card--flush" id="epi-change-log">
			<div class="bw-card__head">
				<div class="bw-card__titles">
					<p class="bw-card__eyebrow"><?php esc_html_e( 'History', 'blueworx_client_forum' ); ?></p>
					<h2 class="bw-card__title"><?php esc_html_e( 'Product change log', 'blueworx_client_forum' ); ?></h2>
				</div>
			</div>
			<?php self::render_updates( $product_id ); ?>
		</section>
		<?php
	}

	/**
	 * Render the updates table: one grouped row per update, then its fields.
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	private static function render_updates( $product_id ) {
		$updates = self::get_updates( $product_id );

		if ( empty( $updates ) ) {
			?>
			<div class="bw-empty">
				<i class="bw-icon bw-icon--28 bw-empty__icon" data-lucide="archive" aria-hidden="true"></i>
				<h3 class="bw-empty__title"><?php esc_html_e( 'Nothing recorded yet', 'blueworx_client_forum' ); ?></h3>
				<p class="bw-empty__text"><?php esc_html_e( 'Updates from ePim and from staff will appear here.', 'blueworx_client_forum' ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="bw-tablescroll">
			<table class="bw-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Field', 'blueworx_client_forum' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Before', 'blueworx_client_forum' ); ?></th>
						<th scope="col"><?php esc_html_e( 'After', 'blueworx_client_forum' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $updates as $update ) : ?>
						<tr class="bw-table__group">
							<td colspan="3">
								<span class="bw-table__group-title">
									<?php echo esc_html( self::format_date( $update['changed_at'] ) . ' · ' . $update['actor'] ); ?>
								</span>
								<?php if ( $update['gap'] ) : ?>
									<span class="bw-badge bw-badge--warning"><?php esc_html_e( 'May include changes made while logging was off', 'blueworx_client_forum' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<?php foreach ( $update['fields'] as $field ) : ?>
							<tr>
								<td>
									<?php if ( 'product_variation' === $field['object_type'] ) : ?>
										<span class="bw-badge bw-badge--info"><?php echo esc_html( sprintf( /* translators: %d: variation ID. */ __( 'Variation #%d', 'blueworx_client_forum' ), $field['object_id'] ) ); ?></span>
									<?php endif; ?>
									<span class="bw-table__primary"><?php echo esc_html( $field['label'] ); ?></span>
								</td>
								<td><?php self::render_value( $field['before'] ); ?></td>
								<td><?php self::render_value( $field['after'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render one stored value.
	 *
	 * @param mixed $value Decoded value.
	 * @return void
	 */
	private static function render_value( $value ) {
		if ( null === $value || '' === $value || array() === $value ) {
			echo '<span class="bw-badge bw-badge--neutral">' . esc_html__( 'Empty', 'blueworx_client_forum' ) . '</span>';
			return;
		}

		if ( is_array( $value ) ) {
			$display = (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} elseif ( is_bool( $value ) ) {
			$display = $value ? 'true' : 'false';
		} else {
			$display = (string) $value;
		}

		if ( strlen( $display ) > 180 || false !== strpos( $display, "\n" ) ) {
			?>
			<details>
				<summary><?php esc_html_e( 'View value', 'blueworx_client_forum' ); ?></summary>
				<pre><?php echo esc_html( $display ); ?></pre>
			</details>
			<?php
			return;
		}

		echo '<pre>' . esc_html( $display ) . '</pre>';
	}

	/**
	 * Mark an object touched, keeping the first "before" seen this request.
	 *
	 * @param int $object_id Post ID.
	 * @return void
	 */
	private static function touch( $object_id ) {
		$object_id = absint( $object_id );

		if ( ! $object_id || isset( self::$touched[ $object_id ] ) || ! EPI_Change_Fields::product_id( $object_id ) ) {
			return;
		}

		$copy = EPI_Change_Store::get_snapshot( $object_id );

		self::$touched[ $object_id ] = $copy
			? array(
				'before'   => $copy['data'],
				'taken_at' => $copy['taken_at'],
			)
			: array(
				'before'   => EPI_Change_Fields::read( $object_id ),
				'taken_at' => 0,
			);
	}

	/**
	 * Whether WooCommerce handled an order in this request. Stock changes made
	 * then are the order's, not an update.
	 *
	 * @return bool
	 */
	private static function in_order_request() {
		$hooks = array(
			'woocommerce_reduce_order_stock',
			'woocommerce_restore_order_stock',
			'woocommerce_reduce_order_item_stock',
			'woocommerce_restore_order_item_stock',
			'woocommerce_checkout_order_processed',
			'woocommerce_new_order',
			'woocommerce_update_order',
			'woocommerce_order_status_changed',
		);

		foreach ( $hooks as $hook ) {
			if ( did_action( $hook ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert term taxonomy IDs to sorted names.
	 *
	 * @param array $tt_ids Term taxonomy IDs.
	 * @return array
	 */
	private static function term_names( $tt_ids ) {
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
	 * Format a UTC database date in the site's timezone.
	 *
	 * @param string $date_gmt UTC date.
	 * @return string
	 */
	private static function format_date( $date_gmt ) {
		return get_date_from_gmt( $date_gmt, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
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
}
