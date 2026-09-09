<?php
/**
 * The on-this-page bar.
 *
 * The links are built from the sections this record actually shows, so a
 * switched-off section cannot leave a link pointing at nothing.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  Unused here — the bar is not numbered.
 */

defined( 'ABSPATH' ) || exit;

$epi_label = EPI_Forum_Page_Renderer::value( $post_id, 'sectionbar_label', 'ON THIS PAGE' );
$epi_phone = EPI_Forum_Page_Renderer::value( $post_id, 'sectionbar_phone' );
$epi_links = array();

foreach ( EPI_Forum_Page_Renderer::visible_sections( $post_id ) as $epi_panel ) {
	$epi_text = EPI_Forum_Page_Renderer::value( $post_id, $epi_panel . '_nav_label' );

	if ( $epi_text ) {
		$epi_links[ $epi_panel ] = $epi_text;
	}
}

if ( ! $epi_links ) {
	return;
}
?>
<nav class="epi-forum-page__bar" aria-label="<?php echo esc_attr( $epi_label ); ?>">
	<p class="epi-forum-page__barlabel"><?php echo esc_html( $epi_label ); ?></p>
	<ul>
		<?php foreach ( $epi_links as $epi_panel => $epi_text ) : ?>
			<li><a href="#<?php echo esc_attr( $epi_panel ); ?>"><?php echo esc_html( $epi_text ); ?></a></li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $epi_phone ) : ?>
		<a class="epi-forum-page__phone" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $epi_phone ) ); ?>"><?php echo esc_html( $epi_phone ); ?></a>
	<?php endif; ?>
</nav>
