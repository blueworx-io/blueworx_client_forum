<?php
/**
 * Who a change came from.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sorts a request into ePim, staff, or neither.
 */
final class EPI_Change_Source {

	const EPIM    = 'epim';
	const STAFF   = 'staff';
	const IGNORED = 'ignored';

	/**
	 * Classify a request from plain facts about it.
	 *
	 * An API request with a signed-in user and no nonce was authenticated by a
	 * key, not a browser session: WordPress drops cookie sign-ins from REST
	 * requests that carry no nonce. That is ePim.
	 *
	 * @param array $context Keys: cli, cron, api, nonce, admin, user_id, can_edit.
	 * @return string One of the class constants.
	 */
	public static function classify( array $context ) {
		if ( ! empty( $context['cli'] ) || ! empty( $context['cron'] ) || empty( $context['user_id'] ) ) {
			return self::IGNORED;
		}

		if ( ! empty( $context['api'] ) ) {
			if ( empty( $context['nonce'] ) ) {
				return self::EPIM;
			}

			return empty( $context['can_edit'] ) ? self::IGNORED : self::STAFF;
		}

		return ! empty( $context['admin'] ) && ! empty( $context['can_edit'] ) ? self::STAFF : self::IGNORED;
	}

	/**
	 * Classify the current request for one product or variation.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return string
	 */
	public static function current( $object_id ) {
		return self::classify(
			array(
				'cli'      => defined( 'WP_CLI' ) && WP_CLI,
				'cron'     => wp_doing_cron(),
				'api'      => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks a nonce is present, to tell a browser session from an API key; WordPress verifies it.
				'nonce'    => ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) || ! empty( $_REQUEST['_wpnonce'] ),
				'admin'    => is_admin(),
				'user_id'  => get_current_user_id(),
				'can_edit' => current_user_can( 'edit_post', $object_id ),
			)
		);
	}

	/**
	 * The name shown for who made the change.
	 *
	 * @param string $source One of the class constants.
	 * @return string
	 */
	public static function actor( $source ) {
		if ( self::EPIM === $source ) {
			return __( 'ePim External API', 'blueworx_client_forum' );
		}

		$user = wp_get_current_user();

		return $user->exists() ? $user->display_name : __( 'Unknown user', 'blueworx_client_forum' );
	}
}
