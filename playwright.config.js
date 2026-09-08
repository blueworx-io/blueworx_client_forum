// @ts-check
const { defineConfig, devices } = require('@playwright/test');

// The suite runs against a disposable real WordPress that the run provisions
// itself (PHP + SQLite — see `npm run wp:up`), never a hosted staging site.
// PLAYWRIGHT_BASE_URL points at it.
const baseURL = process.env.PLAYWRIGHT_BASE_URL || process.env.BASE_URL || 'http://127.0.0.1:8881';

module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: 'list',
  // The harness is PHP's built-in single-threaded server, and wp-admin screens
  // pull a lot of assets through it one at a time. The default 30s is not
  // enough for those on a cold cache.
  timeout: 90_000,
  // The specs switch plugin features on and off, which is site-wide state.
  // Parallel workers against one WordPress make one spec's "off" another
  // spec's "on", and PHP's built-in server is single-threaded anyway.
  workers: 1,
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'wordpress',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
