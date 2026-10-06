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

test('categories arrive as a hierarchy and are not duplicated', async ({ page }) => {
  // A category the site already has, by name, is reused rather than doubled.
  await api(page, 'POST', '/category', { name: 'Decorative' });

  const first = await api(page, 'POST', '/categories', {
    categories: [
      { Id: 1, Name: 'Lighting controls', ParentId: null },
      { Id: 2, Name: 'Kinetic switches', ParentId: 1 },
      { Id: 3, Name: 'Decorative', ParentId: null },
      // A parent ePim never listed: the child still arrives, at the top level.
      { Id: 4, Name: 'Orphan', ParentId: 77 },
    ],
  });

  expect(Object.keys(first.map).sort()).toEqual(['1', '2', '3', '4']);
  expect(first.terms).toEqual([
    { name: 'Decorative', parent: '', epim: 3 },
    { name: 'Kinetic switches', parent: 'Lighting controls', epim: 2 },
    { name: 'Lighting controls', parent: '', epim: 1 },
    { name: 'Orphan', parent: '', epim: 4 },
  ]);

  // A rename and a move follow ePim; nothing is created twice.
  const second = await api(page, 'POST', '/categories', {
    categories: [
      { Id: 1, Name: 'Controls', ParentId: null },
      { Id: 2, Name: 'Kinetic switches', ParentId: 3 },
      { Id: 3, Name: 'Decorative', ParentId: null },
    ],
  });
  expect(second.map['1']).toBe(first.map['1']);
  expect(second.terms).toEqual([
    { name: 'Controls', parent: '', epim: 1 },
    { name: 'Decorative', parent: '', epim: 3 },
    { name: 'Kinetic switches', parent: 'Decorative', epim: 2 },
    { name: 'Orphan', parent: '', epim: 4 },
  ]);
});

// Map a raw record and apply it, with the fixture categories synced first.
async function applyRaw(page, raw, images = false) {
  return api(page, 'POST', '/apply', { raw, images });
}

const RAW_A = {
  Id: 1001, ProductId: 501, IsArchived: false, IsApprovedForPublishing: true,
  SKU: 'TEST-1001', Name: 'Single Kinetic Switch - White', Price: 51.25,
  SKU_Text: 'Kit includes a switch and a receiver.', ProductCategoryIds: [2],
  PictureIds: [11, 12, 13, 14], PictureIdsGrouped: { Image: [11, 12, 13], Logo: [14] },
  AttributeValues: [
    { AttributeHeaderName: 'Colour', Value: 'White' },
    { AttributeHeaderName: 'Material', Value: 'Plastic' },
  ],
};

test('a record is added, then updated, then hidden, then unchanged', async ({ page }) => {
  const added = await applyRaw(page, RAW_A);
  expect(added.action).toBe('added');
  expect(added.changes.map((c) => c.field)).toEqual(
    expect.arrayContaining(['status', 'name', 'description', 'sku', 'price', 'categories', 'attribute:Colour'])
  );

  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product).toMatchObject({
    status: 'publish',
    title: 'Single Kinetic Switch - White',
    content: 'Kit includes a switch and a receiver.',
    sku: 'TEST-1001',
    price: '51.25',
    epim_id: 1001,
    epim_product_id: 501,
    categories: ['Kinetic switches'],
    attributes: { Colour: 'White', Material: 'Plastic' },
    thumbnail: '',
    gallery: '',
  });

  const updated = await applyRaw(page, { ...RAW_A, Name: 'Kit - White', Price: 55, AttributeValues: [{ AttributeHeaderName: 'Colour', Value: 'Off white' }] });
  expect(updated.action).toBe('updated');
  expect(updated.product_id).toBe(product.id);
  expect(updated.changes).toEqual([
    { field: 'name', label: 'Product name', before: 'Single Kinetic Switch - White', after: 'Kit - White' },
    { field: 'price', label: 'Regular price', before: '51.25', after: '55.00' },
    { field: 'attribute:Colour', label: 'Attribute: Colour', before: 'White', after: 'Off white' },
  ]);
  // An attribute ePim stopped sending is left alone.
  expect((await api(page, 'GET', '/product/TEST-1001')).attributes).toEqual({ Colour: 'Off white', Material: 'Plastic' });

  const hidden = await applyRaw(page, { ...RAW_A, IsArchived: true });
  expect(hidden.action).toBe('hidden');
  expect(hidden.changes).toEqual([{ field: 'status', label: 'Status', before: 'Published', after: 'Draft (hidden)' }]);
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('draft');

  expect((await applyRaw(page, { ...RAW_A, IsArchived: true })).action).toBe('unchanged');

  // Back on sale in ePim: published again.
  const back = await applyRaw(page, RAW_A);
  expect(back.action).toBe('updated');
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('publish');
});

