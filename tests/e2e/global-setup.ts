/**
 * Global setup: authenticates admin and author users, saves browser state,
 * and installs all MU-plugins needed by the test suite.
 * Reuses cached auth files if they are less than 30 minutes old.
 */
import { chromium, request as playwrightRequest, FullConfig } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { BASE_URL, ADMIN_USER, ADMIN_PASS } from './helpers/env';
import { installAllTestMuPlugins, installCptMuPlugin, enableE2eTesting } from './helpers/setup';
import { backupAnalyticsTables } from './helpers/backup';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const AUTH_DIR = path.join(__dirname, '.auth');
const MAX_AGE_MS = 30 * 60 * 1000; // 30 minutes

function isAuthFresh(statePath: string): boolean {
  if (!fs.existsSync(statePath)) return false;
  const stat = fs.statSync(statePath);
  return Date.now() - stat.mtimeMs < MAX_AGE_MS;
}

/**
 * HARNESS CANARY — one POST, before any spec runs.
 *
 * The suite spent months reporting 26 x "Cannot read properties of undefined (reading 'nonce')"
 * and 12 x "test_create_nonce failed: HTTP 400" as 38 separate spec failures. They were one
 * fact: `.wp-env.json` bind-mounted `wp-content/mu-plugins` over the directory
 * installAllTestMuPlugins() writes into, so nonce-helper-mu-plugin.php was copied successfully
 * to a path WordPress could not see, and admin-ajax.php answered 400 for an action nothing had
 * registered.
 *
 * Every one of those failures pointed at the spec that happened to ask first. This asks first,
 * on purpose, and names the real thing — the harness cannot deliver its own mu-plugins — before
 * a single spec gets a chance to mistranslate it.
 *
 * It throws. A warning here would be a warning in a log nobody reads until the suite is red for
 * a reason it does not state.
 */
async function assertNonceHelperReachable(baseURL: string, adminStatePath: string): Promise<void> {
  const ctx = await playwrightRequest.newContext({ storageState: adminStatePath });
  try {
    const res = await ctx.post(`${baseURL}/wp-admin/admin-ajax.php`, {
      form: { action: 'test_create_nonce', nonce_action: 'slimstat_chart_nonce' },
    });
    const status = res.status();
    const text = await res.text();

    let body: unknown = null;
    try { body = JSON.parse(text); } catch { /* reported below as the raw body */ }
    const nonce = (body as { data?: { nonce?: string } } | null)?.data?.nonce;

    if (status !== 200 || typeof nonce !== 'string' || nonce === '') {
      throw new Error(
        `HARNESS CANARY FAILED: test_create_nonce returned HTTP ${status} with body ` +
        `${text.slice(0, 300)}\n\n` +
        'This is the harness, not a product defect. nonce-helper-mu-plugin.php is not loading. ' +
        'Check, in order: (1) no bind mount covers wp-content/mu-plugins in .wp-env.json or ' +
        '.wp-env.override.json — a mount there REPLACES the directory setup.ts writes into, and ' +
        'the copy still reports success; (2) WP_ROOT names the tests site, not development; ' +
        '(3) SLIMSTAT_E2E_TESTING is defined in that site\'s wp-config.php. ' +
        'tests/e2e-harness-contract-test.php pins (1) and (2) at source level.'
      );
    }
  } finally {
    await ctx.dispose();
  }
}

async function loginAndSave(
  baseURL: string,
  username: string,
  password: string,
  statePath: string
): Promise<void> {
  if (isAuthFresh(statePath)) return; // reuse cached auth

  const browser = await chromium.launch();
  // ignoreHTTPSErrors gated by PW_IGNORE_HTTPS (LocalWP self-signed cert); no-op in CI.
  const context = await browser.newContext({ baseURL, ignoreHTTPSErrors: process.env.PW_IGNORE_HTTPS === '1' });
  const page = await context.newPage();

  await page.goto('/wp-login.php');
  await page.fill('#user_login', username);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**', { timeout: 60_000 });

  // Never bake the tracker's offline queue into the saved auth state.
  //
  // SlimStat buffers an interaction into localStorage when it fires before a
  // pageview id exists, and replays it on the next page load. Captured here, that
  // queue is restored by EVERY spec that uses this storageState, and each one
  // silently gains a phantom interaction attached to its own pageview — observed
  // as a stray {"type":"submit"} event appearing in unrelated tests, which is
  // indistinguishable from a real double-count and corrupts any spec that counts
  // events. It survives for the 30-minute auth cache window, so it also comes and
  // goes on its own, which is worse.
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem('slimstat_offline_queue');
    } catch {
      /* localStorage unavailable — nothing cached to clear */
    }
  });

  await context.storageState({ path: statePath });
  await browser.close();
}

export default async function globalSetup(config: FullConfig): Promise<void> {
  const baseURL = BASE_URL;

  // Before anything else, and before any spec can truncate. This is the only place
  // the whole suite has to pass through, and ALLOW_LIVE_DB=1 is the only way any of
  // it reaches a real dataset — so it is where the backup belongs. Throws rather
  // than warns: 443,535 rows of parity baseline are not worth a console message.
  backupAnalyticsTables();

  // Install all MU-plugins once so individual specs don't need to manage them.
  // This prevents state contamination when one spec's afterAll removes a plugin
  // that the next spec expects to be present.
  installAllTestMuPlugins();
  installCptMuPlugin();

  // Enable the SLIMSTAT_E2E_TESTING constant the test mu-plugins are gated on, for
  // the whole run, so endpoint specs that POST directly to admin-ajax get a working
  // test_create_nonce. Settle past LocalWP's opcache revalidate window (~2s) so PHP
  // sees the define before the first test; harmless in CI (runs well before tests).
  enableE2eTesting();
  await new Promise((r) => setTimeout(r, 2500));

  fs.mkdirSync(AUTH_DIR, { recursive: true });

  // Login as admin — credentials from helpers/env.ts (single source of truth).
  await loginAndSave(
    baseURL,
    ADMIN_USER,
    ADMIN_PASS,
    path.join(AUTH_DIR, 'admin.json')
  );

  // Login as author — override via WP_AUTHOR_USER / WP_AUTHOR_PASS env vars.
  // Non-fatal; some test environments lack this user.
  const authorUser = process.env.WP_AUTHOR_USER ?? 'dordane';
  const authorPass = process.env.WP_AUTHOR_PASS ?? 'testpass123';
  try {
    await loginAndSave(
      baseURL,
      authorUser,
      authorPass,
      path.join(AUTH_DIR, 'author.json')
    );
  } catch (e) {
    console.warn('Author login failed, using admin fallback:', (e as Error).message);
    const adminPath = path.join(AUTH_DIR, 'admin.json');
    const authorPath = path.join(AUTH_DIR, 'author.json');
    if (fs.existsSync(adminPath) && !fs.existsSync(authorPath)) {
      fs.copyFileSync(adminPath, authorPath);
    }
  }

  // LAST, and only once the admin session exists -- the endpoint requires manage_options, so
  // running this before login would report 'forbidden' for a helper that is in fact loaded.
  await assertNonceHelperReachable(baseURL, path.join(AUTH_DIR, 'admin.json'));
}
