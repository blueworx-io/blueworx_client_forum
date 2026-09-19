const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers');

// The banner ships its three designed slides, so the shortcode alone is a
// complete banner. Pages use the block editor, so the host page is made over
// REST rather than by clicking through it.
async function hostPage(page, content) {
  await loginAsAdmin(page);
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const created = await page.request.post('/wp-json/wp/v2/pages', {
    headers: { 'X-WP-Nonce': nonce },
    data: { title: `Banner host ${Date.now()}`, content, status: 'publish' },
  });
  return (await created.json()).id;
}

test('the banner shows the three designed slides and moves on the arrows', async ({ page }) => {
  const id = await hostPage(page, '[forum_banner]');
  await page.goto(`/?page_id=${id}`);

  const banner = page.locator('.epi-banner');
  const current = banner.locator('.epi-banner__slide[aria-hidden="false"]');

  await expect(banner.locator('.epi-banner__slide')).toHaveCount(3);
  await expect(current.locator('h2')).toHaveText('Wireless switches that power themselves.');
  await expect(banner.locator('.epi-banner__dot')).toHaveCount(3);
  await expect(banner).toHaveAttribute('data-autoplay', '6');

  await banner.locator('.epi-banner__arrow--next').click();
  await expect(current.locator('h2')).toHaveText('The BHS Decorative Range has landed.');
  await expect(current).toHaveClass(/epi-banner__slide--light/);
  await expect(current.locator('.epi-banner__badge')).toHaveText('NEW IN');

  await banner.locator('.epi-banner__arrow--prev').click();
  await expect(current.locator('h2')).toHaveText('Wireless switches that power themselves.');
});

test('the banner is offered as a feature on the Lab screen', async ({ page }) => {
  await loginAsAdmin(page);
  await page.goto('/wp-admin/options-general.php?page=bwlab');

  await expect(page.locator('#epi-lab-form')).toContainText('Banner slider');
});
