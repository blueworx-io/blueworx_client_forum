<?php
/**
 * "Where they work" — the gallery.
 *
 * The first card is the big one; the rest fill two columns beside it. A card
 * with no photo yet shows the design's placeholder frame.
 *
 * Under the cards, the receiver comparison table. It is left out when no
 * receivers are listed.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "04".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'where_heading' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'where_intro' );
$epi_cards   = EPI_Forum_Page_Renderer::rows( $post_id, 'where_cards' );

// The receiver table: one column per receiver, one row per property. The
// connection doubles as the red tag above each name. A receiver with a SKU
// takes its name, photo, link, max load and size from that product; what was
// typed fills in only where the product has nothing, and the rest is typed.
$epi_receivers = array();

foreach ( EPI_Forum_Page_Renderer::rows( $post_id, 'where_receivers' ) as $epi_row ) {
	$epi_sku     = isset( $epi_row['sku'] ) ? trim( (string) $epi_row['sku'] ) : '';
	$epi_product = null;

	if ( '' !== $epi_sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
		$epi_found = wc_get_product_id_by_sku( $epi_sku );
		if ( $epi_found ) {
			$epi_product = wc_get_product( $epi_found );
		}
	}

	// A drafted or withdrawn product would link to a page nobody can see.
	if ( $epi_product && 'publish' === $epi_product->get_status() ) {
		// The import writes "max. 10A / 1000W LED"; the row is already called Max load.
		$epi_load = trim( (string) preg_replace( '/^\s*max\.?\s*/i', '', $epi_product->get_attribute( 'Max rated load' ) ) );
		$epi_size = array();
		foreach ( array( 'Product Height (mm)', 'Product Width (mm)', 'Product Depth (mm)' ) as $epi_dimension ) {
			$epi_mm = trim( (string) preg_replace( '/\s*mm\s*$/i', '', $epi_product->get_attribute( $epi_dimension ) ) );
			if ( '' !== $epi_mm ) {
				$epi_size[] = $epi_mm . 'mm';
			}
		}

		$epi_row['url'] = $epi_product->get_permalink();

		// The product's own image markup, so a photo held in ePim shows the
		// way it does in the shop rather than being read as a media item.
		if ( $epi_product->get_image_id() ) {
			$epi_row['image_html'] = $epi_product->get_image( 'medium' );
		}

		foreach ( array(
			'name'     => $epi_product->get_name(),
			'max_load' => $epi_load,
			'size'     => implode( ' x ', $epi_size ),
		) as $epi_key => $epi_value ) {
			if ( $epi_value ) {
				$epi_row[ $epi_key ] = $epi_value;
			}
		}
	}

	$epi_receivers[] = $epi_row;
}

