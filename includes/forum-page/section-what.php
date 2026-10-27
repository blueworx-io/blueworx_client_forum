<?php
/**
 * "What they are".
 *
 * Body copy and the pull quote on the left; on the right, the switch photo
 * with the parts labelled beside it. The photo is part of the design, not
 * the record, so it ships with the plugin. Its leader lines are drawn for
 * three parts, so they are left out when the record lists any other number.
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

	<div class="epi-forum-page__whatgrid">
		<div>
			<div class="epi-forum-page__body"><?php echo wp_kses_post( $epi_body ); ?></div>

			<?php if ( $epi_pullquote ) : ?>
				<blockquote class="epi-forum-page__pullquote"><?php echo esc_html( $epi_pullquote ); ?></blockquote>
			<?php endif; ?>
		</div>

		<?php if ( $epi_parts ) : ?>
			<div class="epi-forum-page__diagram<?php echo 3 === count( $epi_parts ) ? ' epi-forum-page__diagram--leaders' : ''; ?>">
				<img class="epi-forum-page__switchphoto" src="<?php echo esc_url( EPI_Forum_Page_Renderer::asset_url( 'assets/img/forum-page/what-switch.png' ) ); ?>" alt="" width="300" height="300">
				<?php if ( 3 === count( $epi_parts ) ) : ?>
					<span class="epi-forum-page__leaders" aria-hidden="true">
						<img class="epi-forum-page__leaderlines" src="<?php echo esc_url( EPI_Forum_Page_Renderer::asset_url( 'assets/img/forum-page/what-leaders.svg' ) ); ?>" alt="" width="113.422" height="149.575">
						<img class="epi-forum-page__leaderdots" src="<?php echo esc_url( EPI_Forum_Page_Renderer::asset_url( 'assets/img/forum-page/what-dots.svg' ) ); ?>" alt="" width="34.1" height="66.65">
					</span>
				<?php endif; ?>
				<ul class="epi-forum-page__parts">
					<?php foreach ( $epi_parts as $epi_part ) : ?>
						<li>
							<h3><?php echo esc_html( isset( $epi_part['title'] ) ? $epi_part['title'] : '' ); ?></h3>
							<p><?php echo esc_html( isset( $epi_part['desc'] ) ? $epi_part['desc'] : '' ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
</section>
