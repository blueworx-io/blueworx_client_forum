const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { loginAsAdmin } = require('./helpers');

// A release has to install itself: the plugin sees new releases from GitHub, but
// without this a site only gets one when somebody presses Update. The harness
// has no GitHub release to fetch, so a test-only mu-plugin asks WordPress's own
// automatic updater — the code that decides what to install in the background —
// about a pending update on this plugin and on two others. It starts the site's
// list of self-updating plugins from a known state (Hello Dolly only) and puts
// the old list back afterwards, so the result never depends on earlier runs.

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
  const { cases } = await decisions(page);
  const own = cases.own;

  expect(own.installs).toBe(true);
  // The Plugins screen shows it as switched on, whatever the site chose.
  expect(own.forced).toBe(true);
  expect(own.kept_true).toBe(true);
  expect(own.kept_false).toBe(true);
});

test('a release with no plugin zip attached is not installed automatically', async ({ page }) => {
  const { cases } = await decisions(page);

  // GitHub's raw source archive holds files the release build leaves out.
  expect(cases.own_src.installs).toBe(false);
  expect(cases.own_src.kept_true).toBe(false);
  // No download address yet: nothing to refuse, and nothing would be fetched.
  expect(cases.own_none.forced).toBe(true);
});

test("other plugins' update settings are left exactly as they were", async ({ page }) => {
  const { cases, option_changed } = await decisions(page);

  // Hello Dolly was chosen by the site, so it updates; this plugin changes nothing about that.
  expect(cases.hello.installs).toBe(true);
  expect(cases.hello.forced).toBeNull();
  // Nobody chose this one: still nobody's choice, and nothing is installed on its behalf.
  expect(cases.other.installs).toBe(false);
  expect(cases.other.forced).toBeNull();

  for (const name of ['hello', 'other']) {
    // A site's own choice for it passes straight through.
    expect(cases[name].kept_true, name).toBe(true);
    expect(cases[name].kept_false, name).toBe(false);
  }

  // Nothing was written to the site's list of plugins set to update themselves.
  expect(option_changed).toBe(false);
});
