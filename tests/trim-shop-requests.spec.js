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

test('page sizes already under the limit are kept, however they were set', async ({ baseURL }) => {
  const ctx = await guest(baseURL);
  const size = async (query) =>
    (await (await ctx.get(`/wp-json/epi-test/v1/listing-size?value=12&${query}`)).json()).per_page;

  // WordPress reads -5 as "five" for these two as well; only -1 means "all".
  expect(await size('showposts=-5')).toBe(5);
  expect(await size('archive=-5')).toBe(5);
  expect(await size('showposts=-100')).toBe(48);
  expect(await size('archive=-100')).toBe(48);
  await ctx.dispose();
});

test('colour and other attribute listings are held to the limit, other listings are not', async ({
  baseURL,
}) => {
  const ctx = await guest(baseURL);
  const size = async (query) =>
    (await (await ctx.get(`/wp-json/epi-test/v1/listing-size?${query}`)).json()).per_page;

  expect(await size('value=100&tax=pa_capcolour&tax_for=product')).toBe(48);
  expect(await size('value=12&tax=pa_capcolour&tax_for=product')).toBe(12);
  expect(await size('value=100&tax=capgenre&tax_for=post')).toBe(100);
  await ctx.dispose();
});

test.describe('with more products than fit on one page', () => {
  const COUNT = 110;
  const ALL = Array.from({ length: COUNT }, (_, i) => `Cap ${String(i + 1).padStart(3, '0')}`);

  const stock = async (baseURL, count) => {
    const ctx = await guest(baseURL);
    const res = await ctx.post('/wp-json/epi-test/v1/cap-products', { form: { count } });
    expect(res.ok()).toBeTruthy();
    await ctx.dispose();
  };

  test.beforeAll(async ({ baseURL }) => stock(baseURL, COUNT));
  test.afterAll(async ({ baseURL }) => stock(baseURL, 0));

  // The products one block lists on one page, and the page numbers it offers.
  const blockPage = async (ctx, query, page) => {
    const res = await ctx.get(`/wp-json/epi-test/v1/cap-block?${query}&query-7-page=${page}`);
    expect(res.ok()).toBeTruthy();
    const { html } = await res.json();
    return {
      titles: html.match(/Cap \d{3}/g) || [],
      pages: [...html.matchAll(/class="page-numbers[^"]*"[^>]*>(\d+)</g)].map((m) => Number(m[1])),
      next: html.includes('wp-block-query-pagination-next'),
      html,
    };
  };

  // Every product a shopper reaches by following "next" from page one.
  const walkBlock = async (ctx, query) => {
    const seen = [];
    for (let page = 1; page < 10; page++) {
      const result = await blockPage(ctx, query, page);
      expect(result.titles.length).toBeLessThanOrEqual(48);
      seen.push(...result.titles);
      if (!result.next) break;
    }
    return seen;
  };

  test('a paged product block set to 100 still reaches every product, once each', async ({
    baseURL,
  }) => {
    const ctx = await guest(baseURL);
    const first = await blockPage(ctx, 'size=100', 1);
    expect(first.titles).toEqual(ALL.slice(0, 48));
    expect(first.pages).toEqual([1, 2, 3]);
    expect((await blockPage(ctx, 'size=100', 2)).titles).toEqual(ALL.slice(48, 96));
    expect((await blockPage(ctx, 'size=100', 3)).titles).toEqual(ALL.slice(96));
    expect(await walkBlock(ctx, 'size=100')).toEqual(ALL);

    // Page links inside another block are still the list's own.
    expect(await walkBlock(ctx, 'size=100&layout=grouped')).toEqual(ALL);
    await ctx.dispose();
  });

  test('a product block that does not start at its first product is left as it was set', async ({
    baseURL,
  }) => {
    const ctx = await guest(baseURL);

    // Skipped in the block's own settings, as it was before the limit.
    const first = await blockPage(ctx, 'size=100&offset=3', 1);
    expect(first.titles).toEqual(ALL.slice(3, 103));
    expect((await blockPage(ctx, 'size=100&offset=3', 2)).titles).toEqual(ALL.slice(103));

    // Skipped by other code, with the size from the settings or raised.
    for (const query of ['size=100&shift=20', 'size=12&raise=100&shift=20']) {
      expect((await blockPage(ctx, query, 1)).titles).toEqual(ALL.slice(20));
    }

    // Pages of uneven size.
    const uneven = 'size=100&short_first=1';
    expect((await blockPage(ctx, uneven, 1)).titles).toEqual(ALL.slice(0, 99));
    expect((await blockPage(ctx, uneven, 2)).titles).toEqual(ALL.slice(99));
    await ctx.dispose();
  });

  test('a filtered product block is paged by what it shows, not by the whole shop', async ({
    baseURL,
  }) => {
    const ctx = await guest(baseURL);
    expect((await blockPage(ctx, 'size=100&matches=60', 1)).pages).toEqual([1, 2]);
    expect(await walkBlock(ctx, 'size=100&matches=60')).toEqual(ALL.slice(0, 60));

    // Ten matches fit on one page, so no page links are offered.
    const few = await blockPage(ctx, 'size=100&matches=10', 1);
    expect(few.titles).toEqual(ALL.slice(0, 10));
    expect(few.pages).toEqual([]);
    await ctx.dispose();
  });

  test('a product block whose other products could not be reached is left as it was set', async ({
    baseURL,
  }) => {
    const ctx = await guest(baseURL);

    // No page links of its own, and none are added.
    for (const layout of ['none', 'nested']) {
      const result = await blockPage(ctx, `size=100&layout=${layout}`, 1);
      expect(result.titles.slice(0, 100)).toEqual(ALL.slice(0, 100));
      expect(result.pages).toEqual([]);
    }

    // Held to a number of pages.
    const held = await blockPage(ctx, 'size=100&pages=1', 1);
    expect(held.titles).toEqual(ALL.slice(0, 100));
    expect(held.next).toBe(false);

    // In random order, which may be shuffled again for each page.
    expect((await blockPage(ctx, 'size=100&orderby=rand', 1)).titles.length).toBe(100);

    // The same goes when other code raised the size.
    expect((await blockPage(ctx, 'size=12&raise=100&layout=none', 1)).titles).toEqual(
      ALL.slice(0, 100)
    );
    expect((await blockPage(ctx, 'size=12&raise=100&pages=1', 1)).titles).toEqual(
      ALL.slice(0, 100)
    );
    await ctx.dispose();
  });

  test('a paged product block whose size was raised by other code still reaches every product', async ({
    baseURL,
  }) => {
    const ctx = await guest(baseURL);
    expect(await walkBlock(ctx, 'size=12&raise=100')).toEqual(ALL);
    expect(await walkBlock(ctx, 'size=12&raise=-1')).toEqual(ALL);
    await ctx.dispose();
  });

  test('a product block under the limit is paged as it was', async ({ baseURL }) => {
    const ctx = await guest(baseURL);
    expect((await blockPage(ctx, 'size=40', 2)).titles).toEqual(ALL.slice(40, 80));
    expect((await blockPage(ctx, 'size=40&pages=2', 2)).next).toBe(false);
    await ctx.dispose();
  });

  const shortcode = async (ctx, query) => {
    const res = await ctx.get(`/wp-json/epi-test/v1/cap-shortcode?${query}`);
    expect(res.ok()).toBeTruthy();
    return res.json();
  };

  test('a [products] grid with page numbers is held to the limit and reaches every product', async ({
    baseURL,
  }) => {
    const ctx = await guest(baseURL);
    for (const query of ['limit=100&paginate=true', 'limit=-1&paginate=yes', 'limit=-100&paginate=1']) {
      const seen = [];
      for (let page = 1; page <= 3; page++) {
        const result = await shortcode(ctx, `${query}&product-page=${page}`);
        expect(result.total_pages).toBe(3);
        expect(result.titles.length).toBeLessThanOrEqual(48);
        seen.push(...result.titles);
      }
      expect(seen).toEqual(ALL);
    }
    await ctx.dispose();
  });

  test('a [products] grid without page numbers is left as it was set', async ({ baseURL }) => {
    const ctx = await guest(baseURL);

    // WooCommerce does not read "on" as a yes, so it prints no page numbers.
    for (const paginate of ['', 'false', 'on']) {
      const all = await shortcode(ctx, `limit=-1&paginate=${paginate}`);
      expect(all.titles).toEqual(ALL);
      expect(all.has_links).toBe(false);
      const hundred = await shortcode(ctx, `limit=100&paginate=${paginate}`);
      expect(hundred.titles).toEqual(ALL.slice(0, 100));
    }

    // Random order is shuffled again for each page, so some could be missed.
    const random = await shortcode(ctx, 'limit=100&paginate=true&orderby=rand');
    expect(random.titles.length).toBe(100);

    // A theme took WooCommerce's page numbers away.
    const bare = await shortcode(ctx, 'limit=100&paginate=true&no_links=1');
    expect(bare.titles).toEqual(ALL.slice(0, 100));

    // A fixed start point, zero included, makes every page show the same ones.
    for (const offset of [0, 5]) {
      const fixed = await shortcode(ctx, `limit=100&paginate=true&offset=${offset}`);
      expect(fixed.titles).toEqual(ALL.slice(offset, offset + 100));
    }

    expect((await shortcode(ctx, 'limit=12')).titles).toEqual(ALL.slice(0, 12));
    const paged = await shortcode(ctx, 'limit=40&paginate=true&product-page=2');
    expect(paged.titles).toEqual(ALL.slice(40, 80));
    await ctx.dispose();
  });
});
