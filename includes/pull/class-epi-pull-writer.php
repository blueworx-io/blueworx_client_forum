<?php
/**
 * Applies an ePim record to a WooCommerce product.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds the product, works out what would change, writes it, and reports the
 * changes field by field. Uses WooCommerce's product API when WooCommerce is
 * present and plain posts and meta otherwise (the test harness).
 */
final class EPI_Pull_Writer {

	/**
	 * Meta keys written on every product the pull touches.
	 */
	const META_VARIATION = '_epim_variation_id';
	const META_PRODUCT   = '_epim_product_id';
	const META_SYNCED    = '_epim_synced_at';

	/**
	 * Apply one mapped product.
	 *
	 * @param array $product      From EPI_Pull_Mapper::map().
	 * @param array $category_map ePim category ID => term ID.
	 * @param bool  $images       Whether to set the pictures.
	 * @return array action, product_id, changes, message.
	 */
	public static function apply( array $product, array $category_map, $images ) {
		$id = self::find( $product );

		if ( ! $id && $product['hidden'] ) {
			return self::result( 'skipped', 0, array(), __( 'Not on the site and not live in ePim, so nothing to add.', 'blueworx_client_forum' ) );
		}

		$before = $id ? self::read( $id ) : array();

		// An archived or unapproved record leaves a hidden or binned product
		// where it is. Only a live record brings one out of the bin.
		if ( $id && $product['hidden'] && in_array( $before['status'], array( 'draft', 'trash' ), true ) ) {
			self::stamp( $id, $product );
			return self::result( 'unchanged', $id, array(), '' );
		}

		$wanted = $product['hidden'] ? array( 'status' => 'draft' ) : self::wanted( $product, $category_map, $images );
		$changes = self::diff( $before, $wanted );

		if ( $id && empty( $changes ) ) {
			self::stamp( $id, $product );
			return self::result( 'unchanged', $id, array(), '' );
		}

		$saved = self::write( $id, $wanted, $product );

		if ( is_wp_error( $saved ) ) {
			return self::result( 'error', (int) $id, $changes, $saved->get_error_message() );
		}

		$action = ! $id ? 'added' : ( $product['hidden'] ? 'hidden' : 'updated' );

		return self::result( $action, (int) $saved, $changes, '' );
	}

	/**
	 * Hide every product an ePim deletion names.
	 *
	 * @param array $entry One DeletedEntities record: EntityType, EntityId.
	 * @return array One result per product hidden, each with sku and name added.
	 */
	public static function hide_deleted( array $entry ) {
		$type   = isset( $entry['EntityType'] ) ? (string) $entry['EntityType'] : '';
		$entity = isset( $entry['EntityId'] ) ? absint( $entry['EntityId'] ) : 0;

		if ( 'SKU_Product_Mapping' === $type ) {
			$meta_key = self::META_VARIATION;
		} elseif ( 'Product' === $type ) {
			$meta_key = self::META_PRODUCT;
		} else {
			return array();
		}

		if ( ! $entity ) {
			return array();
		}

		$results = array();

		foreach ( self::find_by_meta( $meta_key, $entity, -1 ) as $id ) {
			$before = self::read( $id );

			// Already hidden, or in the bin: a deletion must not pull it back out.
			if ( in_array( $before['status'], array( 'draft', 'trash' ), true ) ) {
				continue;
			}

			$wanted  = array( 'status' => 'draft' );
			$changes = self::diff( $before, $wanted );
			$saved   = self::write( $id, $wanted, null );
			$result  = is_wp_error( $saved )
				? self::result( 'error', $id, $changes, $saved->get_error_message() )
				: self::result( 'hidden', $id, $changes, '' );

			if ( ! is_wp_error( $saved ) ) {
				update_post_meta( $id, self::META_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
			}

			$result['sku']  = $before['sku'];
			$result['name'] = $before['name'];
			$results[]      = $result;
		}

		return $results;
	}

	/**
	 * The product's current values for the fields the pull owns.
	 *
	 * @param int $id Product ID.
	 * @return array
	 */
	public static function read( $id ) {
		$post       = get_post( $id );
		$attributes = array();
		$stored     = get_post_meta( $id, '_product_attributes', true );

		foreach ( is_array( $stored ) ? $stored : array() as $attribute ) {
			if ( is_array( $attribute ) && empty( $attribute['is_taxonomy'] ) && isset( $attribute['name'] ) ) {
				$attributes[ (string) $attribute['name'] ] = isset( $attribute['value'] ) ? (string) $attribute['value'] : '';
			}
		}

		$categories = wp_get_object_terms( $id, 'product_cat', array( 'fields' => 'ids' ) );
		$categories = is_wp_error( $categories ) ? array() : array_map( 'intval', $categories );
		sort( $categories );

		$gallery = (string) get_post_meta( $id, '_product_image_gallery', true );
		$gallery = '' === $gallery ? array() : array_values( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) );

		return array(
			'status'      => $post instanceof WP_Post ? $post->post_status : '',
			'name'        => $post instanceof WP_Post ? $post->post_title : '',
			'description' => $post instanceof WP_Post ? $post->post_content : '',
			'sku'         => (string) get_post_meta( $id, '_sku', true ),
			'price'       => (string) get_post_meta( $id, '_regular_price', true ),
			'attributes'  => $attributes,
			'categories'  => $categories,
			'image'       => absint( get_post_meta( $id, '_thumbnail_id', true ) ),
			'gallery'     => $gallery,
		);
	}

