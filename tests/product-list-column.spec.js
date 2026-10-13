const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The products list is a fixed-layout table: WooCommerce sizes its own columns
// and the rest share what is left. The "Last updated" column had no width of
// its own, so once SEO and brand plugins added theirs it was squeezed to one
// letter wide and its date ran down the page.
//
// It needs WooCommerce's product list, so CI's harness without WooCommerce skips.

test('the Last updated column on the products list is wide enough to read', async ({ page }) => {
  await page.setViewportSize({ width: 1100, height: 900 });
  await loginAsAdmin(page);

  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text())
  );
  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: `Column width product ${Date.now()}`, regular_price: '10', status: 'publish' },
  });
  test.skip(!created.ok(), 'WooCommerce is not installed on this WordPress');

  await page.goto('/wp-admin/edit.php?post_type=product', { waitUntil: 'domcontentloaded' });

  const header = page.locator('th#epi_last_updated');
  await expect(header).toBeVisible();
  const box = await header.boundingBox();
  // "Last updated" on one line, and a date like "13 October 2026" beneath it.
  expect(box.width).toBeGreaterThanOrEqual(110);
});
