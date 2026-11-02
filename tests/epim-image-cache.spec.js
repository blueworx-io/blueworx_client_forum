const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin, LAB_SCREEN } = require('./helpers');

// "Keep ePim images in the browser": a service worker that keeps each ePim
// product image in the visitor's browser after the first download, because
// ePim's own answers tell browsers nothing they can cache on.
//
// The specs never touch the real ePim. A test-only mu-plugin (tests/support/)
// points the ePim image base at the harness itself under "localhost", which is
// a different origin from the 127.0.0.1 the site runs on — just as ePim is to
// the live site — and answers like ePim does, 500 page for a missing picture
// included. It is removed again afterwards, so other specs see the real base.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-epim-standin.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const MU_FILE = path.join(MU_DIR, path.basename(SUPPORT));
const VERSION = require('../package.json').version;

const FEATURE = 'epim-image-cache';
const WORKER = '/wp-admin/admin-ajax.php?action=epi_image_cache_sw';
const CACHE = 'epi-epim-images-v1';

// Flip one feature in BlueWorx Lab and leave every other switch as it is.
async function setFeature(page, on) {
  await loginAsAdmin(page);
  await page.goto(LAB_SCREEN);
  await page
    .locator(`#epi-lab-form input[name="epi_features[]"][value="${FEATURE}"]`)
    .evaluate((box, value) => {
      box.checked = value;
    }, on);
  await page.locator('.bw-savebar button[type="submit"]').click();
  await page.waitForURL(/updated=true/);
}

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, MU_FILE);
});

test.afterAll(async ({ browser }) => {
  const page = await browser.newPage();
  await setFeature(page, true);
  await page.close();
  fs.rmSync(MU_FILE, { force: true });
});

test('the worker address serves the caching worker with a site-wide scope header', async ({
  request,
}) => {
  const res = await request.get(WORKER);

  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('javascript');
  expect(res.headers()['service-worker-allowed']).toBe('/');
  expect(res.headers()['cache-control']).toContain('no-cache');

  const body = await res.text();
  expect(body).toContain(CACHE);
  expect(body).toContain('localhost');
  // The version changes the worker's bytes on each release, so browsers take the new one.
  expect(body).toContain(VERSION);
});

test('switched off, the worker address serves a worker that removes itself', async ({
  page,
  request,
}) => {
  await setFeature(page, false);

  const res = await request.get(WORKER);
  expect(res.status()).toBe(200);
  expect(res.headers()['service-worker-allowed']).toBe('/');

  const body = await res.text();
  expect(body).toContain('unregister');
  expect(body).not.toContain(CACHE);

  await setFeature(page, true);
});
