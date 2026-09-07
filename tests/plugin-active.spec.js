const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The plugin runs on a shop that has WooCommerce and Elementor. The disposable
// test instance has neither, and activating without them must still be safe —
// that is what the dependency guards in the feature registry are for.

test('the plugin is active and did not fatal on load', async ({ page }) => {
  await loginAsAdmin(page);

  const response = await page.goto('/wp-admin/plugins.php');
  expect(response.status()).toBeLessThan(400);

  const row = page.locator('tr[data-slug="blueworx-client-forum"], tr#blueworx_client_forum, tr').filter({
    hasText: 'BlueWorx Lab | Forum Lighting',
  });

  await expect(row.first()).toBeVisible();
  await expect(page.locator('#wpbody-content')).not.toContainText('Fatal error');
  await expect(page.locator('#wpbody-content')).not.toContainText('has been deactivated due to an error');
});

test('the front page still renders with the plugin on', async ({ page }) => {
  const response = await page.goto('/');

  expect(response.status()).toBeLessThan(400);
  await expect(page.locator('body')).not.toContainText('Fatal error');
});
