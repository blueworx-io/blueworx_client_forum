<?php
/**
 * Keeps WooCommerce's product categories in step with ePim's.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each ePim category is one product_cat term, tagged with the ePim ID in term
 * meta so renames and moves follow ePim without making a second term.
 */
final class EPI_Pull_Categories {

	/**
	 * Term meta holding the ePim category ID.
	 */
	const META = '_epim_category_id';

	/**
	 * Create or update a term for every category, parents before children.
	 *
	 * @param array $categories Entries with Id, Name, ParentId.
	 * @param bool  $dry_run    Only match existing terms; change nothing.
	 * @return array ePim category ID => term ID.
	 */
	public static function sync( array $categories, $dry_run = false ) {
		$by_id = array();

		foreach ( $categories as $category ) {
			if ( is_array( $category ) && ! empty( $category['Id'] ) ) {
				$by_id[ absint( $category['Id'] ) ] = $category;
			}
		}

		$map     = array();
		$pending = $by_id;
		$guard   = 0;
		$limit   = count( $by_id );

		// Each pass places every category whose parent is placed (or unknown).
		// A list of n categories needs at most n passes; the guard stops a cycle.
		while ( $pending && $guard++ <= $limit ) {
			foreach ( $pending as $epim_id => $category ) {
				$parent_epim = isset( $category['ParentId'] ) ? absint( $category['ParentId'] ) : 0;

				if ( $parent_epim && isset( $by_id[ $parent_epim ] ) && ! isset( $map[ $parent_epim ] ) ) {
					continue;
				}

				$parent_term = $parent_epim && isset( $map[ $parent_epim ] ) ? (int) $map[ $parent_epim ] : 0;
				$term_id     = self::ensure_term( isset( $category['Name'] ) ? (string) $category['Name'] : '', $parent_term, $epim_id, $dry_run );

				if ( $term_id ) {
					$map[ $epim_id ] = $term_id;
				}

				unset( $pending[ $epim_id ] );
			}
		}

		return $map;
	}

	/**
	 * The term for an ePim category ID, or null.
	 *
	 * @param int $epim_id ePim category ID.
	 * @return WP_Term|null
	 */
	public static function term_for( $epim_id ) {
		$found = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1,
				// WooCommerce sorts product_cat by its own meta by default, which would replace the lookup below.
				'orderby'    => 'name',
				'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One term per ePim ID.
				'meta_value' => (string) absint( $epim_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( is_wp_error( $found ) || ! $found ) {
			return null;
		}

		return $found[0] instanceof WP_Term ? $found[0] : null;
	}

	/**
	 * The term for one ePim category: found by ePim ID, else by name under the
	 * same parent (the site's existing categories, on first contact), else made.
	 * In a dry run only terms that already exist are matched; nothing is
	 * created, renamed or tagged.
	 *
	 * @param string $name    Category name.
	 * @param int    $parent  Parent term ID, 0 for top level.
	 * @param int    $epim_id ePim category ID.
	 * @param bool   $dry_run Match only; change nothing.
	 * @return int Term ID, or 0 if it could not be made.
	 */
	private static function ensure_term( $name, $parent, $epim_id, $dry_run = false ) {
		$name = trim( $name );

		if ( '' === $name ) {
			return 0;
		}

		$term = self::term_for( $epim_id );

		if ( $term ) {
			$term_id = (int) $term->term_id;

			if ( ! $dry_run && ( $term->name !== $name || (int) $term->parent !== $parent ) ) {
				wp_update_term(
					$term_id,
					'product_cat',
					array(
						'name'   => $name,
						'parent' => $parent,
					)
				);
			}

			return $term_id;
		}

		$existing = term_exists( $name, 'product_cat', $parent );

		if ( $dry_run ) {
			return $existing ? (int) ( is_array( $existing ) ? $existing['term_id'] : $existing ) : 0;
		}

		if ( $existing ) {
			$term_id = (int) ( is_array( $existing ) ? $existing['term_id'] : $existing );
		} else {
			$created = wp_insert_term( $name, 'product_cat', array( 'parent' => $parent ) );

			if ( is_wp_error( $created ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A category that cannot be made must leave a trace.
				error_log( sprintf( '[Forum ePim pull] Could not make category "%1$s": %2$s', $name, $created->get_error_message() ) );
				return 0;
			}

			$term_id = (int) $created['term_id'];
		}

		update_term_meta( $term_id, self::META, (string) $epim_id );

		return $term_id;
	}
}
