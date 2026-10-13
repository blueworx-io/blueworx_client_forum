const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The site's product rules were written when ePim's details arrived as shared
// attributes (pa_epim-27546, pa_ecd-special-prices). They now arrive as plain
// attributes named after the detail, as the push and the pull both write them,
// and the rules have to find them that way too.
//
// Needs a real WooCommerce product, so each spec makes one over REST. CI's
// harness has no WooCommerce, and there they skip rather than pretending.

async function makeProduct(page, attributes) {
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text())
  );

  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      name: `Attribute rules product ${Date.now()}`,
      regular_price: '24.11',
      status: 'publish',
      attributes: Object.entries(attributes).map(([name, value]) => ({ name, options: [value], visible: true })),
    },
  });
  test.skip(!created.ok(), 'WooCommerce is not installed on this WordPress');

  return created.json();
}

test('pack, carton and waste details are hidden from the product page', async ({ page }) => {
  await loginAsAdmin(page);
  const product = await makeProduct(page, {
    Colour: 'White',
    'Product Weight (g)': '100',
    'Product Pack Depth (cm)': '2.5',
    'Product Packed Weight (kg)': '0.125',
    'Outer Pack Gross Weight (kg)': '5',
    'Waste Packaging Weight - Card (kg)': '0.024',
  });

  await page.goto(product.permalink, { waitUntil: 'domcontentloaded' });

  const table = page.locator('.woocommerce-product-attributes');
  await expect(table).toContainText('Colour');
  for (const hidden of ['Product Weight (g)', 'Pack Depth', 'Packed Weight', 'Outer Pack', 'Waste Packaging']) {
    await expect(table).not.toContainText(hidden);
  }
});

test('the ECD special price is charged from the product\'s ECD detail', async ({ page }) => {
  // Administrators are priced as customer 4: the ECD price, else 40% of list.
  await loginAsAdmin(page);
  const product = await makeProduct(page, { 'ECD special prices': '8.10' });

  await page.goto(product.permalink, { waitUntil: 'domcontentloaded' });

  // 40% of 24.11 would be 9.64.
  await expect(page.locator('.price').filter({ hasText: 'ex. VAT' }).first()).toContainText('£8.10');
});
