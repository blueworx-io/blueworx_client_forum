<?php
/**
 * Frontend: Show ACF User Details on Profile
 *
 * Show all the custom ACF fields per user on the frontend
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Add shortcode to display ACF fields for a user
add_shortcode('show_user_acf_fields', function () {
	// Get current user ID
	$user_id = get_current_user_id();
	if (!$user_id) {
		return '<p>Please log in to view your account details.</p>';
	}

	// Fetch ACF fields
	$account_number     = get_field('account_number', 'user_' . $user_id);
	$sales_representative = get_field('sales_representative', 'user_' . $user_id);
	$account_manager    = get_field('account_manager', 'user_' . $user_id);
	$contact_number     = get_field('contact_number', 'user_' . $user_id);
	$contact_email      = get_field('contact_email', 'user_' . $user_id);

	ob_start(); ?>
	<table class="user-acf-table">
		<tr><th>Account Number</th><td><?php echo esc_html($account_number); ?></td></tr>
		<tr><th>Sales Representative</th><td><?php echo esc_html($sales_representative); ?></td></tr>
		<tr><th>Account Manager</th><td><?php echo esc_html($account_manager); ?></td></tr>
		<tr><th>Contact Number</th><td><?php echo esc_html($contact_number); ?></td></tr>
		<tr><th>Contact Email</th><td><?php echo esc_html($contact_email); ?></td></tr>
	</table>
	<?php
	return ob_get_clean();
});
