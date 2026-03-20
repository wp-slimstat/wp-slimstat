/**
 * E2E: User Exclusion in Server-Side Tracking Mode
 *
 * Tests that ignore_wp_users and ignore_users settings properly exclude
 * logged-in users when javascript_mode is 'off' (server-side tracking).
 *
 * CRITICAL: This test explicitly disables GDPR (gdpr_enabled=off) to isolate
 * user exclusion from the consent gate. Without this, fresh browser contexts
 * have no consent cookie, causing Consent::canTrack() to return false for ALL
 * users - making user exclusion tests false positives.
 *
 * Root cause analysis for issue #246:
 * - The existing test in exclusion-filters.spec.ts did NOT disable GDPR
 * - Fresh browser contexts have no consent cookie
 * - Consent::canTrack() returns false → tracking blocked for ALL users
 * - Test passed because of consent gate, NOT user exclusion
 *
 * This spec provides verified coverage for user exclusion in server-side mode.
 *
 * @see https://github.com/wp-slimstat/wp-slimstat/issues/246
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
  installCptMuPlugin,
  uninstallCptMuPlugin,
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

/**
 * Create a published product post with a unique slug.
 *
 * Server-mode tracking does not reliably persist homepage query-string markers
 * in this local environment, so these tests use the same product permalinks
 * already proven by exclusion-filters.spec.ts to generate pageview rows.
 */
async function createTrackableProduct(title: string, slug: string): Promise<string> {
  const now = new Date().toISOString().slice(0, 19).replace('T', ' ');
  await getPool().execute(
    `INSERT INTO wp_posts (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_name, post_modified, post_modified_gmt, post_type, to_ping, pinged, post_content_filtered)
     VALUES (1, ?, ?, 'Server mode user exclusion test content.', ?, '', 'publish', 'closed', 'closed', ?, ?, ?, 'product', '', '', '')`,
    [now, now, title, slug, now, now]
  );

  return `${BASE_URL}/product/${slug}/`;
}

const EMPTY_STORAGE_STATE = { cookies: [], origins: [] };

// ─── Login helper ────────────────────────────────────────────────

async function loginAsAdmin(browser: import('@playwright/test').Browser): Promise<{
  context: import('@playwright/test').BrowserContext;
  page: import('@playwright/test').Page;
}> {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', 'parhumm');
  await page.fill('#user_pass', 'testpass123');
  await page.click('#wp-submit');
  await page.waitForURL('**/wp-admin/**', { timeout: 45_000, waitUntil: 'domcontentloaded' });
  return { context, page };
}

// ─── Test suite ──────────────────────────────────────────────────

