<?php
/**
 * Backend: Remove Woo App Text From Emails
 *
 * Remove the "Process your orders on the go. Get the app." text from order emails
 *
 * Moved verbatim from the Code Snippets plugin (two original snippet blocks,
 * combined into one toggleable feature).
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'woocommerce_email_footer_text',
	function ( $text ) {
		// Remove "Process your orders on the go. Get the app."
		return '';
	}
);

/**
 * Disable messages about the mobile apps in WooCommerce emails.
 * https://wordpress.org/support/topic/remove-process-your-orders-on-the-go-get-the-app/
 */
function epi_disable_mobile_messaging( $mailer ) {
	remove_action( 'woocommerce_email_footer', array( $mailer->emails['WC_Email_New_Order'], 'mobile_messaging' ), 9 );
}
add_action( 'woocommerce_email', 'epi_disable_mobile_messaging' );
