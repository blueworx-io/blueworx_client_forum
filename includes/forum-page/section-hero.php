<?php
/**
 * The hero at the top of a Forum Page.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  Unused here — the hero is not numbered.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'hero_heading' );
$epi_eyebrow = EPI_Forum_Page_Renderer::value( $post_id, 'hero_eyebrow' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'hero_intro' );
$epi_crumb   = EPI_Forum_Page_Renderer::value( $post_id, 'hero_breadcrumb' );
$epi_image   = (int) EPI_Forum_Page_Renderer::value( $post_id, 'hero_image', 0 );
$epi_chips   = array_filter(
	array(
		EPI_Forum_Page_Renderer::value( $post_id, 'hero_meta_category' ),
		EPI_Forum_Page_Renderer::value( $post_id, 'hero_meta_read' ),
		EPI_Forum_Page_Renderer::value( $post_id, 'hero_meta_updated' ),
	)
);
?>
<section class="epi-forum-page__hero">
	<?php if ( $epi_crumb ) : ?>
		<nav class="epi-forum-page__crumbs" aria-label="<?php esc_attr_e( 'Breadcrumb', 'blueworx_client_forum' ); ?>">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'blueworx_client_forum' ); ?></a>
			<span aria-hidden="true">&#9656;</span>
			<span><?php echo esc_html( $epi_crumb ); ?></span>
		</nav>
	<?php endif; ?>

	<?php if ( $epi_eyebrow ) : ?>
		<p class="epi-forum-page__eyebrow"><?php echo esc_html( $epi_eyebrow ); ?></p>
	<?php endif; ?>

	<h1><?php echo esc_html( $epi_heading ); ?></h1>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php
	foreach ( array( 1, 2 ) as $epi_n ) :
		$epi_label = EPI_Forum_Page_Renderer::value( $post_id, 'hero_cta' . $epi_n . '_label' );
		$epi_url   = EPI_Forum_Page_Renderer::value( $post_id, 'hero_cta' . $epi_n . '_url' );

		if ( ! $epi_label || ! $epi_url ) {
			continue;
		}
		?>
		<a class="epi-forum-page__button<?php echo 2 === $epi_n ? ' epi-forum-page__button--ghost' : ''; ?>" href="<?php echo esc_url( $epi_url ); ?>"><?php echo esc_html( $epi_label ); ?></a>
		<?php
	endforeach;
	?>

	<?php if ( $epi_chips ) : ?>
		<ul class="epi-forum-page__chips">
			<?php foreach ( $epi_chips as $epi_chip ) : ?>
				<li><?php echo esc_html( $epi_chip ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $epi_image ) : ?>
		<?php echo wp_get_attachment_image( $epi_image, 'large', false, array( 'class' => 'epi-forum-page__heroimage' ) ); ?>
	<?php endif; ?>
</section>
