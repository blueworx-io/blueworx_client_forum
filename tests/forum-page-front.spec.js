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
