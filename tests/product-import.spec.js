const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The pull talks to ePim over HTTP and writes WooCommerce products. The harness
// has neither ePim nor WooCommerce, and PHP's built-in server cannot answer a
// request to itself, so a test-only mu-plugin fakes ePim at the HTTP transport
// and stands a plain post type in for products.
const SUPPORT = [
  path.resolve(__dirname, 'support', 'epi-test-epim.php'),
  // Boots the product change log without WooCommerce, for the change log test.
  path.resolve(__dirname, 'support', 'epi-test-change-log.php'),
];
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const SCREEN = '/wp-admin/edit.php?post_type=product&page=epi-product-import';
const API = '/wp-json/epi-test/v1/pull';

let nonce;

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  for (const file of SUPPORT) fs.copyFileSync(file, path.join(MU_DIR, path.basename(file)));
});

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text())
  );
  await api(page, 'POST', '/reset');
});

async function api(page, method, route, data) {
  const res = await page.request.fetch(`${API}${route}`, {
    method,
    headers: { 'X-WP-Nonce': nonce },
    data,
  });
  expect(res.ok(), `${method} ${route}: ${res.status()} ${await res.text()}`).toBeTruthy();
  return res.json();
}

// Start a pull and run it to completion without waiting on cron.
async function pull(page, options = {}) {
  if (options.scenario) await api(page, 'POST', '/scenario', { scenario: options.scenario });
  return api(page, 'POST', '/pull', { full: !!options.full });
}

test('the pull feature is on by default and its tables exist', async ({ page }) => {
  await page.goto('/wp-admin/options-general.php?page=bwlab');
  const toggle = page.locator('input[name="epi_features[]"][value="epim-pull"]');
  await expect(toggle).toBeChecked();

  const tables = await api(page, 'GET', '/tables');
  expect(tables).toEqual({ runs: true, items: true });
});

test('the client pages through variations and reports a bad key', async ({ page }) => {
  // The fake serves two records a page (filter epi_pull_page_size), and the
  // initial scenario has three variations.
  const first = await api(page, 'POST', '/fetch', { what: 'variations', start: 0 });
  expect(first.total).toBe(3);
  expect(first.results.map((r) => r.SKU)).toEqual(['TEST-1001', 'TEST-1002']);

  const second = await api(page, 'POST', '/fetch', { what: 'variations', start: 2 });
  expect(second.results.map((r) => r.SKU)).toEqual(['TEST-1003']);

  const calls = await api(page, 'GET', '/calls');
  expect(calls[0].path).toBe('Variations');
  expect(calls[0].query).toMatchObject({ start: '0', limit: '2', showArchived: 'true', showUnApproved: 'true' });

  const categories = await api(page, 'POST', '/fetch', { what: 'categories' });
  expect(categories.map((c) => c.Name)).toEqual(['Lighting controls', 'Kinetic switches', 'Decorative']);

  const bad = await api(page, 'POST', '/fetch', { what: 'categories', key: 'wrong' });
  expect(bad.error).toBe('ePim did not accept the subscription key.');
});

test('an ePim record maps to the product fields', async ({ page }) => {
  const mapped = await api(page, 'POST', '/map', {
    raw: {
      Id: 7,
      ProductId: 70,
      IsArchived: false,
      IsApprovedForPublishing: false,
      SKU: ' CUL-1 ',
      Name: 'Lamp ',
      SKU_Text: 'Long copy.',
      Price: 51.2,
      ProductCategoryIds: [3, 0, 5],
      PictureIds: [1, 2, 3, 2],
      PictureIdsGrouped: { Logo: [3] },
      AttributeValues: [
        { AttributeHeaderName: 'Colour', Value: 'White' },
        { AttributeHeaderName: 'Bulb Type', Value: '' },
        { AttributeHeaderName: '', Value: 'x' },
      ],
    },
  });

  expect(mapped).toEqual({
    epim_id: 7,
    epim_product_id: 70,
    sku: 'CUL-1',
    name: 'Lamp',
    description: 'Long copy.',
    price: '51.20',
    hidden: true,
    category_ids: [3, 5],
    image_ids: [1, 2],
    attributes: { Colour: 'White' },
  });

  // With an Image group, that group is the whole picture list, in its order.
  const grouped = await api(page, 'POST', '/map', {
    raw: { Id: 8, SKU: 'X', PictureIds: [9, 8, 7], PictureIdsGrouped: { Image: [8, 7], Logo: [9] } },
  });
  expect(grouped.image_ids).toEqual([8, 7]);
  expect(grouped.hidden).toBe(false);
  expect(grouped.price).toBe('');
});
