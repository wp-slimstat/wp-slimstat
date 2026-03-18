/**
 * E2E: User Exclusion in Client-Side (JS) Tracking Mode
 *
 * Tests that ignore_wp_users and ignore_users settings properly exclude
 * logged-in users when javascript_mode is 'on' (client-side tracking).
 *
 * The existing exclusion-filters.spec.ts only tests server-side mode
 * (javascript_mode=off). This spec covers the JS-only tracking path where:
 * 1. The JS tracker is loaded for ALL visitors
 * 2. The tracking request goes via AJAX/REST
 * 3. Processor::process() must still exclude the user server-side
 *
 * Regression test for wp.org support thread: "Problems with recent updates"
 * (tkbuhler — user exclusion not working after v5.4.x update)
 */
import { test, expect } from '@playwright/test';
import {
  getPool,
  closeDb,
  clearStatsTable,
  setSlimstatSetting,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  enableDisableWpCron,
  restoreWpConfig,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

// ─── DB helpers ──────────────────────────────────────────────────

async function getRecentStatByResource(resourceLike: string): Promise<any | null> {
  const [rows] = await getPool().execute(
    'SELECT id, resource, username FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1',
    [`%${resourceLike}%`]
  ) as any;
  return rows.length > 0 ? rows[0] : null;
}

async function getStatCount(): Promise<number> {
  const [rows] = await getPool().execute('SELECT COUNT(*) as cnt FROM wp_slim_stats') as any;
  return rows[0].cnt;
}

// ─── Login helper ────────────────────────────────────────────────

async function loginAsAdmin(browser: import('@playwright/test').Browser): Promise<{
  context: import('@playwright/test').BrowserContext;
  page: import('@playwright/test').Page;
}> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'parhumm');
  await page.fill('#user_pass', 'testpass123');
  await page.click('#wp-submit');
  // Use domcontentloaded — wp-admin full load is slow due to tracking scripts
  await page.waitForURL('**/wp-admin/**', { timeout: 45_000, waitUntil: 'domcontentloaded' });
  return { context, page };
}

/**
 * Wait for the JS tracker's network request to complete (success or failure).
 * For excluded users, the request completes quickly with an error/empty response.
 * This is much faster than waiting for SlimStatParams.id which is never set for excluded users.
 */
async function waitForTrackingRequest(page: import('@playwright/test').Page, timeoutMs = 10_000): Promise<void> {
  try {
    await page.waitForResponse(
      (resp) => {
        const url = resp.url();
        return url.includes('admin-ajax.php') || url.includes('slimstat/v1/hit');
      },
      { timeout: timeoutMs }
    );
  } catch {
    // Timeout is acceptable — tracker may not fire if excluded client-side
  }
  // Small buffer for any async DB writes to complete
  await page.waitForTimeout(1_000);
}

// ─── Test suite ──────────────────────────────────────────────────

