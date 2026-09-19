<?php
/**
 * The closing call to action, with the stats bar under it.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  Unused here — the closing section is not numbered.
 */

defined( 'ABSPATH' ) || exit;

$epi_eyebrow = EPI_Forum_Page_Renderer::value( $post_id, 'cta_eyebrow' );
$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'cta_heading' );
$epi_body    = EPI_Forum_Page_Renderer::value( $post_id, 'cta_body' );
$epi_stats   = EPI_Forum_Page_Renderer::rows( $post_id, 'cta_stats' );
$epi_buttons = array();

foreach ( array( 1, 2 ) as $epi_n ) {
	$epi_label = EPI_Forum_Page_Renderer::value( $post_id, 'cta_cta' . $epi_n . '_label' );
	$epi_url   = EPI_Forum_Page_Renderer::value( $post_id, 'cta_cta' . $epi_n . '_url' );

	if ( $epi_label && $epi_url ) {
		$epi_buttons[ $epi_n ] = array( $epi_label, $epi_url );
	}
}
?>
<section class="epi-forum-page__section epi-forum-page__cta" id="cta">
	<?php if ( $epi_eyebrow ) : ?>
		<p class="epi-forum-page__eyebrow"><?php echo esc_html( $epi_eyebrow ); ?></p>
	<?php endif; ?>

	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_body ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_body ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_buttons ) : ?>
		<div class="epi-forum-page__buttons">
			<?php foreach ( $epi_buttons as $epi_n => $epi_button ) : ?>
				<a class="epi-forum-page__button<?php echo 2 === $epi_n ? ' epi-forum-page__button--ghost' : ''; ?>" href="<?php echo esc_url( $epi_button[1] ); ?>"><?php echo esc_html( $epi_button[0] ); ?></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $epi_stats ) : ?>
		<ul class="epi-forum-page__stats">
			<?php foreach ( $epi_stats as $epi_stat ) : ?>
				<li>
					<strong><?php echo esc_html( isset( $epi_stat['value'] ) ? $epi_stat['value'] : '' ); ?></strong>
					<span><?php echo esc_html( isset( $epi_stat['label'] ) ? $epi_stat['label'] : '' ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