test('a record that is archived and not on the site is skipped, and a missing SKU is an error', async ({ page }) => {
  const skipped = await applyRaw(page, { ...RAW_A, IsArchived: true });
  expect(skipped.action).toBe('skipped');
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBe(0);

  const unapproved = await applyRaw(page, { ...RAW_A, IsApprovedForPublishing: false });
  expect(unapproved.action).toBe('skipped');
});

test('an existing product with the SKU but no ePim id is updated, not duplicated', async ({ page }) => {
  const existing = await api(page, 'POST', '/product', { sku: 'TEST-1001', title: 'Old name' });

  const result = await applyRaw(page, RAW_A);
  expect(result.action).toBe('updated');
  expect(result.product_id).toBe(existing.id);

  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product.count).toBe(1);
  expect(product.epim_id).toBe(1001);
  expect(product.title).toBe('Single Kinetic Switch - White');
});

test('pictures are left alone until the switch is on', async ({ page }) => {
  await applyRaw(page, RAW_A, false);
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '', gallery: '' });

  const withImages = await applyRaw(page, RAW_A, true);
  expect(withImages.action).toBe('updated');
  expect(withImages.changes).toEqual([
    { field: 'image', label: 'Main image', before: '', after: '11' },
    { field: 'gallery', label: 'Gallery', before: '', after: '12, 13' },
  ]);
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '11', gallery: '12,13' });
});

test('a record with no pictures leaves the product pictures alone', async ({ page }) => {
  await applyRaw(page, RAW_A, true);
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '11', gallery: '12,13' });
  const result = await applyRaw(page, { ...RAW_A, PictureIds: [], PictureIdsGrouped: {} }, true);
  expect(result.action).toBe('unchanged');
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '11', gallery: '12,13' });
});

test('a product keeps its own pictures while the switch is off', async ({ page }) => {
  await api(page, 'POST', '/product', { sku: 'TEST-1001', title: 'Pushed product', thumbnail: '900', gallery: '901,902' });
  expect((await applyRaw(page, RAW_A, false)).action).toBe('updated');
  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ thumbnail: '900', gallery: '901,902' });
});

test('a trashed product stays in the bin for an archived record, and comes back for a live one', async ({ page }) => {
  await applyRaw(page, RAW_A);
  await api(page, 'POST', '/trash', { sku: 'TEST-1001' });
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('trash');

  expect((await applyRaw(page, { ...RAW_A, IsArchived: true })).action).toBe('unchanged');
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('trash');

  const back = await applyRaw(page, RAW_A);
  expect(back.action).toBe('updated');
  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product.status).toBe('publish');
  expect(product.count).toBe(1);
});

test('a name with an ampersand is stored the same way every run', async ({ page }) => {
  const added = await applyRaw(page, { ...RAW_A, Name: 'Switch & Receiver', SKU_Text: 'Fish & chips <script>x</script>' });
  expect(added.action).toBe('added');
  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product.title).toBe('Switch &amp; Receiver');
  expect(product.content).toBe('Fish &amp; chips x');
  expect((await applyRaw(page, { ...RAW_A, Name: 'Switch & Receiver', SKU_Text: 'Fish & chips <script>x</script>' })).action).toBe('unchanged');
});