test.describe('User Exclusion — JS/Client-Side Mode (@user-exclusion-js)', () => {
  // JS mode tracking involves async AJAX/REST requests + login overhead — allow more time
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    enableDisableWpCron();
    await snapshotSlimstatOptions();
    // Enable client-side (JS) tracking mode — this is the key difference from
    // the server-side tests in exclusion-filters.spec.ts
    await setSlimstatSetting('javascript_mode', 'on');
    await setSlimstatSetting('is_tracking', 'on');
    // Disable GDPR to avoid consent gate interference — focus on user exclusion
    await setSlimstatSetting('gdpr_enabled', 'off');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    restoreWpConfig();
    await closeDb();
  });

  test.beforeEach(async () => {
    await clearStatsTable();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 1: ignore_wp_users=on excludes admin in JS mode (AJAX transport)
  // ────────────────────────────────────────────────────────────────
  test('ignore_wp_users=on excludes logged-in admin via AJAX transport', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');
    await setSlimstatSetting('tracking_request_method', 'ajax');

    const { context, page } = await loginAsAdmin(browser);

    // Clear stats after login (login page itself might generate tracking attempts)
    await clearStatsTable();

    // Visit a frontend page with a unique marker
    const marker = `e2e-js-user-excl-ajax-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });

    // Wait for the tracking network request to complete (success or rejection)
    await waitForTrackingRequest(page);

    // Admin should NOT be tracked — verify no row with our marker exists
    await expect.poll(
      () => getRecentStatByResource(marker),
      { timeout: 5_000, intervals: [500] }
    ).toBeNull();

    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 2: ignore_wp_users=on excludes admin in JS mode (REST transport)
  // ────────────────────────────────────────────────────────────────
  test('ignore_wp_users=on excludes logged-in admin via REST transport', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');
    await setSlimstatSetting('tracking_request_method', 'rest');

    const { context, page } = await loginAsAdmin(browser);
    await clearStatsTable();

    const marker = `e2e-js-user-excl-rest-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackingRequest(page);

    // Admin should NOT be tracked
    await expect.poll(
      () => getRecentStatByResource(marker),
      { timeout: 5_000, intervals: [500] }
    ).toBeNull();

    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 3: ignore_users with specific username in JS mode
  // ────────────────────────────────────────────────────────────────
  test('ignore_users excludes specific username in JS mode', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'no');
    await setSlimstatSetting('ignore_users', 'parhumm');
    await setSlimstatSetting('tracking_request_method', 'ajax');

    const { context, page } = await loginAsAdmin(browser);
    await clearStatsTable();

    const marker = `e2e-js-username-excl-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackingRequest(page);

    // User 'parhumm' should be excluded by username blacklist
    await expect.poll(
      () => getRecentStatByResource(marker),
      { timeout: 5_000, intervals: [500] }
    ).toBeNull();

    // Reset ignore_users for next tests
    await setSlimstatSetting('ignore_users', '');
    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 4: Non-excluded anonymous user IS tracked in JS mode (control)
  // Confirms the tracker works — if this fails, the exclusion tests
  // might be false positives.
  // ────────────────────────────────────────────────────────────────
  test('Anonymous user IS tracked in JS mode (control test)', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');
    await setSlimstatSetting('tracking_request_method', 'ajax');

    // Anonymous context (no login)
    const context = await browser.newContext();
    const page = await context.newPage();

    await clearStatsTable();

    const marker = `e2e-js-anon-control-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackingRequest(page);

    // Anonymous user SHOULD be tracked (ignore_wp_users only blocks logged-in users)
    await expect.poll(
      () => getRecentStatByResource(marker),
      { timeout: 10_000, intervals: [500] }
    ).not.toBeNull();

    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 5: REST transport with stale nonce — verify user is still
  // excluded even when nonce fails and JS retries without it.
  // This tests the potential bypass via wp_set_current_user(0).
  // ────────────────────────────────────────────────────────────────
  test('REST nonce failure does not bypass user exclusion', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');
    await setSlimstatSetting('tracking_request_method', 'rest');

    const { context, page } = await loginAsAdmin(browser);
    await clearStatsTable();

    // Invalidate the REST nonce by overwriting SlimStatParams.wp_rest_nonce
    // This simulates a cached page with a stale nonce
    const marker = `e2e-js-stale-nonce-${Date.now()}`;
    await page.goto(`${BASE_URL}/?e2e_marker=${marker}`, { waitUntil: 'domcontentloaded' });

    // Tamper with the nonce BEFORE the tracker fires
    // (race: the tracker uses requestIdleCallback/setTimeout(250ms), so we have a window)
    await page.evaluate(() => {
      const w = window as any;
      if (w.SlimStatParams) {
        w.SlimStatParams.wp_rest_nonce = 'invalid_stale_nonce_for_testing';
      }
    });

    // Wait for tracker to fire and all fallback transports to complete
    await page.waitForTimeout(5_000);

    // Even with stale nonce, user should NOT be tracked
    const stat = await getRecentStatByResource(marker);
    expect(stat).toBeNull();

    await page.close();
    await context.close();
  });
});
