<?php
/**
 * "The range" — the products this page points at.
 *
 * Each row names a product by SKU; its name, photo and link come from the
 * WooCommerce product, so they never drift from the shop. Only the one-line
 * description is editorial. A row whose SKU is blank or matches nothing — or a
 * site without WooCommerce — still shows with what was typed, so the page
 * never quietly loses a card.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "06".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading    = EPI_Forum_Page_Renderer::value( $post_id, 'range_heading' );
$epi_intro      = EPI_Forum_Page_Renderer::value( $post_id, 'range_intro' );
$epi_rows       = EPI_Forum_Page_Renderer::rows( $post_id, 'range_products' );
$epi_more_label = EPI_Forum_Page_Renderer::value( $post_id, 'range_more_label' );
$epi_more_url   = EPI_Forum_Page_Renderer::value( $post_id, 'range_more_url' );
$epi_products   = array();

foreach ( $epi_rows as $epi_row ) {
	$epi_sku   = isset( $epi_row['sku'] ) ? trim( (string) $epi_row['sku'] ) : '';
	$epi_blurb = isset( $epi_row['blurb'] ) ? trim( (string) $epi_row['blurb'] ) : '';

	if ( '' === $epi_sku && '' === $epi_blurb ) {
		continue;
	}

	$epi_product = null;

	if ( '' !== $epi_sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
		$epi_found = wc_get_product_id_by_sku( $epi_sku );
		if ( $epi_found ) {
			$epi_product = wc_get_product( $epi_found );
		}
	}

	$epi_products[] = array(
		'sku'   => $epi_sku,
		'blurb' => $epi_blurb,
		'name'  => $epi_product ? $epi_product->get_name() : '',
		'url'   => $epi_product ? $epi_product->get_permalink() : '',
		'image' => $epi_product ? (int) $epi_product->get_image_id() : 0,
	);
}
?>
<section class="epi-forum-page__section epi-forum-page__range" id="range">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_products ) : ?>
		<ul class="epi-forum-page__products">
			<?php foreach ( $epi_products as $epi_item ) : ?>
				<li class="epi-forum-page__product">
					<div class="epi-forum-page__productimage">
						<?php if ( $epi_item['image'] ) : ?>
							<?php echo wp_get_attachment_image( $epi_item['image'], 'medium_large' ); ?>
						<?php else : ?>
							<span class="epi-forum-page__label"><?php esc_html_e( 'Product', 'blueworx_client_forum' ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $epi_item['name'] ) : ?>
						<h3><?php echo esc_html( $epi_item['name'] ); ?></h3>
					<?php endif; ?>
					<?php if ( $epi_item['blurb'] ) : ?>
						<p><?php echo esc_html( $epi_item['blurb'] ); ?></p>
					<?php endif; ?>
					<p class="epi-forum-page__sku">
						<?php
						echo esc_html(
							'' !== $epi_item['sku']
								? sprintf( 'SKU — %s', $epi_item['sku'] )
								: __( 'SKU — to supply', 'blueworx_client_forum' )
						);
						?>
					</p>
					<?php if ( $epi_item['url'] ) : ?>
						<a class="epi-forum-page__button epi-forum-page__button--outline" href="<?php echo esc_url( $epi_item['url'] ); ?>"><?php esc_html_e( 'Read more', 'blueworx_client_forum' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $epi_more_label && $epi_more_url ) : ?>
		<div class="epi-forum-page__buttons">
			<a class="epi-forum-page__button" href="<?php echo esc_url( $epi_more_url ); ?>"><?php echo esc_html( $epi_more_label ); ?></a>
		</div>
	<?php endif; ?>
</section>
