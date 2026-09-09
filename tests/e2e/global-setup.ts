/**
 * Global setup: authenticates admin and author users, saves browser state,
 * and installs all MU-plugins needed by the test suite.
 * Reuses cached auth files if they are less than 30 minutes old.
 */
import { chromium, request as playwrightRequest, FullConfig } from '@playwright/test';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { BASE_URL, ADMIN_USER, ADMIN_PASS, AUTHOR_USER, AUTHOR_PASS } from './helpers/env';
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
  const ctx = await playwrightRequest.newContext({
    storageState: adminStatePath,
    // Same gate as every other context here; without it this canary reports the LocalWP
    // self-signed cert as "the mu-plugin is unreachable", which is the one thing it exists
    // not to do. No-op in CI, where PW_IGNORE_HTTPS is unset.
    ignoreHTTPSErrors: process.env.PW_IGNORE_HTTPS === '1',
  });
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

/**
 * ABORT THE RUN when Pro is installed but did not boot.
 *
 * On 2026-09-09 a 137-minute zero-retry census ran against a Pro 3.0.0 that deactivated
 * itself on the login request in global setup: WordPress loads 'wp-slimstat-pro/…' before
 * 'wp-slimstat/…' on the sorted active_plugins order, so Pro's minimum-Free guard read an
 * undefined SLIMSTAT_ANALYTICS_VERSION as "free is too old". Ten failures were then filed
 * against MaxMind, User Overview and author scoping, and seven more tests skipped themselves
 * with "Pro is not installed/active". One fact, misread seventeen ways.
 *
 * Costs one POST. Fails in minute one, with the reason, rather than in hour three, without it.
 * "Not installed" is the ONLY tolerated state — the Free CI lanes carry no Pro by declaration
 * (ci.yml: private repository, no deploy key), and it is reported so the log says which run
 * this was.
 */
async function assertProBootedIfInstalled(baseURL: string, adminStatePath: string): Promise<void> {
  const ctx = await playwrightRequest.newContext({
    storageState: adminStatePath,
    ignoreHTTPSErrors: process.env.PW_IGNORE_HTTPS === '1',
  });
  try {
    const res = await ctx.post(`${baseURL}/wp-admin/admin-ajax.php`, {
      form: { action: 'e2e_get_slimstat_version' },
    });
    const text = await res.text();
    let data: any = null;
    try { data = JSON.parse(text)?.data; } catch { /* reported below as the raw body */ }
    if (!res.ok() || !data) {
      throw new Error(
        `HARNESS PRO PROBE FAILED: e2e_get_slimstat_version returned HTTP ${res.status()} with body ${text.slice(0, 300)}`
      );
    }

    if (!data.pro_installed) {
      console.log('[global-setup] wp-slimstat-pro is not installed — Pro specs will skip (declared Free-only lane).');
      return;
    }

    if (!data.pro_activated || !data.pro_booted) {
      const degradations = Array.isArray(data.pro_degradations) && data.pro_degradations.length
        ? data.pro_degradations.join(', ')
        : '(none recorded)';
      throw new Error(
        'PRO BOOT FAILED — aborting the run before any spec can misattribute it.\n' +
        `  is_plugin_active: ${data.pro_activated}\n` +
        `  report slim_p8_01 registered on slimstat_reports_info: ${data.pro_booted}\n` +
        `  slimstat_degradations pro_* keys: ${degradations}\n` +
        `  free version: ${data.version ?? '(undefined)'}   pro version: ${data.pro_version ?? '(unknown)'}\n\n` +
        'Pro is switched on and dead, or was deactivated by the login request above. Check the ' +
        'site debug.log for "requires SlimStat Analytics" and wp-slimstat-pro.php _checkRequirements(); ' +
        'tests/free-floor-load-order-test.php in the Pro repo covers the load-order case.'
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
  try {
    await page.waitForURL('**/wp-admin/**', { timeout: 60_000 });
  } catch (error) {
    const artifacts = path.join(__dirname, 'run-artifacts');
    fs.mkdirSync(artifacts, { recursive: true });
    fs.writeFileSync(path.join(artifacts, `login-failure-${username}.json`), JSON.stringify({
      url: page.url(),
      loginError: await page.locator('#login_error').textContent().catch(() => null),
      body: await page.locator('body').innerText().catch(() => ''),
    }, null, 2));
    await page.screenshot({ path: path.join(artifacts, 'login-failure.png') });
    await browser.close();
    throw error;
  }

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

  // Permission coverage requires the real author account; never substitute admin.
  await loginAndSave(baseURL, AUTHOR_USER, AUTHOR_PASS, path.join(AUTH_DIR, 'author.json'));
  for (const [role, username] of [['administrator', ADMIN_USER], ['author', AUTHOR_USER]]) {
    const state = role === 'administrator' ? 'admin' : 'author';
    const ctx = await playwrightRequest.newContext({
      storageState: path.join(AUTH_DIR, `${state}.json`),
      ignoreHTTPSErrors: process.env.PW_IGNORE_HTTPS === '1',
    });
    try {
      const response = await ctx.post(`${baseURL}/wp-admin/admin-ajax.php`, {
        form: { action: 'test_current_identity' },
      });
      const body = await response.json();
      if (!response.ok() || !body.success || body.data?.login !== username
          || !Array.isArray(body.data?.roles) || !body.data.roles.includes(role)
          || (role === 'author' && body.data.can_manage_options !== false)) {
        throw new Error(`HARNESS IDENTITY FAILED: ${state} storage state is not the configured ${role}`);
      }
    } finally {
      await ctx.dispose();
    }
  }

  // LAST, and only once the admin session exists -- the endpoint requires manage_options, so
  // running this before login would report 'forbidden' for a helper that is in fact loaded.
  await assertNonceHelperReachable(baseURL, path.join(AUTH_DIR, 'admin.json'));

  // Same reason, same place: the login above is exactly the interactive admin request that
  // a Pro with a broken requirements guard deactivates itself on.
  await assertProBootedIfInstalled(baseURL, path.join(AUTH_DIR, 'admin.json'));
}
