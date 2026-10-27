const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const {
  loginAsAdmin,
  saveEditor,
  panelSwitch,
  repeater,
  addRepeaterRow,
} = require('./helpers');

// The front end reads a record the test makes itself. Nothing here assumes
// content already on the site.

async function seedForumPage(page, { title, heading }) {
  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', title);
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = Number(new URL(page.url()).searchParams.get('post'));

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.fill('#hero_heading', heading);
  await saveEditor(page, expect);

  return id;
}

// Making a record and opening its editor, without filling anything in.
async function newForumPage(page, title) {
  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', title);
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = Number(new URL(page.url()).searchParams.get('post'));
  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  return id;
}

test('the page renders its hero heading', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await seedForumPage(page, {
    title: 'Kinetic wireless switches',
    heading: 'Getting started with kinetic wireless switches',
  });

  await page.goto(`/?p=${id}&post_type=forum_page`);

  await expect(page.locator('.epi-forum-page__hero h1')).toHaveText(
    'Getting started with kinetic wireless switches'
  );
});

test('the on-this-page bar lists the sections shown, and numbering closes up', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await newForumPage(page, 'Numbering');

  await page.fill('#hero_heading', 'Numbering');
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await page.fill('#what_nav_label', 'What they are');
  await page.fill('#what_heading', 'A switch with no supply');
  await page.fill('#how_nav_label', 'How they work');
  await page.fill('#how_heading', 'Press, generate, transmit, switch');
  await saveEditor(page, expect);

  await page.goto(`/?p=${id}&post_type=forum_page`);

  // Both sections shown: they number 01 and 02 and both appear in the bar.
  await expect(page.locator('#what .epi-forum-page__number')).toHaveText('01');
  await expect(page.locator('#how .epi-forum-page__number')).toHaveText('02');
  await expect(page.locator('.epi-forum-page__bar')).toContainText('What they are');
  await expect(page.locator('.epi-forum-page__bar')).toContainText('How they work');

  // Switch the first one off.
  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await panelSwitch(page, 'What they are').click();
  await saveEditor(page, expect);

  await page.goto(`/?p=${id}&post_type=forum_page`);

  await expect(page.locator('#what')).toHaveCount(0);
  await expect(page.locator('.epi-forum-page__bar')).not.toContainText('What they are');
  // The section behind it takes the number that was freed.
  await expect(page.locator('#how .epi-forum-page__number')).toHaveText('01');
});

test('the stepper shows one step at a time and the FAQs collapse', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await newForumPage(page, 'Steps and questions');

  await page.fill('#hero_heading', 'Steps and questions');
  await page.locator('.bw-tabs').getByText('Explainer').click();
  await page.fill('#how_heading', 'Press, generate, transmit, switch');

  // Two steps is enough to prove one-at-a-time.
  const steps = repeater(page, 'how_steps');
  await addRepeaterRow(page, 'how_steps');
  await steps.locator('input[type="text"]').nth(0).fill('Press');
  await addRepeaterRow(page, 'how_steps');
  await steps.locator('input[type="text"]').nth(1).fill('Generate');

  await page.locator('.bw-tabs').getByText('Close').click();
  await page.fill('#faqs_heading', 'Frequently asked');
  await addRepeaterRow(page, 'faqs_items');
  await repeater(page, 'faqs_items')
    .locator('input[type="text"]')
    .nth(0)
    .fill('Do they need a battery?');

  await saveEditor(page, expect);

  await page.goto(`/?p=${id}&post_type=forum_page`);

  // One step panel visible, and choosing the second swaps which.
  await expect(page.locator('#how .epi-forum-page__step:visible')).toHaveCount(1);
  await page.locator('#how-tab-1').click();
  await expect(page.locator('#how-step-1')).toBeVisible();
  await expect(page.locator('#how-step-0')).toBeHidden();

  // The first question starts open, as in the design; clicking it closes it.
  const question = page.locator('#faqs button[aria-expanded]').first();
  await expect(question).toHaveAttribute('aria-expanded', 'true');
  await question.click();
  await expect(question).toHaveAttribute('aria-expanded', 'false');
});

test('[forum_page id] renders a chosen page inside an ordinary page', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await seedForumPage(page, {
    title: 'Placed by id',
    heading: 'Heading placed by id',
  });

  // Pages use the block editor, so make the host page over REST instead.
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const created = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce },
    data: { title: `Host page for ${id}`, content: `[forum_page id="${id}"]`, status: 'publish' },
  });
  const hostId = (await created.json()).id;

  await page.goto(`/?page_id=${hostId}`);
  await expect(page.locator('.epi-forum-page__hero h1')).toHaveText('Heading placed by id');
});

