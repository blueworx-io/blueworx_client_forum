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
	 * The details the shop filter reads, as attribute slug => name. They are
	 * saved as shared attributes (taxonomy "pa_<slug>") rather than text, and
	 * the filter looks them up by these slugs.
	 */
	const FILTER_SPECS = array(
		'colour'         => 'Colour',
		'bulb-type'      => 'Bulb Type',
		'ip-rating'      => 'IP Rating',
		'fittings-class' => 'Fittings Class',
	);

	/**
	 * Apply one mapped product.
	 *
	 * @param array $product      From EPI_Pull_Mapper::map().
	 * @param array $category_map ePim category ID => term ID.
	 * @param bool  $images       Whether to set the pictures.
	 * @param bool  $dry_run      Report what would change; write nothing.
	 * @return array action, product_id, changes, message.
	 */
	public static function apply( array $product, array $category_map, $images, $dry_run = false ) {
		$id = self::find( $product );

		if ( ! $id && $product['hidden'] ) {
			return self::result( 'skipped', 0, array(), __( 'Not on the site and not live in ePim, so nothing to add.', 'blueworx_client_forum' ) );
		}

		$before = $id ? self::read( $id ) : array();

		// An archived or unapproved record leaves a hidden or binned product
		// where it is. Only a live record brings one out of the bin.
		if ( $id && $product['hidden'] && in_array( $before['status'], array( 'draft', 'trash' ), true ) ) {
			if ( ! $dry_run ) {
				self::stamp( $id, $product );
			}

			return self::result( 'unchanged', $id, array(), '' );
		}

		$wanted = $product['hidden'] ? array( 'status' => 'draft' ) : self::wanted( $product, $category_map, $images );

		if ( isset( $wanted['attributes'], $before['attributes'] ) ) {
			$wanted['attributes'] = self::site_names( $wanted['attributes'], $before['attributes'] );
		}

		$changes = self::diff( $before, $wanted );

		if ( $id && empty( $changes ) ) {
			if ( ! $dry_run ) {
				self::stamp( $id, $product );
			}

			return self::result( 'unchanged', $id, array(), '' );
		}

		$action = ! $id ? 'added' : ( $product['hidden'] ? 'hidden' : 'updated' );

		// A test pull reports what it would do and stops here.
		if ( $dry_run ) {
			return self::result( $action, (int) $id, $changes, '' );
		}

		$saved = self::write( $id, $wanted, $product );

		if ( is_wp_error( $saved ) ) {
			return self::result( 'error', (int) $id, $changes, $saved->get_error_message() );
		}

		return self::result( $action, (int) $saved, $changes, '' );
	}

	/**
	 * Hide every product an ePim deletion names.
	 *
	 * @param array $entry   One DeletedEntities record: EntityType, EntityId.
	 * @param bool  $dry_run Report what would be hidden; change nothing.
	 * @return array One result per product hidden, each with sku and name added.
	 */
	public static function hide_deleted( array $entry, $dry_run = false ) {
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

			if ( $dry_run ) {
				$result = self::result( 'hidden', $id, $changes, '' );
			} else {
				$saved  = self::write( $id, $wanted, null );
				$result = is_wp_error( $saved )
					? self::result( 'error', $id, $changes, $saved->get_error_message() )
					: self::result( 'hidden', $id, $changes, '' );

				if ( ! is_wp_error( $saved ) ) {
					update_post_meta( $id, self::META_SYNCED, gmdate( 'Y-m-d H:i:s' ) );
				}
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

		$filters = array();

		foreach ( array_keys( self::filter_specs() ) as $slug ) {
			$taxonomy = 'pa_' . $slug;

			if ( ! taxonomy_exists( $taxonomy ) || empty( $stored[ $taxonomy ]['is_taxonomy'] ) ) {
				continue;
			}

			$names = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'names' ) );

			if ( ! is_wp_error( $names ) && $names ) {
				$filters[ $slug ] = implode( ', ', $names );
			}
		}

		return array(
			'status'      => $post instanceof WP_Post ? $post->post_status : '',
			'name'        => $post instanceof WP_Post ? $post->post_title : '',
			'description' => $post instanceof WP_Post ? $post->post_content : '',
			'sku'         => (string) get_post_meta( $id, '_sku', true ),
			'price'       => (string) get_post_meta( $id, '_regular_price', true ),
			'attributes'  => $attributes,
			'filters'     => $filters,
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

		$filters = self::filters( $product['attributes'] );

		if ( $filters ) {
			$wanted['filters'] = $filters;
		}

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
	 * Give ePim's attributes the names the product already uses for them.
	 * WooCommerce treats "Bullet-1" and "Bullet 1" as the same attribute; the
	 * push writes one and the API sends the other, and renaming it on every
	 * pull would only flip-flop with the push.
	 *
	 * @param array $values  Name => value from ePim.
	 * @param array $current Name => value on the product.
	 * @return array
	 */
	private static function site_names( array $values, array $current ) {
		$names = array();

		foreach ( array_keys( $current ) as $name ) {
			$names[ sanitize_title( $name ) ] = (string) $name;
		}

		$renamed = array();

		foreach ( $values as $name => $value ) {
			$key = sanitize_title( $name );

			$renamed[ isset( $names[ $key ] ) ? $names[ $key ] : $name ] = $value;
		}

		return $renamed;
	}

	/**
	 * The details the shop filter reads, as slug => name.
	 *
	 * @return array
	 */
	private static function filter_specs() {
		return (array) apply_filters( 'epi_pull_filter_specs', self::FILTER_SPECS );
	}

	/**
	 * The filter details among ePim's attributes, as slug => value. Left as
	 * text where the site has no way to hold a shared attribute (no
	 * WooCommerce).
	 *
	 * @param array $values Name => value from ePim.
	 * @return array
	 */
	private static function filters( array $values ) {
		$specs   = self::filter_specs();
		$filters = array();

		foreach ( $values as $name => $value ) {
			$slug = sanitize_title( $name );

			if ( isset( $specs[ $slug ] ) && ( taxonomy_exists( 'pa_' . $slug ) || function_exists( 'wc_create_attribute' ) ) ) {
				$filters[ $slug ] = $value;
			}
		}

		return $filters;
	}

	/**
	 * The term for a filter value, creating the shared attribute and the
	 * value when the site does not have them yet.
	 *
	 * @param string $slug  Attribute slug.
	 * @param string $value Value.
	 * @return int Term ID, 0 when it could not be made.
	 */
	private static function filter_term( $slug, $value ) {
		$taxonomy = 'pa_' . $slug;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			$specs   = self::filter_specs();
			$created = function_exists( 'wc_create_attribute' ) ? wc_create_attribute(
				array(
					'name'         => isset( $specs[ $slug ] ) ? $specs[ $slug ] : $slug,
					'slug'         => $slug,
					'type'         => 'select',
					'order_by'     => 'menu_order',
					'has_archives' => false,
				)
			) : 0;

			if ( ! $created || is_wp_error( $created ) ) {
				return 0;
			}

			// WooCommerce registers its attribute taxonomies on init, which has passed.
			register_taxonomy(
				$taxonomy,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			);
		}

		$term = get_term_by( 'name', $value, $taxonomy );

		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		}

		if ( $term instanceof WP_Term ) {
			return (int) $term->term_id;
		}

		$made = wp_insert_term( $value, $taxonomy );

		if ( is_wp_error( $made ) ) {
			return 'term_exists' === $made->get_error_code() ? (int) $made->get_error_data() : 0;
		}

		return (int) $made['term_id'];
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
				$old    = is_array( $old ) ? $old : array();
				$shared = isset( $wanted['filters'] ) ? $wanted['filters'] : array();

				// Only the attributes ePim sends: one it stopped sending is left alone.
				foreach ( $after as $name => $value ) {
					// A filter detail is compared below, as a shared attribute.
					if ( isset( $shared[ sanitize_title( $name ) ] ) ) {
						continue;
					}

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

			if ( 'filters' === $field ) {
				$old   = is_array( $old ) ? $old : array();
				$specs = self::filter_specs();
				$text  = array();

				foreach ( array_keys( isset( $before['attributes'] ) ? $before['attributes'] : array() ) as $name ) {
					$text[ sanitize_title( $name ) ] = true;
				}

				// Changed when the shared value differs, or a text copy is still there to remove.
				foreach ( $after as $slug => $value ) {
					$was  = isset( $old[ $slug ] ) ? $old[ $slug ] : null;
					$name = isset( $specs[ $slug ] ) ? $specs[ $slug ] : $slug;

					if ( $was !== $value || isset( $text[ $slug ] ) ) {
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
				$wc->set_attributes( self::wc_attributes( $wc->get_attributes(), $wanted['attributes'], isset( $wanted['filters'] ) ? $wanted['filters'] : array() ) );
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
	 * attributes stay. A filter detail becomes its shared attribute and its
	 * text copy goes; other taxonomy attributes are never touched.
	 *
	 * @param array $current Current WC_Product_Attribute list.
	 * @param array $values  Name => value from ePim.
	 * @param array $filters Filter details, slug => value.
	 * @return array
	 */
	private static function wc_attributes( array $current, array $values, array $filters ) {
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
			$term_id   = isset( $filters[ $key ] ) ? self::filter_term( $key, $value ) : 0;

			if ( $term_id ) {
				$attribute->set_id( (int) wc_attribute_taxonomy_id_by_name( 'pa_' . $key ) );
				$attribute->set_name( 'pa_' . $key );
				$attribute->set_options( array( $term_id ) );
				$attribute->set_position( $position++ );
				$attribute->set_visible( true );
				$attribute->set_variation( false );

				unset( $by_key[ $key ] );
				$by_key[ 'pa_' . $key ] = $attribute;
				continue;
			}

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
			update_post_meta( $id, '_product_attributes', self::meta_attributes( $id, $wanted['attributes'], isset( $wanted['filters'] ) ? $wanted['filters'] : array() ) );
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
	 * merged over the current ones. A filter detail is set as its shared
	 * attribute's term, and its text copy goes.
	 *
	 * @param int   $id      Product ID.
	 * @param array $values  Name => value.
	 * @param array $filters Filter details, slug => value.
	 * @return array
	 */
	private static function meta_attributes( $id, array $values, array $filters ) {
		$stored   = get_post_meta( $id, '_product_attributes', true );
		$stored   = is_array( $stored ) ? $stored : array();
		$position = 0;

		foreach ( $values as $name => $value ) {
			$key     = sanitize_title( $name );
			$term_id = isset( $filters[ $key ] ) ? self::filter_term( $key, $value ) : 0;

			if ( $term_id ) {
				wp_set_object_terms( $id, array( $term_id ), 'pa_' . $key, false );
				unset( $stored[ $key ] );

				$stored[ 'pa_' . $key ] = array(
					'name'         => 'pa_' . $key,
					'value'        => '',
					'position'     => $position++,
					'is_visible'   => 1,
					'is_variation' => 0,
					'is_taxonomy'  => 1,
				);
				continue;
			}

			$stored[ $key ] = array(
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
