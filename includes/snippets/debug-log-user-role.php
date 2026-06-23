<?php
/**
 * Frontend: Console Log User Role
 *
 * Log the users role to the console for troubleshooting.
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function console_log_user_type() {
    $current_user = wp_get_current_user();

    if ( is_user_logged_in() ) {
        $roles = $current_user->roles;
        $role  = ! empty( $roles ) ? implode( ', ', $roles ) : 'No role assigned';
    } else {
        $role = 'guest';
    }

    echo "<script>console.log('WordPress User Type: " . esc_js( $role ) . "');</script>";
}
add_action( 'wp_footer', 'console_log_user_type' );
