/**
 * Regression test for Issue #219: Fatal TypeError in Flysystem adapter
 * due to namespace mismatch in bundled dependencies.
 *
 * When Browscap browser detection is enabled and the browscap cache exists,
 * the Flysystem adapter's constructor must accept the scoped
 * SlimStat\Dependencies\League\Flysystem\Filesystem without throwing a TypeError.
 *
 * This test enables Browscap, triggers a tracking request, and verifies:
 *   1. The tracking request does NOT return a 500 error.
 *   2. A stat row is created in the database with browser data.
 */
import { test, expect } from '@playwright/test';
import {
  installOptionMutator,
  uninstallOptionMutator,
  setSlimstatOption,
  snapshotSlimstatOptions,
  restoreSlimstatOptions,
  clearStatsTable,
  waitForPageviewRow,
  waitForTrackerId,
  getPool,
  closeDb,
} from './helpers/setup';
import { BASE_URL } from './helpers/env';

test.describe('Browscap Flysystem namespace fix — Issue #219 regression', () => {
  test.setTimeout(60_000);

  test.beforeAll(async () => {
    installOptionMutator();
  });

  test.beforeEach(async ({ page }) => {
    await snapshotSlimstatOptions();
    await clearStatsTable();
    await setSlimstatOption(page, 'is_tracking', 'on');
    await setSlimstatOption(page, 'ignore_wp_users', 'off');
    await setSlimstatOption(page, 'gdpr_enabled', 'off');
    await setSlimstatOption(page, 'javascript_mode', 'off');
    await setSlimstatOption(page, 'tracking_request_method', 'rest');
    await setSlimstatOption(page, 'enable_browscap', 'on');
  });

  test.afterEach(async () => {
    await restoreSlimstatOptions();
  });

  test.afterAll(async () => {
    uninstallOptionMutator();
    await closeDb();
  });

  test('tracking request succeeds with Browscap enabled (no 500 TypeError)', async ({ page }) => {
    const marker = `e2e-browscap-${Date.now()}`;

    // Listen for tracking request responses to verify no 500 error
    const trackingResponses: { url: string; status: number }[] = [];
    page.on('response', (response) => {
      const url = response.url();
      if (url.includes('slimstat/v1/hit') || url.includes('admin-ajax.php')) {
        trackingResponses.push({ url, status: response.status() });
      }
    });

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);

    // Wait for the pageview row to appear in the DB
    const row = await waitForPageviewRow(marker);
    expect(row, 'pageview row must exist — tracking must not fail with TypeError').not.toBeNull();

    // Verify no 500 errors on tracking endpoints
    const serverErrors = trackingResponses.filter((r) => r.status >= 500);
    expect(
      serverErrors,
      `tracking endpoints must not return 500 errors: ${JSON.stringify(serverErrors)}`,
    ).toHaveLength(0);
  });

  test('Browscap populates browser field in stat row', async ({ page }) => {
    const marker = `e2e-browscap-browser-${Date.now()}`;

    await page.goto(`${BASE_URL}/?e2e=${marker}`, { waitUntil: 'domcontentloaded' });
    await waitForTrackerId(page);
    await waitForPageviewRow(marker);

    // Query for browser data on the stat row
    const [rows] = await getPool().execute(
      "SELECT browser, browser_version FROM wp_slim_stats WHERE resource LIKE ? ORDER BY id DESC LIMIT 1",
      [`%${marker}%`],
    ) as any;

    expect(rows.length, 'stat row must exist').toBeGreaterThan(0);
    const stat = rows[0];
    // Browscap should detect the browser (Chromium/Chrome for Playwright)
    // If Browscap fails silently, the fallback UADetector still populates this,
    // but if Browscap throws a fatal error, no row exists at all.
    expect(stat.browser, 'browser field must be populated').not.toBe('');
    expect(stat.browser, 'browser must not be default').not.toBe('Default Browser');
  });
});
