const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The [product_bullets] shortcode lists a product's "Bullet N" attributes. The
// text has to come out exactly as it was typed on the product: the shop writes
// things like "IP44 Rating" and "LED", and a tidy-up that lowercases them turns
// those into "Ip44 rating" and "Led" on the live page.
//
// It needs a real WooCommerce product, so the spec makes one over REST. CI's
// harness has no WooCommerce, and there the spec skips rather than pretending.

test('bullets keep the case they were typed in', async ({ page }) => {
  await loginAsAdmin(page);

  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );

  const bullet = 'IP44 Rating suitable for outdoor use';
  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      name: `Bullet case product ${Date.now()}`,
      regular_price: '25',
      status: 'publish',
      // The shortcode reads the product's own attributes; putting it in the
      // description is the simplest place it renders on the product page.
      description: '[product_bullets]',
      attributes: [{ name: 'Bullet 1', options: [bullet], visible: true }],
    },
  });
  test.skip(!created.ok(), 'WooCommerce is not installed on this WordPress');

  const product = await created.json();
  // A product page pulls a lot of WooCommerce assets through the harness's
  // single-threaded server; the markup is what matters here, not the load event.
  await page.goto(product.permalink, { waitUntil: 'domcontentloaded' });

  await expect(page.locator('.product-bullets li')).toHaveText([bullet]);
});