	/**
	 * What a live product should hold.
	 *
	 * @param array $product      Mapped product.
	 * @param array $category_map ePim category ID => term ID.
	 * @param bool  $images       Whether pictures are set.
	 * @return array
	 */
	private static function wanted( array $product, array $category_map, $images ) {
		$wanted = array(
			'status'      => 'publish',
			// Filtered here the way WordPress filters on save for a user without unfiltered_html
			// (always the case under cron), so cron and admin runs store, and compare, the same text.
			'name'        => wp_kses( $product['name'], 'data' ),
			'description' => wp_kses_post( $product['description'] ),
			'sku'         => $product['sku'],
		);

		// No usable price from ePim: leave the site price alone. One bad record must not make a live product unbuyable.
		if ( '' !== $product['price'] ) {
			$wanted['price'] = $product['price'];
		}

		$wanted['attributes'] = $product['attributes'];

		$terms = array();

		foreach ( $product['category_ids'] as $epim_id ) {
			if ( isset( $category_map[ $epim_id ] ) ) {
				$terms[] = (int) $category_map[ $epim_id ];
			}
		}

		// No mappable category: leave the product's categories as they are.
		if ( $terms ) {
			$terms = array_values( array_unique( $terms ) );
			sort( $terms );
			$wanted['categories'] = $terms;
		}

		// No pictures in the record: leave the product's pictures alone. One empty record must not strip a product's pictures.
		if ( $images && $product['image_ids'] ) {
			$wanted['image']   = (int) $product['image_ids'][0];
			$wanted['gallery'] = array_slice( $product['image_ids'], 1 );
		}

		return $wanted;
	}

	/**
	 * The fields whose value would change, in a form the import log shows.
	 *
	 * @param array $before From read(), or empty for a new product.
	 * @param array $wanted From wanted().
	 * @return array Each: field, label, before, after.
	 */
	public static function diff( array $before, array $wanted ) {
		$changes = array();

		foreach ( $wanted as $field => $after ) {
			$old = array_key_exists( $field, $before ) ? $before[ $field ] : null;

			if ( 'attributes' === $field ) {
				$old = is_array( $old ) ? $old : array();

				// Only the attributes ePim sends: one it stopped sending is left alone.
				foreach ( $after as $name => $value ) {
					$was = isset( $old[ $name ] ) ? $old[ $name ] : null;

					if ( $was !== $value ) {
						$changes[] = array(
							'field'  => 'attribute:' . $name,
							/* translators: %s: attribute name. */
							'label'  => sprintf( __( 'Attribute: %s', 'blueworx_client_forum' ), $name ),
							'before' => $was,
							'after'  => $value,
						);
					}
				}

				continue;
			}

			if ( self::same( $field, $old, $after ) ) {
				continue;
			}

			$changes[] = array(
				'field'  => $field,
				'label'  => self::label( $field ),
				'before' => self::display( $field, $old ),
				'after'  => self::display( $field, $after ),
			);
		}

		return $changes;
	}

