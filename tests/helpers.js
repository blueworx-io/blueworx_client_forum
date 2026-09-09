// Shared sign-in for the wp-admin specs.
//
// The credentials come from the environment because CI passes the disposable
// instance's own admin account through WP_ADMIN_USER / WP_ADMIN_PASS. The
// defaults match what the foundation's wp-test-env.mjs provisions locally, so
// `npm run wp:up && npm test` works with nothing exported.

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.WP_ADMIN_PASS || 'wptest-admin-pw';

// The plugin's control centre, under Settings.
const LAB_SCREEN = '/wp-admin/options-general.php?page=bwlab';

async function loginAsAdmin(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', ADMIN_USER);
  await page.fill('#user_pass', ADMIN_PASS);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/);
}

// Save whatever is open in the BlueWorx page editor, and wait for the save bar
// to say it landed. The wording is the library's, so it lives here once rather
// than in every spec that saves something.
async function saveEditor(page, expect) {
  await page.locator('.bw-savebar .bw-btn--primary').click();
  await expect(page.locator('.bw-savebar')).toContainText('Everything is saved');
}

// A hideable panel's show/hide switch. It belongs to the panel head rather than
// the fields grid, so it carries no id of its own — it is found by the panel it
// heads. Reads "Shown" or "Hidden", and clicking it flips the panel.
function panelSwitch(page, panelTitle) {
  return page.locator(
    `section.bw-card:has(.bw-card__title:text-is("${panelTitle}")) label.bw-switch`
  );
}

module.exports = {
  loginAsAdmin,
  saveEditor,
  panelSwitch,
  ADMIN_USER,
  ADMIN_PASS,
  LAB_SCREEN,
};
