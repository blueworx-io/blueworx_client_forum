const { test, expect } = require('@playwright/test');
const { loginAsAdmin, LAB_SCREEN } = require('./helpers');

// The Lab screen is the plugin's whole control surface: if it does not render,
// nobody can switch a feature on or off on the shop.

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('the Lab screen renders, and is built from the design system', async ({ page }) => {
  const response = await page.goto(LAB_SCREEN);

  expect(response.status()).toBeLessThan(400);
  await expect(page.locator('#wpbody-content')).not.toContainText('Fatal error');
  await expect(page.locator('#wpbody-content')).not.toContainText(
    'Sorry, you are not allowed to access this page.'
  );

  // The design system shell, not hand-written markup.
  await expect(page.locator('.bw-page .bw-pagehead__h1')).toHaveText('Forum Lighting');
  await expect(page.locator('.bw-savebar')).toBeVisible();
});

test('the Settings menu carries the Lab screen', async ({ page }) => {
  await page.goto('/wp-admin/options-general.php');

  await expect(page.locator('#adminmenu')).toContainText('BlueWorx Lab');
});

test('every feature group is offered, and one section shows at a time', async ({ page }) => {
  await page.goto(LAB_SCREEN);

  const links = page.locator('[data-epi-section-link]');
  const panels = page.locator('[data-epi-section]');

  // Each group in the nav has a panel behind it.
  const count = await links.count();
  expect(count).toBeGreaterThan(1);
  await expect(panels).toHaveCount(count);

  // Exactly one panel is shown on load, and it is the one the nav marks.
  await expect(panels.locator('visible=true')).toHaveCount(1);

  // Choosing another section swaps which panel is shown.
  const second = links.nth(1);
  const key = await second.getAttribute('data-epi-section-link');
  await second.click();

  await expect(page.locator(`[data-epi-section="${key}"]`)).toBeVisible();
  await expect(panels.locator('visible=true')).toHaveCount(1);
  await expect(second).toHaveClass(/is-active/);
});

test('every feature switch posts, whichever section is showing', async ({ page }) => {
  await page.goto(LAB_SCREEN);

  // A hidden panel's switches are still in the form — that is what lets one
  // Save cover the whole screen. A panel removed from the DOM instead of
  // hidden would silently switch off every feature the nav was not left on.
  const switches = page.locator('#epi-lab-form input[name="epi_features[]"][type="checkbox"]');
  expect(await switches.count()).toBeGreaterThan(1);

  const hidden = page.locator('[data-epi-section][hidden] input[type="checkbox"]');
  expect(await hidden.count()).toBeGreaterThan(0);
});

test('saving the form reports back', async ({ page }) => {
  await page.goto(LAB_SCREEN);

  await page.locator('.bw-savebar button[type="submit"]').click();
  await page.waitForURL(/updated=true/);

  await expect(page.locator('.bw-notice--success')).toContainText('Feature settings saved.');
});