$epi_r_eyebrow = EPI_Forum_Page_Renderer::value( $post_id, 'where_receivers_eyebrow' );
$epi_r_heading = EPI_Forum_Page_Renderer::value( $post_id, 'where_receivers_heading' );
$epi_r_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'where_receivers_intro' );
$epi_r_note    = array_values( array_filter( array_map( 'trim', explode( '·', EPI_Forum_Page_Renderer::value( $post_id, 'where_receivers_note' ) ) ) ) );
$epi_r_flags   = array( 'app', 'wired' );
$epi_r_rows    = array(
	'connection' => __( 'Connection', 'blueworx_client_forum' ),
	'channels'   => __( 'Channels', 'blueworx_client_forum' ),
	'function'   => __( 'Function', 'blueworx_client_forum' ),
	'max_load'   => __( 'Max load', 'blueworx_client_forum' ),
	'app'        => __( 'Smart App control', 'blueworx_client_forum' ),
	'wired'      => __( 'Wired switch supported', 'blueworx_client_forum' ),
	'size'       => __( 'Size', 'blueworx_client_forum' ),
);
$epi_tick      = EPI_Forum_Page_Renderer::asset_url( 'assets/img/forum-page/receiver-tick.svg' );
?>
<section class="epi-forum-page__section epi-forum-page__where" id="where">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_cards ) : ?>
		<ul class="epi-forum-page__cards">
			<?php foreach ( $epi_cards as $epi_i => $epi_card ) : ?>
				<li class="epi-forum-page__card<?php echo 0 === $epi_i ? ' epi-forum-page__card--featured' : ''; ?>">
					<div class="epi-forum-page__cardimage">
						<?php if ( ! empty( $epi_card['image'] ) ) : ?>
							<?php echo wp_get_attachment_image( (int) $epi_card['image'], 0 === $epi_i ? 'large' : 'medium_large' ); ?>
						<?php else : ?>
							<span class="epi-forum-page__label"><?php esc_html_e( 'Photography', 'blueworx_client_forum' ); ?></span>
						<?php endif; ?>
					</div>
					<h3><?php echo esc_html( isset( $epi_card['title'] ) ? $epi_card['title'] : '' ); ?></h3>
					<p><?php echo esc_html( isset( $epi_card['body'] ) ? $epi_card['body'] : '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $epi_receivers ) : ?>
		<div class="epi-forum-page__receivers">
			<div class="epi-forum-page__receiverhead">
				<?php if ( $epi_r_eyebrow ) : ?>
					<p class="epi-forum-page__receivereyebrow"><?php echo esc_html( $epi_r_eyebrow ); ?></p>
				<?php endif; ?>
				<?php if ( $epi_r_heading ) : ?>
					<h3><?php echo esc_html( $epi_r_heading ); ?></h3>
				<?php endif; ?>
				<?php if ( $epi_r_intro ) : ?>
					<p class="epi-forum-page__receiverintro"><?php echo esc_html( $epi_r_intro ); ?></p>
				<?php endif; ?>
				<?php if ( $epi_r_note ) : ?>
					<p class="epi-forum-page__receivernote">
						<span class="epi-forum-page__label"><?php esc_html_e( 'All receivers', 'blueworx_client_forum' ); ?></span>
						<?php foreach ( $epi_r_note as $epi_i => $epi_item ) : ?>
							<?php if ( $epi_i ) : ?>
								<span class="epi-forum-page__receiverdot" aria-hidden="true">·</span>
							<?php endif; ?>
							<strong><?php echo esc_html( $epi_item ); ?></strong>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>

			<div class="epi-forum-page__tablewrap">
				<table>
					<thead>
						<tr>
							<td></td>
							<?php foreach ( $epi_receivers as $epi_receiver ) : ?>
								<th scope="col">
									<span class="epi-forum-page__receiverimage">
										<?php if ( ! empty( $epi_receiver['image_html'] ) ) : ?>
											<?php echo $epi_receiver['image_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WooCommerce's own image markup. ?>
										<?php elseif ( ! empty( $epi_receiver['image'] ) ) : ?>
											<?php echo wp_get_attachment_image( (int) $epi_receiver['image'], 'medium' ); ?>
										<?php endif; ?>
									</span>
									<?php if ( ! empty( $epi_receiver['connection'] ) ) : ?>
										<span class="epi-forum-page__receivertag"><?php echo esc_html( $epi_receiver['connection'] ); ?></span>
									<?php endif; ?>
									<span class="epi-forum-page__receivername">
										<?php if ( ! empty( $epi_receiver['url'] ) ) : ?>
											<a href="<?php echo esc_url( $epi_receiver['url'] ); ?>"><?php echo esc_html( isset( $epi_receiver['name'] ) ? $epi_receiver['name'] : '' ); ?></a>
										<?php else : ?>
											<?php echo esc_html( isset( $epi_receiver['name'] ) ? $epi_receiver['name'] : '' ); ?>
										<?php endif; ?>
									</span>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $epi_r_rows as $epi_key => $epi_label ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $epi_label ); ?></th>
								<?php foreach ( $epi_receivers as $epi_receiver ) : ?>
									<?php $epi_cell = isset( $epi_receiver[ $epi_key ] ) ? $epi_receiver[ $epi_key ] : ''; ?>
									<td>
										<?php if ( in_array( $epi_key, $epi_r_flags, true ) ) : ?>
											<?php if ( $epi_cell ) : ?>
												<img class="epi-forum-page__tick" src="<?php echo esc_url( $epi_tick ); ?>" alt="<?php esc_attr_e( 'Yes', 'blueworx_client_forum' ); ?>" width="13.4999" height="9.74996">
											<?php else : ?>
												<span class="epi-forum-page__dash" role="img" aria-label="<?php esc_attr_e( 'No', 'blueworx_client_forum' ); ?>">—</span>
											<?php endif; ?>
										<?php elseif ( '' === trim( (string) $epi_cell ) ) : ?>
											<span class="epi-forum-page__dash" role="img" aria-label="<?php esc_attr_e( 'Not stated', 'blueworx_client_forum' ); ?>">—</span>
										<?php else : ?>
											<?php echo esc_html( $epi_cell ); ?>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>
</section>
