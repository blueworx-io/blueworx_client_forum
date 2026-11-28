const { test, expect, request } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// Guests cannot buy on Forum, yet bots and search engines kept following
// add-to-cart links, each one rebuilding a whole shop page with the cache
// skipped. The feature stops that work being started for nothing.
//
// The harness has no WooCommerce, so a test-only mu-plugin stands in for the
// parts of it the feature touches and reports what the plugin does to them.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-trim-shop.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

// A visitor who is not logged in. Redirects are not followed, so a test sees
// the redirect itself.
async function guest(baseURL) {
  return request.newContext({ baseURL, maxRedirects: 0 });
}

test('a guest following an add-to-cart link is sent back to the page without it', async ({
  baseURL,
}) => {
  const ctx = await guest(baseURL);
  const res = await ctx.get('/?page_id=2&add-to-cart=123&quantity=2&keep=yes');
  expect(res.status()).toBe(302);
  const location = res.headers()['location'];
  expect(location).toContain('page_id=2');
  expect(location).toContain('keep=yes');
  expect(location).not.toContain('add-to-cart');
  expect(location).not.toContain('quantity');
  await ctx.dispose();
});

test('a guest posting an add-to-cart form is sent back to the page', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const res = await ctx.post('/?page_id=2', { form: { 'add-to-cart': '123', quantity: '1' } });
  expect(res.status()).toBe(303);
  expect(res.headers()['location']).not.toContain('add-to-cart');
  await ctx.dispose();
});

test('a logged-in user following an add-to-cart link is not turned away', async ({ page }) => {
  await loginAsAdmin(page);
  const res = await page.request.get('/?page_id=2&add-to-cart=123', { maxRedirects: 0 });
  expect(res.status()).not.toBe(302);
});

test('guests get no cart-check script, logged-in users do', async ({ page, baseURL }) => {
  const ctx = await guest(baseURL);
  const guestHtml = await (await ctx.get('/')).text();
  expect(guestHtml).not.toContain('cart-fragments');
  await ctx.dispose();

  await loginAsAdmin(page);
  const userHtml = await (await page.request.get('/')).text();
  expect(userHtml).toContain('cart-fragments');
});

test('a guest cart check is answered with nothing', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const res = await ctx.get('/wp-json/epi-test/v1/cart-check');
  expect(res.ok()).toBeTruthy();
  expect(await res.json()).toEqual({ fragments: {}, cart_hash: '' });
  await ctx.dispose();
});

test('guests get no mini-cart', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const body = await (await ctx.get('/wp-json/epi-test/v1/mini-cart-hidden')).json();
  expect(body.hidden).toBe(true);
  await ctx.dispose();
});

test('logged-in users get quick add-to-cart on shop pages, guests are left alone', async ({
  page,
  baseURL,
}) => {
  const ctx = await guest(baseURL);
  const guestBody = await (await ctx.get('/wp-json/epi-test/v1/quick-add')).json();
  expect(guestBody.value).toBe('no');
  await ctx.dispose();

  await loginAsAdmin(page);
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const res = await page.request.get('/wp-json/epi-test/v1/quick-add', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect((await res.json()).value).toBe('yes');
});

test('a REST request carrying add-to-cart is not redirected', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const res = await ctx.get('/wp-json/epi-test/v1/mini-cart-hidden?add-to-cart=123');
  expect(res.status()).toBe(200);
  expect((await res.json()).hidden).toBe(true);
  await ctx.dispose();
});

test('a guest cart check through admin-ajax is answered with nothing', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const res = await ctx.get('/wp-admin/admin-ajax.php?action=woocommerce_get_refreshed_fragments');
  expect(res.ok()).toBeTruthy();
  expect(await res.json()).toEqual({ fragments: {}, cart_hash: '' });
  await ctx.dispose();
});

test('guests get no Mini-Cart block', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const body = await (await ctx.get('/wp-json/epi-test/v1/mini-cart-block')).json();
  expect(body.html).toBe('');
  await ctx.dispose();
});

test('logged-in users still get the Mini-Cart block', async ({ page }) => {
  await loginAsAdmin(page);
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const res = await page.request.get('/wp-json/epi-test/v1/mini-cart-block', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect((await res.json()).html).toBeNull();
});

test('robots.txt keeps search engines off add-to-cart links', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const res = await ctx.get('/?robots=1');
  expect(res.ok()).toBeTruthy();
  expect(await res.text()).toContain('Disallow: /*add-to-cart=');
  await ctx.dispose();
});

