<?php
/**
 * WooCommerce product metadata viewer.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a readable metadata section to WooCommerce product edit screens.
 *
 * @since 1.0.4
 */
final class EPI_Product_Meta {

	/**
	 * Register WordPress admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_full_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_product_list_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_product_list_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( __CLASS__, 'make_product_list_column_sortable' ) );
	}

	/**
	 * Add the metadata section to product edit pages.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'epi-product-meta',
			esc_html__( 'Product Meta Data', 'external-product-images' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	/**
	 * Register a hidden admin page for the full metadata view.
	 *
	 * @return void
	 */
	public static function register_full_page() {
		add_submenu_page(
			null,
			esc_html__( 'Product Meta Data', 'external-product-images' ),
			esc_html__( 'Product Meta Data', 'external-product-images' ),
			'edit_products',
			'epi-product-meta',
			array( __CLASS__, 'render_full_page' )
		);
	}

	/**
	 * Load admin assets only where the viewer is used.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$screen          = get_current_screen();
		$is_product_edit = $screen && 'product' === $screen->post_type && 'post' === $screen->base;
		$is_product_list = $screen && 'product' === $screen->post_type && 'edit' === $screen->base;
		$is_full_page    = 'admin_page_epi-product-meta' === $hook_suffix;

		if ( ! $is_product_edit && ! $is_product_list && ! $is_full_page ) {
			return;
		}

		wp_enqueue_style(
			'epi-product-meta',
			EPI_PLUGIN_URL . 'assets/css/epi-product-meta.css',
			array(),
			EPI_VERSION
		);

		if ( $is_product_edit || $is_full_page ) {
			wp_enqueue_script(
				'epi-product-meta',
				EPI_PLUGIN_URL . 'assets/js/epi-product-meta.js',
				array(),
				EPI_VERSION,
				true
			);
		}
	}

	/**
	 * Add a last updated column to the WooCommerce product list.
	 *
	 * @param array $columns Existing product columns.
	 * @return array
	 */
	public static function add_product_list_column( $columns ) {
		$updated_columns = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$updated_columns['epi_last_updated'] = esc_html__( 'Last updated', 'external-product-images' );
			}

