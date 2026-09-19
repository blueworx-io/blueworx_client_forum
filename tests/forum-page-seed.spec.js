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