	/**
	 * Whether two values of a field are the same.
	 *
	 * @param string $field Field.
	 * @param mixed  $old   Current value.
	 * @param mixed  $new   Wanted value.
	 * @return bool
	 */
	private static function same( $field, $old, $new ) {
		if ( 'price' === $field ) {
			return self::money( $old ) === self::money( $new );
		}

		if ( is_array( $old ) || is_array( $new ) ) {
			return array_map( 'intval', (array) $old ) === array_map( 'intval', (array) $new );
		}

		return (string) $old === (string) $new;
	}

	/**
	 * A price as two decimals, or '' when there is none.
	 *
	 * @param mixed $value Price.
	 * @return string
	 */
	private static function money( $value ) {
		return is_numeric( $value ) ? number_format( (float) $value, 2, '.', '' ) : '';
	}

	/**
	 * A readable name for a field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private static function label( $field ) {
		$labels = array(
			'status'      => __( 'Status', 'blueworx_client_forum' ),
			'name'        => __( 'Product name', 'blueworx_client_forum' ),
			'description' => __( 'Description', 'blueworx_client_forum' ),
			'sku'         => __( 'SKU', 'blueworx_client_forum' ),
			'price'       => __( 'Regular price', 'blueworx_client_forum' ),
			'categories'  => __( 'Categories', 'blueworx_client_forum' ),
			'image'       => __( 'Main image', 'blueworx_client_forum' ),
			'gallery'     => __( 'Gallery', 'blueworx_client_forum' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * A value as the import log shows it.
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 * @return string|null Null for "nothing".
	 */
	private static function display( $field, $value ) {
		if ( null === $value ) {
			return null;
		}

		if ( 'status' === $field ) {
			return 'publish' === $value ? __( 'Published', 'blueworx_client_forum' ) : __( 'Draft (hidden)', 'blueworx_client_forum' );
		}

		if ( 'price' === $field ) {
			return self::money( $value );
		}

		if ( 'categories' === $field ) {
			$names = array();

			foreach ( (array) $value as $term_id ) {
				$term    = get_term( (int) $term_id, 'product_cat' );
				$names[] = $term instanceof WP_Term ? $term->name : '#' . (int) $term_id;
			}

			return implode( ', ', $names );
		}

		if ( 'image' === $field ) {
			return $value ? (string) (int) $value : '';
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', $value ) );
		}

		return (string) $value;
	}

