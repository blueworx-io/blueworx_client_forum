<?php
/**
 * "Advantages" — what you gain, and what to allow for.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'advantages_heading' );
$epi_lists   = array(
	array(
		'label' => EPI_Forum_Page_Renderer::value( $post_id, 'advantages_wins_label' ),
		'items' => EPI_Forum_Page_Renderer::rows( $post_id, 'advantages_wins' ),
	),
	array(
		'label' => EPI_Forum_Page_Renderer::value( $post_id, 'advantages_allow_label' ),
		'items' => EPI_Forum_Page_Renderer::rows( $post_id, 'advantages_allow' ),
	),
);
?>
<section class="epi-forum-page__section epi-forum-page__advantages" id="advantages">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<div class="epi-forum-page__advantagegrid">
		<?php
		foreach ( $epi_lists as $epi_list ) :
			if ( ! $epi_list['items'] ) {
				continue;
			}
			?>
			<div class="epi-forum-page__advantagelist">
				<h3><?php echo esc_html( $epi_list['label'] ); ?></h3>
				<ul>
					<?php foreach ( $epi_list['items'] as $epi_item ) : ?>
						<li><?php echo esc_html( isset( $epi_item['item'] ) ? $epi_item['item'] : '' ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		endforeach;
		?>
	</div>
</section>
