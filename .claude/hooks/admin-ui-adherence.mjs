#!/usr/bin/env node
// Claude Code PreToolUse hook: refuses a Write or Edit that would put markup
// into a WordPress admin screen that is not built from the shared
// blueworx-admin-design system. Same rules as the CI check, so a session finds
// out at the moment it writes the line rather than at the pull request.
//
// The rules live in the foundation. If no foundation checkout is reachable this
// exits silently: a missing guardrail must never break somebody's editing. That
// also means it gives no signal when it is not firing — if you are wondering
// why it never seems to run, check first whether a foundation checkout is
// actually reachable from this repo.

import { existsSync, readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import { join, resolve } from 'node:path';

const CANDIDATES = [
  process.env.BLUEWORX_FOUNDATION ? join(process.env.BLUEWORX_FOUNDATION, 'scripts') : null,
  '.foundation/scripts',
  '../bluegroup_core_foundation/scripts',
  '../../bluegroup_core_foundation/scripts',
].filter(Boolean);

const scripts = CANDIDATES.find((dir) => existsSync(join(dir, 'lib', 'admin-ui.mjs')));
if (!scripts) process.exit(0);

const load = (rel) => import(pathToFileURL(resolve(scripts, rel)).href);

let input;
try {
  input = JSON.parse(readFileSync(0, 'utf8') || '{}');
} catch {
  process.exit(0);
}

const path = input?.tool_input?.file_path;
if (!path) process.exit(0);

// Write carries the whole file; Edit carries only the replacement text, which is
// a fragment — so the whole-file rules are held back for it.
const isWrite = input.tool_name === 'Write';
const pending = isWrite ? input?.tool_input?.content : input?.tool_input?.new_string;
if (typeof pending !== 'string' || pending === '') process.exit(0);

const skillDir = '.claude/skills/blueworx-admin-design';
const skillCss = join(skillDir, 'styles.css');
if (!existsSync(skillCss)) process.exit(0);

// Everything from here on depends on foundation modules the CANDIDATES scan
// only spot-checked one file of (lib/admin-ui.mjs) — a stale, partial, or
// mid-sync foundation clone can have that file but be missing lib/io.mjs or
// lib/design-system.mjs, and a bare `await import(...)` on a missing module
// throws. A guardrail that cannot run must be invisible, never a stack trace
// on every keystroke — so nothing past this point is allowed to escape
// uncaught. Do not narrow this to a warning; the whole point is silence.
try {
  const { vocabulary } = await load('lib/design-system.mjs');
  const { classifyAdminFile, findViolations, adminAssetPaths } = await load('lib/admin-ui.mjs');
  const { readTextFiles, readJson } = await load('lib/io.mjs');

  const readmeFile = join(skillDir, 'readme.md');
  const vocab = vocabulary({
    css: readFileSync(skillCss, 'utf8'),
    manifest: readJson(join(skillDir, '_ds_manifest.json')),
    markup: existsSync(readmeFile) ? readFileSync(readmeFile, 'utf8') : '',
  });

  // Classify on what the file will be: the pending content for a Write, and
  // what is already on disk for an Edit fragment.
  const forClassification = isWrite
    ? pending
    : (existsSync(path) ? readFileSync(path, 'utf8') : pending);

  // adminAssetPaths does a full-repo PHP scan, but classifyAdminFile's php
  // branch never consults its result — only the jsx and css branches do. The
  // common case is an edit to a PHP admin screen, so only pay for the scan
  // when the pending file could actually need it.
  const needsAdminAssets = /\.(?:jsx|tsx|css)$/i.test(path);
  const adminAssets = needsAdminAssets ? adminAssetPaths(readTextFiles('.', ['.php'])) : new Set();

  const kind = classifyAdminFile({ path, content: forClassification, adminAssets });
  if (!kind) process.exit(0);

  const problems = findViolations({ path, kind, content: pending, vocab, whole: isWrite })
    .filter((p) => p.severity === 'error');
  if (problems.length === 0) process.exit(0);

  // findViolations scans whatever text it is given. For a Write that is the
  // whole file, so its line numbers are real. For an Edit it is only the
  // replacement fragment, so a "line 5 in the real file" problem gets reported
  // as line 1 of the fragment — a wrong number, which is worse than none. Name
  // just the file for a fragment rather than print a line that misleads.
  const location = (p) => (isWrite ? `${p.path}:${p.line}` : p.path);

  console.error([
    'This admin screen is not built from the blueworx-admin-design system:',
    '',
    ...problems.map((p) => `  ${location(p)} — ${p.message}`),
    '',
    'Invoke the blueworx-admin-design skill and take the pattern from there. If',
    'the system has no pattern for what you need, add it to the system in the',
    'foundation first, then build the screen on it.',
  ].join('\n'));
  process.exit(2);
} catch {
  process.exit(0);
}
