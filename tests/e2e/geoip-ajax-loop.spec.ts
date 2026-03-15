/**
 * E2E tests: GeoIP AJAX infinite loop prevention (PR #166)
 *
 * Verifies that the fallback GeoIP update mechanism fires at most once
 * when DISABLE_WP_CRON is true, instead of infinite self-replicating AJAX.
 *
 * Oracle: mu-plugin logger writes to wp-content/geoip-ajax-calls.log
 * each time the wp_ajax_slimstat_update_geoip_database handler is invoked.
 */
import { test, expect } from '@playwright/test';
import path from 'path';
import { fileURLToPath } from 'url';
import {
  setupTest,
  teardownTest,
  readAjaxLog,
  clearAjaxLog,
  getGeoipTimestamp,
  clearGeoipTimestamp,
  setProviderDisabled,
  closeDb,
  enableDisableWpCron,
  restoreWpConfig,
  installMuPlugin,
  uninstallMuPlugin,
} from './helpers/setup';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// All tests run sequentially (workers: 1) because they share wp-config.php and DB state.

test.describe('GeoIP AJAX loop prevention (admin)', () => {
  test.beforeEach(async () => {
    await setupTest();
  });

  test.afterEach(async () => {
    await teardownTest();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('Test 1: single admin page load triggers at most 1 AJAX call', async ({ page }) => {
    await page.goto('/wp-admin/');
    // Wait for the non-blocking wp_safe_remote_post to complete
    await page.waitForTimeout(4000);

    const log = readAjaxLog();
    // The key assertion: at most 1 AJAX call, not a cascade
    expect(log.length).toBeLessThanOrEqual(1);
    // Note: slimstat_last_geoip_dl may NOT be set if the download fails
    // (no GeoIP license key configured). That's OK — we're testing the
    // loop prevention, not the download success.
  });

  test('Test 2: successive admin loads — no multiplication', async ({ page }) => {
    test.setTimeout(120_000);
    // First load may trigger 1 AJAX call — wait for it to complete
    // before loading the second page. On slow local servers, the AJAX call
    // (wp_safe_remote_post to GeoIP provider) can take 10+ seconds.
    await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(8000); // Let first AJAX settle and set slimstat_last_geoip_dl

    // Second load should see slimstat_last_geoip_dl already set → no new AJAX
    await page.goto('/wp-admin/plugins.php', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(6000);

    const log = readAjaxLog();
    // Each load may trigger at most 1 AJAX call. On slow servers the first
    // AJAX may not complete before the second page load, so we allow up to 2.
    // Under the old bug, this would be 5+ (each load triggers its own AJAX).
    expect(log.length).toBeLessThanOrEqual(2);
  });

  test('Test 3: 3 concurrent tabs — bounded count (not exponential)', async ({ context }) => {
    test.setTimeout(120_000);
    // Open 3 pages simultaneously (reduced from 5 for local dev server stability)
    const urls = [
      '/wp-admin/',
      '/wp-admin/plugins.php',
      '/wp-admin/edit.php',
    ];

    const pagePromises = urls.map(async (url) => {
      const p = await context.newPage();
      await p.goto(url, { waitUntil: 'domcontentloaded' });
      return p;
    });

    const openPages = await Promise.all(pagePromises);

    // Wait for all async wp_safe_remote_post calls to complete
    await openPages[0].waitForTimeout(5000);

    const log = readAjaxLog();

    // Race condition: all 3 PHP processes may check before any AJAX completes.
    // Each fires one AJAX → up to 3. On slow servers, additional race windows
    // may produce up to 5. But NOT 9, 27, etc. (infinite loop).
    // The key assertion: count is bounded, not exponential.
    expect(log.length).toBeLessThanOrEqual(5);

    // Under the old bug, a second generation would fire, producing 3 more,
    // then 3 more, etc. With a 5-second wait, we'd see 9-15+ entries.
    // The fix ensures each AJAX request does NOT re-trigger itself.

    // Close extra pages
    for (const p of openPages) {
      await p.close();
    }
  });

  test('Test 5: provider disabled — 0 AJAX calls', async ({ page }) => {
    test.setTimeout(90_000);
    await setProviderDisabled();

    await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    const log = readAjaxLog();
    expect(log.length).toBe(0);

    // resolve_geolocation_provider() returns false → guard condition fails
    const ts = await getGeoipTimestamp();
    expect(ts).toBeNull();
  });

  test('Test 6: direct AJAX POST — no self-recursion', async ({ page }) => {
    test.setTimeout(90_000); // SlimStat admin page can take ~50 s on slow local servers
    // First, visit an admin page to get a valid nonce
    await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    clearAjaxLog(); // Clear any AJAX from the page load itself

    await clearGeoipTimestamp();
    clearAjaxLog();

    // Navigate to an admin page where SlimStat might inject the nonce
    await page.goto('/wp-admin/admin.php?page=slimstat', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    clearAjaxLog(); // Clear page-load AJAX

    // Use page.request (inherits browser cookies) to POST directly
    const response = await page.request.post('/wp-admin/admin-ajax.php', {
      form: {
        action: 'slimstat_update_geoip_database',
        security: 'test_invalid', // Will fail nonce, but tests the path
      },
    });

    await page.waitForTimeout(2000);

    const log = readAjaxLog();
    // The mu-plugin fires at priority 1 (before nonce check at priority 10)
    // so we should see exactly 1 entry even if the nonce fails
    expect(log.length).toBe(1);
    // The important thing: it's 1, not 2+. No self-recursion.
  });

  test('MaxMind provider: single admin page load triggers at most 1 AJAX call', async ({ page }) => {
    test.setTimeout(90_000);
    // Switch provider to maxmind2 via DB before the page load
    await clearGeoipTimestamp();
    clearAjaxLog();

    // Set provider to maxmind2 — the AJAX loop prevention should still work
    // regardless of which provider is active
    const mysql = await import('mysql2/promise');
    const { MYSQL_CONFIG } = await import('./helpers/env');
    const tmpPool = mysql.createPool(MYSQL_CONFIG);
    await tmpPool.execute(
      "UPDATE wp_options SET option_value = REPLACE(option_value, '\"geolocation_provider\":\"dbip\"', '\"geolocation_provider\":\"maxmind2\"') WHERE option_name = 'slimstat_options'"
    );
    await tmpPool.end();

    await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(4000);

    const log = readAjaxLog();
    // Same assertion as Test 1: at most 1 AJAX call, not a cascade
    expect(log.length).toBeLessThanOrEqual(1);
  });
});

// ─── Separate test for non-admin user (uses 'author' project) ──────

test.describe('GeoIP AJAX loop prevention (author)', () => {
  test.use({ storageState: path.join(__dirname, '.auth/author.json') });

  test.beforeEach(async () => {
    await setupTest();
  });

  test.afterEach(async () => {
    await teardownTest();
  });

  test.afterAll(async () => {
    await closeDb();
  });

  test('Test 4: author (non-admin) gets 0 AJAX calls', async ({ page }) => {
    await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);

    const log = readAjaxLog();
    expect(log.length).toBe(0);
    // The AJAX log being empty proves the author didn't trigger the fallback.
    // We don't check slimstat_last_geoip_dl here because a prior admin test's
    // async wp_safe_remote_post may set it after this test's clearGeoipTimestamp().
  });
});
