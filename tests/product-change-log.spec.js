const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The product change log keeps each product's last two updates from ePim or
// from staff, field by field. The harness has no WooCommerce, so a test-only
// mu-plugin stands a "product" post type in for it, lets a request act as ePim
// (an API key, no browser session), and reports what the log recorded.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-change-log.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const PRODUCTS = '/wp-json/wp/v2/product';
const EPIM = { 'X-EPI-Test-Key': 'epim-test-key' };

let nonce;

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  await setLogging(page, true);
});

async function ok(res, what) {
  expect(res.ok(), `${what}: ${res.status()} ${await res.text()}`).toBeTruthy();
  return res.json();
}

// Staff: the logged-in browser session, which carries a nonce.
async function createProduct(page, title) {
  const product = await ok(
    await page.request.post(PRODUCTS, {
      headers: { 'X-WP-Nonce': nonce },
      data: { title, status: 'publish', meta: { _sku: 'START' } },
    }),
    'create product'
  );
  return product.id;
}

async function staffSave(page, id, meta) {
  return ok(
    await page.request.post(`${PRODUCTS}/${id}`, { headers: { 'X-WP-Nonce': nonce }, data: { meta } }),
    'staff save'
  );
}

async function staffWriteMeta(page, id, key, value) {
  return ok(
    await page.request.post('/wp-json/epi-test/v1/write-meta', {
      headers: { 'X-WP-Nonce': nonce },
      data: { id, key, value },
    }),
    'staff write'
  );
}

// ePim: an API key and no browser session. `request` shares no cookies with `page`.
async function epimSave(request, id, meta) {
  return ok(await request.post(`${PRODUCTS}/${id}`, { headers: EPIM, data: { meta } }), 'ePim save');
}

// Anyone else: no session and no key — a guest, a scheduled task, another plugin.
async function outsideWriteMeta(request, id, key, value) {
  return ok(
    await request.post('/wp-json/epi-test/v1/write-meta', { data: { id, key, value } }),
    'outside write'
  );
}

async function updates(page, id) {
  return ok(
    await page.request.get(`/wp-json/epi-test/v1/updates/${id}`, { headers: { 'X-WP-Nonce': nonce } }),
    'read updates'
  );
}

async function setLogging(page, on) {
  return ok(
    await page.request.post('/wp-json/epi-test/v1/logging', {
      headers: { 'X-WP-Nonce': nonce },
      data: { on },
    }),
    'set logging'
  );
}

test('a staff edit is recorded under their name, with before and after', async ({ page }) => {
  const id = await createProduct(page, 'Staff edit product');
  await staffSave(page, id, { _sku: 'STAFF-1' });

  const me = await ok(
    await page.request.get('/wp-json/wp/v2/users/me', { headers: { 'X-WP-Nonce': nonce } }),
    'current user'
  );
  const [latest] = await updates(page, id);

  expect(latest.source).toBe('staff');
  expect(latest.actor).toBe(me.name);
  expect(latest.gap).toBe(false);
  expect(latest.fields).toEqual([
    expect.objectContaining({
      field_type: 'meta',
      field_name: '_sku',
      label: 'SKU',
      before: 'START',
      after: 'STAFF-1',
    }),
  ]);
});

test('an ePim push is recorded as "ePim External API", never as the key owner', async ({
  page,
  request,
}) => {
  const id = await createProduct(page, 'ePim product');
  await epimSave(request, id, { _sku: 'Ø-90° EPIM' });

  const [latest] = await updates(page, id);

  expect(latest.source).toBe('epim');
  expect(latest.actor).toBe('ePim External API');
  expect(latest.fields).toEqual([
    expect.objectContaining({ field_name: '_sku', before: 'START', after: 'Ø-90° EPIM' }),
  ]);
});

test('other sources and SEO fields are not recorded, but the next "before" is still right', async ({
  page,
  request,
}) => {
  const id = await createProduct(page, 'Quiet product');

  await staffSave(page, id, { surerank_seo_checks_last_updated: '1790846768' });
  await staffSave(page, id, { _sku: 'START' }); // a save that changes nothing tracked
  await staffWriteMeta(page, id, '_regular_price', ''); // missing to empty is not a change
  await outsideWriteMeta(request, id, '_sku', 'OUTSIDE');

  expect(await updates(page, id)).toHaveLength(1); // only the creation

  await epimSave(request, id, { _sku: 'EPIM-2' });

  const list = await updates(page, id);
  expect(list).toHaveLength(2);
  expect(list[0].fields).toEqual([
    expect.objectContaining({ field_name: '_sku', before: 'OUTSIDE', after: 'EPIM-2' }),
  ]);
  expect(JSON.stringify(list)).not.toContain('surerank');
});

test('only the last two updates are kept, newest first', async ({ page }) => {
  const id = await createProduct(page, 'Busy product');
  await staffSave(page, id, { _sku: 'A' });
  await staffSave(page, id, { _sku: 'B' });

  const list = await updates(page, id);
  expect(list).toHaveLength(2);
  expect(list[0].fields).toEqual([expect.objectContaining({ before: 'A', after: 'B' })]);
  expect(list[1].fields).toEqual([expect.objectContaining({ before: 'START', after: 'A' })]);
});

test('structured fields are kept as data, not as stored text', async ({ page }) => {
  const id = await createProduct(page, 'Attributes product');
  const attributes = {
    colour: { name: 'Colour', value: 'White', position: 0, is_visible: 1, is_variation: 0, is_taxonomy: 0 },
  };
  await staffWriteMeta(page, id, '_product_attributes', attributes);

  const [latest] = await updates(page, id);
  expect(latest.fields).toEqual([
    expect.objectContaining({
      field_name: '_product_attributes',
      label: 'Attributes',
      before: null,
      after: attributes,
    }),
  ]);
});

test('an update after logging was switched off and on is flagged', async ({ page, request }) => {
  const id = await createProduct(page, 'Gap product');

  await setLogging(page, false);
  await staffSave(page, id, { _sku: 'WHILE-OFF' });
  await setLogging(page, true);
  await epimSave(request, id, { _sku: 'AFTER' });

  const [latest] = await updates(page, id);
  expect(latest.gap).toBe(true);
  expect(latest.fields).toEqual([expect.objectContaining({ before: 'START', after: 'AFTER' })]);
});

test('permanently deleting a product removes its log', async ({ page }) => {
  const id = await createProduct(page, 'Doomed product');
  expect(await updates(page, id)).toHaveLength(1);

  await ok(
    await page.request.delete(`${PRODUCTS}/${id}?force=true`, { headers: { 'X-WP-Nonce': nonce } }),
    'delete product'
  );

  expect(await updates(page, id)).toEqual([]);
});

test('the product screen shows the last updates and who made them', async ({ page, request }) => {
  const id = await createProduct(page, 'Screen product');
  await epimSave(request, id, { _sku: 'ON-SCREEN' });

  // The edit screen pulls a lot through the harness's single-threaded server;
  // the box's markup is what matters, not the load event.
  await page.goto(`/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });

  const box = page.locator('#epi-product-change-log');
  const groups = box.locator('tr.bw-table__group');

  await expect(groups).toHaveCount(2);
  await expect(groups.first()).toContainText('ePim External API');
  await expect(box).toContainText('SKU');
  await expect(box).toContainText('ON-SCREEN');
  await expect(box).not.toContainText('Fatal error');
});
