<?php
/**
 * "FAQs" — two columns of questions, the first one open.
 *
 * Every answer is in the markup and shown; the script collapses all but the
 * first, so the page reads in full when the script does not.
 *
 * @package ExternalProductImages
 *
 * @var int    $post_id The record being rendered.
 * @var string $number  This section's number on the page, e.g. "08".
 */

defined( 'ABSPATH' ) || exit;

$epi_heading = EPI_Forum_Page_Renderer::value( $post_id, 'faqs_heading' );
$epi_items   = array_values( EPI_Forum_Page_Renderer::rows( $post_id, 'faqs_items' ) );
$epi_columns = $epi_items ? array_chunk( $epi_items, (int) ceil( count( $epi_items ) / 2 ), true ) : array();
?>
<section class="epi-forum-page__section epi-forum-page__faqs" id="faqs" data-epi-faqs>
	<p class="epi-forum-page__number"><?php echo esc_html( $number ); ?></p>
	<h2><?php echo esc_html( $epi_heading ); ?></h2>

	<?php if ( $epi_columns ) : ?>
		<div class="epi-forum-page__faqcolumns">
			<?php foreach ( $epi_columns as $epi_column ) : ?>
				<ul class="epi-forum-page__faqlist">
					<?php foreach ( $epi_column as $epi_i => $epi_item ) : ?>
						<li>
							<h3>
								<button type="button" aria-expanded="true" aria-controls="faq-answer-<?php echo esc_attr( $epi_i ); ?>">
									<span><?php echo esc_html( isset( $epi_item['question'] ) ? $epi_item['question'] : '' ); ?></span>
									<svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M2 5.5l6 6 6-6"/></svg>
								</button>
							</h3>
							<div class="epi-forum-page__answer" id="faq-answer-<?php echo esc_attr( $epi_i ); ?>">
								<p><?php echo esc_html( isset( $epi_item['answer'] ) ? $epi_item['answer'] : '' ); ?></p>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>
