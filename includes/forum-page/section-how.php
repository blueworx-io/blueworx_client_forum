<?php
/**
 * "How they work" — one section in four states.
 *
 * The steps render stacked; the script turns them into a stepper. A step with
 * no photo of its own shows the design's illustration for its position, so
 * the four shipped drawings appear without anyone uploading anything.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "02".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading       = EPI_Forum_Page_Renderer::value( $post_id, 'how_heading' );
$epi_intro         = EPI_Forum_Page_Renderer::value( $post_id, 'how_intro' );
$epi_steps         = EPI_Forum_Page_Renderer::rows( $post_id, 'how_steps' );
$epi_total         = count( $epi_steps );
$epi_illustrations = array( 'step-press.svg', 'step-generate.svg', 'step-transmit.svg', 'step-switch.svg' );
?>
<section class="epi-forum-page__section epi-forum-page__how" id="how" data-epi-steps>
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_intro ) : ?>
		<p class="epi-forum-page__intro"><?php echo esc_html( $epi_intro ); ?></p>
	<?php endif; ?>

	<?php if ( $epi_steps ) : ?>
		<div class="epi-forum-page__stepper">
			<ul class="epi-forum-page__steptabs" role="tablist" aria-orientation="vertical">
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

			<div class="epi-forum-page__steps">
				<?php foreach ( $epi_steps as $epi_i => $epi_step ) : ?>
					<div
						class="epi-forum-page__step"
						role="tabpanel"
						id="how-step-<?php echo esc_attr( $epi_i ); ?>"
						aria-labelledby="how-tab-<?php echo esc_attr( $epi_i ); ?>"
					>
						<div class="epi-forum-page__stepimage">
							<?php if ( ! empty( $epi_step['image'] ) ) : ?>
								<?php echo wp_get_attachment_image( (int) $epi_step['image'], 'large', false, array( 'class' => 'epi-forum-page__stepphoto' ) ); ?>
							<?php elseif ( isset( $epi_illustrations[ $epi_i ] ) ) : ?>
								<img src="<?php echo esc_url( EPI_PLUGIN_URL . 'assets/img/forum-page/' . $epi_illustrations[ $epi_i ] ); ?>" alt="">
							<?php endif; ?>
						</div>

						<div>
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

							<?php if ( $epi_total > 1 ) : ?>
								<button type="button" class="epi-forum-page__nextstep" data-epi-next hidden>
									<?php echo esc_html( $epi_i + 1 < $epi_total ? __( 'Next step', 'blueworx_client_forum' ) : __( 'Back to the start', 'blueworx_client_forum' ) ); ?>
									<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M5 2l6 6-6 6"/></svg>
								</button>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
</section>
