const { test, expect } = require('@playwright/test');
const { loginAsAdmin, LAB_SCREEN } = require('./helpers');

// The chrome of the Lab screen, which is the shared design system's and not
// this plugin's. It is tested here because this is where a site owner meets it:
// a Save button that wanders up the page, or a toggle that lights up blue when
// it cannot be used, is the plugin looking broken however sound the settings are.

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto(LAB_SCREEN);
});

test('the save bar stays along the bottom of the window', async ({ page }) => {
  const bar = page.locator('.bw-savebar');
  await expect(bar).toBeVisible();

  // innerHeight, not the viewport size: a horizontal scrollbar takes a slice of
  // the window and the bar sits above it, which is right and not a failure.
  const viewport = await page.evaluate(() => window.innerHeight);
  const box = await bar.boundingBox();

  // On the bottom edge, not wherever the content happened to stop.
  expect(Math.abs(box.y + box.height - viewport)).toBeLessThan(2);

  // And it clears WordPress's admin menu rather than lying across it.
  const menu = await page.locator('#adminmenuwrap').boundingBox();
  expect(box.x).toBeGreaterThanOrEqual(menu.x + menu.width - 1);
});

test('the last panel can be scrolled clear of the save bar', async ({ page }) => {
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));

  const bar = await page.locator('.bw-savebar').boundingBox();
  const panels = await page.locator('.bw-panels').boundingBox();

  expect(panels.y + panels.height).toBeLessThanOrEqual(bar.y + 1);
});

test('every toggle sits to the right of the setting it switches', async ({ page }) => {
  const switches = page.locator('.bw-switch');
  const count = await switches.count();
  expect(count).toBeGreaterThan(0);

  for (let i = 0; i < count; i += 1) {
    const row = switches.nth(i);
    if (!(await row.isVisible())) {
      continue;
    }
    const track = await row.locator('.bw-switch__track').boundingBox();
    const label = await row.locator('.bw-switch__label').boundingBox();

    expect(track.x).toBeGreaterThan(label.x + label.width - 1);
  }
});

test('a toggle that cannot be used reads as unavailable, and never highlights', async ({ page }) => {
  // Blocked by a missing plugin — the harness has none of the dependencies
  // installed, so at least one switch on this screen is always locked. It may
  // be in a section the screen does not open on, so go to that section first.
  const section = await page
    .locator('[data-epi-section]:has(input:disabled)')
    .first()
    .getAttribute('data-epi-section');
  expect(section).toBeTruthy();
  await page.locator(`[data-epi-section-link="${section}"]`).click();

  const blocked = page
    .locator(`[data-epi-section="${section}"] .bw-switch:has(input:disabled)`)
    .first();
  await expect(blocked).toBeVisible();

  await expect(blocked.locator('input')).toBeDisabled();
  await expect(blocked).toHaveCSS('cursor', 'not-allowed');
  await expect(blocked.locator('.bw-switch__label')).toHaveCSS('opacity', '0.5');
  await expect(blocked.locator('.bw-switch__track')).toHaveCSS('opacity', '0.5');

  // Selecting across the row selects nothing, so it cannot paint itself blue.
  const selected = await blocked.evaluate((el) => {
    const range = document.createRange();
    range.selectNodeContents(el);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    return selection.toString().trim();
  });
  expect(selected).toBe('');
});