			$updated_columns[ $key ] = $label;
		}

		if ( ! isset( $updated_columns['epi_last_updated'] ) ) {
			$updated_columns['epi_last_updated'] = esc_html__( 'Last updated', 'external-product-images' );
		}

		return $updated_columns;
	}

	/**
	 * Render the last updated product list column.
	 *
	 * @param string $column  Current column name.
	 * @param int    $post_id Product ID.
	 * @return void
	 */
	public static function render_product_list_column( $column, $post_id ) {
		if ( 'epi_last_updated' !== $column ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			echo '&mdash;';
			return;
		}

		$editor = self::get_last_editor( $post );
		?>
		<div class="epi-product-updated">
			<strong><?php echo esc_html( self::get_modified_date( $post ) ); ?></strong>
			<span><?php echo esc_html( self::get_modified_time( $post ) ); ?></span>
			<span>
				<?php
				printf(
					/* translators: %s: user display name. */
					esc_html__( 'by %s', 'external-product-images' ),
					esc_html( $editor )
				);
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Allow sorting products by their last updated date.
	 *
	 * @param array $columns Sortable product columns.
	 * @return array
	 */
	public static function make_product_list_column_sortable( $columns ) {
		$columns['epi_last_updated'] = 'modified';

		return $columns;
	}

	/**
	 * Render the product edit screen metadata section.
	 *
	 * @param WP_Post $post Current product post.
	 * @return void
	 */
	public static function render_meta_box( $post ) {
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$full_page_url = self::get_full_page_url( $post->ID );
		$meta_count    = count( self::get_product_meta( $post->ID ) );
		?>
		<div class="epi-meta-viewer" data-epi-meta-viewer>
			<div class="epi-meta-toolbar">
				<div>
					<p class="epi-meta-summary">
						<?php
						printf(
							/* translators: %d: number of metadata fields. */
							esc_html( _n( '%d metadata field', '%d metadata fields', $meta_count, 'external-product-images' ) ),
							absint( $meta_count )
						);
						?>
					</p>
				</div>

				<?php self::render_export_controls( $post->ID, $full_page_url ); ?>
			</div>

			<?php self::render_activity( $post ); ?>

			<?php self::render_search(); ?>

			<div class="epi-meta-table-wrap epi-meta-table-wrap--box">
				<?php self::render_table( $post->ID ); ?>
			</div>

			<p class="epi-meta-no-results" data-epi-meta-no-results hidden>
				<?php esc_html_e( 'No matching metadata found.', 'external-product-images' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the standalone metadata page.
	 *
	 * @return void
	 */
	public static function render_full_page() {
		$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		$product    = $product_id ? get_post( $product_id ) : null;

		if (
			! $product instanceof WP_Post
			|| 'product' !== $product->post_type
			|| ! current_user_can( 'edit_post', $product_id )
		) {
			wp_die( esc_html__( 'You cannot view metadata for this product.', 'external-product-images' ) );
		}

		$meta_count = count( self::get_product_meta( $product_id ) );
		$edit_url   = get_edit_post_link( $product_id, 'raw' );
		?>
		<div class="wrap epi-meta-page">
			<div class="epi-meta-page__header">
				<div>
					<p class="epi-meta-page__eyebrow"><?php esc_html_e( 'WooCommerce product', 'external-product-images' ); ?></p>
					<h1><?php echo esc_html( get_the_title( $product_id ) ); ?></h1>
					<p class="epi-meta-summary">
						<?php
						printf(
							/* translators: %d: number of metadata fields. */
							esc_html( _n( '%d metadata field', '%d metadata fields', $meta_count, 'external-product-images' ) ),
							absint( $meta_count )
						);
						?>
					</p>
				</div>

				<?php if ( $edit_url ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( $edit_url ); ?>">
						<?php esc_html_e( 'Back to product', 'external-product-images' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<div class="epi-meta-viewer epi-meta-viewer--page" data-epi-meta-viewer>
				<?php self::render_activity( $product ); ?>

				<div class="epi-meta-toolbar epi-meta-toolbar--page">
					<p class="epi-meta-summary"><?php esc_html_e( 'Only product and WooCommerce metadata is included.', 'external-product-images' ); ?></p>
					<?php self::render_export_controls( $product_id ); ?>
				</div>

				<?php self::render_search(); ?>

				<div class="epi-meta-table-wrap">
					<?php self::render_table( $product_id ); ?>
				</div>

				<p class="epi-meta-no-results" data-epi-meta-no-results hidden>
					<?php esc_html_e( 'No matching metadata found.', 'external-product-images' ); ?>
				</p>
			</div>

			<?php EPI_Product_Change_Log::render_full_log( $product_id ); ?>
		</div>
		<?php
	}

	/**
	 * Render the metadata search field.
	 *
	 * @return void
	 */
	private static function render_search() {
		?>
		<label class="epi-meta-search">
			<span class="dashicons dashicons-search" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Search product metadata', 'external-product-images' ); ?></span>
			<input
				type="search"
				placeholder="<?php esc_attr_e( 'Search meta keys or values...', 'external-product-images' ); ?>"
				data-epi-meta-search
				autocomplete="off"
			/>
		</label>
		<?php
	}

	/**
	 * Render copy and JSON download controls.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string $full_page_url Optional full page URL.
	 * @return void
	 */
	private static function render_export_controls( $product_id, $full_page_url = '' ) {
		$export_data = self::get_export_data( $product_id );
		$json        = wp_json_encode(
			$export_data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		$filename    = sanitize_file_name( 'product-meta-' . absint( $product_id ) . '.json' );
		?>
		<div class="epi-meta-actions">
			<button type="button" class="button button-secondary" data-epi-copy-meta>
				<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
				<span data-epi-copy-label><?php esc_html_e( 'Copy metadata', 'external-product-images' ); ?></span>
			</button>
			<button type="button" class="button button-secondary" data-epi-download-meta data-epi-filename="<?php echo esc_attr( $filename ); ?>">
				<span class="dashicons dashicons-download" aria-hidden="true"></span>
				<?php esc_html_e( 'Download JSON', 'external-product-images' ); ?>
			</button>
			<?php if ( $full_page_url ) : ?>
				<a
					class="button button-secondary epi-meta-new-tab"
					href="<?php echo esc_url( $full_page_url ); ?>"
					target="_blank"
					rel="noopener noreferrer"
				>
					<span class="dashicons dashicons-external" aria-hidden="true"></span>
					<?php esc_html_e( 'Open in new tab', 'external-product-images' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<script type="application/json" data-epi-meta-json><?php echo false !== $json ? $json : '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		<?php
	}

	/**
	 * Render a clear product update summary.
	 *
	 * @param WP_Post $post Product post.
	 * @return void
	 */
	private static function render_activity( $post ) {
		?>
		<div class="epi-meta-activity" aria-label="<?php esc_attr_e( 'Product update information', 'external-product-images' ); ?>">
			<div class="epi-meta-activity__item">
				<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
				<div>
					<span class="epi-meta-activity__label"><?php esc_html_e( 'Last updated', 'external-product-images' ); ?></span>
					<strong><?php echo esc_html( self::get_modified_date( $post ) ); ?></strong>
					<span><?php echo esc_html( self::get_modified_time( $post ) ); ?></span>
				</div>
			</div>

			<div class="epi-meta-activity__item">
				<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
				<div>
					<span class="epi-meta-activity__label"><?php esc_html_e( 'Last edited by', 'external-product-images' ); ?></span>
					<strong><?php echo esc_html( self::get_last_editor( $post ) ); ?></strong>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render all metadata for a product.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return void
	 */
	private static function render_table( $product_id ) {
		$all_meta = self::get_product_meta( $product_id );
		ksort( $all_meta, SORT_NATURAL | SORT_FLAG_CASE );

		if ( empty( $all_meta ) ) {
			?>
			<div class="epi-meta-empty">
				<?php esc_html_e( 'This product has no metadata.', 'external-product-images' ); ?>
			</div>
			<?php
			return;
		}
		?>
		<table class="widefat striped epi-meta-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Meta key', 'external-product-images' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meta value', 'external-product-images' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $all_meta as $meta_key => $meta_values ) : ?>
					<tr data-epi-meta-row>
						<th scope="row">
							<code><?php echo esc_html( $meta_key ); ?></code>
						</th>
						<td><?php self::render_value( $meta_values ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Return only product and WooCommerce metadata.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	public static function get_product_meta( $product_id ) {
		$all_meta = get_post_meta( $product_id );

		foreach ( array_keys( $all_meta ) as $meta_key ) {
			if ( ! self::is_product_meta_key( $meta_key, $product_id ) ) {
				unset( $all_meta[ $meta_key ] );
			}
		}

		ksort( $all_meta, SORT_NATURAL | SORT_FLAG_CASE );

		return $all_meta;
	}

	/**
	 * Check whether a metadata key belongs to the product.
	 *
	 * Unknown custom fields remain included so external product APIs are not
	 * accidentally hidden.
	 *
	 * @param string $meta_key  Metadata key.
	 * @param int    $product_id Product or variation ID.
	 * @return bool
	 */
	public static function is_product_meta_key( $meta_key, $product_id = 0 ) {
		$excluded_keys = array(
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_page_template',
		);
		$excluded_prefixes = array(
			'_wp_',
			'_elementor_',
			'_yoast_',
			'_wpseo_',
			'_aioseo_',
			'_rank_math_',
			'rank_math_',
			'_oembed_',
		);
		$included = ! in_array( $meta_key, $excluded_keys, true );

		foreach ( $excluded_prefixes as $prefix ) {
			if ( 0 === strpos( $meta_key, $prefix ) ) {
				$included = false;
				break;
			}
		}

		/**
		 * Filter whether a metadata field is treated as product metadata.
		 *
		 * @param bool   $included   Whether the key is included.
		 * @param string $meta_key   Metadata key.
		 * @param int    $product_id Product or variation ID.
		 */
		return (bool) apply_filters( 'epi_product_meta_is_included', $included, $meta_key, $product_id );
	}

	/**
	 * Prepare product metadata for copying or downloading.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private static function get_export_data( $product_id ) {
		$export = array();

		foreach ( self::get_product_meta( $product_id ) as $meta_key => $values ) {
			$values = array_map( 'maybe_unserialize', $values );

			$export[ $meta_key ] = 1 === count( $values )
				? reset( $values )
				: array_values( $values );
		}

		return $export;
	}

	/**
	 * Render one metadata value safely and clearly.
	 *
	 * @param mixed $value Metadata value.
	 * @return void
	 */
	private static function render_value( $value ) {
		$display_value = self::normalise_value( $value );

		if ( '' === $display_value ) {
			?>
			<span class="epi-meta-empty-value"><?php esc_html_e( 'Empty', 'external-product-images' ); ?></span>
			<?php
			return;
		}

		if ( is_string( $display_value ) && filter_var( $display_value, FILTER_VALIDATE_URL ) ) {
			?>
			<a href="<?php echo esc_url( $display_value ); ?>" target="_blank" rel="noopener noreferrer">
				<?php echo esc_html( $display_value ); ?>
			</a>
			<?php
			return;
		}

		if ( is_array( $display_value ) || is_object( $display_value ) ) {
			$json = wp_json_encode(
				$display_value,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			?>
			<pre><?php echo esc_html( false !== $json ? $json : '' ); ?></pre>
			<?php
			return;
		}

		?>
		<pre><?php echo esc_html( (string) $display_value ); ?></pre>
		<?php
	}

	/**
	 * Make simple values easier to understand.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return mixed
	 */
	private static function normalise_value( $value ) {
		if ( is_array( $value ) ) {
			$normalised = array_map( 'maybe_unserialize', $value );

			if ( 1 === count( $normalised ) ) {
				return reset( $normalised );
			}

			return array_values( $normalised );
		}

		if ( null === $value ) {
			return 'NULL';
		}

		if ( true === $value ) {
			return 'true';
		}

		if ( false === $value ) {
			return 'false';
		}

		return $value;
	}

	/**
	 * Get the product's last modified date.
	 *
	 * @param WP_Post $post Product post.
	 * @return string
	 */
	private static function get_modified_date( $post ) {
		return get_post_modified_time( get_option( 'date_format' ), false, $post, true );
	}

	/**
	 * Get the product's last modified time.
	 *
	 * @param WP_Post $post Product post.
	 * @return string
	 */
	private static function get_modified_time( $post ) {
		return get_post_modified_time( get_option( 'time_format' ), false, $post, true );
	}

	/**
	 * Get the last editor's display name.
	 *
	 * @param WP_Post $post Product post.
	 * @return string
	 */
	private static function get_last_editor( $post ) {
		$editor_id = absint( get_post_meta( $post->ID, '_edit_last', true ) );
		$editor    = $editor_id ? get_userdata( $editor_id ) : null;

		if ( $editor instanceof WP_User ) {
			return $editor->display_name;
		}

		return esc_html__( 'Unknown', 'external-product-images' );
	}

	/**
	 * Build the standalone metadata page URL.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return string
	 */
	public static function get_full_page_url( $product_id ) {
		return add_query_arg(
			array(
				'page'       => 'epi-product-meta',
				'product_id' => absint( $product_id ),
			),
			admin_url( 'admin.php' )
		);
	}
}
