<?php
/**
 * "Comparison" — kinetic against the alternatives.
 *
 * A real table, because it is one: row headers down the side, column headers
 * across the top, so a screen reader announces each cell with both.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'comparison_heading' );
$epi_cols    = array(
	EPI_Forum_Page_Renderer::value( $post_id, 'comparison_col1' ),
	EPI_Forum_Page_Renderer::value( $post_id, 'comparison_col2' ),
	EPI_Forum_Page_Renderer::value( $post_id, 'comparison_col3' ),
);
$epi_rows    = EPI_Forum_Page_Renderer::rows( $post_id, 'comparison_rows' );
?>
<section class="epi-forum-page__section epi-forum-page__comparison" id="comparison">
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_rows ) : ?>
		<div class="epi-forum-page__tablewrap">
			<table>
				<thead>
					<tr>
						<td></td>
						<?php foreach ( $epi_cols as $epi_col ) : ?>
							<th scope="col"><?php echo esc_html( $epi_col ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $epi_rows as $epi_row ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( isset( $epi_row['label'] ) ? $epi_row['label'] : '' ); ?></th>
							<?php foreach ( array( 'col1', 'col2', 'col3' ) as $epi_cell ) : ?>
								<td><?php echo esc_html( isset( $epi_row[ $epi_cell ] ) ? $epi_row[ $epi_cell ] : '' ); ?></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
