const { test, expect } = require('@playwright/test');
const { loginAsAdmin, saveEditor } = require('./helpers');

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