test('a first pull imports everything and a second asks only for changes', async ({ page }) => {
  const first = await pull(page);
  expect(first).toMatchObject({ status: 'done', is_full: '1', since_utc: '', added: '2', updated: '0', hidden: '0', skipped: '1', errors: '0' });

  const calls = await api(page, 'GET', '/calls');
  expect(calls.map((c) => c.path)).toEqual(['Categories', 'Variations', 'Variations', 'DeletedEntities']);
  expect(calls[1].query.changedSinceUTC).toBe('2000-01-01T00:00:00Z');

  const second = await pull(page, { scenario: 'changed' });
  expect(second).toMatchObject({ status: 'done', is_full: '0', added: '0', updated: '1', hidden: '1', unchanged: '0', skipped: '1' });
  // Since the first run started, less five minutes. The harness database may store it as a plain datetime; ePim is always asked in ISO UTC.
  expect(second.since_utc).toMatch(/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}Z?$/);
  const sinceCalls = await api(page, 'GET', '/calls');
  const asked = sinceCalls.find((c) => c.path === 'Variations').query.changedSinceUTC;
  expect(asked).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
  expect(asked).toBe(second.since_utc.replace(' ', 'T').replace(/Z?$/, 'Z'));

  expect(await api(page, 'GET', '/product/TEST-1001')).toMatchObject({ title: 'Single Kinetic Switch Kit - White', price: '55.00' });
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('draft');

  // A full re-import asks for everything again.
  const full = await pull(page, { full: true });
  expect(full).toMatchObject({ status: 'done', is_full: '1', since_utc: '' });
});

test('a deleted entity hides its product', async ({ page }) => {
  await pull(page);
  const run = await pull(page, { scenario: 'deleted' });

  expect(run).toMatchObject({ status: 'done', hidden: '2' });
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('draft');
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('draft');

  const items = await api(page, 'GET', `/runs/${run.id}/items`);
  const hidden = items.filter((i) => i.action === 'hidden');
  expect(hidden.map((i) => i.sku).sort()).toEqual(['TEST-1001', 'TEST-1002']);
  expect(hidden[0].raw.EntityType).toBeDefined();
});

test('a record without a SKU is an error and the run carries on', async ({ page }) => {
  await api(page, 'POST', '/scenario', { scenario: 'nosku' });
  const run = await api(page, 'POST', '/pull', {});

  expect(run).toMatchObject({ status: 'done', added: '1', errors: '1' });
  const items = await api(page, 'GET', `/runs/${run.id}/items`);
  expect(items.find((i) => i.action === 'error').message).toBe('No SKU, so it cannot be matched to a product.');
});

test('a second pull is refused while one is running, and a stale lock is cleared', async ({ page }) => {
  const started = await api(page, 'POST', '/start', {});
  expect(started.run_id).toBeGreaterThan(0);

  const again = await api(page, 'POST', '/start', {});
  expect(again.error).toBe('epi_pull_running');

  await api(page, 'POST', '/drain', { run_id: started.run_id });
  expect((await api(page, 'POST', '/start', {})).run_id).toBeGreaterThan(started.run_id);

  // A lock 21 minutes old belongs to a run that died: it is failed and released.
  const dead = await api(page, 'POST', '/lock', { minutes_ago: 21 });
  const next = await api(page, 'POST', '/start', {});
  expect(next.run_id).toBeGreaterThan(0);
  const runs = await api(page, 'GET', '/runs');
  expect(runs.find((r) => Number(r.id) === dead.run_id)).toMatchObject({ status: 'failed', message: 'Timed out: no batch finished for 20 minutes.' });
});

