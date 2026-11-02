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
const GALLERY_META = path.resolve(__dirname, 'support', 'epi-test-gallery-meta.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');
const MU_FILE = path.join(MU_DIR, path.basename(SUPPORT));
const SERVER_LOG = path.resolve(__dirname, '..', '.wp-test', 'php-server.log');
const VERSION = require('../package.json').version;

// A picture number the stand-in answers with a 500 page, as ePim does for a missing one.
const MISSING = '999999';

// The smallest valid PNG, for the featured image upload.
const PIXEL = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=',
  'base64'
);

const FEATURE = 'epim-image-cache';
const WORKER = '/wp-admin/admin-ajax.php?action=epi_image_cache_sw';
const CACHE = 'epi-epim-images-v1';

// Flip one feature in BlueWorx Lab and leave every other switch as it is.
async function setFeature(page, on) {
  await page.goto(LAB_SCREEN);
  if (page.url().includes('wp-login.php')) {
    await loginAsAdmin(page);
    await page.goto(LAB_SCREEN);
  }
  await page
    .locator(`#epi-lab-form input[name="epi_features[]"][value="${FEATURE}"]`)
    .evaluate((box, value) => {
      box.checked = value;
    }, on);
  await page.locator('.bw-savebar button[type="submit"]').click();
  await page.waitForURL(/updated=true/);
}

// A page standing in for a product, showing its pictures as ePim images: the
// featured image first, then the gallery ids given.
async function epimPage(page, galleryIds) {
  await loginAsAdmin(page);
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const stamp = Date.now();

  const upload = await page.request.post('/wp-json/wp/v2/media', {
    headers: { 'X-WP-Nonce': nonce },
    multipart: { file: { name: `epim-${stamp}.png`, mimeType: 'image/png', buffer: PIXEL } },
  });
  expect(upload.ok(), `upload: ${upload.status()}`).toBeTruthy();
  const featured = await upload.json();

  const created = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      title: `ePim cache host ${stamp}`,
      content: '[forum_product_gallery source="epim"]',
      status: 'publish',
      featured_media: featured.id,
      meta: { _product_image_gallery: galleryIds.join(',') },
    },
  });
  expect(created.ok(), `page: ${created.status()}`).toBeTruthy();
  const host = await created.json();

  return { url: `/?page_id=${host.id}`, featuredId: String(featured.id) };
}

// A visitor's first view: wait until the worker is in charge of the page.
async function firstVisit(page, url) {
  await page.goto(url);
  await page.evaluate(() => navigator.serviceWorker.ready);
  await expect.poll(() => page.evaluate(() => !!navigator.serviceWorker.controller)).toBe(true);
}

function cachedUrls(page) {
  return page.evaluate((name) =>
    caches.open(name).then((cache) => cache.keys()).then((keys) => keys.map((k) => k.url)),
  CACHE);
}

// How many times the stand-in ePim has been asked for a picture.
function standinHits(id) {
  const log = fs.existsSync(SERVER_LOG) ? fs.readFileSync(SERVER_LOG, 'utf8') : '';
  return log.split(`GET /?epi_test_image=${id}.jpg`).length - 1;
}

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, MU_FILE);
  fs.copyFileSync(GALLERY_META, path.join(MU_DIR, path.basename(GALLERY_META)));
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

test('after the first visit, ePim images come from the browser, not ePim', async ({
  page,
  browser,
}) => {
  const { url, featuredId } = await epimPage(page, ['424242']);
  const imageUrl = `${new URL(page.url()).protocol}//localhost:${new URL(page.url()).port}/?epi_test_image=${featuredId}.jpg`;

  const visitor = await (await browser.newContext()).newPage();
  await firstVisit(visitor, url);

  // The worker is now in charge; this load saves the image.
  await visitor.reload();
  await expect.poll(() => cachedUrls(visitor)).toContain(imageUrl);

  // Every later load takes it from the browser: ePim is not asked again.
  const before = standinHits(featuredId);
  await visitor.reload();
  await visitor.waitForLoadState('load');
  await visitor.reload();
  await visitor.waitForLoadState('load');
  expect(standinHits(featuredId)).toBe(before);

  await visitor.context().close();
});

test('page loads and the site\'s own files are left to the network', async ({ page, browser }) => {
  const { url } = await epimPage(page, ['424243']);

  const visitor = await (await browser.newContext()).newPage();
  await firstVisit(visitor, url);

  const responses = [];
  visitor.on('response', (res) => responses.push(res));
  await visitor.reload();
  await visitor.waitForLoadState('load');

  const documentLoad = responses.find((r) => r.request().resourceType() === 'document');
  const ownFile = responses.find((r) => r.url().includes('/wp-content/plugins/'));
  expect(documentLoad, 'no page load seen').toBeTruthy();
  expect(ownFile, 'no plugin file seen').toBeTruthy();
  expect(documentLoad.fromServiceWorker()).toBe(false);
  expect(ownFile.fromServiceWorker()).toBe(false);

  await visitor.context().close();
});

test('an ePim image that fails to load is not kept', async ({ page, browser }) => {
  const { url } = await epimPage(page, [MISSING]);
  const base = new URL(page.url());
  const brokenUrl = `${base.protocol}//localhost:${base.port}/?epi_test_image=${MISSING}.jpg`;

  const visitor = await (await browser.newContext()).newPage();
  await firstVisit(visitor, url);
  await visitor.reload();
  await visitor.waitForLoadState('load');

  // The thumbnail has given up and shows the placeholder...
  await expect(
    visitor.locator('.epi-gallery__thumb img').nth(1)
  ).toHaveAttribute('src', /epi-edison-bulb\.svg/);
  // ...and the browser holds no copy of the broken answer.
  await expect.poll(() => cachedUrls(visitor)).not.toContain(brokenUrl);
  // The image that worked is still kept.
  await expect.poll(async () => (await cachedUrls(visitor)).length).toBeGreaterThan(1);

  await visitor.context().close();
});

test('the page warms up its connection to ePim', async ({ page, browser }) => {
  const { url } = await epimPage(page, ['424244']);

  const visitor = await (await browser.newContext()).newPage();
  await visitor.goto(url);
  await expect(visitor.locator('link[rel="preconnect"][href*="localhost"]')).toHaveCount(1);

  await visitor.context().close();
});

test('switched off, pages no longer register the worker', async ({ page, browser }) => {
  const { url } = await epimPage(page, ['424245']);
  await setFeature(page, false);

  const visitor = await (await browser.newContext()).newPage();
  await visitor.goto(url);
  await visitor.waitForLoadState('load');

  await expect(visitor.locator('script[src*="epi-image-cache.js"]')).toHaveCount(0);
  expect(await visitor.evaluate(() => navigator.serviceWorker.getRegistrations().then((r) => r.length))).toBe(0);

  await visitor.context().close();
  await setFeature(page, true);
});
