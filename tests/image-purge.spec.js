const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// The purge tool's scan once ran as one long request, which a live host's
// gateway cut off part way. It now scans in short steps, each its own request,
// carrying on by itself until it is done.
const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-image-purge.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const SCREEN = '/wp-admin/tools.php?page=one-time-wc-product-image-purge';

let nonce;

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text())
  );
});

async function api(page, route, data) {
  const res = await page.request.post(`/wp-json/epi-test/v1${route}`, {
    headers: { 'X-WP-Nonce': nonce },
    data,
  });
  expect(res.ok(), `${route}: ${res.status()} ${await res.text()}`).toBeTruthy();
  return res.json();
}

function listed(page, listTitle, id) {
  return page.locator(
    `section.bw-card:has(.bw-card__title:text-is("${listTitle}")) .bw-scrolllist code:text-is("#${id}")`
  );
}

async function scan(page) {
  await page.goto(SCREEN);
  await page.getByRole('button', { name: 'Run dry scan' }).click();

  // It stops after a step and carries on by itself.
  await expect(page.getByText('Keep this tab open')).toBeVisible();
  await expect(page.locator('.bw-card__title', { hasText: 'What the scan found' })).toBeVisible({
    timeout: 120000,
  });
}

test('the scan runs in short steps and still protects images used elsewhere', async ({ page }) => {
  const ids = await api(page, '/purge-fixture');

  await scan(page);

  await expect(listed(page, 'Product-only images', ids.only)).toHaveCount(1);
  await expect(listed(page, 'Protected images', ids.shared)).toHaveCount(1);
  await expect(listed(page, 'Product-only images', ids.shared)).toHaveCount(0);

  // An ePim-switched product's picture numbers are ePim's, not media items,
  // even when one matches a media item's number.
  await expect(listed(page, 'Product-only images', ids.clash)).toHaveCount(0);
});

test('deleting removes product-only images and leaves ePim pictures alone', async ({ page }) => {
  const ids = await api(page, '/purge-fixture');

  await scan(page);

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Delete product-only images' }).click();
  await expect(page.getByText('Finished. Nothing is left to delete.')).toBeVisible({ timeout: 120000 });

  const state = await api(page, '/purge-state', ids);
  expect(state.exists).toEqual({ only: false, shared: true, clash: true });
  expect(state.media_thumbnail).toBe('');
  expect(state.switched_thumb).toBe(String(ids.clash));
});

test('deleting with no saved scan asks for a scan instead of running one', async ({ page }) => {
  await api(page, '/purge-fixture');
  await page.goto(SCREEN);

  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Delete product-only images' }).click();

  await expect(page.getByText('Run the dry scan first')).toBeVisible();
});
