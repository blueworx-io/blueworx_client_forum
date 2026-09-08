<?php
/**
 * Frontend: Allow Filter in Catalogues
 *
 * This snippet will allow for filtering of the catalogues in the backend
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Catalogue manual + drag/drop ordering
add_action(
	'init',
	function () {
		add_post_type_support( 'catalogue', 'page-attributes' );
	},
	20
);

add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $pagenow;

		if ( $pagenow === 'edit.php' && $query->get( 'post_type' ) === 'catalogue' && ! $query->get( 'orderby' ) ) {
			$query->set( 'orderby', 'menu_order' );
			$query->set( 'order', 'ASC' );
		}
	}
);

add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( $hook !== 'edit.php' ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'catalogue' ) {
			return;
		}

		wp_enqueue_script( 'jquery-ui-sortable' );

		wp_add_inline_script(
			'jquery-ui-sortable',
			'
        jQuery(function($) {
            $("#the-list").sortable({
                items: "tr",
                axis: "y",
                cursor: "move",
                update: function() {
                    var order = [];

                    $("#the-list tr").each(function() {
                        var id = $(this).attr("id");
                        if (id) {
                            order.push(id.replace("post-", ""));
                        }
                    });

                    $.post(ajaxurl, {
                        action: "save_catalogue_drag_order",
                        order: order,
                        nonce: "' . wp_create_nonce( 'catalogue_drag_order' ) . '"
                    });
                }
            });
        });
    '
		);
	}
);

add_action(
	'wp_ajax_save_catalogue_drag_order',
	function () {
		check_ajax_referer( 'catalogue_drag_order', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
		}

		if ( empty( $_POST['order'] ) || ! is_array( $_POST['order'] ) ) {
			wp_send_json_error();
		}

		// Nonce already verified above via check_ajax_referer(); unslash and cast IDs to integers.
		$order = array_map( 'absint', (array) wp_unslash( $_POST['order'] ) );

		foreach ( $order as $position => $post_id ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'menu_order' => intval( $position ),
				)
			);
		}

		wp_send_json_success();
	}
);
