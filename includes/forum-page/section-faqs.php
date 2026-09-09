<?php
/**
 * "FAQs".
 *
 * Every answer is in the markup and open. The script collapses them into an
 * accordion; without it the page is a plain question-and-answer list.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page.
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'faqs_heading' );
$epi_items   = EPI_Forum_Page_Renderer::rows( $post_id, 'faqs_items' );
?>
<section class="epi-forum-page__section epi-forum-page__faqs" id="faqs" data-epi-faqs>
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_items ) : ?>
		<ul class="epi-forum-page__faqlist">
			<?php foreach ( $epi_items as $epi_i => $epi_item ) : ?>
				<li>
					<h3>
						<button type="button" aria-expanded="true" aria-controls="faq-answer-<?php echo esc_attr( $epi_i ); ?>">
							<?php echo esc_html( isset( $epi_item['question'] ) ? $epi_item['question'] : '' ); ?>
						</button>
					</h3>
					<div class="epi-forum-page__answer" id="faq-answer-<?php echo esc_attr( $epi_i ); ?>">
						<p><?php echo esc_html( isset( $epi_item['answer'] ) ? $epi_item['answer'] : '' ); ?></p>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
