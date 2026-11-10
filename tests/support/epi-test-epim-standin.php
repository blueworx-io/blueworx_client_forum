<?php
/**
 * Plugin Name: Forum test support — stand-in ePim image host
 * Description: Test-only. Copied into the harness's mu-plugins by tests/epim-image-cache.spec.js
 *              so the specs never depend on the real ePim. Never shipped: tests/ is outside the
 *              release allowlist.
 */

// The harness is 127.0.0.1; "localhost" on the same port is a different origin,
// which is what ePim is to the live site. mu-plugins load before the plugin, so
// this wins over the plugin's own definition.
$epi_test_port = isset( $_SERVER['SERVER_PORT'] ) ? (int) $_SERVER['SERVER_PORT'] : 8881;
define( 'EPI_EPIM_IMAGE_BASE', 'http://localhost:' . $epi_test_port . '/?epi_test_image=' );

// Answer ?epi_test_image={id}.jpg the way ePim does: an image with
// "Cache-Control: private", or a 500 HTML page for a picture that is missing.
add_action(
	'init',
	static function () {
		$asked = isset( $_GET['epi_test_image'] ) ? sanitize_text_field( wp_unslash( $_GET['epi_test_image'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! preg_match( '#^(\d+)\.jpg$#', $asked, $match ) ) {
			return;
		}

		if ( '999999' === $match[1] ) {
			status_header( 500 );
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<html><body>Server Error</body></html>';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: image/png' );
		header( 'Cache-Control: private' );
		// The smallest valid PNG: one white pixel.
		echo base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=' ); // phpcs:ignore
		exit;
	},
	0
);