test.describe('User Exclusion — Server-Side Mode (@user-exclusion-server)', () => {
  test.setTimeout(90_000);

  test.beforeAll(async () => {
    enableDisableWpCron();
    installCptMuPlugin();
    await snapshotSlimstatOptions();
    // Enable server-side tracking mode (javascript_mode=off)
    await setSlimstatSetting('javascript_mode', 'off');
    await setSlimstatSetting('is_tracking', 'on');
    // CRITICAL: Disable GDPR to isolate user exclusion from consent gate (#246)
    // Without this, Consent::canTrack() returns false for fresh browser contexts
    // (no consent cookie), making ALL users blocked - not just logged-in ones.
    await setSlimstatSetting('gdpr_enabled', 'off');
  });

  test.afterAll(async () => {
    await restoreSlimstatOptions();
    uninstallCptMuPlugin();
    restoreWpConfig();
    await closeDb();
  });

  test.beforeEach(async () => {
    // Reset exclusion knobs to prevent state leakage between tests
    await setSlimstatSetting('ignore_wp_users', 'no');
    await setSlimstatSetting('ignore_users', '');
    await setSlimstatSetting('ignore_capabilities', '');
    await clearStatsTable();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 1: ignore_wp_users=on excludes logged-in admin in server mode
  // This is the PRIMARY test for issue #246
  // ────────────────────────────────────────────────────────────────
  test('ignore_wp_users=on excludes logged-in admin in server-side mode', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');

    const { context, page } = await loginAsAdmin(browser);

    // Clear stats after login (login page itself might be tracked)
    await clearStatsTable();

    const slug = `e2e-server-user-excl-${Date.now()}`;
    const pageUrl = await createTrackableProduct('E2E Server User Exclusion', slug);
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    // Admin should NOT be tracked on a page that the server-mode tracker does record.
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 6_000, intervals: [500] }
    ).toBeNull();

    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 2: ignore_users with specific username in server mode
  // ────────────────────────────────────────────────────────────────
  test('ignore_users excludes specific username in server-side mode', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'no');
    await setSlimstatSetting('ignore_users', 'parhumm');

    const { context, page } = await loginAsAdmin(browser);
    await clearStatsTable();

    const slug = `e2e-server-username-excl-${Date.now()}`;
    const pageUrl = await createTrackableProduct('E2E Username Exclusion', slug);
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    // User 'parhumm' should be excluded by username blacklist
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 6_000, intervals: [500] }
    ).toBeNull();

    // Reset ignore_users for next tests
    await setSlimstatSetting('ignore_users', '');
    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 3: ignore_capabilities excludes admin role in server mode
  // ────────────────────────────────────────────────────────────────
  test('ignore_capabilities excludes administrator role in server-side mode', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'no');
    await setSlimstatSetting('ignore_users', '');
    await setSlimstatSetting('ignore_capabilities', 'administrator');

    const { context, page } = await loginAsAdmin(browser);
    await clearStatsTable();

    const slug = `e2e-server-cap-excl-${Date.now()}`;
    const pageUrl = await createTrackableProduct('E2E Capability Exclusion', slug);
    await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    // Admin should be excluded by capability blacklist
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 6_000, intervals: [500] }
    ).toBeNull();

    // Reset ignore_capabilities for next tests
    await setSlimstatSetting('ignore_capabilities', '');
    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 4: Anonymous user IS tracked in server mode (control test)
  // Confirms server-side tracking works — if this fails, exclusion
  // tests might be false positives.
  // ────────────────────────────────────────────────────────────────
  test('Anonymous user IS tracked in server-side mode (control test)', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'on');
    await setSlimstatSetting('ignore_users', '');
    await setSlimstatSetting('ignore_capabilities', '');

    // This suite runs under the Playwright "admin" project, so an explicit empty
    // storage state is required to create a truly anonymous browser context.
    const context = await browser.newContext({
      javaScriptEnabled: false,
      storageState: EMPTY_STORAGE_STATE,
    });
    const page = await context.newPage();

    await clearStatsTable();
    const slug = `e2e-server-anon-control-${Date.now()}`;
    const pageUrl = await createTrackableProduct('E2E Anonymous Server Control', slug);
    const response = await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    // Verify page loaded (not 404 from missing rewrite rules)
    expect(response?.status(), `Product page ${pageUrl} must load (not 404)`).toBeLessThan(400);

    // Anonymous user SHOULD be tracked (ignore_wp_users only blocks logged-in users)
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 15_000, intervals: [500] }
    ).not.toBeNull();

    await page.close();
    await context.close();
  });

  // ────────────────────────────────────────────────────────────────
  // Test 5: Logged-in user IS tracked when ignore_wp_users=off (control)
  // Confirms that user exclusion is actually what blocks tracking.
  // ────────────────────────────────────────────────────────────────
  test('Logged-in admin IS tracked when ignore_wp_users=off (control test)', async ({ browser }) => {
    await setSlimstatSetting('ignore_wp_users', 'no');
    await setSlimstatSetting('ignore_users', '');
    await setSlimstatSetting('ignore_capabilities', '');

    const { context, page } = await loginAsAdmin(browser);
    await clearStatsTable();

    const slug = `e2e-server-admin-tracked-${Date.now()}`;
    const pageUrl = await createTrackableProduct('E2E Admin Server Control', slug);
    const adminResponse = await page.goto(pageUrl, { waitUntil: 'domcontentloaded' });

    // Verify page loaded (not 404 from missing rewrite rules)
    expect(adminResponse?.status(), `Product page ${pageUrl} must load (not 404)`).toBeLessThan(400);

    // Admin SHOULD be tracked when ignore_wp_users is off
    await expect.poll(
      () => getRecentStatByResource(slug),
      { timeout: 15_000, intervals: [500] }
    ).not.toBeNull();

    // Verify the tracked row has the username
    const stat = await getRecentStatByResource(slug);
    expect(stat).not.toBeNull();
    expect(stat!.username).toBe('parhumm');

    await page.close();
    await context.close();
  });
});