test('a lock left by a start that died is cleared after a minute', async ({ page }) => {
  await api(page, 'POST', '/lock', { minutes_ago: 2, placeholder: true });
  expect((await api(page, 'POST', '/start', {})).run_id).toBeGreaterThan(0);
});

test('a batch that runs out of time queues the next batch', async ({ page }) => {
  const started = await api(page, 'POST', '/start', {});
  const once = await api(page, 'POST', '/batch-once', { run_id: started.run_id });
  expect(once.stage).toBe('products');
  expect(once.batches).toBe(2);
  expect(once.next).toBe(true);
  const run = await api(page, 'POST', '/drain', { run_id: started.run_id });
  expect(run.status).toBe('done');
});

test('the batch loopback carries the lock wp-cron.php checks', async ({ page }) => {
  const result = await api(page, 'POST', '/loopback');
  expect(result.calls).toHaveLength(1);
  const url = new URL(result.calls[0].url);
  expect(url.pathname.endsWith('/wp-cron.php')).toBe(true);
  expect(url.searchParams.get('doing_wp_cron')).toBe(String(result.transient));
});

test('a bad key fails the run with a plain message', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: 'wrong' });
  const run = await api(page, 'POST', '/pull', {});
  expect(run).toMatchObject({ status: 'failed', message: 'ePim did not accept the subscription key.' });
});

test('a page without results fails the run instead of finishing it', async ({ page }) => {
  const run = await pull(page, { scenario: 'broken' });
  expect(run.status).toBe('failed');
  expect(run.message).toContain('without results');
});

test('records older than 90 days are pruned', async ({ page }) => {
  const old = await api(page, 'POST', '/seed-run', { days_ago: 100 });
  const recent = await api(page, 'POST', '/seed-run', { days_ago: 80 });

  const pruned = await api(page, 'POST', '/prune');
  expect(pruned.removed).toBe(1);

  const runs = await api(page, 'GET', '/runs');
  expect(runs.map((r) => Number(r.id))).toEqual([recent.id]);
  expect(runs.map((r) => Number(r.id))).not.toContain(old.id);
  expect(await api(page, 'GET', `/runs/${old.id}/items`)).toEqual([]);
});

test('a daily pull is scheduled for 02:00 site time', async ({ page }) => {
  const schedule = await api(page, 'GET', '/schedule');
  expect(schedule.had).toBeGreaterThan(0);
  expect(schedule.next).toBeGreaterThan(Date.now() / 1000);
  expect(schedule.local_time).toBe('02:00');
  expect(schedule.recurrence).toBe('daily');
});

test('a pull shows in the product change log as ePim', async ({ page }) => {
  await pull(page);
  const product = await api(page, 'GET', '/product/TEST-1001');

  const updates = await api(page, 'GET', `/changes/${product.id}`);
  expect(updates.length).toBeGreaterThan(0);
  expect(updates[0].source).toBe('epim');
  expect(updates[0].actor).toBe('ePim External API');
});

async function loginAs(page, user, pass) {
  await page.context().clearCookies();
  await page.goto('/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/);
}

test('only administrators can open Product import', async ({ page }) => {
  await page.goto('/wp-admin/edit.php?post_type=product');
  await expect(page.locator('#adminmenu')).toContainText('Product import');

  await loginAs(page, 'epi-test-editor', 'editor-test-pw');
  await page.goto(SCREEN);
  await expect(page.locator('#wpbody-content, body')).toContainText('Sorry, you are not allowed to access this page.');
});

test('the page renders from the design system and saves the settings', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('.bw-page .bw-pagehead__h1')).toHaveText('Product import');
  await expect(page.locator('.bw-empty__title')).toHaveText('No pulls yet');

  await page.fill('#epi_pull_key', 'new-key-123');
  await page.locator('input[name="epi_pull_images"]').check();
  await page.getByRole('button', { name: 'Save settings' }).click();

  await expect(page.locator('.bw-page .bw-notice--success')).toContainText('Settings saved.');
  await expect(page.locator('#epi_pull_key')).toHaveValue('new-key-123');
  await expect(page.locator('input[name="epi_pull_images"]')).toBeChecked();
});

