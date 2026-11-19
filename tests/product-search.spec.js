const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// Forum's customers search by product code, and most product names don't
// contain the code, so a code search came back empty even for products on
// the shop. The site search now also matches the SKU, ignoring spaces, since
// some SKUs arrive from ePim with a stray space in them ("VSC-45577- SBRS").
//
// The harness has no WooCommerce, so a test-only mu-plugin gives it a bare
// product post type and a way to make a product with a SKU.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-sku-search.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

async function makeProduct(page, title, sku, password = '') {
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const res = await page.request.post('/wp-json/epi-test/v1/sku-product', {
    headers: { 'X-WP-Nonce': nonce },
    data: { title, sku, password },
  });
  expect(res.ok()).toBeTruthy();
}

async function searchFor(page, term) {
  await page.goto(`/?s=${encodeURIComponent(term)}`, { waitUntil: 'domcontentloaded' });
  return page.locator('body');
}

test('site search finds a product by its SKU, ignoring stray spaces', async ({ page }) => {
  await loginAsAdmin(page);

  const stamp = Date.now();
  const code = `TST-${stamp}`;
  const plain = `Kinetic grid switch ${stamp}`;
  const spaced = `Hugo diner pendant ${stamp}`;

  await makeProduct(page, plain, code);
  await makeProduct(page, spaced, `${code}- SBRS`);

  // The code alone finds both: the name holds no code at all.
  let body = await searchFor(page, code);
  await expect(body).toContainText(plain);
  await expect(body).toContainText(spaced);

  // Typed as it should read, the full code still finds the one with a space in it,
  // and only that one.
  body = await searchFor(page, `${code}-SBRS`);
  await expect(body).toContainText(spaced);
  await expect(body).not.toContainText(plain);

  // Searching by name still works as before.
  body = await searchFor(page, plain);
  await expect(body).toContainText(plain);

  // A code nothing has finds nothing.
  body = await searchFor(page, `NOPE-${stamp}`);
  await expect(body).not.toContainText(plain);
  await expect(body).not.toContainText(spaced);
});

test('a code search does not show password-protected products to visitors', async ({ page, browser }) => {
  await loginAsAdmin(page);

  const stamp = Date.now();
  const code = `PWD-${stamp}`;
  const name = `Private switch ${stamp}`;
  await makeProduct(page, name, code, 'secret');

  // WordPress hides password-protected posts from logged-out searches; a SKU
  // match must not get round that.
  const visitor = await browser.newContext();
  const guest = await visitor.newPage();
  await guest.goto(`/?s=${encodeURIComponent(code)}`, { waitUntil: 'domcontentloaded' });
  await expect(guest.locator('body')).not.toContainText(name);
  await visitor.close();
});

// The card markup each listing really uses on the live site: WooCommerce's loop
// on shop and category pages, and the theme's (UiCore) post cards on search.
const CARDS = {
  'shop and category': '<h2 class="woocommerce-loop-product__title">%s</h2>',
  search: '<h4 class="uicore-post-title"><span>%s</span></h4>',
};

for (const [where, markup] of Object.entries(CARDS)) {
  test(`long product names wrap inside their card on ${where} pages`, async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' });

    // A card narrow like the real grid, holding a name with a long run of
    // letters and no spaces, as ePim sends some.
    const overflow = await page.evaluate((html) => {
      const card = document.createElement('div');
      card.style.width = '180px';
      card.innerHTML = html.replace('%s', '6Pin 12mm StriptoWiretoStripConnector/15cm 22 AWG wire');
      document.body.appendChild(card);
      const title = card.firstElementChild;
      return title.scrollWidth - title.clientWidth;
    }, markup);

    expect(overflow).toBeLessThanOrEqual(0);
  });
}
