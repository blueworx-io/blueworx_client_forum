// The update checker's own vendor folder has to be in git: releases are built
// from git, and without these files the check for updates stops with an error.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

test('the update checker ships with every file it loads', () => {
  const tracked = execFileSync('git', ['ls-files', 'plugin-update-checker/vendor'], {
    cwd: path.resolve(__dirname, '..'),
    encoding: 'utf8',
  });
  for (const file of ['Parsedown.php', 'ParsedownModern.php', 'PucReadmeParser.php']) {
    expect(tracked).toContain(`plugin-update-checker/vendor/${file}`);
  }
});
