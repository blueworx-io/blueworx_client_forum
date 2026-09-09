const { test, expect } = require('@playwright/test');
const { loginAsAdmin, saveEditor } = require('./helpers');

// The editor edits a record that already exists. Everything here starts by
// making one, because a test that assumes a record is a test that passes on
// one machine.

async function createForumPage(page, title) {
  await page.goto('/wp-admin/post-new.php?post_type=forum_page');
  await page.fill('#title', title);
  await page.click('#publish');
  await page.waitForURL(/post=(\d+)/);
  const id = new URL(page.url()).searchParams.get('post');
  return Number(id);
}

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('Forum Pages has its own menu section', async ({ page }) => {
  await page.goto('/wp-admin/');
  await expect(page.locator('#adminmenu')).toContainText('Forum Pages');
});

test('the editor opens on a real record and saves the hero heading', async ({ page }) => {
  const id = await createForumPage(page, 'Kinetic wireless switches');

  await page.goto(`/wp-admin/admin.php?page=forum-page&id=${id}`);

  await expect(page.locator('#bw-page-editor')).toBeVisible();
  await expect(page.locator('.bw-savebar')).toBeVisible();

  await page.fill('#hero_heading', 'Getting started with kinetic wireless switches');
  await saveEditor(page, expect);

  await page.reload();
  await expect(page.locator('#hero_heading')).toHaveValue(
    'Getting started with kinetic wireless switches'
  );
});

test('the editor says so when there is no record to edit', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=forum-page');

  await expect(page.locator('#wpbody-content')).toContainText('could not be found');
});
