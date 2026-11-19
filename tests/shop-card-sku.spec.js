const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// Shop and category cards show "SKU: <sku>" under the product name. ePim's own
// helper plugin used to print it; once that plugin was switched off the line
// vanished, so the plugin prints it now, in the same markup the site styled.
//
// It needs real WooCommerce products, so the spec makes them over REST. CI's
// harness has no WooCommerce, and there the spec skips rather than pretending.

async function restNonce(page) {
  return page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
}

test('shop cards show the SKU under the product name', async ({ page }) => {
  await loginAsAdmin(page);
  const nonce = await restNonce(page);
  const stamp = Date.now();
  const sku = `EPI-CARD-${stamp}`;

  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: `Card SKU product ${stamp}`, sku, regular_price: '25', status: 'publish' },
  });
  test.skip(!created.ok(), 'WooCommerce is not installed on this WordPress');

  const product = await created.json();
  await page.goto(`/?post_type=product&s=${encodeURIComponent(product.name)}`, {
    waitUntil: 'domcontentloaded',
  });

  const card = page.locator(`li.product.post-${product.id}`);
  await expect(card.locator('.product-sku')).toHaveText(`SKU: ${sku}`);
});

test('a product with no SKU shows no empty SKU line', async ({ page }) => {
  await loginAsAdmin(page);
  const nonce = await restNonce(page);
  const stamp = Date.now();

  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: `Card no SKU product ${stamp}`, regular_price: '25', status: 'publish' },
  });
  test.skip(!created.ok(), 'WooCommerce is not installed on this WordPress');

  const product = await created.json();
  await page.goto(`/?post_type=product&s=${encodeURIComponent(product.name)}`, {
    waitUntil: 'domcontentloaded',
  });

  const card = page.locator(`li.product.post-${product.id}`);
  await expect(card).toBeVisible();
  await expect(card.locator('.product-sku')).toHaveCount(0);
});
