const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The test WordPress has no WooCommerce, which is exactly the state the notice
// exists for. That also makes it the one converted screen the harness can
// reach: the metadata viewer, the change log and the purge tool all need
// WooCommerce before they appear at all.

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('the WooCommerce notice is drawn from the design system, not core', async ({ page }) => {
  await page.goto('/wp-admin/');

  const notice = page.locator('.bw-notice--warning');
  await expect(notice).toContainText('works best with WooCommerce active');

  // Core's own notice classes would look like a different plugin's work.
  await expect(page.locator('.notice-warning')).toHaveCount(0);
});

test('the notice brings the design system with it', async ({ page }) => {
  await page.goto('/wp-admin/');

  // A notice with the markup but not the stylesheet renders as unstyled text,
  // and one without the icon module draws an empty box where the icon goes.
  const stylesheet = page.locator('link[href*="blueworx-admin-design.css"]');
  await expect(stylesheet).toHaveCount(1);

  const icon = page.locator('.bw-notice--warning .bw-icon[data-lucide="triangle-alert"] svg');
  await expect(icon).toBeVisible();
});
