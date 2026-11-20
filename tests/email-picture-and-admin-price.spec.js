const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// Two fixes Forum asked for together.
//
// Order emails ask WooCommerce for each product's picture at a set small size.
// The plugin swaps in the ePim picture, and used to drop that size, so emails
// showed every picture full size and an order printed over many pages.
//
// Admin logins see the 60% trade price, as Steve and Mike expect. Shop managers
// keep the ECD special price.
//
// The harness has no WooCommerce, so a test-only mu-plugin gives the code a
// stand-in product and reports what it returns.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-woo-stand-in.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

async function getJson(page, route) {
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const res = await page.request.get(`/wp-json/epi-test/v1/${route}`, {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok()).toBeTruthy();
  return res.json();
}

test('an email-sized product picture keeps the small size it was asked for', async ({ page }) => {
  await loginAsAdmin(page);

  const { html } = await getJson(page, 'product-image?width=48&height=48');
  expect(html).toContain('777.jpg');
  expect(html).toMatch(/\swidth="48"/);
  expect(html).toMatch(/\sheight="48"/);
  expect(html).toContain('max-width:48px');
});

test('a named picture size on the shop is left to the theme', async ({ page }) => {
  await loginAsAdmin(page);

  const { html } = await getJson(page, 'product-image');
  expect(html).toContain('777.jpg');
  expect(html).not.toMatch(/\swidth="/);
});

test('admins see the 60% trade price, shop managers the ECD price', async ({ page }) => {
  await loginAsAdmin(page);

  expect((await getJson(page, 'role-price?role=administrator')).price).toBe(40);
  expect((await getJson(page, 'role-price?role=price_customer_1')).price).toBe(40);
  expect((await getJson(page, 'role-price?role=shop_manager')).price).toBe(77);
  expect((await getJson(page, 'role-price?role=price_customer_4')).price).toBe(77);
});
