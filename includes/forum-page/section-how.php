<?php
/**
 * "How they work" — the four-step sequence.
 *
 * Every step is in the markup. The script turns them into one-at-a-time; with
 * no script they stack, which reads fine and loses nothing.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'how_heading' );
$epi_intro   = EPI_Forum_Page_Renderer::value( $post_id, 'how_intro' );
$epi_steps   = EPI_Forum_Page_Renderer::rows( $post_id, 'how_steps' );
$epi_total   = count( $epi_steps );
?>
<section class="epi-forum-page__section epi-forum-page__how" id="how" data-epi-steps>
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_steps ) : ?>
		<ul class="epi-forum-page__steptabs" role="tablist">
			<?php foreach ( $epi_steps as $epi_i => $epi_step ) : ?>
				<li role="presentation">
					<button
						type="button"
						role="tab"
						id="how-tab-<?php echo esc_attr( $epi_i ); ?>"
						aria-controls="how-step-<?php echo esc_attr( $epi_i ); ?>"
						aria-selected="<?php echo 0 === $epi_i ? 'true' : 'false'; ?>"
					>
						<span class="epi-forum-page__stepnumber"><?php echo esc_html( sprintf( '%02d', $epi_i + 1 ) ); ?></span>
						<?php echo esc_html( isset( $epi_step['title'] ) ? $epi_step['title'] : '' ); ?>
					</button>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php foreach ( $epi_steps as $epi_i => $epi_step ) : ?>
			<div
				class="epi-forum-page__step"
				role="tabpanel"
				id="how-step-<?php echo esc_attr( $epi_i ); ?>"
				aria-labelledby="how-tab-<?php echo esc_attr( $epi_i ); ?>"
			>
				<p class="epi-forum-page__stepof">
					<?php
					printf(
						/* translators: 1: this step's number, 2: how many steps there are. */
						esc_html__( 'STEP %1$s OF %2$s', 'blueworx_client_forum' ),
						esc_html( sprintf( '%02d', $epi_i + 1 ) ),
						esc_html( sprintf( '%02d', $epi_total ) )
					);
					?>
				</p>
				<h3><?php echo esc_html( isset( $epi_step['title'] ) ? $epi_step['title'] : '' ); ?></h3>
				<p><?php echo esc_html( isset( $epi_step['body'] ) ? $epi_step['body'] : '' ); ?></p>
				<?php if ( ! empty( $epi_step['image'] ) ) : ?>
					<?php echo wp_get_attachment_image( (int) $epi_step['image'], 'large' ); ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
