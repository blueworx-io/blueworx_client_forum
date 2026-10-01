<?php
/**
 * Which product data the change log tracks, and how it reads it.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The fixed list of product data, read straight from the database.
 *
 * Reads bypass the object cache: WooCommerce writes stock with plain SQL, and
 * a cached read would miss it.
 */
final class EPI_Change_Fields {

	/**
	 * Tracked post columns.
	 *
	 * @return array Column => label.
	 */
	public static function post_fields() {
		return array(
			'post_title'   => __( 'Product name', 'blueworx_client_forum' ),
			'post_content' => __( 'Description', 'blueworx_client_forum' ),
			'post_excerpt' => __( 'Short description', 'blueworx_client_forum' ),
			'post_status'  => __( 'Status', 'blueworx_client_forum' ),
			'post_name'    => __( 'Slug', 'blueworx_client_forum' ),
			'menu_order'   => __( 'Menu order', 'blueworx_client_forum' ),
		);
	}

	/**
	 * Tracked WooCommerce fields.
	 *
	 * `_price` is left out: WooCommerce works it out from the regular and sale
	 * price, so it would only repeat them.
	 *
	 * @return array Meta key => label.
	 */
	public static function meta_fields() {
		$fields = array(
			'_sku'                   => __( 'SKU', 'blueworx_client_forum' ),
			'_global_unique_id'      => __( 'GTIN', 'blueworx_client_forum' ),
			'_regular_price'         => __( 'Regular price', 'blueworx_client_forum' ),
			'_sale_price'            => __( 'Sale price', 'blueworx_client_forum' ),
			'_sale_price_dates_from' => __( 'Sale starts', 'blueworx_client_forum' ),
			'_sale_price_dates_to'   => __( 'Sale ends', 'blueworx_client_forum' ),
			'_tax_status'            => __( 'Tax status', 'blueworx_client_forum' ),
			'_tax_class'             => __( 'Tax class', 'blueworx_client_forum' ),
			'_manage_stock'          => __( 'Manage stock', 'blueworx_client_forum' ),
			'_stock'                 => __( 'Stock quantity', 'blueworx_client_forum' ),
			'_stock_status'          => __( 'Stock status', 'blueworx_client_forum' ),
			'_backorders'            => __( 'Backorders', 'blueworx_client_forum' ),
			'_low_stock_amount'      => __( 'Low stock amount', 'blueworx_client_forum' ),
			'_sold_individually'     => __( 'Sold individually', 'blueworx_client_forum' ),
			'_weight'                => __( 'Weight', 'blueworx_client_forum' ),
			'_length'                => __( 'Length', 'blueworx_client_forum' ),
			'_width'                 => __( 'Width', 'blueworx_client_forum' ),
			'_height'                => __( 'Height', 'blueworx_client_forum' ),
			'_virtual'               => __( 'Virtual', 'blueworx_client_forum' ),
			'_downloadable'          => __( 'Downloadable', 'blueworx_client_forum' ),
			'_downloadable_files'    => __( 'Downloadable files', 'blueworx_client_forum' ),
			'_download_limit'        => __( 'Download limit', 'blueworx_client_forum' ),
			'_download_expiry'       => __( 'Download expiry', 'blueworx_client_forum' ),
			'_purchase_note'         => __( 'Purchase note', 'blueworx_client_forum' ),
			'_product_attributes'    => __( 'Attributes', 'blueworx_client_forum' ),
			'_default_attributes'    => __( 'Default attributes', 'blueworx_client_forum' ),
			'_thumbnail_id'          => __( 'Featured image', 'blueworx_client_forum' ),
			'_product_image_gallery' => __( 'Gallery', 'blueworx_client_forum' ),
			'_upsell_ids'            => __( 'Upsells', 'blueworx_client_forum' ),
			'_crosssell_ids'         => __( 'Cross-sells', 'blueworx_client_forum' ),
			'_product_url'           => __( 'Product URL', 'blueworx_client_forum' ),
			'_button_text'           => __( 'Button text', 'blueworx_client_forum' ),
			'_children'              => __( 'Grouped products', 'blueworx_client_forum' ),
			'_variation_description' => __( 'Variation description', 'blueworx_client_forum' ),
		);

		/**
		 * Filter the product fields the change log tracks.
		 *
		 * @param array $fields Meta key => readable label.
		 */
		return (array) apply_filters( 'epi_change_log_meta_fields', $fields );
	}

