const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// A release has to install itself: the plugin sees new releases from GitHub, but
// without this a site only gets one when somebody presses Update. The harness
// has no GitHub release to fetch, so a test-only mu-plugin asks WordPress's own
// automatic updater — the code that decides what to install in the background —
// about a pending update on this plugin and on two others.

const SUPPORT = path.resolve(__dirname, 'support', 'epi-test-auto-updates.php');
const MU_DIR = path.resolve(__dirname, '..', '.wp-test', 'wp', 'wp-content', 'mu-plugins');

test.beforeAll(() => {
  fs.mkdirSync(MU_DIR, { recursive: true });
  fs.copyFileSync(SUPPORT, path.join(MU_DIR, path.basename(SUPPORT)));
});

async function decisions(page) {
  await loginAsAdmin(page);
  const nonce = await page.evaluate(() =>
    fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) =>
      r.text()
    )
  );
  const res = await page.request.get('/wp-json/epi-test/v1/auto-updates', {
    headers: { 'X-WP-Nonce': nonce },
  });
  expect(res.ok(), `auto-updates: ${res.status()}`).toBeTruthy();
  return res.json();
}

test('a new release of this plugin installs without anybody pressing Update', async ({ page }) => {
  const body = await decisions(page);
  const own = body.plugins[body.own];

  expect(own.installs).toBe(true);
  // The Plugins screen shows it as switched on, whatever the site chose.
  expect(own.forced).toBe(true);
  expect(own.kept_true).toBe(true);
  expect(own.kept_false).toBe(true);
});

test("other plugins' update settings are left exactly as they were", async ({ page }) => {
  const body = await decisions(page);

  for (const basename of ['hello.php', 'someone-else/someone-else.php']) {
    const other = body.plugins[basename];
    // Nobody chose: still nobody's choice, and nothing is installed on its behalf.
    expect(other.installs, basename).toBe(false);
    expect(other.forced, basename).toBeNull();
    // A site's own choice for it passes straight through.
    expect(other.kept_true, basename).toBe(true);
    expect(other.kept_false, basename).toBe(false);
  }

  // Nothing was written to the site's list of plugins set to update themselves.
  expect(body.option_changed).toBe(false);
  expect(body.option).not.toContain(body.own);
});
