<?php
/**
 * Talks to ePim's read API.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GET requests with the subscription key, and one page of a paged list.
 */
final class EPI_Pull_Client {

	/**
	 * Fetch one endpoint and decode its JSON.
	 *
	 * @param string $path  Path after /api/, such as 'Variations'.
	 * @param array  $query Query string values.
	 * @return array|WP_Error Decoded body, or a plain-language error.
	 */
	public static function get( $path, array $query = array() ) {
		$key = EPI_Pull_Settings::key();

		if ( '' === $key ) {
			return new WP_Error( 'epi_pull_no_key', __( 'No ePim subscription key is saved.', 'blueworx_client_forum' ) );
		}

		$url = EPI_Pull_Settings::base_url() . ltrim( $path, '/' );

		if ( $query ) {
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Ocp-Apim-Subscription-Key' => $key,
					'Accept'                    => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'epi_pull_network',
				/* translators: 1: endpoint path, 2: the network error. */
				sprintf( __( 'Could not reach ePim for %1$s: %2$s', 'blueworx_client_forum' ), $path, $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'epi_pull_bad_key', __( 'ePim did not accept the subscription key.', 'blueworx_client_forum' ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			/* translators: 1: HTTP status code, 2: endpoint path. */
			return new WP_Error( 'epi_pull_http', sprintf( __( 'ePim answered %1$d for %2$s.', 'blueworx_client_forum' ), $code, $path ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			/* translators: %s: endpoint path. */
			return new WP_Error( 'epi_pull_body', sprintf( __( 'ePim sent something that is not JSON for %s.', 'blueworx_client_forum' ), $path ) );
		}

		return $body;
	}

	/**
	 * One page of a paged list.
	 *
	 * @param string $path  Endpoint path.
	 * @param array  $query Query values besides start and limit.
	 * @param int    $start Offset of the first record.
	 * @return array|WP_Error array( 'results' => array, 'total' => int ).
	 */
	public static function page( $path, array $query, $start ) {
		$query['start'] = (int) $start;
		$query['limit'] = EPI_Pull_Settings::page_size();

		$body = self::get( $path, $query );

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// A page with no results list is broken, not empty: treating it as the
		// end of the list would make a part-read pull the next baseline.
		if ( ! isset( $body['Results'] ) || ! is_array( $body['Results'] ) ) {
			/* translators: %s: endpoint path. */
			return new WP_Error( 'epi_pull_body', sprintf( __( 'ePim sent a page without results for %s.', 'blueworx_client_forum' ), $path ) );
		}

		return array(
			'results' => array_values( $body['Results'] ),
			'total'   => isset( $body['TotalResults'] ) ? (int) $body['TotalResults'] : 0,
		);
	}

	/**
	 * A page of variations changed since a time, archived and unapproved included
	 * so that a product taken off sale comes through and gets hidden.
	 *
	 * @param string $since_utc ISO 8601 UTC time.
	 * @param int    $start     Offset.
	 * @return array|WP_Error
	 */
	public static function variations( $since_utc, $start ) {
		return self::page(
			'Variations',
			array(
				'changedSinceUTC' => $since_utc,
				'showArchived'    => 'true',
				'showUnApproved'  => 'true',
			),
			$start
		);
	}

	/**
	 * A page of deleted entities since a time.
	 *
	 * @param string $since_utc ISO 8601 UTC time.
	 * @param int    $start     Offset.
	 * @return array|WP_Error
	 */
	public static function deleted( $since_utc, $start ) {
		return self::page( 'DeletedEntities', array( 'since' => $since_utc ), $start );
	}

	/**
	 * Every category. The list is small, so it is not paged.
	 *
	 * @return array|WP_Error
	 */
	public static function categories() {
		$body = self::get( 'Categories' );

		return is_wp_error( $body ) ? $body : array_values( $body );
	}
}