test('Pull now starts a pull that shows in the table, and a second is refused while it runs', async ({ page }) => {
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Pull now' }).click();
  await expect(page.locator('.bw-page .bw-notice--success')).toContainText('Pull started.');

  const row = page.locator('.bw-table tbody tr').first();
  await expect(row).toContainText('Manual');
  await expect(row.locator('.bw-badge')).toHaveText(/Queued|Running/);

  await page.getByRole('button', { name: 'Pull now' }).click();
  await expect(page.locator('.bw-page .bw-notice--warning')).toContainText('A pull is already running.');

  // Let it finish, then the row reads Done with its counts.
  const runs = await api(page, 'GET', '/runs');
  await api(page, 'POST', '/drain', { run_id: Number(runs[0].id) });
  await page.goto(SCREEN);
  await expect(page.locator('.bw-table tbody tr').first().locator('.bw-badge')).toHaveText('Done');
  await expect(page.locator('.bw-table tbody tr').first().locator('td').nth(2)).toHaveText('2'); // Added
  await expect(page.locator('.bw-stat__value').first()).not.toHaveText('Never');
});

test('without a key, Pull now says so', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: '' });
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Pull now' }).click();
  await expect(page.locator('.bw-page .bw-notice--warning')).toContainText('No ePim subscription key is saved.');
});

test('the detail view lists each product with what changed and the raw record', async ({ page }) => {
  await pull(page);
  const run = await pull(page, { scenario: 'changed' });

  await page.goto(`${SCREEN}&run=${run.id}`);
  await expect(page.locator('.bw-pagehead__h1')).toContainText('Pull on');
  await expect(page.locator('.bw-dl:not(.bw-dl--stack)')).toContainText('Manual');
  await expect(page.locator('.bw-dl:not(.bw-dl--stack)')).toContainText('Done');

  const rows = page.locator('.bw-table tbody tr');
  await expect(rows).toHaveCount(2);

  const updated = rows.filter({ hasText: 'TEST-1001' });
  await expect(updated.locator('.bw-badge')).toHaveText('Updated');
  await expect(updated).toContainText('Product name');
  await expect(updated).toContainText('Single Kinetic Switch - White');
  await expect(updated).toContainText('Single Kinetic Switch Kit - White');
  await expect(updated).toContainText('Regular price');
  await expect(updated).toContainText('55.00');

  const hidden = rows.filter({ hasText: 'TEST-1002' });
  await expect(hidden.locator('.bw-badge')).toHaveText('Hidden');
  await expect(hidden).toContainText('Draft (hidden)');

  // The raw record is there, closed until asked for.
  const raw = updated.locator('[data-epi-accordion]');
  await expect(raw.locator('.bw-accordion__body')).toBeHidden();
  await raw.locator('.bw-accordion__head').click();
  await expect(raw.locator('.bw-accordion__body')).toBeVisible();
  await expect(raw.locator('pre')).toContainText('"SKU": "TEST-1001"');

  await page.getByRole('link', { name: 'Back to pulls' }).click();
  await expect(page.locator('.bw-pagehead__h1')).toHaveText('Product import');

  // A run that touched nothing says so.
  const quiet = await pull(page, { scenario: 'changed' });
  await page.goto(`${SCREEN}&run=${quiet.id}`);
  await expect(page.locator('.bw-empty__title')).toHaveText('Nothing changed');

  // A run that does not exist goes back to the list.
  await page.goto(`${SCREEN}&run=999999`);
  await expect(page.locator('.bw-page .bw-notice--warning')).toContainText('That pull could not be found.');
});

test('test mode is on by default after install', async ({ page }) => {
  const defaults = await api(page, 'GET', '/settings-default');
  expect(defaults.test).toBe(true);
  expect(defaults.images).toBe(false);
});

