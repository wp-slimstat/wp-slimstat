import { defineConfig } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';
import { BASE_URL, envInt } from './helpers/env';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.ts',
  timeout: 45_000,
  // PW_RETRIES / PW_MAX_FAILURES exist for ONE purpose: a census run. A census wants
  // retries 0 (a retry hides a flake, and a census is counting flakes) and maxFailures 0
  // (unlimited). The CI defaults below are unchanged; both CI reads so far were capped at
  // 50 and reported `success`, so nobody had ever seen the whole suite fail or pass.
  retries: envInt('PW_RETRIES', process.env.CI ? 1 : 0),
  fullyParallel: false, // Tests modify shared state (wp-config, DB options)
  // Single worker required: chart tests use TRUNCATE TABLE for isolation,
  // which would race with parallel workers. Do NOT increase without
  // switching to per-test user_agent filtering or transaction rollback.
  workers: 1,
  // 50 is a CAP, not an allowance: the previous comment here said it was "to get full
  // pass/fail picture", and it is the exact thing that prevents one — the run aborts at
  // failure 50 and the remaining ~480 tests never execute. Kept as the per-PR default so a
  // broken harness fails fast; set PW_MAX_FAILURES=0 for a census.
  maxFailures: envInt('PW_MAX_FAILURES', process.env.CI ? 50 : 0),
  reporter: [
    ['list'],
    ['html', { open: 'never', outputFolder: path.join(__dirname, 'playwright-report') }],
    ['blob', { outputDir: path.join(__dirname, 'run-artifacts', 'blob') }],
    ['json', { outputFile: path.join(__dirname, 'run-artifacts', 'results.json') }],
  ],
  use: {
    baseURL: BASE_URL,
    // LocalWP serves a self-signed cert Chromium rejects. Gated by PW_IGNORE_HTTPS
    // so it is a no-op in CI (which uses plain HTTP / a trusted cert).
    ignoreHTTPSErrors: process.env.PW_IGNORE_HTTPS === '1',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  globalSetup: path.join(__dirname, 'global-setup.ts'),
  globalTeardown: path.join(__dirname, 'global-teardown.ts'),
  projects: [
    {
      name: 'admin',
      use: { storageState: path.join(__dirname, '.auth/admin.json') },
    },
    {
      name: 'author',
      use: { storageState: path.join(__dirname, '.auth/author.json') },
      testMatch: '**/*author*.spec.ts',
    },
  ],
});