	/**
	 * Find the product for a record: by ePim ID first, then by SKU.
	 *
	 * @param array $product Mapped product.
	 * @return int Product ID or 0.
	 */
	private static function find( array $product ) {
		if ( $product['epim_id'] ) {
			$ids = self::find_by_meta( self::META_VARIATION, $product['epim_id'], 1 );

			if ( $ids ) {
				return (int) $ids[0];
			}
		}

		if ( '' === $product['sku'] ) {
			return 0;
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$id = (int) wc_get_product_id_by_sku( $product['sku'] );

			if ( $id && 'product' === get_post_type( $id ) ) {
				return $id;
			}
		}

		$ids = self::find_by_meta( '_sku', $product['sku'], 1 );

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Product IDs with a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Meta value.
	 * @param int    $limit How many, -1 for all.
	 * @return int[]
	 */
	private static function find_by_meta( $key, $value, $limit ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'meta_key'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Matching by ePim id or SKU is the whole point.
					'meta_value'     => (string) $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	/**
	 * Write the wanted fields, through WooCommerce when it is there.
	 *
	 * @param int        $id      Product ID, 0 to create.
	 * @param array      $wanted  Fields to set.
	 * @param array|null $product Mapped product, for the ePim stamp; null to leave it.
	 * @return int|WP_Error Product ID.
	 */
	private static function write( $id, array $wanted, $product ) {
		if ( class_exists( 'WC_Product_Simple' ) && function_exists( 'wc_get_product' ) ) {
			return self::write_woocommerce( $id, $wanted, $product );
		}

		return self::write_posts( $id, $wanted, $product );
	}

	/**
	 * WooCommerce's own save: keeps its lookup tables and caches right.
	 *
	 * @param int        $id      Product ID, 0 to create.
	 * @param array      $wanted  Fields.
	 * @param array|null $product Mapped product or null.
	 * @return int|WP_Error
	 */
	private static function write_woocommerce( $id, array $wanted, $product ) {
		try {
			$wc = $id ? wc_get_product( $id ) : new WC_Product_Simple();

			if ( ! $wc ) {
				/* translators: %d: product ID. */
				return new WP_Error( 'epi_pull_missing', sprintf( __( 'Product #%d could not be loaded.', 'blueworx_client_forum' ), $id ) );
			}

			if ( isset( $wanted['name'] ) ) {
				$wc->set_name( $wanted['name'] );
			}
			if ( isset( $wanted['description'] ) ) {
				$wc->set_description( $wanted['description'] );
			}
			if ( isset( $wanted['status'] ) ) {
				$wc->set_status( $wanted['status'] );
			}
			if ( isset( $wanted['sku'] ) ) {
				$wc->set_sku( $wanted['sku'] );
			}
			if ( isset( $wanted['price'] ) ) {
				$wc->set_regular_price( $wanted['price'] );
			}
			if ( isset( $wanted['categories'] ) ) {
				$wc->set_category_ids( $wanted['categories'] );
			}
			if ( isset( $wanted['attributes'] ) ) {
				$wc->set_attributes( self::wc_attributes( $wc->get_attributes(), $wanted['attributes'] ) );
			}
			if ( array_key_exists( 'image', $wanted ) ) {
				$wc->set_image_id( (int) $wanted['image'] );
			}
			if ( isset( $wanted['gallery'] ) ) {
				$wc->set_gallery_image_ids( $wanted['gallery'] );
			}

			if ( is_array( $product ) ) {
				$wc->update_meta_data( self::META_VARIATION, (string) $product['epim_id'] );
				$wc->update_meta_data( self::META_PRODUCT, (string) $product['epim_product_id'] );
				$wc->update_meta_data( self::META_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
			}

			$saved = $wc->save();

			return $saved ? (int) $saved : new WP_Error( 'epi_pull_save', __( 'WooCommerce did not save the product.', 'blueworx_client_forum' ) );
		} catch ( Throwable $e ) {
			return new WP_Error( 'epi_pull_save', $e->getMessage() );
		}
	}

	/**
	 * Merge ePim's attributes into the product's: ePim's values win, other
	 * attributes stay. Taxonomy attributes are never touched.
	 *
	 * @param array $current Current WC_Product_Attribute list.
	 * @param array $values  Name => value from ePim.
	 * @return array
	 */
	private static function wc_attributes( array $current, array $values ) {
		$by_key = array();

		foreach ( $current as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute ) {
				$by_key[ sanitize_title( $attribute->get_name() ) ] = $attribute;
			}
		}

		$position = 0;

		foreach ( $values as $name => $value ) {
			$key = sanitize_title( $name );
			// Always a fresh object: WooCommerce spots a change by comparing the old and new
			// attribute objects, so editing the existing one in place would never be saved.
			$attribute = new WC_Product_Attribute();

			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( array( $value ) );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );

			$by_key[ $key ] = $attribute;
		}

		return array_values( $by_key );
	}

	/**
	 * Plain WordPress save, for a site (the test harness) without WooCommerce.
	 * Writes the same post fields and meta keys WooCommerce would.
	 *
	 * @param int        $id      Product ID, 0 to create.
	 * @param array      $wanted  Fields.
	 * @param array|null $product Mapped product or null.
	 * @return int|WP_Error
	 */
	private static function write_posts( $id, array $wanted, $product ) {
		$postarr = array( 'post_type' => 'product' );

		if ( isset( $wanted['name'] ) ) {
			$postarr['post_title'] = $wanted['name'];
		}
		if ( isset( $wanted['description'] ) ) {
			$postarr['post_content'] = $wanted['description'];
		}
		if ( isset( $wanted['status'] ) ) {
			$postarr['post_status'] = $wanted['status'];
		}

		if ( $id ) {
			if ( ! get_post( $id ) ) {
				/* translators: %d: product ID. */
				return new WP_Error( 'epi_pull_missing', sprintf( __( 'Product #%d could not be loaded.', 'blueworx_client_forum' ), $id ) );
			}

			$postarr['ID'] = $id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$id = (int) $result;

		if ( isset( $wanted['sku'] ) ) {
			update_post_meta( $id, '_sku', $wanted['sku'] );
		}
		if ( isset( $wanted['price'] ) ) {
			update_post_meta( $id, '_regular_price', $wanted['price'] );
			update_post_meta( $id, '_price', $wanted['price'] );
		}
		if ( isset( $wanted['attributes'] ) ) {
			update_post_meta( $id, '_product_attributes', self::meta_attributes( $id, $wanted['attributes'] ) );
		}
		if ( isset( $wanted['categories'] ) ) {
			wp_set_object_terms( $id, $wanted['categories'], 'product_cat', false );
		}
		if ( array_key_exists( 'image', $wanted ) ) {
			update_post_meta( $id, '_thumbnail_id', (int) $wanted['image'] );
		}
		if ( isset( $wanted['gallery'] ) ) {
			update_post_meta( $id, '_product_image_gallery', implode( ',', array_map( 'intval', $wanted['gallery'] ) ) );
		}
		if ( taxonomy_exists( 'product_type' ) ) {
			wp_set_object_terms( $id, 'simple', 'product_type', false );
		}

		if ( is_array( $product ) ) {
			self::stamp( $id, $product );
		}

		return $id;
	}

	/**
	 * The _product_attributes array WooCommerce stores, with ePim's values
	 * merged over the current ones.
	 *
	 * @param int   $id     Product ID.
	 * @param array $values Name => value.
	 * @return array
	 */
	private static function meta_attributes( $id, array $values ) {
		$stored   = get_post_meta( $id, '_product_attributes', true );
		$stored   = is_array( $stored ) ? $stored : array();
		$position = 0;

		foreach ( $values as $name => $value ) {
			$stored[ sanitize_title( $name ) ] = array(
				'name'         => $name,
				'value'        => $value,
				'position'     => $position++,
				'is_visible'   => 1,
				'is_variation' => 0,
				'is_taxonomy'  => 0,
			);
		}

		return $stored;
	}

	/**
	 * Note which ePim record the product is, and when it was last pulled.
	 *
	 * @param int   $id      Product ID.
	 * @param array $product Mapped product.
	 * @return void
	 */
	private static function stamp( $id, array $product ) {
		update_post_meta( $id, self::META_VARIATION, (string) $product['epim_id'] );
		update_post_meta( $id, self::META_PRODUCT, (string) $product['epim_product_id'] );
		update_post_meta( $id, self::META_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
	}

	/**
	 * A result array.
	 *
	 * @param string $action     Action.
	 * @param int    $product_id Product ID.
	 * @param array  $changes    Changes.
	 * @param string $message    Message.
	 * @return array
	 */
	private static function result( $action, $product_id, array $changes, $message ) {
		return array(
			'action'     => $action,
			'product_id' => (int) $product_id,
			'changes'    => $changes,
			'message'    => (string) $message,
		);
	}
}
