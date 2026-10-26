const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The plugin ships the Kinetic page. The harness activates the plugin before
// any test runs, so by now the seed has had its one chance to create it.

const SLUG = 'getting-started-with-kinetic-wireless-switches';

test('the Kinetic page is there on a fresh site, filled in', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/wp-admin/edit.php?post_type=forum_page');

  const row = page
    .locator('.wp-list-table tbody tr', {
      hasText: 'Getting started with kinetic wireless switches',
    })
    .first();
  await expect(row).toBeVisible();

  await row.hover();
  await row.locator('.row-actions a', { hasText: 'Edit' }).first().click();
  await page.waitForURL(/page=forum-page/);

  await expect(page.locator('#hero_heading')).toHaveValue(
    'Getting started with kinetic wireless switches'
  );
  await expect(page.locator('#hero_meta_read')).toHaveValue('6 MIN READ');

  await page.locator('.bw-tabs').getByText('Explainer').click();
  await expect(page.locator('#how_heading')).toHaveValue('Press, generate, transmit, switch');
});

test('the Kinetic page renders every section with its photo', async ({ page }) => {
  await page.goto(`/forum-pages/${SLUG}/`);

  await expect(page.locator('.epi-forum-page__hero h1')).toContainText('kinetic wireless switches');
  await expect(page.locator('.epi-forum-page__hero img')).toHaveAttribute('src', /kinetic-hero/);
  await expect(page.locator('.epi-forum-page')).toContainText('Six things to settle on site');
  await expect(page.locator('.epi-forum-page')).toContainText('Frequently asked');
});

test('the Kinetic page compares the six receivers', async ({ page }) => {
  await page.goto(`/forum-pages/${SLUG}/`);

  const table = page.locator('.epi-forum-page__receivers table');
  await expect(table.locator('thead th[scope="col"]')).toHaveCount(6);
  await expect(table.locator('thead')).toContainText('Mini RF + WiFi Receiver');
  await expect(table.locator('thead img')).toHaveCount(6);

  const maxLoad = table.locator('tbody tr', { hasText: 'Max load' });
  await expect(maxLoad).toContainText('2x 6A / 2x 600w LED');

  const app = table.locator('tbody tr', { hasText: 'Smart App control' });
  await expect(app.locator('.epi-forum-page__tick')).toHaveCount(3);
});

test('the Kinetic page shows the switch photo and the new step icons', async ({ page }) => {
  await page.goto(`/forum-pages/${SLUG}/`);

  await expect(page.locator('.epi-forum-page__diagram img').first()).toHaveAttribute('src', /what-switch\.png/);
  await expect(page.locator('.epi-forum-page__leaders')).toHaveCount(1);
  await expect(page.locator('#how-step-0 img')).toHaveAttribute('src', /step-press\.svg\?ver=/);
});

test('Steve can edit the receivers from the page editor', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/wp-admin/edit.php?post_type=forum_page&s=Getting+started+with+kinetic');
  const row = page
    .locator('.wp-list-table tbody tr', { hasText: 'Getting started with kinetic wireless switches' })
    .first();
  await row.hover();
  await row.locator('.row-actions a', { hasText: 'Edit' }).first().click();
  await page.waitForURL(/page=forum-page/);

  await page.locator('.bw-tabs').getByText('Evidence').click();
  await expect(page.locator('#where_receivers_heading')).toHaveValue('Six receivers, one for every circuit');
});
