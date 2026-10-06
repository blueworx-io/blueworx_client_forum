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
	 * Background jobs are nobody's update, even though Action Scheduler starts
	 * them from wp-admin with the admin's cookies. And a key holder who cannot
	 * edit products (a customer on the mobile app, say) is not ePim.
	 *
	 * @param array $context Keys: cli, cron, background, api, nonce, admin, user_id, can_edit.
	 * @return string One of the class constants.
	 */
	public static function classify( array $context ) {
		if ( ! empty( $context['cli'] ) || ! empty( $context['cron'] ) || ! empty( $context['background'] ) ) {
			return self::IGNORED;
		}

		if ( empty( $context['user_id'] ) || empty( $context['can_edit'] ) ) {
			return self::IGNORED;
		}

		if ( ! empty( $context['api'] ) ) {
			return empty( $context['nonce'] ) ? self::EPIM : self::STAFF;
		}

		return ! empty( $context['admin'] ) ? self::STAFF : self::IGNORED;
	}

	/**
	 * Classify the current request for one product or variation.
	 *
	 * @param int $object_id Product or variation ID.
	 * @return string
	 */
	public static function current( $object_id ) {
		$source = self::classify(
			array(
				'cli'        => defined( 'WP_CLI' ) && WP_CLI,
				'cron'       => wp_doing_cron(),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads which AJAX action is running; nothing is changed on it.
				'background' => did_action( 'action_scheduler_before_execute' ) || ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'as_async_request_queue_runner' === $_REQUEST['action'] ),
				'api'        => ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WC_API_REQUEST' ) && WC_API_REQUEST ),
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checks a nonce is present, to tell a browser session from an API key; WordPress verifies it.
				'nonce'      => ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) || ! empty( $_REQUEST['_wpnonce'] ),
				'admin'      => is_admin(),
				'user_id'    => get_current_user_id(),
				'can_edit'   => current_user_can( 'edit_post', $object_id ),
			)
		);

		/**
		 * Filter who a change is from. The ePim pull runs as a background job,
		 * which would be ignored; it claims its writes as ePim through this.
		 *
		 * @param string $source    One of the class constants.
		 * @param int    $object_id Product or variation ID.
		 */
		return (string) apply_filters( 'epi_change_source', $source, $object_id );
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
