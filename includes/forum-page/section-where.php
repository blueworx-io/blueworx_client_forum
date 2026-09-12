<?php
/**
 * "Where they work" — the gallery.
 *
 * The first card is the big one; the rest fill two columns beside it. A card
 * with no photo yet shows the design's placeholder frame.
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
</section>