test('a shop page never lists more than 48 products', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const get = async (route) =>
    (await (await ctx.get(`/wp-json/epi-test/v1/${route}`)).json()).per_page;

  expect(await get('per-page?value=100')).toBe(48);
  expect(await get('per-page?value=24')).toBe(24);
  expect(await get('listing-size?value=100')).toBe(48);
  expect(await get('listing-size?value=-1')).toBe(48);
  expect(await get('listing-size?value=12')).toBe(12);

  // A theme or plugin turning paging off, or setting the archive page size,
  // cannot lift the limit.
  const archive = async (query) =>
    (await (await ctx.get(`/wp-json/epi-test/v1/listing-size?${query}`)).json());
  const noPaging = await archive('value=12&nopaging=1');
  expect(noPaging.nopaging).toBe(false);
  expect((await archive('value=12&archive=500')).archive).toBe(48);
  expect((await archive('value=12&archive=-1')).archive).toBe(48);

  // WordPress turns -100 into 100 and lets "showposts" replace the page size,
  // both after the limit has been applied.
  expect(await get('listing-size?value=-100')).toBe(48);
  expect(await get('listing-size?value=-5')).toBe(5);
  expect((await archive('value=12&showposts=100')).showposts).toBe(48);
  expect((await archive('value=12&showposts=-1')).showposts).toBe(48);

  // A block that runs its own product query is held to the same limit.
  const block = async (query) =>
    (await (await ctx.get(`/wp-json/epi-test/v1/block-query-size?${query}`)).json()).per_page;
  expect(await block('type=product&value=100')).toBe(48);
  expect(await block('type=product&value=-1')).toBe(48);
  expect(await block('type=product&value=12')).toBe(12);
  expect(await block('type=post&value=100')).toBe(100);
  await ctx.dispose();
});

test('a capped block still shows every product, page after page', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const block = async (query) =>
    await (await ctx.get(`/wp-json/epi-test/v1/block-query-size?type=product&${query}`)).json();

  // The block worked out its page offset from the size it asked for (100), so
  // once the size is cut to 48 the offset has to be worked out again, or page 2
  // would skip products 49 to 100.
  expect(await block('value=100&page=1')).toEqual({ per_page: 48, offset: 0 });
  expect(await block('value=100&page=2')).toEqual({ per_page: 48, offset: 48 });
  expect(await block('value=100&page=3')).toEqual({ per_page: 48, offset: 96 });
  expect(await block('value=-1&page=2')).toEqual({ per_page: 48, offset: 48 });
  // A block's own "skip the first few" setting is kept.
  expect(await block('value=100&page=2&extra=3')).toEqual({ per_page: 48, offset: 51 });
  // A block already under the limit is left exactly as it was.
  expect(await block('value=12&page=3')).toEqual({ per_page: 12, offset: 24 });
  await ctx.dispose();
});

test('shortcode product grids are held to the same limit', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const grid = async (value) =>
    await (await ctx.get(`/wp-json/epi-test/v1/shortcode-query-size?value=${value}`)).json();

  expect((await grid(100)).per_page).toBe(48);
  expect((await grid(-1)).per_page).toBe(48);
  expect((await grid(12)).per_page).toBe(12);
  // The page being shown is not touched.
  expect((await grid(100)).paged).toBe(2);
  await ctx.dispose();
});

test('product attribute archives are held to the limit too', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const get = async (query) =>
    (await (await ctx.get(`/wp-json/epi-test/v1/listing-size?${query}`)).json()).per_page;

  expect(await get('taxonomy=pa_colour&value=100')).toBe(48);
  expect(await get('taxonomy=pa_colour&value=-1')).toBe(48);
  expect(await get('taxonomy=pa_colour&value=12')).toBe(12);
  await ctx.dispose();
});

test('page sizes already below the limit are left as they are', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const archive = async (query) =>
    await (await ctx.get(`/wp-json/epi-test/v1/listing-size?value=12&${query}`)).json();

  // Below the limit, including a negative size WordPress reads as its positive value.
  expect((await archive('archive=20')).archive).toBe(20);
  expect((await archive('archive=-5')).archive).toBe(-5);
  expect((await archive('showposts=20')).showposts).toBe(20);
  expect((await archive('showposts=-5')).showposts).toBe(-5);
  // Over the limit, or unlimited, still come down to it.
  expect((await archive('archive=-100')).archive).toBe(48);
  expect((await archive('showposts=-100')).showposts).toBe(48);
  expect((await archive('archive=49')).archive).toBe(48);
  await ctx.dispose();
});