	/**
	 * Whether a meta key is tracked. Variation attribute values are stored
	 * under `attribute_<name>`, one key per attribute.
	 *
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public static function is_tracked_meta( $meta_key ) {
		$meta_key = (string) $meta_key;

		return array_key_exists( $meta_key, self::meta_fields() ) || 0 === strpos( $meta_key, 'attribute_' );
	}

	/**
	 * Whether a taxonomy is tracked.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function is_tracked_taxonomy( $taxonomy ) {
		return in_array(
			$taxonomy,
			array( 'product_cat', 'product_tag', 'product_type', 'product_visibility', 'product_shipping_class' ),
			true
		) || 0 === strpos( (string) $taxonomy, 'pa_' );
	}

	/**
	 * Fields an order changes. `product_visibility` carries the out-of-stock
	 * term, which WooCommerce sets when stock runs out.
	 *
	 * @return string[] Field ids.
	 */
	public static function stock_fields() {
		return array( 'meta:_stock', 'meta:_stock_status', 'taxonomy:product_visibility' );
	}

	/**
	 * The product a product or variation belongs to.
	 *
	 * @param int $object_id Post ID.
	 * @return int Product ID, or 0 if it is neither.
	 */
	public static function product_id( $object_id ) {
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
	 * Read an object's tracked data, fresh from the database.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return array Field id (`post:post_title`, `meta:_sku`, `taxonomy:product_cat`) => value.
	 *               Empty if the post no longer exists.
	 */
	public static function read( $object_id ) {
		global $wpdb;

		$object_id = absint( $object_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached: the log must see what is in the database now.
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_title, post_content, post_excerpt, post_status, post_name, menu_order FROM {$wpdb->posts} WHERE ID = %d",
				$object_id
			),
			ARRAY_A
		);

		if ( ! $post ) {
			return array();
		}

		$data = array();

		foreach ( array_keys( self::post_fields() ) as $column ) {
			$data[ 'post:' . $column ] = (string) $post[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deliberately uncached, as above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id",
				$object_id
			),
			ARRAY_A
		);

		$meta = array();

		foreach ( $rows as $row ) {
			if ( self::is_tracked_meta( $row['meta_key'] ) ) {
				$meta[ $row['meta_key'] ][] = maybe_unserialize( $row['meta_value'] );
			}
		}

		foreach ( $meta as $key => $values ) {
			$data[ 'meta:' . $key ] = 1 === count( $values ) ? $values[0] : $values;
		}

		foreach ( get_object_taxonomies( (string) get_post_type( $object_id ) ) as $taxonomy ) {
			if ( ! self::is_tracked_taxonomy( $taxonomy ) ) {
				continue;
			}

			$names = wp_get_object_terms( $object_id, $taxonomy, array( 'fields' => 'names' ) );

			if ( is_wp_error( $names ) || empty( $names ) ) {
				continue;
			}

			natcasesort( $names );
			$data[ 'taxonomy:' . $taxonomy ] = array_values( $names );
		}

		ksort( $data );

		return $data;
	}

	/**
	 * Fields whose value differs.
	 *
	 * @param array $before Data before.
	 * @param array $after  Data after.
	 * @return array Field id => array( before, after ).
	 */
	public static function diff( array $before, array $after ) {
		$changes = array();

		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $field_id ) {
			$old = array_key_exists( $field_id, $before ) ? $before[ $field_id ] : null;
			$new = array_key_exists( $field_id, $after ) ? $after[ $field_id ] : null;

			if ( self::encode( $old ) !== self::encode( $new ) ) {
				$changes[ $field_id ] = array( $old, $new );
			}
		}

		ksort( $changes );

		return $changes;
	}

	/**
	 * Encode a value for storage and comparison. Missing, `''` and an empty
	 * array all mean "empty", so a field going from one to another is not a
	 * change.
	 *
	 * @param mixed $value Value.
	 * @return string JSON.
	 */
	public static function encode( $value ) {
		if ( '' === $value || array() === $value ) {
			$value = null;
		}

		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );

		return false === $json ? (string) wp_json_encode( is_scalar( $value ) ? (string) $value : null ) : $json;
	}

	/**
	 * A readable name for a field.
	 *
	 * @param string $field_type `post`, `meta` or `taxonomy`.
	 * @param string $field_name Column, meta key or taxonomy.
	 * @return string
	 */
	public static function label( $field_type, $field_name ) {
		if ( 'post' === $field_type ) {
			$fields = self::post_fields();
			return isset( $fields[ $field_name ] ) ? $fields[ $field_name ] : $field_name;
		}

		if ( 'taxonomy' === $field_type ) {
			$taxonomy = get_taxonomy( $field_name );
			return $taxonomy ? $taxonomy->labels->singular_name : $field_name;
		}

		$fields = self::meta_fields();

		if ( isset( $fields[ $field_name ] ) ) {
			return $fields[ $field_name ];
		}

		if ( 0 === strpos( $field_name, 'attribute_' ) ) {
			/* translators: %s: attribute name. */
			return sprintf( __( 'Variation attribute: %s', 'blueworx_client_forum' ), substr( $field_name, 10 ) );
		}

		return $field_name;
	}
}
