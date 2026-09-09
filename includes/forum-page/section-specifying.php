<?php
/**
 * "Specifying" — the numbered points, and what to do when something is wrong.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'specifying_heading' );
$epi_points  = EPI_Forum_Page_Renderer::rows( $post_id, 'specifying_points' );
$epi_tlabel  = EPI_Forum_Page_Renderer::value( $post_id, 'specifying_trouble_label' );
$epi_trouble = EPI_Forum_Page_Renderer::rows( $post_id, 'specifying_trouble' );
?>
<section class="epi-forum-page__section epi-forum-page__specifying" id="specifying">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_points ) : ?>
		<ol class="epi-forum-page__points">
			<?php foreach ( $epi_points as $epi_point ) : ?>
				<li>
					<h3><?php echo esc_html( isset( $epi_point['title'] ) ? $epi_point['title'] : '' ); ?></h3>
					<p><?php echo esc_html( isset( $epi_point['body'] ) ? $epi_point['body'] : '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<?php if ( $epi_trouble ) : ?>
		<div class="epi-forum-page__trouble">
			<h3><?php echo esc_html( $epi_tlabel ); ?></h3>
			<?php foreach ( $epi_trouble as $epi_item ) : ?>
				<div class="epi-forum-page__troubleitem">
					<h4><?php echo esc_html( isset( $epi_item['title'] ) ? $epi_item['title'] : '' ); ?></h4>
					<p><?php echo esc_html( isset( $epi_item['body'] ) ? $epi_item['body'] : '' ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
