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
  // Insist there is something to save first. "Everything is saved." is also
  // what the bar says before anything is touched, so without this a click that
  // missed its target — a switch, say — reads as a successful save and the
  // test passes having changed nothing.
  await expect(page.locator('.bw-savebar')).toContainText('Unsaved changes');
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

// A repeater field. The rows are drawn by the library and the wrapper carries
// no id, so it is found by the label that heads it. Row cells reuse plain ids
// like "title-0" across every repeater on the screen, so anything inside a
// repeater has to be looked up within this container rather than page-wide.
function repeater(page, fieldId) {
  return page.locator(`.bw-field:has(label[for="${fieldId}"])`);
}

async function addRepeaterRow(page, fieldId) {
  await repeater(page, fieldId).getByRole('button', { name: 'Add a row' }).click();
}

module.exports = {
  loginAsAdmin,
  saveEditor,
  panelSwitch,
  repeater,
  addRepeaterRow,
  ADMIN_USER,
  ADMIN_PASS,
  LAB_SCREEN,
};