test('the range shows a product picked by SKU', async ({ page }) => {
  await loginAsAdmin(page);

  // A product to point at, made over REST so the test owns it. CI's harness
  // has no WooCommerce, so there the row falls back to what was typed.
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const sku = `EPI-TEST-${Date.now()}`;
  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: { name: 'Kinetic 1 Gang Wireless Wall Switch', sku, regular_price: '25', status: 'publish' },
  });
  const hasProduct = created.ok();

  const id = await newForumPage(page, 'Range by SKU');
  await page.locator('.bw-tabs').getByText('Products').click();
  await page.fill('#range_heading', 'Plates, dimmers and receivers');
  await addRepeaterRow(page, 'range_products');
  const row = repeater(page, 'range_products');
  await row.locator('input[type="text"]').nth(0).fill(sku);
  await row.locator('input[type="text"]').nth(1).fill('Single circuit on, off and pair');
  await saveEditor(page, expect);

  await page.goto(`/?p=${id}&post_type=forum_page`);
  const card = page.locator('#range .epi-forum-page__product').first();
  await expect(card).toContainText('Single circuit on, off and pair');
  await expect(card).toContainText(sku);

  if (hasProduct) {
    await expect(card).toContainText('Kinetic 1 Gang Wireless Wall Switch');
    await expect(card.locator('a.epi-forum-page__button')).toHaveAttribute('href', /kinetic-1-gang/);
  }
});

test('with no receivers listed, the receiver table is left out', async ({ page }) => {
  await loginAsAdmin(page);
  const id = await newForumPage(page, 'No receivers');

  await page.fill('#hero_heading', 'No receivers');
  await page.locator('.bw-tabs').getByText('Evidence').click();
  await page.fill('#where_heading', 'Jobs where the cable is the problem');
  await page.fill('#where_receivers_heading', 'Six receivers, one for every circuit');
  await saveEditor(page, expect);

  await page.goto(`/?p=${id}&post_type=forum_page`);

  await expect(page.locator('#where h2')).toHaveText('Jobs where the cable is the problem');
  await expect(page.locator('.epi-forum-page__receivers')).toHaveCount(0);
});

test('a receiver takes its details from the product with its SKU', async ({ page }) => {
  await loginAsAdmin(page);

  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  // A photo for the product, so the test sees the product's image come through.
  const media = await page.request.post('/wp-json/wp/v2/media', {
    headers: { 'X-WP-Nonce': nonce, 'Content-Disposition': 'attachment; filename=receiver-test.png' },
    data: fs.readFileSync(path.join(__dirname, '..', 'assets', 'seed', 'receiver-mini.png')),
  });
  const mediaId = media.ok() ? (await media.json()).id : 0;

  // Attributes named as the ePim import names them.
  const sku = `EPI-RECV-${Date.now()}`;
  const created = await page.request.post('/wp-json/wc/v3/products', {
    headers: { 'X-WP-Nonce': nonce },
    data: {
      name: 'RF 2 Channel Kinetic Switch On/Off Receiver Non-dimmable',
      sku,
      regular_price: '25',
      status: 'publish',
      images: mediaId ? [{ id: mediaId }] : [],
      attributes: [
        { name: 'Max rated load', options: ['max. 2 x 6A / 2 x 600W LED'], visible: true },
        { name: 'Product Height (mm)', options: ['80mm'], visible: true },
        { name: 'Product Width (mm)', options: ['39mm'], visible: true },
        { name: 'Product Depth (mm)', options: ['22mm'], visible: true },
      ],
    },
  });
  // CI's harness has no WooCommerce; there the SKU matches nothing and the
  // typed values must stand in, which is checked either way.
  const hasProduct = created.ok();

  const id = await newForumPage(page, 'Receivers by SKU');
  await page.fill('#hero_heading', 'Receivers by SKU');
  await page.locator('.bw-tabs').getByText('Evidence').click();
  await page.fill('#where_heading', 'Jobs where the cable is the problem');
  await page.fill('#where_receivers_heading', 'Six receivers, one for every circuit');

  // One row on the product, one typed by hand with no product behind it.
  const rows = repeater(page, 'where_receivers');
  await addRepeaterRow(page, 'where_receivers');
  await rows.locator('#sku-0').fill(sku);
  await rows.locator('#name-0').fill('Typed name');
  await rows.locator('#channels-0').fill('2');
  await rows.locator('#max_load-0').fill('Typed load');
  await addRepeaterRow(page, 'where_receivers');
  await rows.locator('#name-1').fill('Mini RF Receiver');
  await rows.locator('#max_load-1').fill('6A / 500w LED');
  await rows.locator('#size-1').fill('44mm x 50mm');
  await saveEditor(page, expect);

  await page.goto(`/?p=${id}&post_type=forum_page`);
  const table = page.locator('.epi-forum-page__receivers table');

  const cells = (label) => table.locator('tbody tr', { hasText: label }).locator('td');
  const first = table.locator('thead th[scope="col"]').nth(0);
  await expect(table.locator('thead th[scope="col"]').nth(1)).toContainText('Mini RF Receiver');
  await expect(cells('Max load').nth(1)).toHaveText('6A / 500w LED');
  await expect(cells('Size').nth(1)).toHaveText('44mm x 50mm');
  // Channels are not in the shop, so the typed value stays.
  await expect(cells('Channels').nth(0)).toHaveText('2');

  if (hasProduct) {
    await expect(first).toContainText('RF 2 Channel Kinetic Switch On/Off Receiver Non-dimmable');
    await expect(first.locator('a')).toHaveAttribute('href', /rf-2-channel-kinetic/);
    await expect(first.locator('.epi-forum-page__receiverimage img')).toHaveCount(1);
    await expect(cells('Max load').nth(0)).toHaveText('2 x 6A / 2 x 600W LED');
    await expect(cells('Size').nth(0)).toHaveText('80mm x 39mm x 22mm');
  } else {
    await expect(first).toContainText('Typed name');
    await expect(cells('Max load').nth(0)).toHaveText('Typed load');
  }
});
