<?php
/**
 * Plugin Name: Forum test support — automatic update decisions over REST
 * Description: Test-only. Copied into the harness's mu-plugins by tests/auto-updates.spec.js
 *              so a spec can ask WordPress's own automatic updater what it would install.
 *              Never shipped: tests/ is outside the release allowlist.
 */

// Ask the real decision WordPress makes before it installs an update in the
// background (WP_Automatic_Updater::should_update), for a pending update on
// this plugin and on two others, and report the Plugins screen's view of it.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'epi-test/v1',
			'/auto-updates',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => static function () {
					require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
					require_once ABSPATH . 'wp-admin/includes/admin.php';

					// The harness sits inside a git checkout, which would make
					// WordPress skip every automatic update for that reason alone.
					add_filter( 'automatic_updates_is_vcs_checkout', '__return_false' );

					// Start from a known list, whatever an earlier run left: the site
					// has chosen automatic updates for Hello Dolly and nothing else.
					$original = get_site_option( 'auto_update_plugins', false );
					$known    = array( 'hello.php' );
					update_site_option( 'auto_update_plugins', $known );

					$updater = new WP_Automatic_Updater();
					$own     = plugin_basename( EPI_PLUGIN_FILE );
					$asset   = 'https://github.com/blueworx-io/blueworx_client_forum/releases/download/v99.0.0/blueworx_client_forum-99.0.0.zip';
					$source  = 'https://api.github.com/repos/blueworx-io/blueworx_client_forum/zipball/v99.0.0';
					$result  = array();

					$cases = array(
						'own'      => array( $own, $asset ),
						'own_src'  => array( $own, $source ),
						'own_none' => array( $own, '' ),
						'hello'    => array( 'hello.php', $source ),
						'other'    => array( 'someone-else/someone-else.php', $source ),
					);

					foreach ( $cases as $name => $case ) {
						list( $basename, $package ) = $case;

						$item = (object) array(
							'id'          => 'w.org/plugins/' . dirname( $basename ),
							'slug'        => dirname( $basename ),
							'plugin'      => $basename,
							'new_version' => '99.0.0',
							'package'     => $package,
						);

						$result[ $name ] = array(
							'installs'   => (bool) $updater->should_update( 'plugin', $item, WP_PLUGIN_DIR ),
							// What the Plugins screen sees when nobody has chosen: null = left to the site.
							'forced'     => apply_filters( 'auto_update_plugin', null, $item ),
							// A site that switched auto-updates on / off for it by hand.
							'kept_true'  => apply_filters( 'auto_update_plugin', true, $item ),
							'kept_false' => apply_filters( 'auto_update_plugin', false, $item ),
						);
					}

					$changed = get_site_option( 'auto_update_plugins', array() ) !== $known;

					if ( false === $original ) {
						delete_site_option( 'auto_update_plugins' );
					} else {
						update_site_option( 'auto_update_plugins', $original );
					}

					return array(
						'cases'          => $result,
						'option_changed' => $changed,
					);
				},
			)
		);
	}
);
