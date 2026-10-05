<?php
/**
 * Turns one ePim variation record into the fields a product gets.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure mapping: no database, no HTTP. The writer applies the result.
 */
final class EPI_Pull_Mapper {

	/**
	 * Map a record as ePim sends it.
	 *
	 * @param array $raw One entry of a Variations response.
	 * @return array See the task's Interfaces block for the keys.
	 */
	public static function map( array $raw ) {
		$archived = ! empty( $raw['IsArchived'] );
		$approved = ! array_key_exists( 'IsApprovedForPublishing', $raw ) || ! empty( $raw['IsApprovedForPublishing'] );
		$price    = isset( $raw['Price'] ) && is_numeric( $raw['Price'] ) ? number_format( (float) $raw['Price'], 2, '.', '' ) : '';

		return array(
			'epim_id'         => isset( $raw['Id'] ) ? absint( $raw['Id'] ) : 0,
			'epim_product_id' => isset( $raw['ProductId'] ) ? absint( $raw['ProductId'] ) : 0,
			'sku'             => isset( $raw['SKU'] ) ? trim( (string) $raw['SKU'] ) : '',
			'name'            => isset( $raw['Name'] ) ? trim( (string) $raw['Name'] ) : '',
			'description'     => isset( $raw['SKU_Text'] ) ? (string) $raw['SKU_Text'] : '',
			'price'           => $price,
			'hidden'          => $archived || ! $approved,
			'category_ids'    => self::ids( isset( $raw['ProductCategoryIds'] ) ? $raw['ProductCategoryIds'] : array() ),
			'image_ids'       => self::images( $raw ),
			'attributes'      => self::attributes( isset( $raw['AttributeValues'] ) ? $raw['AttributeValues'] : array() ),
		);
	}

	/**
	 * The product photos, main image first. The "Image" group when ePim sends
	 * one; otherwise every picture that is not in another group (logo, datasheet).
	 *
	 * @param array $raw The record.
	 * @return int[]
	 */
	private static function images( array $raw ) {
		$grouped = isset( $raw['PictureIdsGrouped'] ) && is_array( $raw['PictureIdsGrouped'] ) ? $raw['PictureIdsGrouped'] : array();

		if ( ! empty( $grouped['Image'] ) && is_array( $grouped['Image'] ) ) {
			return self::ids( $grouped['Image'] );
		}

		$images = isset( $raw['PictureIds'] ) && is_array( $raw['PictureIds'] ) ? $raw['PictureIds'] : array();

		foreach ( $grouped as $group => $ids ) {
			if ( 'Image' !== $group && is_array( $ids ) ) {
				$images = array_diff( $images, $ids );
			}
		}

		return self::ids( $images );
	}

	/**
	 * Attribute name => value, in the order sent. Empty names or values are dropped.
	 *
	 * @param mixed $values The AttributeValues list.
	 * @return array
	 */
	private static function attributes( $values ) {
		$attributes = array();

		foreach ( is_array( $values ) ? $values : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$name  = isset( $entry['AttributeHeaderName'] ) ? trim( (string) $entry['AttributeHeaderName'] ) : '';
			$value = isset( $entry['Value'] ) ? trim( (string) $entry['Value'] ) : '';

			if ( '' === $name || '' === $value ) {
				continue;
			}

			$attributes[ $name ] = $value;
		}

		return $attributes;
	}

	/**
	 * Positive integers, unique, in order.
	 *
	 * @param mixed $values A list.
	 * @return int[]
	 */
	private static function ids( $values ) {
		$ids = array();

		foreach ( is_array( $values ) ? $values : array() as $value ) {
			$id = absint( $value );

			if ( $id && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