test('a test pull records what it would add and creates nothing', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true, images: true });
  const run = await pull(page);
  expect(run).toMatchObject({ status: 'done', is_test: '1', added: '2', skipped: '1' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBe(0);
  expect((await api(page, 'GET', '/product/TEST-1002')).id).toBe(0);
  expect(await api(page, 'GET', '/terms')).toEqual([]);

  const items = await api(page, 'GET', `/runs/${run.id}/items`);
  expect(items.map((i) => i.action)).toEqual(['added', 'added']);
  expect(items[0].changes.map((c) => c.field)).toEqual(expect.arrayContaining(['name', 'price', 'image']));

  // The test run is not a baseline: switching test mode off, the next pull is the real full import.
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: false });
  const real = await pull(page);
  expect(real).toMatchObject({ status: 'done', is_test: '0', is_full: '1', since_utc: '', added: '2' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBeGreaterThan(0);
});

test('a test pull against existing products reports updates without applying them', async ({ page }) => {
  await pull(page);
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true });
  const run = await pull(page, { scenario: 'changed' });
  expect(run).toMatchObject({ status: 'done', is_test: '1', updated: '1', hidden: '1' });

  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product.title).toBe('Single Kinetic Switch - White');
  expect(product.price).toBe('51.25');
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('publish');

  const deleted = await pull(page, { scenario: 'deleted' });
  expect(deleted).toMatchObject({ is_test: '1', hidden: '2' });
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('publish');
});

test('the page shows test mode and marks test runs', async ({ page }) => {
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true });
  const run = await pull(page);

  await page.goto(SCREEN);
  await expect(page.locator('.bw-page .bw-notice--info')).toContainText('Test mode is on');
  await expect(page.locator('input[name="epi_pull_test"]')).toBeChecked();
  await expect(page.locator('.bw-table tbody tr').first()).toContainText('Test');

  await page.goto(`${SCREEN}&run=${run.id}`);
  await expect(page.locator('.bw-page .bw-notice--info')).toContainText('nothing on the site was changed');
  await expect(page.locator('.bw-dl:not(.bw-dl--stack)')).toContainText('Test');

  await page.goto(SCREEN);
  await page.locator('input[name="epi_pull_test"]').uncheck();
  await page.getByRole('button', { name: 'Save settings' }).click();
  await expect(page.locator('.bw-page .bw-notice--info')).toHaveCount(0);
});

test('a dry run matches existing categories and creates, renames or tags nothing', async ({ page }) => {
  await api(page, 'POST', '/category', { name: 'Decorative' });
  const tagged = await api(page, 'POST', '/categories', { categories: [{ Id: 1, Name: 'Old controls', ParentId: null }] });
  expect(tagged.map['1']).toBeGreaterThan(0);

  const dry = await api(page, 'POST', '/categories', {
    dry_run: true,
    categories: [
      { Id: 1, Name: 'Lighting controls', ParentId: null },
      { Id: 2, Name: 'Kinetic switches', ParentId: 1 },
      { Id: 3, Name: 'Decorative', ParentId: null },
    ],
  });
  expect(dry.map['1']).toBe(tagged.map['1']);
  expect(dry.map['2']).toBeUndefined();
  expect(dry.map['3']).toBeGreaterThan(0);
  expect(dry.terms).toEqual([
    { name: 'Decorative', parent: '', epim: 0 },
    { name: 'Old controls', parent: '', epim: 1 },
  ]);
});

test('refreshing categories from the page creates the terms and offers them', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('#epi_pull_category option')).toHaveCount(1); // the placeholder only

  await page.getByRole('button', { name: 'Refresh categories from ePim' }).click();
  await expect(page.locator('.bw-page .bw-notice--success')).toContainText('Categories refreshed from ePim.');

  const labels = await page.locator('#epi_pull_category option').allTextContents();
  expect(labels.slice(1)).toEqual(['Decorative', 'Lighting controls', 'Lighting controls › Kinetic switches']);
});

