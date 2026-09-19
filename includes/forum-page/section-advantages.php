<?php
/**
 * "Advantages" — what you gain, and what to allow for.
 *
 * Two cards: the wins carry a tick, the things to allow for a warning mark.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "03".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'advantages_heading' );
$epi_lists   = array(
	'wins'  => array(
		'label' => EPI_Forum_Page_Renderer::value( $post_id, 'advantages_wins_label' ),
		'items' => EPI_Forum_Page_Renderer::rows( $post_id, 'advantages_wins' ),
		'icon'  => '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8.5"/><path d="M6.5 10.2l2.3 2.3 4.7-4.8"/></svg>',
	),
	'allow' => array(
		'label' => EPI_Forum_Page_Renderer::value( $post_id, 'advantages_allow_label' ),
		'items' => EPI_Forum_Page_Renderer::rows( $post_id, 'advantages_allow' ),
		'icon'  => '<svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8.5"/><path d="M10 5.8v5.2"/><circle cx="10" cy="14" r="0.6"/></svg>',
	),
);
?>
<section class="epi-forum-page__section epi-forum-page__advantages" id="advantages">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<div class="epi-forum-page__advantagegrid">
		<?php
		foreach ( $epi_lists as $epi_kind => $epi_list ) :
			if ( ! $epi_list['items'] ) {
				continue;
			}
			?>
			<div class="epi-forum-page__advantagelist epi-forum-page__advantagelist--<?php echo esc_attr( $epi_kind ); ?>">
				<h3 class="epi-forum-page__label"><?php echo esc_html( $epi_list['label'] ); ?></h3>
				<ul>
					<?php foreach ( $epi_list['items'] as $epi_item ) : ?>
						<li>
							<?php echo $epi_list['icon']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static markup declared above. ?>
							<span><?php echo esc_html( isset( $epi_item['item'] ) ? $epi_item['item'] : '' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
		endforeach;
		?>
	</div>
</section>
