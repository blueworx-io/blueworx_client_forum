const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The product change log and the meta viewer only cover the product's own data.
// SEO plugins write their scores onto the product too, and SureRank rewrites its
// page checks on every scan, which buried the real changes (ePim pushes) in
// noise. The harness has no WooCommerce, so a test-only mu-plugin asks the
// plugin's key check directly.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-meta-keys.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

test('SureRank fields are not treated as product data; product fields are', async ({ page }) => {
  await loginAsAdmin(page);

  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );

  const keys = [
    'surerank_seo_checks',
    'surerank_seo_checks_last_updated',
    '_surerank_settings',
    '_sku',
    '_regular_price',
    '_product_attributes',
  ];
  const query = keys.map((k) => `keys[]=${encodeURIComponent(k)}`).join('&');
  const res = await page.request.get(`/wp-json/epi-test/v1/meta-keys?${query}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), `meta-keys: ${res.status()}`).toBeTruthy();

  expect(await res.json()).toEqual({
    surerank_seo_checks: false,
    surerank_seo_checks_last_updated: false,
    _surerank_settings: false,
    _sku: true,
    _regular_price: true,
    _product_attributes: true,
  });
});