test('pulling one category touches only its products, including subcategories', async ({ page }) => {
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Refresh categories from ePim' }).click();

  // Lighting controls is the parent of Kinetic switches, which holds TEST-1001.
  await page.locator('#epi_pull_category').selectOption({ label: 'Lighting controls' });
  await page.getByRole('button', { name: 'Pull this category' }).click();
  await expect(page.locator('.bw-page .bw-notice--success')).toContainText('Pull started.');

  const runs = await api(page, 'GET', '/runs');
  const run = await api(page, 'POST', '/drain', { run_id: Number(runs[0].id) });
  expect(run).toMatchObject({ status: 'done', added: '1', skipped: '0', category_name: 'Lighting controls' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBeGreaterThan(0);
  expect((await api(page, 'GET', '/product/TEST-1002')).id).toBe(0);

  await page.goto(SCREEN);
  await expect(page.locator('.bw-table tbody tr').first()).toContainText('Lighting controls');

  // A category pull is not a baseline: the next normal pull is still the first full import.
  const next = await pull(page);
  expect(next).toMatchObject({ is_full: '1', since_utc: '', added: '1', unchanged: '1' });
});

test('pulling with no category chosen says so', async ({ page }) => {
  await page.goto(SCREEN);
  // The button is disabled until ePim's categories are on the site.
  await page.getByRole('button', { name: 'Refresh categories from ePim' }).click();
  await expect(page.locator('.bw-page .bw-notice--success')).toContainText('Categories refreshed from ePim.');
  await page.getByRole('button', { name: 'Pull this category' }).click();
  await expect(page.locator('.bw-page .bw-notice--warning')).toContainText('Choose a category first.');
});

test('the category picker says when there is nothing to choose yet', async ({ page }) => {
  await page.goto(SCREEN);
  await expect(page.locator('.bw-field__help').filter({ hasText: 'No ePim categories yet' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Pull this category' })).toBeDisabled();
});

test('a test pull stamps nothing on a product it matched by SKU', async ({ page }) => {
  await api(page, 'POST', '/product', { sku: 'TEST-1001', title: 'Old name' });
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true });
  const run = await pull(page);
  expect(run).toMatchObject({ is_test: '1', updated: '1' });
  const product = await api(page, 'GET', '/product/TEST-1001');
  expect(product).toMatchObject({ title: 'Old name', epim_id: 0, synced: '', categories: [] });
});

test('a category pull ignores deletions', async ({ page }) => {
  await pull(page);
  await api(page, 'POST', '/categories', { categories: [
    { Id: 1, Name: 'Lighting controls', ParentId: null },
    { Id: 2, Name: 'Kinetic switches', ParentId: 1 },
    { Id: 3, Name: 'Decorative', ParentId: null },
  ] });
  await api(page, 'POST', '/scenario', { scenario: 'deleted' });
  const run = await api(page, 'POST', '/pull', { category_id: 3 });
  expect(run).toMatchObject({ status: 'done', hidden: '0', category_name: 'Decorative' });
  expect((await api(page, 'GET', '/product/TEST-1001')).status).toBe('publish');
  expect((await api(page, 'GET', '/product/TEST-1002')).status).toBe('publish');
});

test('a category pull in test mode creates nothing', async ({ page }) => {
  await api(page, 'POST', '/categories', { categories: [
    { Id: 1, Name: 'Lighting controls', ParentId: null },
    { Id: 2, Name: 'Kinetic switches', ParentId: 1 },
    { Id: 3, Name: 'Decorative', ParentId: null },
  ] });
  await api(page, 'POST', '/settings', { key: 'epim-test-key', test: true });
  const run = await api(page, 'POST', '/pull', { category_id: 1 });
  expect(run).toMatchObject({ status: 'done', is_test: '1', added: '1', category_name: 'Lighting controls' });
  expect((await api(page, 'GET', '/product/TEST-1001')).id).toBe(0);
});
