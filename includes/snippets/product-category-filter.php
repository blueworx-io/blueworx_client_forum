<?php
/**
 * Frontend: Generate Product Page Filters
 *
 * Generate a short code to render Categories & Atrributes Filters
 *
 * Moved verbatim from the Code Snippets plugin.
 *
 * @package ExternalProductImages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// === Product Filter: Categories + Lamp Type + Fitting Class + IP Rating + Colour ===
add_shortcode('product_category_filter', function () {
	$shop_url = get_permalink(wc_get_page_id('shop'));

	// Current selections
	$get_array = function ($key) {
		return !empty($_GET[$key]) ? array_filter(array_map('sanitize_title', explode(',', sanitize_text_field($_GET[$key])))) : [];
	};

	$selected = [
		'categories'            => $get_array('categories'),
		'filter_bulb-type'      => $get_array('filter_bulb-type'),
		'filter_fittings-class' => $get_array('filter_fittings-class'),
		'filter_ip-rating'      => $get_array('filter_ip-rating'),
		'filter_colour'         => $get_array('filter_colour'),
	];

	$minPrice = isset($_GET['min_price']) ? (int) $_GET['min_price'] : '';
	$maxPrice = isset($_GET['max_price']) ? (int) $_GET['max_price'] : '';

	// Terms (only parent categories)
	$taxonomies = [
		[
			'name'     => 'categories',
			'label'    => 'Categories',
			'taxonomy' => 'product_cat',
			'args'     => ['parent' => 0],
		],
		[
			'name'     => 'filter_bulb-type',
			'label'    => 'Lamp Type', // Renamed from Bulb Type
			'taxonomy' => 'pa_bulb-type',
		],
		[
			'name'     => 'filter_fittings-class',
			'label'    => 'Fitting Class',
			'taxonomy' => 'pa_fittings-class',
		],
		[
			'name'     => 'filter_ip-rating',
			'label'    => 'IP Rating',
			'taxonomy' => 'pa_ip-rating',
		],
		[
			'name'     => 'filter_colour',
			'label'    => 'Colour',
			'taxonomy' => 'pa_colour',
		],
	];

	ob_start(); ?>
	<div id="product-filter" data-shop-url="<?php echo esc_url($shop_url); ?>">

	<?php foreach ($taxonomies as $tax):
		$terms = get_terms(array_merge(['taxonomy' => $tax['taxonomy'], 'hide_empty' => true], $tax['args'] ?? []));
		if (empty($terms) || is_wp_error($terms)) continue;
		$name = $tax['name'];
		$label = $tax['label'];
		$selected_terms = $selected[$name] ?? [];
		?>
		<div class="filter-section">
			<button class="filter-toggle" type="button"><?php echo esc_html($label); ?></button>
			<div class="filter-content">
				<ul>
					<?php foreach ($terms as $term): ?>
						<li>
							<label>
								<input type="checkbox" name="<?php echo esc_attr($name); ?>[]" value="<?php echo esc_attr($term->slug); ?>"
								<?php checked(in_array($term->slug, $selected_terms, true)); ?> >
								<?php echo esc_html($term->name); ?>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
	<?php endforeach; ?>

	<?php /* ?>
	<!-- Price -->
	<div class="filter-section">
		<button class="filter-toggle" type="button">Price Range</button>
		<div class="filter-content">
			<div class="price-inputs">
				<label>Min: <input type="number" name="min_price" value="<?php echo esc_attr($minPrice); ?>" min="0" step="1"></label>
				<label>Max: <input type="number" name="max_price" value="<?php echo esc_attr($maxPrice); ?>" min="0" step="1"></label>
			</div>
		</div>
	</div>
	<?php */ ?>

	<div class="filter-actions" style="display:flex; gap:.5rem; flex-direction: column; padding-top: 15px;">
		<div id="primary-custom-button" class="elementor-button-wrapper">
			<a href="#" id="apply-filters" class="elementor-button elementor-size-sm elementor-animation-flip">
				<span class="elementor-button-content-wrapper">
					<span class="ui-btn-anim-wrapp">
						<span class="elementor-button-text">APPLY FILTERS</span>
						<span class="elementor-button-text">APPLY FILTERS</span>
					</span>
				</span>
			</a>
		</div>

		<div id="secondary-custom-button" class="elementor-button-wrapper">
			<a href="#" id="clear-filters" class="elementor-button elementor-size-sm elementor-animation-flip">
				<span class="elementor-button-content-wrapper">
					<span class="ui-btn-anim-wrapp">
						<span class="elementor-button-text">CLEAR FILTERS</span>
						<span class="elementor-button-text">CLEAR FILTERS</span>
					</span>
				</span>
			</a>
		</div>
	</div>
	</div>

	<script>
	(function(){
		console.groupCollapsed('ð§© Product Filter Accordion Init (Safe Bind)');
		const root = document.getElementById('product-filter');
		if(!root) { console.warn('No #product-filter element found.'); console.groupEnd(); return; }

		if (root.dataset.initialized === "true") {
			console.warn('Accordion already initialized â skipping rebind.');
			console.groupEnd();
			return;
		}
		root.dataset.initialized = "true";

		const shopUrl = root.getAttribute('data-shop-url') || (window.location.origin + '/shop/');
		const qsel    = s => Array.from(root.querySelectorAll(s));
		const getVals = n => qsel(`[name="${n}[]"]:checked`).map(i => i.value);
		const getVal  = n => { const el = root.querySelector(`[name="${n}"]`); return el ? el.value.trim() : ''; };

		function apply(){
			const url = new URL(shopUrl, window.location.origin);
			const filters = [
				'categories',
				'filter_bulb-type',
				'filter_fittings-class',
				'filter_ip-rating',
				'filter_colour'
			];

			filters.forEach(f => {
				const vals = getVals(f);
				if (vals.length) {
					url.searchParams.set(f, vals.join(','));
					if (f !== 'categories') {
						url.searchParams.set(`query_type_${f.replace('filter_', '')}`, 'or');
					}
				}
			});

			// const min = getVal('min_price');
			// const max = getVal('max_price');
			// if (min) url.searchParams.set('min_price', min);
			// if (max) url.searchParams.set('max_price', max);

			window.location.href = url.toString();
		}

		function clearAll(){ window.location.href = shopUrl; }

		root.querySelector('#apply-filters')?.addEventListener('click', apply);
		root.querySelector('#clear-filters')?.addEventListener('click', clearAll);

		const toggles = root.querySelectorAll('.filter-toggle');
		console.log(`Found ${toggles.length} toggles. Binding events once.`);

		toggles.forEach((btn, i) => {
			const content = btn.nextElementSibling;
			btn.addEventListener('click', e => {
				console.groupCollapsed(`â¶ï¸ Toggle ${i+1}: ${btn.textContent.trim()}`);
				e.stopImmediatePropagation();
				const isOpen = btn.classList.toggle('open');
				const h = content.scrollHeight;
				console.log('isOpen:', isOpen, '| scrollHeight:', h);

				if (isOpen) {
					content.style.transition = 'max-height 0.3s ease';
					content.style.maxHeight = h + 'px';
					console.log('â Opening to', h + 'px');
				} else {
					content.style.transition = 'max-height 0.3s ease';
					content.style.maxHeight = h + 'px';
					requestAnimationFrame(() => {
						content.style.maxHeight = '0px';
						console.log('â Closing');
					});
				}

				content.addEventListener('transitionend', () => {
					if (btn.classList.contains('open')) {
						content.style.maxHeight = 'none';
						console.log('â Fully open â maxHeight none');
					} else {
						console.log('â Fully closed â 0px');
					}
					console.groupEnd();
				}, { once: true });
			});
		});
		console.groupEnd();
	})();
	</script>

	<style>
	#product-filter { font-family: inherit; max-width: 400px; }
	.filter-section {
		border: 1px solid #ddd;
		border-radius: 4px;
		margin-bottom: .75rem;
		overflow: hidden;
	}
	.filter-toggle {
		width: 100%;
		text-align: left;
		background: #f8f8f8;
		border: none;
		padding: .6rem .8rem;
		font-weight: 600;
		cursor: pointer;
		transition: background .2s;
	}
	.filter-toggle.open { background: #e9e9e9; }
	.filter-content {
		max-height: 0;
		overflow: hidden;
		transition: max-height .25s ease;
		padding: 0 .8rem;
	}
	.filter-toggle.open + .filter-content { padding: .8rem; }
	.filter-content ul { list-style: none; padding-left: 1rem; margin: 0; }
	.filter-content label { display: flex; align-items: center; gap: .3rem; cursor: pointer; width: 100%; }
	.price-inputs { display: flex; gap: .5rem; }
	.price-inputs input { width: 100%; }
	</style>

	<?php
	return ob_get_clean();
});
