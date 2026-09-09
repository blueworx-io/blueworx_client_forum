<?php
/**
 * "What they are".
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "01".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading   = EPI_Forum_Page_Renderer::value( $post_id, 'what_heading' );
$epi_body      = EPI_Forum_Page_Renderer::value( $post_id, 'what_body' );
$epi_pullquote = EPI_Forum_Page_Renderer::value( $post_id, 'what_pullquote' );
$epi_parts     = EPI_Forum_Page_Renderer::rows( $post_id, 'what_parts' );
?>
<section class="epi-forum-page__section epi-forum-page__what" id="what">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_parts ) : ?>
		<ul class="epi-forum-page__parts">
			<?php foreach ( $epi_parts as $epi_part ) : ?>
				<li>
					<h3><?php echo esc_html( isset( $epi_part['title'] ) ? $epi_part['title'] : '' ); ?></h3>
					<p><?php echo esc_html( isset( $epi_part['desc'] ) ? $epi_part['desc'] : '' ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<div class="epi-forum-page__body"><?php echo wp_kses_post( $epi_body ); ?></div>

	<?php if ( $epi_pullquote ) : ?>
		<blockquote class="epi-forum-page__pullquote"><?php echo esc_html( $epi_pullquote ); ?></blockquote>
	<?php endif; ?>
</section>
